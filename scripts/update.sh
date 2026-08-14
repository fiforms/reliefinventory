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
#   bash scripts/update.sh --backup-only       backup, nothing else (for cron/timer)
#   bash scripts/update.sh --seed-permissions  full update + PermissionsSeeder
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

# ---------------------------------------------------------------- configuration
APP_DIR="${APP_DIR:-/var/www/reliefinventory-demo}"
APP_USER="${APP_USER:-www-data}"
APP_USER_HOME="${APP_USER_HOME:-/var/www}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/reliefinventory}"
LOG_DIR="${LOG_DIR:-/var/log/reliefinventory}"
KEEP_BACKUPS="${KEEP_BACKUPS:-14}"
PHP="${PHP:-php8.4}"
BRANCH="${BRANCH:-master}"
QUEUE_SERVICE="${QUEUE_SERVICE:-reliefinventory-demo-queue}"
FPM_SERVICE="${FPM_SERVICE:-php8.4-fpm}"
# Health checks default to APP_URL from .env; override if that isn't reachable
# from the box itself: HEALTH_URL=http://127.0.0.1 bash scripts/update.sh
HEALTH_URL="${HEALTH_URL:-}"

BACKUP_ONLY=0
SEED_PERMISSIONS=0
for arg in "$@"; do
    case "$arg" in
        --backup-only) BACKUP_ONLY=1 ;;
        --seed-permissions) SEED_PERMISSIONS=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

[ "$(id -u)" -eq 0 ] || { echo "Run as root (app steps drop to $APP_USER)." >&2; exit 2; }
[ -f "$APP_DIR/artisan" ] || { echo "No artisan found in $APP_DIR" >&2; exit 2; }

# Run a command as the app user, in the app dir, with the app user's HOME so
# composer/npm caches land under $APP_USER_HOME and never as root.
as_app() {
    runuser -u "$APP_USER" -- env HOME="$APP_USER_HOME" "$@"
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
    if [ "$MAINTENANCE_DOWN" -eq 1 ]; then
        echo "!! The site has been LEFT IN MAINTENANCE MODE on purpose." >&2
        echo "!! To roll back: restore $BACKUP_PATH/db.sql.gz into the database," >&2
        echo "!! 'git reset --hard \$(cat $BACKUP_PATH/git-sha.txt)' as $APP_USER," >&2
        echo "!! re-run composer install / npm run build if needed, then '$PHP artisan up'." >&2
    fi
}
trap 'on_error $LINENO' ERR

# -------------------------------------------------------------------- 1. backup
BACKUP_PATH="$BACKUP_DIR/$STAMP"
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

# Rotation: keep the newest $KEEP_BACKUPS timestamped directories.
ls -1d "$BACKUP_DIR"/*/ 2>/dev/null | sort | head -n -"$KEEP_BACKUPS" | while read -r old; do
    echo "-- Pruning old backup $old"
    rm -rf "$old"
done

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
trap 'rm -f "$COOKIE_JAR"' EXIT
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

echo "== Update finished OK: now on $NEW_SHA =="
