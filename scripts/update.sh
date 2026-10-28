#!/usr/bin/env bash
#
# update.sh — backup, update, and rebuild the Relief Inventory app in place.
#
# DEPRECATED (bare-metal installs only): running this from the web page (System Administration > Install Update, via
# reliefinventory-update.service) is deprecated and will be removed — git pull + migrate + npm build from a web request can leave a
# half-updated system. Run it from a shell if you must; Docker installs use the host-side updater (docker/updater/) instead.
#
# This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
# Licensed under the GNU GPL v. 3. See LICENSE.md for details
#
# Run as root on the server (app commands are dropped to www-data internally):
#
#   bash scripts/update.sh                     full update
#   bash scripts/update.sh --backup-only       backup now, nothing else
#   bash scripts/update.sh --scheduled         backup only if one is due (hourly systemd timer)
#   bash scripts/update.sh --seed-permissions  full update + PermissionsSeeder
#
# Every backup is one zip (the database, with a manifest of checksums) made by
# `php artisan backup:create --scope=database --dest=...` — the same command and
# format as every other backup in the app (scripts/BACKUPS.md). It never contains
# the application key or .env. Uploaded files are NOT part of the tiers (an update
# never touches them): take a "Database + uploaded files" backup from the admin
# page, or `backup:create --scope=full`, when they need protecting too.
#
# Backups are tiered: every backup (scheduled, hourly if HOURLY_BACKUPS=1, or
# on-demand — a full update's pre-update backup, or "Run a Scheduled Backup Now"
# from the admin panel) lands in hourly/,
# kept a short while so a bad update can be rolled back to whichever attempt
# preceded it. Only the once-a-day *scheduled* backup is also promoted (as a
# hardlink, so promotion costs no space) into daily/, and the first such
# promotion of a month/year further promotes into monthly/ and yearly/ — an
# on-demand backup never enters daily/monthly/yearly directly, so it can never
# crowd out that history no matter how many times it's triggered in a day.
# Retention, schedule time, and frequency come from
# storage/app/backup-settings.conf — a www-data-writable file, so the admin
# panel can later manage the schedule without root. See scripts/BACKUPS.md.
#
# Sequence: backup (zip: DB dump + manifest with the git commit) -> maintenance
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
REVERB_SERVICE="${REVERB_SERVICE:-$(basename "$APP_DIR")-reverb}"
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
HOURLY_BACKUPS="$(setting HOURLY_BACKUPS 0 '0|1')"                    # 1 = also back up every hour
BACKUP_TZ="$(setting BACKUP_TZ America/Los_Angeles '[A-Za-z0-9_/+-]+')"
KEEP_HOURLY="$(setting KEEP_HOURLY 48 '[0-9]{1,4}')"          # every raw backup, scheduled or on-demand
KEEP_DAILY="$(setting KEEP_DAILY 14 '[0-9]{1,3}')"
KEEP_MONTHLY="$(setting KEEP_MONTHLY 12 '[0-9]{1,3}')"
KEEP_YEARLY="$(setting KEEP_YEARLY 3 '[0-9]{1,2}')"

