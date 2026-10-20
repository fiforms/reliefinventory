#!/bin/bash
# Relief Inventory container entrypoint.
#
#   ri-entrypoint init-secrets   one-shot: create the database passwords (first start only)
#   ri-entrypoint web            (default) first-run setup, then serve HTTP on :8080
#   ri-entrypoint queue          queue worker
#   ri-entrypoint scheduler      hourly volunteer-window sync + daily DB dump
#   ri-entrypoint reverb         optional realtime (WebSocket) server
#   ri-entrypoint artisan <...>  run an artisan command (e.g. user:create)
#   ri-entrypoint <anything>     run that command as-is
#
# Only `web` migrates/seeds/creates the key; the others wait for it, so start
# them after web is healthy (the compose file does).
set -Eeuo pipefail

APP=/var/www/html
ROLE="${1:-web}"
KEYFILE="$APP/storage/app/.app_key"
SECRETS_DIR="${RI_SECRETS_DIR:-/secrets}"

log() { echo "[ri-entrypoint] $*" >&2; }

# ---- phase 1 (root): fix up volume ownership, then drop privileges ----------
if [ "$(id -u)" = 0 ] && [ -z "${RI_DROPPED:-}" ]; then
    mkdir -p /tmp/home "$APP"/storage/{app/public,app/private,framework/cache,framework/sessions,framework/views,logs} "$APP/bootstrap/cache"
    # Named volumes start root-owned; only touch what isn't already right so a
    # large storage volume doesn't cost a full chown on every start.
    extra=""
    # only the init role writes to (and so owns) the secrets volume; everyone else mounts it read-only
    [ "$ROLE" = init-secrets ] && { mkdir -p "$SECRETS_DIR"; extra="$SECRETS_DIR"; }
    for d in /tmp/home "$APP/storage" "$APP/bootstrap/cache" "${RI_BACKUP_DIR:-/backups}" $extra ${RI_CHOWN_EXTRA:-}; do
        [ -d "$d" ] || continue
        find "$d" -not -user www-data -exec chown www-data:www-data {} + 2>/dev/null || true
    done
    export RI_DROPPED=1
    exec setpriv --reuid=www-data --regid=www-data --init-groups "$0" "$@"
fi
# Running as some other non-root user (e.g. `user:` in compose / OpenShift):
# HOME must still be somewhere writable (weasyprint's fontconfig cache).
mkdir -p "${HOME:-/tmp/home}" 2>/dev/null || true

cd "$APP"

# ---- helpers ------------------------------------------------------------------
db_probe() { # exit 0 when the database accepts a connection and answers a query
    php -r '
        try {
            $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "127.0.0.1", getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE") ?: "reliefinventory");
            $p = new PDO($dsn, getenv("DB_USERNAME") ?: "reliefinventory", getenv("DB_PASSWORD") ?: "", [PDO::ATTR_TIMEOUT => 3]);
            $p->query("SELECT 1");
        } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }' 2>/dev/null
}

wait_for_db() {
    local deadline=$((SECONDS + ${RI_DB_WAIT_SECONDS:-120}))
    until db_probe; do
        if [ "$SECONDS" -ge "$deadline" ]; then
            log "database at ${DB_HOST:-127.0.0.1}:${DB_PORT:-3306} not reachable after ${RI_DB_WAIT_SECONDS:-120}s — giving up"
            exit 1
        fi
        log "waiting for database ${DB_HOST:-127.0.0.1}:${DB_PORT:-3306}..."
        sleep 2
    done
}

# The compose stack keeps generated passwords in a volume instead of asking the
# operator to invent them; DB_PASSWORD_FILE points at the app database's.
if [ -n "${DB_PASSWORD_FILE:-}" ] && [ -z "${DB_PASSWORD:-}" ]; then
    if [ "$ROLE" != init-secrets ]; then
        deadline=$((SECONDS + 60))
        until [ -s "$DB_PASSWORD_FILE" ]; do
            [ "$SECONDS" -lt "$deadline" ] || { log "DB_PASSWORD_FILE=$DB_PASSWORD_FILE is missing or empty"; exit 1; }
            sleep 1
        done
        DB_PASSWORD="$(cat "$DB_PASSWORD_FILE")"
        export DB_PASSWORD
    fi
fi

init_secrets() {
    # Idempotent: an existing file is never touched (the database was initialised
    # with it, so changing it would lock the app out). A value supplied in the
    # compose file (DB_PASSWORD / DB_ROOT_PASSWORD) is used instead of a random one
    # the first time only.
    umask 022
    mkdir -p "$SECRETS_DIR"
    local name var value
    for pair in db_password:DB_PASSWORD db_root_password:DB_ROOT_PASSWORD; do
        name="${pair%%:*}"; var="${pair##*:}"
        if [ -s "$SECRETS_DIR/$name" ]; then
            log "$name already exists — leaving it alone"
            continue
        fi
        value="${!var:-}"
        if [ -z "$value" ]; then
            value="$(php -r 'echo bin2hex(random_bytes(18));')"
            log "generated a random $name"
        else
            log "using the $name given in the compose file"
        fi
        printf '%s' "$value" > "$SECRETS_DIR/$name.tmp" && mv "$SECRETS_DIR/$name.tmp" "$SECRETS_DIR/$name"
    done
}

