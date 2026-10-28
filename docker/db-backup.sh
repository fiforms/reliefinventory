#!/bin/bash
# Make a backup zip (manifest + checksums, the same format as every other backup in the app) by running
# `php artisan backup:create`. Shared by the scheduler's daily backup and the web container's pre-migration backup.
#
#   ri-db-backup            the daily backup of EVERY warehouse (warehouse:each backup:scheduled): recorded in the app, so it is listed
#                           under Updates & Backups, kept in the warehouse's own folder $RI_BACKUP_DIR/warehouses/<id>/, made once a
#                           day per warehouse, and deleted by `backup:prune` after RI_BACKUP_KEEP_DAYS
#   ri-db-backup premigrate a label: written to $RI_BACKUP_DIR as relief-backup-<stamp>-<scope>-<label>.zip
#                           with no database record (the schema may not be ready for one yet); `backup:prune`
#                           deletes it after RI_BACKUP_KEEP_DAYS too
#
#   RI_BACKUP_DIR         where backups go (default /backups — mount a volume or host dir)
#   RI_BACKUP_SCOPE       database (default) | full (also the uploaded files)
#   RI_BACKUP_KEEP_DAYS   older backups are deleted (default 14) — a flat retention; the tiered
#                         daily/monthly/yearly retention of a systemd install is not implemented here yet
#   RI_DB_DUMP_ARGS       extra mariadb-dump flags (default --skip-ssl; set e.g. --ssl for a TLS database)
# Exit status 0 only if a complete backup was written.
set -uo pipefail

BACKUP_DIR="${RI_BACKUP_DIR:-/backups}"
KEEP_DAYS="${RI_BACKUP_KEEP_DAYS:-14}"
SCOPE="${RI_BACKUP_SCOPE:-database}"
LABEL="${1:-}"
log() { echo "[db-backup] $(date '+%F %T') $*" >&2; }

mkdir -p "$BACKUP_DIR" 2>/dev/null && [ -w "$BACKUP_DIR" ] || { log "cannot write to $BACKUP_DIR — mount a volume there"; exit 1; }
cd /var/www/html || exit 1

if [ -z "$LABEL" ]; then
    # The scheduler's daily backup: every active warehouse, in turn (the instance-wide lock makes them wait for each other).
    if php artisan warehouse:each "backup:scheduled --scope=$SCOPE --no-interaction" --no-interaction; then
        log "daily backups done"
        find "$BACKUP_DIR" -name 'reliefinventory-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
        exit 0
    fi
    log "a daily backup FAILED"
    exit 1
fi

args=(--scope="$SCOPE" --dest="$BACKUP_DIR" --name="relief-backup-$(date +%Y%m%d-%H%M%S)-$SCOPE-$LABEL")

# A labelled one (the web container's pre-migration backup, before the control schema may exist) is the default warehouse only, for now:
# a pre-upgrade backup of every warehouse is the hold-all upgrade slice of phase 4 (MULTI_WAREHOUSE.md §10).
if out="$(php artisan backup:create --warehouse=default --no-interaction "${args[@]}")"; then
    log "backup written: $(printf '%s\n' "$out" | tail -n1) ($(du -h "$(printf '%s\n' "$out" | tail -n1)" | cut -f1))"
    # Dumps from before backups were zipped (RI image <= 2026-10-20): age them out the old way.
    find "$BACKUP_DIR" -name 'reliefinventory-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
else
    log "backup FAILED"
    exit 1
fi
