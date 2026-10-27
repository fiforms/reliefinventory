#!/bin/bash
# Make a backup zip (manifest + checksums, the same format as every other backup in the app) by running
# `php artisan backup:create`. Shared by the scheduler's daily backup and the web container's pre-migration backup.
#
#   ri-db-backup            the daily backup: recorded in the app, so it is listed under Updates & Backups
#                           and deleted by `backup:prune` after RI_BACKUP_KEEP_DAYS
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

args=(--scope="$SCOPE")
if [ -n "$LABEL" ]; then
    args+=(--dest="$BACKUP_DIR" --name="relief-backup-$(date +%Y%m%d-%H%M%S)-$SCOPE-$LABEL")
fi

# The default warehouse only, for now: a backup of every warehouse, each in its own folder, is phase 4 of MULTI_WAREHOUSE.md.
if out="$(php artisan backup:create --warehouse=default --no-interaction "${args[@]}")"; then
    log "backup written: $(printf '%s\n' "$out" | tail -n1) ($(du -h "$(printf '%s\n' "$out" | tail -n1)" | cut -f1))"
    # Dumps from before backups were zipped (RI image <= 2026-10-20): age them out the old way.
    find "$BACKUP_DIR" -name 'reliefinventory-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
else
    log "backup FAILED"
    exit 1
fi
