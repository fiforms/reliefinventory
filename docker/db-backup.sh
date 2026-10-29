#!/bin/bash
# Make a backup zip (manifest + checksums, the same format as every other backup in the app) by running
# `php artisan backup:create`. Shared by the scheduler's daily backup and the web container's pre-migration backup.
#
#   ri-db-backup            asks EVERY warehouse for its scheduled snapshot (warehouse:each backup:scheduled): the warehouse's own schedule
#                           decides whether one is due; it is recorded in the app (listed under Warehouse Backups), kept in the warehouse's
#                           own folder $RI_BACKUP_DIR/warehouses/<id>/, and deleted by `backup:prune` according to its tiers
#   ri-db-backup premigrate a label: a whole-instance backup (instance:backup, via the provisioner) in $RI_BACKUP_DIR/instance, or, before the
#                           control schema exists, written to $RI_BACKUP_DIR as relief-backup-<stamp>-<scope>-<label>.zip
#                           with no database record (the schema may not be ready for one yet); `backup:prune`
#                           deletes it after RI_BACKUP_KEEP_DAYS too
#
#   RI_BACKUP_DIR         where backups go (default /backups — mount a volume or host dir)
#   RI_BACKUP_SCOPE       database (default) | full (also the uploaded files)
#   RI_BACKUP_KEEP_DAYS   backups made by hand (and pre-migration ones) are deleted after this many days (default 14); scheduled
#                         snapshots are kept by their tiers instead
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
    # The scheduler's hourly ask: every active warehouse, in turn (the instance-wide lock makes them wait for each other).
    if php artisan warehouse:each "backup:scheduled --no-interaction" --no-interaction; then
        log "scheduled backups done"
        find "$BACKUP_DIR" -name 'reliefinventory-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
        exit 0
    fi
    log "a scheduled backup FAILED"
    exit 1
fi

# The pre-migration backup is the WHOLE instance (control + accounts + every warehouse, one consistent dump), taken by the provisioner
# container, which alone can read every schema. Exit 2 means it can't be asked yet (the control schema isn't migrated: the first
# upgrade to a multi-warehouse version), so the default warehouse is backed up the old way.
php artisan instance:backup --via-provisioner --no-interaction
case $? in
    0) log "instance backup written to $BACKUP_DIR/instance"; exit 0 ;;
    2) log "no control schema yet: backing up the default warehouse only" ;;
    *) log "instance backup FAILED"; exit 1 ;;
esac

args=(--scope="$SCOPE" --dest="$BACKUP_DIR" --name="relief-backup-$(date +%Y%m%d-%H%M%S)-$SCOPE-$LABEL")

# Reached only when the instance backup above could not be asked for (no control schema yet).
if out="$(php artisan backup:create --warehouse=default --no-interaction "${args[@]}")"; then
    log "backup written: $(printf '%s\n' "$out" | tail -n1) ($(du -h "$(printf '%s\n' "$out" | tail -n1)" | cut -f1))"
    # Dumps from before backups were zipped (RI image <= 2026-10-20): age them out the old way.
    find "$BACKUP_DIR" -name 'reliefinventory-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
else
    log "backup FAILED"
    exit 1
fi
