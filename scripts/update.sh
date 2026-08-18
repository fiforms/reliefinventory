#!/usr/bin/env bash
#
# update.sh — backup, update, and rebuild the Relief Inventory app in place.
#
# This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
# Licensed under the GNU GPL v. 3. See LICENSE.md for details
#
# Run as root on the server (app commands are dropped to www-data internally):
#
#   bash scripts/update.sh                     full update
#   bash scripts/update.sh --backup-only       backup now, nothing else
#   bash scripts/update.sh --scheduled         backup only if one is due (systemd timer)
#   bash scripts/update.sh --seed-permissions  full update + PermissionsSeeder
#
# Backups are tiered: every backup lands in daily/, and the first backup of a
# month/year is also promoted (as hardlinks, so promotion costs no space) into
# monthly/ and yearly/. Retention, schedule time, and frequency come from
# storage/app/backup-settings.conf — a www-data-writable file, so the admin
# panel can later manage the schedule without root. See scripts/BACKUPS.md.
#
# Sequence: backup (DB dump + storage/app + .env + current git SHA) -> maintenance
# mode -> git reset to origin -> composer/npm only if lock files changed -> asset
# build -> migrate -> cache rebuild -> service restarts -> health check against
# /up (behind the maintenance-mode bypass secret) -> up. If any step fails, the
# site is left in maintenance mode and the log names the backup to restore from.
#
# The full seeder must never run here: several seeders are not idempotent and
# will hit unique-constraint violations on a live database. PermissionsSeeder is
# safe to repeat, hence the dedicated flag.

set -Eeuo pipefail

# Re-exec from a temp copy: 'git reset --hard' below may replace this very file
# mid-run, and bash reads scripts lazily, so running from the checkout directly
# could execute a corrupted mix of old and new script. The copy is immune.
#
# Capture the real script location *before* the copy/exec below, since the temp
# copy's BASH_SOURCE would otherwise point into /tmp — that location is how
# APP_DIR defaults to "whichever instance this script actually lives in"
# further down, instead of a hardcoded path.
if [ -z "${RI_UPDATE_REEXEC:-}" ]; then
    _self_copy="$(mktemp /tmp/ri-update.XXXXXX.sh)"
    cp "${BASH_SOURCE[0]}" "$_self_copy"
    RI_UPDATE_REEXEC=1 \
    RI_UPDATE_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)" \
    exec bash "$_self_copy" "$@"
fi
COOKIE_JAR=""
cleanup() {
    [ -n "$COOKIE_JAR" ] && rm -f "$COOKIE_JAR"
    rm -f "$0"   # the temp copy of ourselves
}
trap cleanup EXIT

# ---------------------------------------------------------------- configuration
# Defaults to the instance this script physically lives in (its parent dir),
# so 'cd /var/www/reliefinventory-wa26 && bash scripts/update.sh' targets wa26
# rather than silently redeploying demo. Explicit APP_DIR (set by the systemd
# units below) always wins; the hardcoded fallback only matters if this script
# is ever invoked via a path bash can't resolve back to a directory.
APP_DIR="${APP_DIR:-$(dirname "${RI_UPDATE_SCRIPT_DIR:-/var/www/reliefinventory-demo/scripts}")}"
APP_USER="${APP_USER:-www-data}"
APP_USER_HOME="${APP_USER_HOME:-/var/www}"
# Mirrors the systemd units' explicit overrides (BACKUP_DIR=.../reliefinventory
# for demo, .../reliefinventory-wa26 for wa26) so a manual run without an
# explicit override doesn't comingle backups from two instances in one folder.
_instance_suffix="$(basename "$APP_DIR" | sed 's/^reliefinventory-//')"
if [ "$_instance_suffix" = "demo" ]; then
    BACKUP_DIR="${BACKUP_DIR:-/var/backups/reliefinventory}"
else
    BACKUP_DIR="${BACKUP_DIR:-/var/backups/reliefinventory-$_instance_suffix}"
fi
LOG_DIR="${LOG_DIR:-/var/log/reliefinventory}"
PHP="${PHP:-php8.4}"
BRANCH="${BRANCH:-master}"
QUEUE_SERVICE="${QUEUE_SERVICE:-$(basename "$APP_DIR")-queue}"
FPM_SERVICE="${FPM_SERVICE:-php8.4-fpm}"
# Health checks default to APP_URL from .env; override if that isn't reachable
# from the box itself: HEALTH_URL=http://127.0.0.1 bash scripts/update.sh
HEALTH_URL="${HEALTH_URL:-}"