# --scheduled (the hourly systemd timer) only proceeds when a backup is due.
# Two independent reasons a run can be due:
#  - the daily/weekly backup: we're at/past the configured hour in the
#    configured timezone (>= rather than ==, so a reboot that skips the exact
#    hour still catches up later that day), on the right weekday for weekly
#    mode, and none has run yet that day. Only this one is promoted into
#    daily/ (and from there monthly/ and yearly/).
#  - an hourly backup, when HOURLY_BACKUPS=1: none has run yet this hour.
#    These only ever land in hourly/ (kept KEEP_HOURLY deep), same as an
#    on-demand backup.
MARKER="$BACKUP_DIR/.last-scheduled"
HOURLY_MARKER="$BACKUP_DIR/.last-hourly"
DAILY_DUE=0
if [ "$SCHEDULED" -eq 1 ]; then
    NOW_HOUR=$((10#$(TZ="$BACKUP_TZ" date +%H)))
    TODAY="$(TZ="$BACKUP_TZ" date +%Y%m%d)"
    THIS_HOUR="$(TZ="$BACKUP_TZ" date +%Y%m%d%H)"
    NOW_DOW="$(TZ="$BACKUP_TZ" date +%u)"
    if [ "$NOW_HOUR" -lt "$BACKUP_HOUR" ]; then
        echo "Scheduled check: daily backup not due yet (before $BACKUP_HOUR:00 $BACKUP_TZ)"
    elif [ "$BACKUP_FREQUENCY" = "weekly" ] && [ "$NOW_DOW" -ne "$BACKUP_DOW" ]; then
        echo "Scheduled check: daily backup not due (weekly, day $BACKUP_DOW)"
    elif [ -f "$MARKER" ] && [ "$(cat "$MARKER")" = "$TODAY" ]; then
        echo "Scheduled check: already backed up today"
    else
        DAILY_DUE=1
    fi

    if [ "$DAILY_DUE" -eq 1 ]; then
        echo "Scheduled backup due ($BACKUP_FREQUENCY at $BACKUP_HOUR:00 $BACKUP_TZ)"
    elif [ "$HOURLY_BACKUPS" -eq 1 ] && ! { [ -f "$HOURLY_MARKER" ] && [ "$(cat "$HOURLY_MARKER")" = "$THIS_HOUR" ]; }; then
        echo "Hourly backup due"
    else
        exit 0
    fi
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
# In BACKUP_TZ, not server-local time — the admin panel displays these stamps
# verbatim (SystemAdmin.vue's formatStamp) as if they were already in the
# configured backup timezone, so the stamp actually has to be in it.
STAMP="$(TZ="$BACKUP_TZ" date +%Y%m%d-%H%M%S)"
# Instance name in the file name: several instances share LOG_DIR, and two started in the same second (in the same
# BACKUP_TZ) would otherwise write into one log.
LOG_NAME="update-$_instance_suffix-$STAMP.log"
LOG_FILE="$LOG_DIR/$LOG_NAME"
exec > >(tee -a "$LOG_FILE") 2>&1
echo "== Relief Inventory update $STAMP (log: $LOG_FILE) =="

# One update/backup at a time on this server, across every instance on it: they share the box's CPU, memory and
# php-fpm, and two npm builds or database dumps at once is what can actually break things. A run that finds the
# lock taken waits its turn (up to LOCK_WAIT_SECONDS) instead of giving up — giving up used to leave the admin
# panel showing "waiting for the updater to start" until it timed out as "stalled". While waiting it refreshes the
# status every 15 s, so the panel says what it's waiting for and never mistakes the wait for a stall.
LOCK_WAIT_SECONDS="${LOCK_WAIT_SECONDS:-3600}"
exec 200>"/var/lock/reliefinventory-update.lock"
if ! flock -n 200; then
    echo "-- Another update or backup is running on this server; waiting for it to finish..."
    write_status running "Waiting for another update or backup on this server to finish"
    waited=0
    until flock -w 15 200; do
        waited=$((waited + 15))
        if [ "$waited" -ge "$LOCK_WAIT_SECONDS" ]; then
            echo "!! Still waiting after ${LOCK_WAIT_SECONDS}s; giving up." >&2
            write_status failed "Gave up after waiting $((LOCK_WAIT_SECONDS / 60)) minutes for another update or backup on this server to finish. Try again later."
            exit 3
        fi
        write_status running "Waiting for another update or backup on this server to finish (${waited}s so far)"
    done
    echo "-- Lock acquired after ${waited}s."
fi

cd "$APP_DIR"

# Read a key out of .env (strips optional surrounding quotes).
env_get() {
    sed -n "s/^$1=//p" "$APP_DIR/.env" | head -n1 | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

MAINTENANCE_DOWN=0
on_error() {
    echo "!! FAILED at line $1. See $LOG_FILE" >&2
    write_status failed "Update failed (line $1, log $LOG_NAME)"
    if [ "$MAINTENANCE_DOWN" -eq 1 ]; then
        echo "!! The site has been LEFT IN MAINTENANCE MODE on purpose." >&2
        echo "!! To roll back, as $APP_USER in $APP_DIR:" >&2
        echo "!!   1. 'git reset --hard ${OLD_SHA:-<commit in the zip manifest: unzip -p $BACKUP_PATH manifest.json>}'," >&2
        echo "!!      then re-run composer install / npm run build if needed," >&2
        echo "!!   2. '$PHP artisan backup:restore --force $BACKUP_PATH' (restores the database, then brings the site up)." >&2
        echo "!! Or, if the database is fine, just fix the failure and run '$PHP artisan up'." >&2
    fi
}
trap 'on_error $LINENO' ERR

write_status running "Update running (started $STAMP)"

# -------------------------------------------------------------------- 1. backup
# Every backup lands here first, scheduled or on-demand — see the tiering
# note at the top of the file for why only the scheduled one gets promoted
# further, into daily/ and beyond.
#
# The zip is written by the app user (artisan needs .env and the database
# credentials, and must not create root-owned files under storage/), which
# cannot write the root-owned tier directories — so it is built in a staging
# directory beside them (same filesystem, so the move is a rename) and moved in.
BACKUP_PATH="$BACKUP_DIR/hourly/$STAMP.zip"
STAGING="$BACKUP_DIR/.staging"
mkdir -p "$BACKUP_DIR/hourly" "$STAGING"
find "$STAGING" -mindepth 1 -delete   # leftovers of an interrupted run (the update lock is held, so nothing else is using it)
chown "$APP_USER" "$STAGING"
echo "-- Backing up to $BACKUP_PATH"

as_app "$PHP" artisan backup:create --scope=database --dest="$STAGING" --name="$STAMP"
[ -s "$STAGING/$STAMP.zip" ] || { echo "Backup did not produce $STAGING/$STAMP.zip" >&2; exit 2; }
mv "$STAGING/$STAMP.zip" "$BACKUP_PATH"
chown root:"$APP_USER" "$BACKUP_PATH"   # group-readable below, once the tier directories are sorted out
echo "-- Backup complete: $(du -sh "$BACKUP_PATH" | cut -f1)"

# Promotion: only the once-a-day *scheduled* backup (never an on-demand one —
# see the tiering note at the top of the file) is linked into daily/, and the
# first such promotion of a month/year is further linked into monthly/ or
# yearly/. A hard link, so a promoted backup costs no extra space until the
# hourly copy it shares data with is pruned.
promote() { # promote <tier> <stamp-prefix>
    local tier="$1" prefix="$2" existing
    mkdir -p "$BACKUP_DIR/$tier"
    existing=( "$BACKUP_DIR/$tier/$prefix"* )
    if [ ! -e "${existing[0]}" ]; then
        echo "-- Promoting this backup to $tier/ (first of $prefix)"
        ln "$BACKUP_PATH" "$BACKUP_DIR/$tier/$STAMP.zip"
    fi
}
if [ "$DAILY_DUE" -eq 1 ]; then
    promote daily "$TODAY"
    promote monthly "$(TZ="$BACKUP_TZ" date +%Y%m)"
    promote yearly "$(TZ="$BACKUP_TZ" date +%Y)"
fi

# Per-tier rotation, oldest first by actual mtime — NOT by glob/name order.
# Stamp names only sort chronologically as long as every stamp was generated
# under the same BACKUP_TZ; right after that setting changes, a backup made
# under the old TZ can sort as "newer" than one made minutes later under the
# new one purely from the string comparison, which would prune the wrong one.
# An entry is a <stamp>.zip, or a <stamp>/ directory made before backups were zipped.
prune_tier() { # prune_tier <dir> <keep-count>
    local dir="$1" keep="$2"
    [ -d "$dir" ] || return 0
    local entries=()
    mapfile -t entries < <(find "$dir" -mindepth 1 -maxdepth 1 \( -type d -o -name '*.zip' \) -printf '%T@\t%p\n' | sort -n | cut -f2-)
    [ "${#entries[@]}" -gt 0 ] || return 0
    while [ "${#entries[@]}" -gt "$keep" ]; do
        echo "-- Pruning old backup ${entries[0]}"
        rm -rf "${entries[0]}"
        entries=( "${entries[@]:1}" )
    done
}
# The admin page lists the tiers and offers each zip for download (to manage-backups holders, exactly like an
# on-demand backup), so the web user must be able to read them: group-readable by the app user, nobody else.
# The zips hold no secrets beyond the data itself — never the application key or .env. Done on every run so a
# directory or zip made before this existed (or by an older umask) is brought in line.
chgrp "$APP_USER" "$BACKUP_DIR" 2>/dev/null && chmod 750 "$BACKUP_DIR"
for tier in hourly daily monthly yearly; do
    [ -d "$BACKUP_DIR/$tier" ] || continue
    chgrp "$APP_USER" "$BACKUP_DIR/$tier" && chmod 750 "$BACKUP_DIR/$tier"
    find "$BACKUP_DIR/$tier" -maxdepth 1 -name '*.zip' -exec chgrp "$APP_USER" {} + -exec chmod 640 {} +
done

prune_tier "$BACKUP_DIR/hourly" "$KEEP_HOURLY"
prune_tier "$BACKUP_DIR/daily" "$KEEP_DAILY"
prune_tier "$BACKUP_DIR/monthly" "$KEEP_MONTHLY"
prune_tier "$BACKUP_DIR/yearly" "$KEEP_YEARLY"

if [ "$DAILY_DUE" -eq 1 ]; then
    echo "$TODAY" > "$MARKER"
fi
if [ "$SCHEDULED" -eq 1 ]; then
    echo "$THIS_HOUR" > "$HOURLY_MARKER"
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
# Needs the <db>_control and <db>_accounts schemas beside the app database (BUILDING.md, MULTI_WAREHOUSE.md):
# create them once, by hand, as MariaDB root. Bare metal is single-warehouse and slated for deprecation.
as_app "$PHP" artisan instance:migrate --force
if [ "$SEED_PERMISSIONS" -eq 1 ]; then
    echo "-- Seeding permissions (PermissionsSeeder only)"
    as_app "$PHP" artisan db:seed --class=PermissionsSeeder --force
fi

# ------------------------------------------------------- 6. caches and services
as_app "$PHP" artisan config:cache
# The cached config holds every secret from .env (APP_KEY, DB password, mail
# key) but is written world-readable under the default umask; lock it down to
# match .env so other local accounts (e.g. claude-agent) can't read it.
chmod 640 "$APP_DIR/bootstrap/cache/config.php"
as_app "$PHP" artisan route:cache
as_app "$PHP" artisan view:cache
systemctl restart "$QUEUE_SERVICE"
# Reverb (realtime) is long-running and holds the old code in memory; bounce it
# too, but only on boxes that actually run it (see scripts/REVERB.md). Clients
# reconnect on their own.
if systemctl cat "$REVERB_SERVICE.service" >/dev/null 2>&1; then
    systemctl restart "$REVERB_SERVICE"
fi
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
