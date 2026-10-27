#!/bin/bash
# End-to-end check of the launcher's remote-backup client against the REAL Laravel app (disposable test database):
#
#   php (app, test DB) <-> ri-launcher remote ... : pair, back up (real mariadb-dump zip), resume-safe download,
#   checksum, retention, revoke, missing grant.
#
#   launcher/e2e/run.sh
#
# Needs a migrated `test_reliefinventory` database (scripts/TESTING.md), `mariadb-dump`, `unzip`, and a built CLI
# (`cd launcher && cargo build -p ri-launcher-core`). It makes rows in the test DB and removes them on exit;
# bootstrap.php (shared with the print client's e2e) refuses to run against any other database.
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
CLI=$ROOT/launcher/target/debug/ri-launcher
PORT=${E2E_PORT:-8124}
WORK=$(mktemp -d)
URL="http://127.0.0.1:$PORT"

export DB_DATABASE=test_reliefinventory PHP_CLI_SERVER_WORKERS=4 BROADCAST_CONNECTION=log
export RI_BACKUP_DIR="$WORK/server-backups"
# The app starts `php artisan backup:run` as a detached process, which must see the same disposable database. Pin the
# environment in a wrapper rather than trusting inheritance through the web server (a miss would point it at real data).
cat >"$WORK/php-test" <<WRAP
#!/bin/sh
export DB_DATABASE=test_reliefinventory BROADCAST_CONNECTION=log RI_BACKUP_DIR="$RI_BACKUP_DIR"
exec php "\$@"
WRAP
chmod +x "$WORK/php-test"
export RI_PHP_BINARY="$WORK/php-test"

pids=()
cleanup() {
  for p in "${pids[@]}"; do kill "$p" 2>/dev/null; done
  php "$HERE/step_trust.php" cleanup 2>&1 | tail -1
  echo "(files kept in $WORK)"
}
trap cleanup EXIT
fail() { echo "FAIL: $*"; exit 1; }
ok() { echo "ok: $*"; }
r() { "$CLI" --data-dir "$WORK/data" remote "$@"; }

[ -x "$CLI" ] || fail "build the CLI first: cd launcher && cargo build -p ri-launcher-core"
php "$HERE/step_trust.php" cleanup >/dev/null 2>&1 || fail "the test database isn't reachable/migrated (or bootstrap refused it)"

cd "$ROOT"
(cd "$ROOT/public" && exec php -S 127.0.0.1:"$PORT" -t . "$ROOT/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" >"$WORK/server.log" 2>&1) & pids+=($!)
for _ in $(seq 1 40); do curl -fs "$URL/up" >/dev/null && break; sleep 0.3; done
curl -fs "$URL/up" >/dev/null || fail "app did not start (see $WORK/server.log)"

# 1. a pairing code made without the grant: pairs, but says so
CODE=$(php "$HERE/step_code.php" "feedback.send") || fail "could not create a code"
out=$(r pair "$URL" "$CODE" --name "E2E" 2>&1) || fail "pairing without grant: $out"
echo "$out" | grep -q "did not allow backups" || fail "expected a no-grant warning, got: $out"
ID=$(echo "$out" | sed -n 's/.*Its id here is \([0-9a-f]*\)\..*/\1/p'); [ -n "$ID" ] || fail "no id in: $out"
ok "pairing without the backup grant warns"
r backup "$ID" >"$WORK/denied.log" 2>&1 && fail "a backup should be refused without the grant"
grep -qi "doesn't allow this computer to make backups" "$WORK/denied.log" || fail "wrong refusal: $(cat "$WORK/denied.log")"
ok "backup refused without the grant (403)"

# 2. the administrator turns the grant on; the same pairing now works
php "$HERE/step_trust.php" grant
r backup "$ID" >"$WORK/b1.log" 2>&1 || fail "first backup: $(cat "$WORK/b1.log")"
tail -1 "$WORK/b1.log"
F1=$(ls "$WORK"/data/remote-backups/"$ID"/relief-backup-*.zip | head -1); [ -f "$F1" ] || fail "no file saved"
unzip -tq "$F1" >/dev/null || fail "saved file is not a valid zip"
unzip -l "$F1" | grep -q "database/db.sql" || fail "zip has no database dump"
SRV=$(ls "$RI_BACKUP_DIR"/relief-backup-*.zip | head -1)
[ "$(sha256sum < "$F1")" = "$(sha256sum < "$SRV")" ] || fail "local copy differs from the server's file"
[ ! -w "$F1" ] || fail "the copy should be read-only"
ok "backup downloaded, valid, identical to the server's, read-only"

# 3. retention: keep 1, make another, the older copy goes
r set "$ID" --keep 1 --every daily --at 03:15 >/dev/null || fail "set"
sleep 1
r backup "$ID" >"$WORK/b2.log" 2>&1 || fail "second backup: $(cat "$WORK/b2.log")"
[ "$(ls "$WORK"/data/remote-backups/"$ID"/relief-backup-*.zip | wc -l)" = 1 ] || fail "retention kept more than 1 copy"
[ ! -e "$F1" ] || fail "the older copy should have been pruned"
ok "retention keeps the newest copy only"
r list | sed 's/^/    /'

# 4. the server's audit trail names what happened
php "$HERE/step_trust.php" audit | grep -q backup_requested || fail "no backup_requested audit row"
php "$HERE/step_trust.php" audit | grep -q backup_downloaded || fail "no backup_downloaded audit row"
ok "server audited the requests and downloads"

# 5. un-paired on the server: stops, and says what to do
php "$HERE/step_trust.php" revoke >/dev/null
r backup "$ID" >"$WORK/b3.log" 2>&1 && fail "a revoked client must not get a backup"
grep -qi "un-paired" "$WORK/b3.log" || fail "wrong message for revocation: $(cat "$WORK/b3.log")"
r list | grep -q "NOT PAIRED" || fail "list should say it needs pairing again"
ok "revocation detected and reported"

# 6. pairing again with a fresh code restores it, keeping settings and copies
CODE=$(php "$HERE/step_code.php") || fail "code"
r pair "$URL" "$CODE" >"$WORK/repair.log" 2>&1 || fail "re-pair: $(cat "$WORK/repair.log")"
grep -q "Its id here is $ID" "$WORK/repair.log" || fail "re-pairing should keep the same local id"
r list | grep -q "keep 1" || fail "settings were lost on re-pairing"
r backup "$ID" >/dev/null 2>&1 || fail "backup after re-pair"
ok "re-pairing keeps the server entry and works again"
echo "ALL PASSED"