BACKUP_ONLY=0
SEED_PERMISSIONS=0
SCHEDULED=0
for arg in "$@"; do
    case "$arg" in
        --backup-only) BACKUP_ONLY=1 ;;
        --scheduled) SCHEDULED=1; BACKUP_ONLY=1 ;;
        --seed-permissions) SEED_PERMISSIONS=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

[ "$(id -u)" -eq 0 ] || { echo "Run as root (app steps drop to $APP_USER)." >&2; exit 2; }
[ -f "$APP_DIR/artisan" ] || { echo "No artisan found in $APP_DIR" >&2; exit 2; }

# ------------------------------------------------- backup schedule + retention
# Settings live in a www-data-writable file so the admin panel can manage them
# without root. The file is parsed key-by-key with strict validation and is
# NEVER sourced — a value that fails validation silently falls back to the
# default, so a garbled (or malicious) file can't break backups or inject shell.
SETTINGS_FILE="${SETTINGS_FILE:-$APP_DIR/storage/app/backup-settings.conf}"
setting() { # setting <key> <default> <valid-regex>
    local raw
    raw="$(sed -n "s/^$1=//p" "$SETTINGS_FILE" 2>/dev/null | head -n1 | tr -d "[:space:]\"'")"
    if printf '%s' "$raw" | grep -Eqx "$3"; then printf '%s' "$raw"; else printf '%s' "$2"; fi
}
BACKUP_FREQUENCY="$(setting BACKUP_FREQUENCY daily 'daily|weekly')"
BACKUP_HOUR="$(setting BACKUP_HOUR 2 '[0-9]|1[0-9]|2[0-3]')"          # 0-23, in BACKUP_TZ
BACKUP_DOW="$(setting BACKUP_DOW 7 '[1-7]')"                          # weekly only: 1=Mon..7=Sun
BACKUP_TZ="$(setting BACKUP_TZ America/Los_Angeles '[A-Za-z0-9_/+-]+')"
KEEP_DAILY="$(setting KEEP_DAILY 14 '[0-9]{1,3}')"
KEEP_MONTHLY="$(setting KEEP_MONTHLY 12 '[0-9]{1,3}')"
KEEP_YEARLY="$(setting KEEP_YEARLY 3 '[0-9]{1,2}')"

