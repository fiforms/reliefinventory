#!/bin/bash
# Relief Inventory container entrypoint.
#
#   ri-entrypoint init-secrets   one-shot: create the database passwords (first start only)
#   ri-entrypoint web            (default) first-run setup, then serve HTTP on :8080
#   ri-entrypoint provisioner    the only container with the database administrator password: makes the
#                                instance's schemas and each warehouse's schema and database user
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
# The database administrator password lives in its own volume, mounted only by `init-secrets`, `db` and `provisioner`,
# so the web, queue and scheduler containers (which run the application's code) can never read it.
ADMIN_SECRETS_DIR="${RI_ADMIN_SECRETS_DIR:-/admin-secrets}"

log() { echo "[ri-entrypoint] $*" >&2; }

# ---- phase 1 (root): fix up volume ownership, then drop privileges ----------
if [ "$(id -u)" = 0 ] && [ -z "${RI_DROPPED:-}" ]; then
    mkdir -p /tmp/home "$APP"/storage/{app/private,framework/cache,framework/sessions,framework/views,logs} "$APP/bootstrap/cache"
    # Named volumes start root-owned; only touch what isn't already right so a
    # large storage volume doesn't cost a full chown on every start.
    extra=""
    # only the init role writes to (and so owns) the secrets volume; everyone else mounts it read-only
    [ "$ROLE" = init-secrets ] && { mkdir -p "$SECRETS_DIR" "$ADMIN_SECRETS_DIR"; extra="$SECRETS_DIR $ADMIN_SECRETS_DIR"; }
    # warehouse key files: writable (and so chowned) only where the app container mounts them read-write
    { [ "$ROLE" = web ] || [ "$ROLE" = provisioner ]; } && [ -n "${RI_WAREHOUSE_SECRETS_DIR:-}" ] && extra="$extra $RI_WAREHOUSE_SECRETS_DIR"
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

wait_for_instance_schemas() { # the provisioner makes the control and accounts schemas and grants the app user on them
    local control="${RI_CONTROL_DATABASE:-${DB_DATABASE:-reliefinventory}_control}" accounts="${RI_ACCOUNTS_DATABASE:-${DB_DATABASE:-reliefinventory}_accounts}"
    local deadline=$((SECONDS + ${RI_DB_WAIT_SECONDS:-120}))
    until php -r '
        try {
            foreach ([$argv[1], $argv[2]] as $db) {
                new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "127.0.0.1", getenv("DB_PORT") ?: "3306", $db),
                    getenv("DB_USERNAME") ?: "reliefinventory", getenv("DB_PASSWORD") ?: "", [PDO::ATTR_TIMEOUT => 3]);
            }
        } catch (Throwable $e) { exit(1); }' "$control" "$accounts" 2>/dev/null; do
        if [ "$SECONDS" -ge "$deadline" ]; then
            log "the schemas $control and $accounts do not exist (or the app user cannot open them) — is the provisioner container running?"
            exit 1
        fi
        log "waiting for the provisioner to make the instance schemas..."
        sleep 2
    done
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
    # the first time only. The application's password goes in $SECRETS_DIR, the
    # administrator's in $ADMIN_SECRETS_DIR.
    umask 022
    mkdir -p "$SECRETS_DIR" "$ADMIN_SECRETS_DIR"

    # An install from before the administrator password had a volume of its own: move it, so the containers that
    # mount $SECRETS_DIR no longer have it.
    if [ -s "$SECRETS_DIR/db_root_password" ] && [ ! -s "$ADMIN_SECRETS_DIR/db_root_password" ]; then
        cp "$SECRETS_DIR/db_root_password" "$ADMIN_SECRETS_DIR/db_root_password.tmp" && mv "$ADMIN_SECRETS_DIR/db_root_password.tmp" "$ADMIN_SECRETS_DIR/db_root_password"
        log "moved db_root_password to the administrator secrets volume"
    fi
    rm -f "$SECRETS_DIR/db_root_password"

    local name var value dir
    for pair in db_password:DB_PASSWORD:"$SECRETS_DIR" db_root_password:DB_ROOT_PASSWORD:"$ADMIN_SECRETS_DIR"; do
        name="${pair%%:*}"; rest="${pair#*:}"; var="${rest%%:*}"; dir="${rest#*:}"
        if [ -s "$dir/$name" ]; then
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
        printf '%s' "$value" > "$dir/$name.tmp" && mv "$dir/$name.tmp" "$dir/$name"
    done
}