load_app_key() {
    if [ -n "${APP_KEY:-}" ]; then return; fi
    if [ ! -s "$KEYFILE" ]; then
        if [ "$ROLE" = web ]; then
            log "no APP_KEY provided; generating one and keeping it in the storage volume ($KEYFILE)"
            (umask 077 && php artisan key:generate --show --no-ansi > "$KEYFILE.tmp" && mv "$KEYFILE.tmp" "$KEYFILE")
        else
            log "waiting for the web container to create the application key..."
            local deadline=$((SECONDS + ${RI_DB_WAIT_SECONDS:-120}))
            until [ -s "$KEYFILE" ]; do
                [ "$SECONDS" -lt "$deadline" ] || { log "no APP_KEY and no $KEYFILE — is the web container running with the same storage volume?"; exit 1; }
                sleep 2
            done
        fi
    fi
    APP_KEY="$(cat "$KEYFILE")"
    export APP_KEY
}

# Dump the database before applying migrations that are about to change its
# schema. Skipped on a fresh install (no migrations table yet) and when nothing
# is pending. A failed dump aborts startup rather than migrating unprotected;
# RI_PREMIGRATE_BACKUP=false is the explicit opt-out.
pre_migrate_backup() {
    if [ "${RI_PREMIGRATE_BACKUP:-true}" != true ]; then
        log "pre-migration backup disabled (RI_PREMIGRATE_BACKUP)"
        return 0
    fi
    local status
    # migrate:status fails when there is no migrations table: a brand-new database.
    status="$(php artisan migrate:status --no-ansi --no-interaction 2>/dev/null)" || return 0
    # pending rows end in "Pending"; anchored so a migration *named* ..._pending can't match
    printf '%s\n' "$status" | grep -Eq '[. ]Pending[[:space:]]*$' || return 0

    log "pending migrations found — backing up the database first"
    if ! ri-db-backup premigrate; then
        log "pre-migration backup FAILED, so migrations were NOT run. Fix the backup directory/database access and restart,"
        log "or set RI_PREMIGRATE_BACKUP=false to migrate without a backup."
        exit 1
    fi
}

# ---- roles --------------------------------------------------------------------
case "$ROLE" in
init-secrets)
    init_secrets
    ;;
web)
    load_app_key
    wait_for_db

    pre_migrate_backup

    log "running migrations"
    php artisan migrate --force --no-interaction

    # Essential lookup data (statuses, units, categories, item catalog,
    # permissions) on a never-seeded database; a no-op otherwise. The web setup
    # wizard does the same, so this only matters for RI_ADMIN_EMAIL installs.
    php artisan setup:seed-essentials --no-interaction

    if [ -n "${RI_ADMIN_EMAIL:-}" ]; then
        php artisan admin:bootstrap --no-interaction \
            --email="$RI_ADMIN_EMAIL" \
            ${RI_ADMIN_PASSWORD:+--password="$RI_ADMIN_PASSWORD"} \
            ${RI_ADMIN_FIRST_NAME:+--firstname="$RI_ADMIN_FIRST_NAME"} \
            ${RI_ADMIN_LAST_NAME:+--lastname="$RI_ADMIN_LAST_NAME"} || log "admin:bootstrap did not create an administrator (see above)"
    elif [ "$(php artisan setup:status --no-interaction)" = needs-setup ]; then
        log "first run: open ${APP_URL:-the server address} in a browser to create the administrator (setup wizard)"
    fi

    # MariaDB's slow log table is only readable by the app user after a one-time grant (best effort; see
    # Setup > Testing Tools). Existing installs get it too, since it is not part of the database's first-run init.
    php artisan slowlog:maintain --grant --no-interaction || true

    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    log "starting web server on :${RI_HTTP_PORT:-8080}"
    exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile
    ;;
queue)
    load_app_key
    wait_for_db
    # Recycle hourly so a long-lived worker never serves stale code/config.
    exec php artisan queue:work --tries=1 --sleep=3 --max-time=3600 --no-interaction
    ;;
scheduler)
    load_app_key
    wait_for_db
    exec ri-scheduler
    ;;
reverb)
    load_app_key
    wait_for_db
    exec php artisan reverb:start --no-interaction
    ;;
artisan)
    load_app_key
    shift
    exec php artisan "$@"
    ;;
*)
    exec "$@"
    ;;
esac
