#!/bin/bash
# The container stand-in for the systemd timers the bare-metal install uses
# (scripts/volunteer-sync.sh, scripts/update.sh --scheduled):
#   hourly  php artisan volunteers:sync-active-windows and backup:prune (expired on-demand backup zips) in each warehouse
#           (the slow-log table is pruned by the provisioner, the only process allowed to)
#   hourly  every warehouse is asked for its rollback snapshot (backup:scheduled): each warehouse's own schedule (Warehouse Backups page:
#           hourly / daily / weekly / monthly tiers and how many of each to keep) decides whether one is due, so most hours make nothing.
#           Zips go in the warehouse's own folder $RI_BACKUP_DIR/warehouses/<id>/ (default /backups), one warehouse at a time
#
#   RI_BACKUP_ENABLED     true|false (default true): off stops all scheduled snapshots, whatever the warehouses' schedules say
#   RI_BACKUP_DIR         where backups go (default /backups — mount a volume or host dir)
#   RI_BACKUP_HOUR / RI_BACKUP_SCOPE / RI_BACKUP_KEEP_DAYS   only the starting values of a warehouse's schedule (set when its schedule is
#                         first created); after that the warehouse admin owns them. RI_BACKUP_KEEP_DAYS is still the lifetime of a backup made by hand
#   RI_DB_DUMP_ARGS       extra mariadb-dump flags (default --skip-ssl; set e.g. --ssl for a TLS database)
# (the backup itself is docker/db-backup.sh -> php artisan backup:create, shared with the web container's pre-migration backup)
set -uo pipefail

cd /var/www/html
BACKUP_DIR="${RI_BACKUP_DIR:-/backups}"
KEEP_DAYS="${RI_BACKUP_KEEP_DAYS:-14}"
log() { echo "[scheduler] $(date '+%F %T') $*" >&2; }

log "started (volunteer sync hourly; scheduled snapshots ${RI_BACKUP_ENABLED:-true}, hand-made backups kept $KEEP_DAYS days, in $BACKUP_DIR)"
while true; do
    # Per-warehouse work runs once in each warehouse (MULTI_WAREHOUSE.md §9)
    php artisan warehouse:each "volunteers:sync-active-windows --no-interaction" --no-interaction || log "volunteer sync failed"
    php artisan warehouse:each "backup:prune --no-interaction" --no-interaction || log "backup prune failed"

    # Every warehouse is asked whether a snapshot is due (its own schedule decides), one at a time.
    if [ "${RI_BACKUP_ENABLED:-true}" = true ]; then
        ri-db-backup || true
    fi

    # sleep to just after the top of the next hour
    now=$(date +%s)
    sleep $(( 3600 - now % 3600 + 5 ))
done