load_app_key() {
    if [ -n "${APP_KEY:-}" ]; then return; fi
    APP_KEY_FROM_FILE=1
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
    status="$(php artisan migrate:status --warehouse=default --no-ansi --no-interaction 2>/dev/null)" || return 0
    # (Only the default warehouse is checked and backed up before migrating, for now; every warehouse is phase 4 of
    # MULTI_WAREHOUSE.md.)
    # pending rows end in "Pending"; anchored so a migration *named* ..._pending can't match
    printf '%s\n' "$status" | grep -Eq '[. ]Pending[[:space:]]*$' || return 0

    log "pending migrations found — backing up the database first"
    if ! ri-db-backup premigrate; then
        log "pre-migration backup FAILED, so migrations were NOT run. Fix the backup directory/database access and restart,"
        log "or set RI_PREMIGRATE_BACKUP=false to migrate without a backup."
        exit 1
    fi
}

# Refuse to start an OLDER image on a database a newer one has migrated: it can't undo those migrations (the
# undo code ships with the newer image) and would run against a schema it doesn't understand. Nothing is changed.
refuse_downgrade() {
    if ! php artisan migrate:check-downgrade --warehouse=default --no-interaction >&2; then
        local last
        last="$(ls -1t "${RI_BACKUP_DIR:-/backups}"/relief-backup-*-premigrate.zip 2>/dev/null | head -n1)"
        [ -n "$last" ] && log "most recent pre-migration backup: $last (restore: docker compose exec app php artisan backup:restore <file> --force, using the matching older image)"
        log "refusing to start; the database was not touched."
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

    refuse_downgrade
    pre_migrate_backup

    # The control and accounts schemas (MULTI_WAREHOUSE.md) sit beside the warehouse schemas. The provisioner, the only
    # container with the database administrator password, makes them (and each warehouse's own database user): wait for it.
    wait_for_instance_schemas

    log "running migrations"
    php artisan instance:migrate --force --no-interaction

    # Once, on an instance moving to per-warehouse keys: the default warehouse keeps the old APP_KEY as its own
    # key, and the instance gets a new APP_KEY (MULTI_WAREHOUSE.md §15). Everyone is logged out once. An APP_KEY
    # set in the compose file can't be changed from here; the command says so.
    if [ -n "${APP_KEY_FROM_FILE:-}" ]; then
        php artisan instance:rotate-app-key --file="$KEYFILE" --no-interaction
        APP_KEY="$(cat "$KEYFILE")"
        export APP_KEY
    else
        php artisan instance:rotate-app-key --no-interaction
    fi

    # Essential lookup data (statuses, units, categories, item catalog,
    # permissions) on a never-seeded database; a no-op otherwise. The web setup
    # wizard does the same, so this only matters for RI_ADMIN_EMAIL installs.
    php artisan setup:seed-essentials --warehouse=default --no-interaction

    if [ -n "${RI_ADMIN_EMAIL:-}" ]; then
        php artisan admin:bootstrap --warehouse=default --no-interaction \
            --email="$RI_ADMIN_EMAIL" \
            ${RI_ADMIN_PASSWORD:+--password="$RI_ADMIN_PASSWORD"} \
            ${RI_ADMIN_FIRST_NAME:+--firstname="$RI_ADMIN_FIRST_NAME"} \
            ${RI_ADMIN_LAST_NAME:+--lastname="$RI_ADMIN_LAST_NAME"} || log "admin:bootstrap did not create an administrator (see above)"
    elif [ "$(php artisan setup:status --warehouse=default --no-interaction)" = needs-setup ]; then
        log "first run: open ${APP_URL:-the server address} in a browser to create the administrator (setup wizard)"
    fi

    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    log "starting web server on :${RI_HTTP_PORT:-8080}"
    exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile
    ;;
provisioner)
    # It encrypts nothing, so it needs no application key: a throwaway one just lets the framework start.
    export APP_KEY="${APP_KEY:-base64:$(php -r 'echo base64_encode(random_bytes(32));')}"
    wait_for_db
    exec php artisan provisioner:work --no-interaction
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
