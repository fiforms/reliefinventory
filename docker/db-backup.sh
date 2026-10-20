#!/bin/bash
# Write a gzip'd SQL dump of the app database into $RI_BACKUP_DIR, then prune old ones.
# Shared by the scheduler's daily dump and the web container's pre-migration dump.
#
#   ri-db-backup [label]     label (optional) is added to the file name:
#                            reliefinventory-<label>-<stamp>.sql.gz (default: reliefinventory-<stamp>.sql.gz)
#
#   RI_BACKUP_DIR         where dumps go (default /backups — mount a volume or host dir)
#   RI_BACKUP_KEEP_DAYS   dumps older than this are deleted (default 14)
#   RI_DB_DUMP_ARGS       extra mariadb-dump flags (default --skip-ssl; set e.g. --ssl for a TLS database)
# Exit status 0 only if a complete dump was written.
set -uo pipefail

BACKUP_DIR="${RI_BACKUP_DIR:-/backups}"
KEEP_DAYS="${RI_BACKUP_KEEP_DAYS:-14}"
LABEL="${1:+$1-}"
log() { echo "[db-backup] $(date '+%F %T') $*" >&2; }

stamp="$(date +%Y%m%d-%H%M%S)"
out="$BACKUP_DIR/reliefinventory-$LABEL$stamp.sql.gz"
mkdir -p "$BACKUP_DIR" 2>/dev/null && [ -w "$BACKUP_DIR" ] || { log "cannot write to $BACKUP_DIR — mount a volume there"; exit 1; }
if MYSQL_PWD="${DB_PASSWORD:-}" mariadb-dump \
        --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USERNAME:-reliefinventory}" \
        --single-transaction --no-tablespaces --routines --triggers ${RI_DB_DUMP_ARGS:---skip-ssl} \
        "${DB_DATABASE:-reliefinventory}" 2>"$out.err" | gzip > "$out.tmp" && [ "${PIPESTATUS[0]}" = 0 ]; then
    mv "$out.tmp" "$out"; rm -f "$out.err"
    log "backup written: $out ($(du -h "$out" | cut -f1))"
    find "$BACKUP_DIR" -name 'reliefinventory-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
else
    log "backup FAILED: $(head -c 400 "$out.err" 2>/dev/null)"
    rm -f "$out.tmp"
    exit 1
fi