# --scheduled (the hourly systemd timer) only proceeds when a backup is due:
# we're at/past the configured hour in the configured timezone (>= rather than
# ==, so a reboot that skips the exact hour still catches up later that day),
# on the right weekday for weekly mode, and none has run yet that day.
MARKER="$BACKUP_DIR/.last-scheduled"
if [ "$SCHEDULED" -eq 1 ]; then
    NOW_HOUR=$((10#$(TZ="$BACKUP_TZ" date +%H)))
    TODAY="$(TZ="$BACKUP_TZ" date +%Y%m%d)"
    NOW_DOW="$(TZ="$BACKUP_TZ" date +%u)"
    if [ "$NOW_HOUR" -lt "$BACKUP_HOUR" ]; then
        echo "Scheduled check: not due yet (before $BACKUP_HOUR:00 $BACKUP_TZ)"; exit 0
    fi
    if [ "$BACKUP_FREQUENCY" = "weekly" ] && [ "$NOW_DOW" -ne "$BACKUP_DOW" ]; then
        echo "Scheduled check: not due (weekly, day $BACKUP_DOW)"; exit 0
    fi
    if [ -f "$MARKER" ] && [ "$(cat "$MARKER")" = "$TODAY" ]; then
        echo "Scheduled check: already backed up today"; exit 0
    fi
    echo "Scheduled backup due ($BACKUP_FREQUENCY at $BACKUP_HOUR:00 $BACKUP_TZ)"
fi

# Run a command as the app user, in the app dir, with the app user's HOME so
# composer/npm caches land under $APP_USER_HOME and never as root.
as_app() {
    runuser -u "$APP_USER" -- env HOME="$APP_USER_HOME" "$@"
}

# Progress reporting for the admin panel (full updates only): a small JSON
# file the app can read back while this script runs outside the request cycle.
STATUS_FILE="${STATUS_FILE:-$APP_DIR/storage/app/system-update-status.json}"
write_status() { # write_status <state> <message>
    [ "$BACKUP_ONLY" -eq 1 ] && return 0
    printf '{"state":"%s","message":"%s","sha":"%s","updated_at":"%s"}\n' \
        "$1" "$2" "${NEW_SHA:-${OLD_SHA:-}}" "$(date -u +%FT%TZ)" > "$STATUS_FILE"
    chown "$APP_USER" "$STATUS_FILE" 2>/dev/null || true
}

mkdir -p "$BACKUP_DIR" "$LOG_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
LOG_FILE="$LOG_DIR/update-$STAMP.log"
exec > >(tee -a "$LOG_FILE") 2>&1
echo "== Relief Inventory update $STAMP (log: $LOG_FILE) =="

# Refuse to run twice at once (matters once a UI button or timer can trigger this).
exec 200>"/var/lock/reliefinventory-update.lock"
flock -n 200 || { echo "Another update/backup is already running; aborting." >&2; exit 3; }

cd "$APP_DIR"

# Read a key out of .env (strips optional surrounding quotes).
env_get() {
    sed -n "s/^$1=//p" "$APP_DIR/.env" | head -n1 | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

MAINTENANCE_DOWN=0
on_error() {
    echo "!! FAILED at line $1. See $LOG_FILE" >&2
    write_status failed "Update failed (line $1, log update-$STAMP.log)"
    if [ "$MAINTENANCE_DOWN" -eq 1 ]; then
        echo "!! The site has been LEFT IN MAINTENANCE MODE on purpose." >&2
        echo "!! To roll back: restore $BACKUP_PATH/db.sql.gz into the database," >&2
        echo "!! 'git reset --hard \$(cat $BACKUP_PATH/git-sha.txt)' as $APP_USER," >&2
        echo "!! re-run composer install / npm run build if needed, then '$PHP artisan up'." >&2
    fi
}
trap 'on_error $LINENO' ERR

write_status running "Update running (started $STAMP)"

# -------------------------------------------------------------------- 1. backup
BACKUP_PATH="$BACKUP_DIR/daily/$STAMP"
mkdir -p "$BACKUP_PATH"
echo "-- Backing up to $BACKUP_PATH"

DB_DATABASE="$(env_get DB_DATABASE)"
DB_USERNAME="$(env_get DB_USERNAME)"
DB_PASSWORD="$(env_get DB_PASSWORD)"
DB_HOST="$(env_get DB_HOST)"
[ -n "$DB_DATABASE" ] || { echo "Could not read DB_DATABASE from .env" >&2; exit 2; }

MYSQL_PWD="$DB_PASSWORD" mysqldump --single-transaction --routines --triggers \
    -h "${DB_HOST:-127.0.0.1}" -u "$DB_USERNAME" "$DB_DATABASE" | gzip > "$BACKUP_PATH/db.sql.gz"
gzip -t "$BACKUP_PATH/db.sql.gz"

tar -czf "$BACKUP_PATH/storage-app.tar.gz" -C "$APP_DIR" storage/app
cp -p "$APP_DIR/.env" "$BACKUP_PATH/env.backup"
if [ -d "$APP_DIR/.git" ]; then
    as_app git -C "$APP_DIR" rev-parse HEAD > "$BACKUP_PATH/git-sha.txt"
fi
chmod -R go-rwx "$BACKUP_PATH"   # dump + .env hold credentials
echo "-- Backup complete: $(du -sh "$BACKUP_PATH" | cut -f1)"

# Promotion: the first backup of a month/year is also linked into monthly/ or
# yearly/. cp -al hardlinks the files, so a promoted backup costs no extra
# space until the daily copy it shares data with is pruned.
promote() { # promote <tier> <stamp-prefix>
    local tier="$1" prefix="$2" existing
    mkdir -p "$BACKUP_DIR/$tier"
    existing=( "$BACKUP_DIR/$tier/$prefix"* )
    if [ ! -e "${existing[0]}" ]; then
        echo "-- Promoting this backup to $tier/ (first of $prefix)"
        cp -al "$BACKUP_PATH" "$BACKUP_DIR/$tier/$STAMP"
    fi
}
promote monthly "$(date +%Y%m)"
promote yearly "$(date +%Y)"

# Per-tier rotation. Stamp names sort chronologically, so glob order is age order.
prune_tier() { # prune_tier <dir> <keep-count>
    local dir="$1" keep="$2"
    [ -d "$dir" ] || return 0
    local entries=( "$dir"/*/ )
    [ -e "${entries[0]}" ] || return 0
    while [ "${#entries[@]}" -gt "$keep" ]; do
        echo "-- Pruning old backup ${entries[0]}"
        rm -rf "${entries[0]}"
        entries=( "${entries[@]:1}" )
    done
}
prune_tier "$BACKUP_DIR/daily" "$KEEP_DAILY"
prune_tier "$BACKUP_DIR/monthly" "$KEEP_MONTHLY"
prune_tier "$BACKUP_DIR/yearly" "$KEEP_YEARLY"

if [ "$SCHEDULED" -eq 1 ]; then
    echo "$TODAY" > "$MARKER"
fi

if [ "$BACKUP_ONLY" -eq 1 ]; then
    echo "== Backup-only run finished OK =="
    exit 0
fi

[ -d "$APP_DIR/.git" ] || { echo "$APP_DIR is not a git checkout — run the conversion in scripts/CONVERT-TO-GIT.md first." >&2; exit 2; }

# -------------------------------------------------------- 2. maintenance mode on
SECRET="$(openssl rand -hex 16)"
echo "-- Entering maintenance mode (bypass: /$SECRET)"
as_app "$PHP" artisan down --secret="$SECRET" --retry=60
MAINTENANCE_DOWN=1

# ------------------------------------------------------------------ 3. git pull
OLD_SHA="$(as_app git -C "$APP_DIR" rev-parse HEAD)"
as_app git -C "$APP_DIR" fetch origin "$BRANCH"
NEW_SHA="$(as_app git -C "$APP_DIR" rev-parse "origin/$BRANCH")"
echo "-- Updating $OLD_SHA -> $NEW_SHA"
as_app git -C "$APP_DIR" reset --hard "origin/$BRANCH"

CHANGED="$(as_app git -C "$APP_DIR" diff --name-only "$OLD_SHA" "$NEW_SHA")"

# ------------------------------------------------------------------- 4. rebuild
if echo "$CHANGED" | grep -qx 'composer.lock'; then
    echo "-- composer.lock changed; running composer install"
    as_app composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$APP_DIR"
else
    echo "-- composer.lock unchanged; skipping composer install"
fi

if echo "$CHANGED" | grep -qx 'package-lock.json'; then
    echo "-- package-lock.json changed; running npm ci"
    as_app npm ci --prefix "$APP_DIR"
else
    echo "-- package-lock.json unchanged; skipping npm ci"
fi
as_app npm run build --prefix "$APP_DIR"

# ------------------------------------------------------------------ 5. database
as_app "$PHP" artisan migrate --force
if [ "$SEED_PERMISSIONS" -eq 1 ]; then
    echo "-- Seeding permissions (PermissionsSeeder only)"
    as_app "$PHP" artisan db:seed --class=PermissionsSeeder --force
fi

# ------------------------------------------------------- 6. caches and services
as_app "$PHP" artisan config:cache
as_app "$PHP" artisan route:cache
as_app "$PHP" artisan view:cache
systemctl restart "$QUEUE_SERVICE"
systemctl reload "$FPM_SERVICE"

# -------------------------------------------------- 7. health check, then go up
BASE_URL="${HEALTH_URL:-$(env_get APP_URL)}"
COOKIE_JAR="$(mktemp)"
echo "-- Health check against $BASE_URL/up (via maintenance bypass)"
curl -fskL -c "$COOKIE_JAR" -o /dev/null "$BASE_URL/$SECRET"
STATUS="$(curl -sk -b "$COOKIE_JAR" -o /dev/null -w '%{http_code}' "$BASE_URL/up")"
if [ "$STATUS" != "200" ]; then
    echo "!! Health check returned $STATUS — leaving the site in maintenance mode." >&2
    false   # routes through the ERR trap for rollback instructions
fi

as_app "$PHP" artisan up
MAINTENANCE_DOWN=0
STATUS="$(curl -sk -o /dev/null -w '%{http_code}' "$BASE_URL/up")"
[ "$STATUS" = "200" ] || { echo "!! Site is up but /up returned $STATUS — check manually." >&2; exit 1; }

write_status success "Updated to ${NEW_SHA:0:9}"
echo "== Update finished OK: now on $NEW_SHA =="
