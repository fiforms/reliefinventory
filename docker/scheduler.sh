#!/bin/bash
# The container stand-in for the systemd timers the bare-metal install uses
# (scripts/volunteer-sync.sh, scripts/update.sh --scheduled):
#   hourly  php artisan volunteers:sync-active-windows, and backup:prune (expired on-demand backup zips)
#   daily   a backup zip (database; RI_BACKUP_SCOPE=full adds the uploaded files) in $RI_BACKUP_DIR (default /backups)
#
#   RI_BACKUP_ENABLED     true|false (default true)
#   RI_BACKUP_HOUR        0-23 local (TZ) hour for the daily dump (default 2)
#   RI_BACKUP_SCOPE       database (default) | full
#   RI_BACKUP_KEEP_DAYS   backups older than this are deleted (default 14; a flat retention — no daily/monthly/yearly tiers)
#   RI_BACKUP_DIR         where backups go (default /backups — mount a volume or host dir)
#   RI_DB_DUMP_ARGS       extra mariadb-dump flags (default --skip-ssl; set e.g. --ssl for a TLS database)
# (the backup itself is docker/db-backup.sh -> php artisan backup:create, shared with the web container's pre-migration backup)
set -uo pipefail

cd /var/www/html
BACKUP_DIR="${RI_BACKUP_DIR:-/backups}"
KEEP_DAYS="${RI_BACKUP_KEEP_DAYS:-14}"
BACKUP_HOUR="${RI_BACKUP_HOUR:-2}"
log() { echo "[scheduler] $(date '+%F %T') $*" >&2; }

# The daily backup is named ...-<scope>-<id>.zip; a pre-migration one ends -premigrate.zip and doesn't count.
backup_done_today() { ls "$BACKUP_DIR"/relief-backup-"$(date +%Y%m%d)"-??????-*-[0-9]*.zip >/dev/null 2>&1; }

log "started (volunteer sync hourly; backup ${RI_BACKUP_ENABLED:-true} daily at hour $BACKUP_HOUR (${RI_BACKUP_SCOPE:-database}), keep $KEEP_DAYS days in $BACKUP_DIR)"
while true; do
    php artisan volunteers:sync-active-windows --no-interaction || log "volunteer sync failed"
    php artisan backup:prune --no-interaction || log "backup prune failed"

    if [ "${RI_BACKUP_ENABLED:-true}" = true ] && [ "$(date +%-H)" -ge "$BACKUP_HOUR" ] && ! backup_done_today; then
        ri-db-backup || true
    fi

    # sleep to just after the top of the next hour
    now=$(date +%s)
    sleep $(( 3600 - now % 3600 + 5 ))
done
