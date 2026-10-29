#!/bin/bash
# End-to-end check of the Rust print client against the REAL Laravel app, with real CUPS:
#
#   php (app on the disposable test DB) <-> ri-print <-> lp/lpstat <-> a raw CUPS queue -> fake printer socket
#
#   print-client/e2e/run.sh              # polling only
#   print-client/e2e/run.sh --realtime   # also starts Reverb and measures the wake-up latency
#
# Needs: a migrated `test_reliefinventory` database (see dev/TESTING.md), `lpadmin` rights, python3,
# and a built client (`cargo build -p ri-print-core`). It creates one throwaway CUPS queue and some test-DB
# rows, and removes them on exit. bootstrap.php refuses to run against any other database.
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
CLI=$ROOT/print-client/target/debug/ri-print
PORT=${E2E_PORT:-8123}; PRINTER_PORT=${E2E_PRINTER_PORT:-19101}
Q="ri-e2e-$$"
WORK=$(mktemp -d)
RT=0; [ "${1:-}" = "--realtime" ] && RT=1

export DB_DATABASE=test_reliefinventory PHP_CLI_SERVER_WORKERS=4
if [ $RT = 1 ]; then
  export BROADCAST_CONNECTION=reverb REVERB_HOST=127.0.0.1 REVERB_PORT=8080 REVERB_SCHEME=http \
         REVERB_SERVER_HOST=127.0.0.1 REVERB_SERVER_PORT=8080
else
  export BROADCAST_CONNECTION=log
fi

pids=()
cleanup() {
  for p in "${pids[@]}"; do kill "$p" 2>/dev/null; done
  lpadmin -x "$Q" 2>/dev/null
  php "$HERE/step_cleanup.php" 2>&1 | tail -1
  echo "(logs kept in $WORK)"
}
trap cleanup EXIT
fail() { echo "FAIL: $*"; exit 1; }
port_open() { (exec 3<>/dev/tcp/127.0.0.1/"$1") 2>/dev/null; }

[ -x "$CLI" ] || fail "build the client first: cargo build -p ri-print-core"
php "$HERE/step_cleanup.php" >/dev/null 2>&1 || fail "the test database isn't reachable/migrated (or bootstrap refused it)"

cd "$ROOT"
if [ $RT = 1 ]; then
  php artisan reverb:start --host=127.0.0.1 --port=8080 >"$WORK/reverb.log" 2>&1 & pids+=($!)
fi
# What `artisan serve` does, minus its env filtering, so DB_DATABASE above is certain to reach the app.
(cd "$ROOT/public" && exec php -S 127.0.0.1:"$PORT" -t . "$ROOT/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" >"$WORK/server.log" 2>&1) & pids+=($!)
for _ in $(seq 1 40); do curl -fs "http://127.0.0.1:$PORT/up" >/dev/null && { [ $RT = 0 ] || port_open 8080; } && break; sleep 0.3; done
curl -fs "http://127.0.0.1:$PORT/up" >/dev/null || fail "app did not start (see $WORK/server.log)"
[ $RT = 0 ] || port_open 8080 || fail "reverb did not start (see $WORK/reverb.log)"
echo "app up$([ $RT = 1 ] && echo ' + reverb')"

python3 "$HERE/receiver.py" "$PRINTER_PORT" "$WORK/received.bin" & pids+=($!)
lpadmin -p "$Q" -E -v "socket://127.0.0.1:$PRINTER_PORT" -m raw 2>/dev/null || fail "lpadmin (are you in the lpadmin group?)"

CODE=$(php "$HERE/step_pair.php") || fail "could not create a pairing code"
"$CLI" --data-dir "$WORK/data" pair --server "http://127.0.0.1:$PORT" --code "$CODE" --name "E2E box" || fail "pairing"
RUST_LOG=ri_print_core=debug "$CLI" --data-dir "$WORK/data" run >"$WORK/client.log" 2>&1 & pids+=($!)

enqueue() { php "$HERE/step_enqueue.php" "$Q" 2>/dev/null; }
wait_job() { # id -> prints final status
  for _ in $(seq 1 60); do
    st=$(php "$HERE/step_status.php" "$1")
    case "$st" in printed*|failed*) echo "$st"; return;; esac
    sleep 1
  done
  echo "TIMEOUT ($st)"
}

JOBS=()
for _ in $(seq 1 40); do out=$(enqueue) && break; sleep 0.5; done
[ -n "${out:-}" ] || fail "client never reported queue $Q (see $WORK/client.log)"
JOBS+=("$out")
status=0
echo "job ${out%% *}: $(wait_job "${out%% *}")"
[ "$(php "$HERE/step_status.php" "${out%% *}")" = printed ] || status=1

if [ $RT = 1 ]; then
  sleep 2 # the socket is subscribed by now; the client polls only every 45 s from here on
  for _ in 2 3 4; do
    out=$(enqueue); JOBS+=("$out"); echo "job ${out%% *}: $(wait_job "${out%% *}")"
    [ "$(php "$HERE/step_status.php" "${out%% *}")" = printed ] || status=1
  done
  echo "enqueue -> claimed latency (poll interval is 45 s with the socket up, so these come from the wake-up):"
  sed 's/\x1b\[[0-9;]*m//g' "$WORK/client.log" >"$WORK/client.clean"
  python3 - "$WORK/client.clean" "${JOBS[@]}" <<'PY'
import sys, re, datetime
log = open(sys.argv[1]).read().splitlines()
for arg in sys.argv[2:]:
    jid, t0 = arg.split()
    for line in log:
        m = re.match(r"(\S+)Z .*job %s claimed" % jid, line)
        if m:
            t = datetime.datetime.fromisoformat(m.group(1) + "+00:00").timestamp()
            print(f"  job {jid}: {t - float(t0):+.2f}s")
            break
    else:
        print(f"  job {jid}: never claimed")
PY
fi
sleep 1
if [ -s "$WORK/received.bin" ] && head -c 4 "$WORK/received.bin" | grep -q '%PDF'; then
  echo "printer received $(wc -c <"$WORK/received.bin") bytes starting with %PDF"
else
  echo "printer did not receive the PDF"; status=1
fi
[ $status = 0 ] && echo "E2E PASSED" || echo "E2E FAILED (see $WORK/client.log)"
exit $status
