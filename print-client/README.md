# RI Print — desktop print server (Windows · macOS · Linux)

Connects the printers of an ordinary PC/Mac/Linux machine to a Relief Inventory server: pair once with a
6-digit code, and staff can then pick that machine's printers from the web app's **Print ▾** menu. It is the
desktop sibling of the R Station Pi print server and speaks the same wire protocol
(`rstation/PRINT_SERVER.md`, `rstation/PRINT_CLIENT_SPEC.md`, `rstation/print-extension.openapi.yaml`).

```
print-client/
  core/        Rust library + `ri-print` CLI: pairing, heartbeat, job loop, ledger, realtime, OS printing
  src-tauri/   Tauri v2 desktop shell: tray icon, window, start-at-login, single instance
  ui/          Vue 3 window (pairing wizard, printers, recent jobs) — talks to the shell via Tauri commands
  e2e/         end-to-end harness against the real Laravel app + real CUPS (see Testing)
```

All behaviour lives in `core/` and is tested there; the shell is deliberately thin. `core` also builds as the
headless **`ri-print`** command, which needs no webview and suits an unattended machine.

## How it prints

No spooler protocol code — thin wrappers over what each OS ships:

| OS | List printers | Print | Follow the job |
|---|---|---|---|
| macOS, Linux | `lpstat -l -p`, `lpstat -a` | `lp -d <queue> -n <copies> -o print-scaling=none` | `lpstat -W not-completed -o` |
| Windows | PowerShell `Get-Printer` | bundled **SumatraPDF** `-print-to … -print-settings "noscale,Nx" -silent` | PowerShell `Get-PrintJob` |

Jobs are printed at **actual size** (the PDF is already the media's exact size). The webview is never used for
printing — it would scale and add margins.

Behaviour implemented from `PRINT_CLIENT_SPEC.md`: heartbeat every ~45 s **and immediately on any printer state
change**; one job at a time per queue, oldest first; claim keep-alive while a job prints; an on-disk **ledger**
(`ledger.json`) so a crash/restart never prints a job twice and never loses a result; a printer that goes
offline mid-job is given up on after 60 s, the OS job is **cancelled** (or it would print later as a duplicate)
and the printer is held "offline" for 2 minutes; `401` wipes the token and returns to pairing; optional realtime
wake-up over a Pusher-protocol WebSocket (falls back to polling every 4 s on its own).

### Known limitations

- **macOS/Linux job outcome is coarse.** `lpstat` can't tell a *completed* job from an *aborted* one (both just
  leave the queue), so a job CUPS aborts (e.g. a broken filter) can be reported as printed unless the printer
  also shows a fault. The fix is an IPP client (`rstation/product/backend/ipp.py` is the reference);
  `PrintBackend` is the seam for it.
- **Windows is untested on real Windows.** The parsers are unit-tested against sample PowerShell output and the
  module compiles everywhere, but nobody has run it on a Windows machine with a printer yet.
- **Not built yet:** auto-update (needs signing-key decisions), code signing/notarization, a log file (stderr
  only), the token in the OS keychain (it lives in `config.json`, mode 0600, in the per-user data dir),
  staff notification when a printer goes offline (server side, `TODO.md` item 11).

## Using it

**Desktop app:** open it, enter the server address and the pairing code from the web app
(*Setup → Devices → Pair a New Device or Print Server*), done. The tray menu's **Relief Inventory Portal** opens the server's `/dashboard` in your default browser. Closing the window leaves printing running in
the tray (on Linux the window always opens at login — stock GNOME has no tray). Name each printer and choose its
paper type on the web app's Devices page before it can receive jobs.

**Headless:**

```bash
ri-print pair --server https://ri.example.org --code 123456 --name "Warehouse PC"
ri-print run          # serve until stopped (Ctrl-C)
ri-print printers     # what this machine would report
ri-print unpair
```

Settings and the ledger live in the per-user data directory (override with `--data-dir`). For an unattended
Linux box, run `ri-print run` from a systemd **user** unit (so it can see the user's CUPS queues).

## Building

Rust ≥ 1.85 and Node ≥ 20. The core and the CLI need nothing else:

```bash
cd print-client
cargo build -p ri-print-core            # builds the `ri-print` CLI too
```

The desktop app additionally needs the platform webview libraries:

```bash
# Debian / Ubuntu (Tauri v2 prerequisites)
sudo apt install build-essential pkg-config libwebkit2gtk-4.1-dev libxdo-dev libssl-dev \
                 libayatana-appindicator3-dev librsvg2-dev cups-client
npm install
npm run tauri dev       # hot-reloading app
npm run tauri build     # installers: .deb + AppImage here, .msi/.exe on Windows, .dmg on macOS
```

- **Windows:** put `SumatraPDF.exe` and its license in `src-tauri/resources/` first
  (see `src-tauri/resources/README.md`; it is GPLv3 and is not committed). WebView2 ships with current Windows.
- **macOS / Windows builds must run on that OS.** `.github/workflows/print-client-{linux,macos,windows}.yml` do it in CI;
  they are manual-only (Actions tab → *Run workflow*), each runs the core tests then builds that OS's unsigned installer
  and uploads it as an artifact.
- Icons come from `src-tauri/icons/source.png` via `npx tauri icon src-tauri/icons/source.png`.

## Testing

```bash
cargo test -p ri-print-core                       # unit tests + 15 engine tests against a mock server
RI_CUPS_INTEGRATION=1 cargo test -p ri-print-core --test cups_integration -- --test-threads=1
e2e/run.sh                                        # real Laravel app + real CUPS, polling
e2e/run.sh --realtime                             # same, with Reverb: also measures wake-up latency
```

- The engine tests (`core/tests/engine_flow.rs`) cover the conformance checklist in `PRINT_CLIENT_SPEC.md` §5:
  pairing and restart, `401` → re-pair, heartbeat contents and change-triggered beats, copies and actual-size
  hand-off, offline/missing/rejected printers, `409` skip without starving the queue, per-queue ordering,
  claim keep-alive on a long job, giving up and cancelling, undeliverable results retried without reprinting,
  and crash recovery (never a duplicate printout).
- `cups_integration` (opt-in: it creates and deletes throwaway loopback CUPS queues, so it needs `lpadmin`)
  checks the Unix backend against real CUPS, including that an unreachable network printer only reads as
  offline while a job is being attempted.
- `e2e/run.sh` pairs the CLI with the **real app** on the disposable `test_reliefinventory` database
  (`e2e/bootstrap.php` refuses to run against any other), prints through a raw CUPS queue to a fake printer
  socket, and checks the bytes arrived. With `--realtime` the claim follows the enqueue by ~0.1 s.
- The desktop shell and the UI have no automated tests; `ui/` can be previewed without Tauri
  (`npm run dev`, then `?mock=setup|paired|problems|revoked`).
