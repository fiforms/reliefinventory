# Print client specification (starting point)

What a **print server client** must do to work with the Relief Inventory print queue — the R Station
Pi daemon and a PC/Mac software print server are the same thing on the wire. This document is the
*client-behaviour* layer. It deliberately does **not** repeat wire formats that are already written
down elsewhere; it points at them. If you extract this spec from the repo, take the documents in
the next section with it.

Status: draft, 2026-10-19. The Pi client exists and printed on a real printer end to end on 2026-10-18. A desktop client (`print-client/`, Rust + Tauri) was built 2026-10-19 and verified against the real web app and real CUPS on Linux; USB/label printers, macOS and Windows remain untested on hardware.

## Documents this spec depends on

Take these with it. Where this spec and one of them disagree, the referenced document wins for wire
details (fix both).

| # | Document | What you need from it |
|---|---|---|
| 1 | `rstation/platform/docs/DEVICE_CONTRACT.md` | The **core device contract**: *Conventions* (JSON, HTTPS, 10 s timeout, ignore unknown fields), *Identity and authentication* (`device_uuid`, bearer token), *`GET /up`*, *Pairing* (`POST {base}/pair`, error codes, re-pairing), *Heartbeat* (request, response, failure behaviour), *Revocation* (a `401` means "you were un-paired"), *Extensions* (how print keys ride on the heartbeat). |
| 2 | `rstation/platform/docs/device-contract.openapi.yaml` | Machine-readable form of #1 (core only). |
| 2b | `rstation/print-extension.openapi.yaml` | Machine-readable form of the **print extension** (#3): the heartbeat's print keys and the four job endpoints, with status codes and limits. Written to merge with #2. Kept honest by `tests/Feature/PrintExtensionOpenApiTest.php`. |
| 3 | `rstation/PRINT_SERVER.md` — sections **"Wire protocol (as built)"** and **"Realtime wake-ups"** | The print extension itself: the heartbeat's `print_platform` / `print_printers` keys, the four job endpoints (`GET /print-jobs`, `claim`, `payload`, `result`) with status codes, claim expiry and retry rules, and how to listen for the optional wake-up. Authoritative for those. |
| 4 | Server source for exact limits | `app/Http/Controllers/DeviceHeartbeatController.php` (validation of `print_platform` / `print_printers`: ≤100 queues, `queue_name` ≤255, `state` ∈ `idle\|printing\|error\|offline`, `state_message` ≤500), `app/Http/Controllers/DevicePrintJobController.php` (request/response shapes), `app/Services/PrintQueueService.php` (claim, expiry, idempotency), `config/printing.php` (media sizes, `claim_expires_minutes`, `max_attempts`, `retain_days`). |
| 5 | `tests/Feature/PrintQueueDeviceApiTest.php`, `tests/Feature/PrintRealtimeTest.php`, `tests/Feature/PrintExtensionOpenApiTest.php` | Executable examples of every server behaviour in #3 — the closest thing to a conformance suite for the server side. |
| 6 | `scripts/REVERB.md` | **Server operators only.** Read it to understand when `print_realtime` is `null` (realtime off). A client never needs to configure Reverb. |
| 7 | `rstation/IMPLEMENTATION.md`, `rstation/platform/docs/PRODUCTS.md` | **Pi product only:** the first-run wizard that sets `server_url`, and the product seams (`heartbeat_payload()`, `on_heartbeat_response()`) the daemon plugs into. |

`{base}` in the contract is `{server_url}/api/devices` for this app.

## 1. Setup and identity

- **Configuration the client needs:** `server_url` (no trailing slash), and a place to persist the
  `device_uuid` and its **Ed25519 private key** (`FEDERATION_AUTH.md`). The Pi gets `server_url` from its
  first-run wizard (#7); a PC/Mac client needs an equivalent — a config file or a one-time setup command.
  Store the key readable only by the service account; never log it. There is no bearer token to store: the
  server pairs by the client's *public* key and the client signs its requests.
- **`device_uuid`:** generate a UUID once on first run and keep it forever (it is how the server
  recognises the same machine across re-pairing).
- **Pairing:** an administrator generates a 10-character code on `/setup/devices` ("Pair a New Device or
  Print Server") or in the Trust Console; the client sends it with its public key and a proof of key
  possession (`FEDERATION_AUTH.md`, *Pairing*; this replaces the 6-digit bearer flow in contract #1).
  Surface `422` ("invalid or expired code") and `429` to whoever is setting it up. Re-pairing the same
  `device_uuid` re-attaches the record; a changed key needs a fresh code.
- **Authenticating:** sign one request for a session token, then send it as `Authorization: Bearer`;
  re-sign when it expires or is refused. A `400` carrying `X-RI-Server-Time` is a clock problem — adjust and
  repeat; never treat it as a revocation.
- **No Function to set by hand.** The first heartbeat that reports a non-empty `print_printers`
  list makes the server mark the device a print station (if an administrator hasn't already chosen
  a different Function), and that same response carries `print_realtime`. So report your queues in
  the very first heartbeat after pairing. An *empty* list doesn't count — a machine with no printers
  yet stays unset until it has some.
- **On a `401`** from a call made with a *fresh* session: the device was revoked (or its key is unknown).
  Forget the pairing (keep the key and `device_uuid`), stop printing, and return to the pairing flow. A
  `401` on a cached session means it expired — sign for a new one first. Do not retry a real revocation.

## 2. Heartbeat and printer discovery

- Send the heartbeat per the contract plus the print keys in #3. Include `print_platform`
  (`windows|macos|linux`; the Pi uses `rstation`).
- **`queue_name` is the printer's identity.** The server keys a printer on `(device, queue_name)`,
  and staff assign its name, site and paper type to that key. Use the OS's **stable queue/printer
  name** (CUPS queue name; Windows printer name). If the user renames a queue it appears as a new,
  untyped printer and the old one goes "unavailable" — so don't derive `queue_name` from anything
  that drifts (model strings, driver names, "Printer (Copy 2)").
- **Report every queue the OS exposes**, including ones staff may not use; typing and enabling is
  done on the server. Send the *complete* list each time.
- **Cadence:** every 30–60 s, plus **immediately when any queue's state changes** (so a "paper out"
  reaches the Print menu quickly). The contract's 5-minute default is too slow for printing; the
  server accepts extra beats (#3). A server with no beat for `devices.offline_after_minutes`
  (15) is shown offline — which affects the Print menu, not job delivery.
- **State mapping** (starting point — confirm against real hardware):

  | OS signal | Report as | `state_message` |
  |---|---|---|
  | CUPS `printer-state` 3 (idle) | `idle` | — |
  | CUPS `printer-state` 4 (processing) | `printing` | — |
  | CUPS 5 (stopped) with reason `media-empty`, `media-jam`, `marker-supply-empty`, `toner-empty`, `cover-open`… | `error` | a plain-language version of the reason ("Out of labels") |
  | CUPS 5 with reason `paused` (queue disabled, `cupsdisable`) | `offline` | "Printer paused" |
  | CUPS `printer-is-accepting-jobs` false (`cupsreject`) | `offline` | "Printer is not accepting jobs" |
  | CUPS 4 (processing) while the printer message says it can't be reached (see below) | `offline` | CUPS' own message, e.g. "Printer not reachable: …" |
  | CUPS 5 with reason `offline-report` | `offline` | "Printer offline" |
  | Windows `Get-Printer`: `PrinterStatus` Normal | `idle` | — |
  | Windows `WorkOffline` true / status Offline | `offline` | "Printer offline" |
  | Windows PaperOut / PaperJam / TonerLow-not-empty… | `error` | what the status says |

  `state_message` is shown to staff next to the printer — write it for a warehouse volunteer.

  **What real CUPS 2.4 does** (checked against throwaway loopback queues on Linux; the Pi
  implementation is `rstation/product/backend/cups_client.py`, and its opt-in
  `test_cups_integration.py` reproduces all of this):

  * `paused` and "not accepting jobs" appear exactly as in the table.
  * **An unreachable *network* printer does not look stopped or offline.** For a socket queue whose
    host refuses the connection, or can't be resolved, CUPS keeps the queue in `processing`, leaves the
    reasons at `none` (or `connecting-to-device`), and never gives up: the job sits in `job-printing`
    forever and `lpstat` still says "idle, Alerts: none". The *only* signal is the free-text
    `printer-state-message` — `The printer may not exist or is unavailable at this time.` for a refused
    connection, `Unable to locate printer "<host>".` for an unresolvable host — and it is only visible
    **while a job is being attempted**; an idle dead printer reads as healthy until something is sent
    to it. So a client must (a) treat those messages, while processing, as `offline`, (b) give up on a
    job whose printer looks offline/errored for more than a short grace period or that makes no progress
    for several minutes — **cancel the CUPS job** (or it prints later when the printer returns, a
    duplicate) and report `failed` with that message — and (c) hold the printer as offline for a couple
    of minutes after such a failure instead of failing the next job the same way. The Pi client does all three.
  * Job states are exact: `completed` (9, reason `job-completed-successfully`), `canceled` (7,
    `job-canceled-by-user`), `aborted` (8), and progress counters (`job-impressions-completed`) are
    reported even for a raw queue. A job CUPS has purged is "not found" — report it failed with advice,
    don't guess it printed.

## 3. Job loop

The endpoints, shapes and status codes are in #3; this section is how a client should *behave*.

1. **Wake or poll.** Poll `GET /print-jobs` every 3–5 s, or every 30–60 s while the realtime
   socket (#3, *Realtime wake-ups*) is connected; run a poll immediately on a wake-up. Realtime is
   optional — if `print_realtime` is `null` or the socket drops, fall back to the fast poll
   automatically, and re-read `print_realtime` from every heartbeat response.
2. **One job at a time per queue**, oldest first. Claim only a job you are about to print now —
   claiming and then queueing locally is how claims expire under you.
   **Keep long jobs alive:** a claim lasts `claim_expires_minutes` (5). While a job is still
   printing, re-`POST …/claim` at least every half of that (every ~2 minutes) — it extends the
   claim without counting as a new attempt. Without it, a job that takes longer than the window
   (a large label batch on a slow printer) is reclaimed and printed again. Use the
   `claim_expires_at` in each response to schedule the next keep-alive, and stop when the job
   is done or a keep-alive returns `404`/`409`.
3. **Claim → download → print → report.** Claim with `POST …/claim`; fetch the PDF with
   `GET …/payload`; print `copies` copies; report `printed` or `failed` with `POST …/result`.
4. **Print "actual size":** no scaling, no fit-to-page, no margins added. The PDF is already
   exactly `width_in` × `height_in` for the media the job was rendered for; the printer's configured
   paper must match (the server only routes jobs to printers typed with that media).
5. **Report what you *know*.** `failed` for anything that went wrong (printer offline, out of
   paper, OS rejected the job), with an `error` staff can act on — what and why, not a stack trace
   ("Printer 'Zebra ZD421' is out of labels", not "lp: exit 1"). Reported failures are never
   retried automatically; a human retries or diverts from `/print-queue`.
6. **Error handling by response:**

   | Response | Meaning | Client action |
   |---|---|---|
   | `401` | revoked (fresh session) | forget the pairing, keep the key, return to pairing (§1) |
   | `404` on a job | not yours / gone | drop it; never retry |
   | `409` on `claim` | someone/something else has it, or it was cancelled | skip it |
   | `409` on `payload` | you don't hold the claim (it expired or was cancelled) | stop; don't print |
   | `410` on `payload` | the file was cleaned up | report `failed` ("file no longer available") |
   | `409` on `result` | claim already settled (expired, cancelled by staff, requeued) | log it; **do not retry** |
   | `429` | rate limited (240/min/IP) | back off; slow the poll |
   | `5xx` / network error | transient | retry with backoff (e.g. 2 s → 60 s cap, with jitter); keep any printed-but-unreported results (below) |

7. **Never print a job twice, and never lose a result.** Keep a small local ledger (job id → state:
   `claimed`, `printed-unreported`, `reported`) on disk. After a crash or restart: report any
   `printed-unreported` jobs first (a `409` is fine — drop it); do not re-print a job whose ledger
   entry says it already printed. A claim that was never reported will expire server-side (5 min
   by default) and the job may be offered again — the ledger is what stops a second printout.
8. **Cancellation is not pushed.** Staff can cancel a job you hold; you only find out when your
   `result` returns `409`. Printing a cancelled job once is expected and harmless.

## 4. Per-platform starting points

*The CUPS behaviour on Linux is verified (above); the Windows commands, USB and real label printers
are not — treat those as suggestions to confirm on real hardware.*

- **Raspberry Pi (R Station) — built:** `rstation/product/backend/` (`print_station.py` is the
  entry point; read its module docstring). It talks to CUPS over **IPP** on `localhost:631` using
  `httpx`, which the platform already ships — `cups_client.py` + `ipp.py`, no `pycups` (not in the
  platform's requirements) and no parsing of `lpstat` text (which can't tell a completed job from an
  aborted one). It runs as a `Product.background_tasks` loop, sends its own faster heartbeats beside
  the platform's 5-minute one (merging the same print keys into that one too), and keeps its
  crash-recovery ledger under `/data/print-station`. Printer setup (drivers, media) is done in the
  CUPS web UI (`PRINT_SERVER.md`, *Printer setup on the device*).
- **Desktop app for Windows / macOS / Linux — built 2026-10-19:** `print-client/` (see its README). A Rust core
  (`print-client/core`, also the headless `ri-print` command) implements this whole spec — pairing, the heartbeat
  and state mapping, the claim/keep-alive/ledger job loop, the optional Pusher wake-up — behind a small
  `PrintBackend` trait; a thin Tauri shell adds the tray icon, window and start-at-login. macOS/Linux shell out to
  `lp`/`lpstat` (so the §2 caveat applies: a job CUPS *aborts* looks like a completed one unless the printer
  also shows a fault — an IPP backend would close that); Windows uses PowerShell + a bundled SumatraPDF. Tested
  against a mock server (the §5 checklist), real CUPS loopback queues, and end to end against the real Laravel
  app including Reverb (claim ~0.1 s after enqueue). **Windows and macOS are untested on real machines.**
- **macOS / Linux software server (alternative, not what the desktop app does):** CUPS runs there too, so `cups_client.py` and `ipp.py` are
  reusable as they are (they depend only on `httpx`); the rest of `print_station.py` only needs the
  pairing/token plumbing swapped. Shelling out to `lp`/`lpstat` works for a first cut but loses the
  job-state precision above. Run as a launchd job / systemd service.
- **Windows software server:** list printers with PowerShell `Get-Printer` (name, `PrinterStatus`,
  `WorkOffline`); print PDFs with a command-line PDF printer such as SumatraPDF
  (`-print-to "<name>" -print-settings "noscale,<n>x" -silent`). Run as a Windows service or
  scheduled task under an account that can see the printers.
- **Any platform:** a websocket client is optional. Raw WebSocket + a few lines of Pusher protocol
  is enough (#3); a Pusher client library also works.

## 5. Conformance checklist

A client is ready when it can demonstrate each of these (the server-side tests in #5 show the
expected responses):

- [ ] Generates a key once, pairs with a code (proving the key), survives a restart, and re-pairs cleanly.
- [ ] Reproduces the shared signing vectors, earns a session, and replaces an expired one without un-pairing.
- [ ] A `401` on a fresh session forgets the pairing (not the key) and returns to pairing; a clock-skew `400` does not.
- [ ] Heartbeats include `print_platform` and the complete `print_printers` list, within 60 s and on any
      state change; a queue renamed in the OS shows as new/untyped on the server.
- [ ] A new job is printed within one poll interval — and, with realtime on, within about a second.
- [ ] Prints at the exact page size with the right copy count.
- [ ] Reports `printed`, and reports `failed` with a human-readable error for an offline printer, a
      missing queue, and a rejected job.
- [ ] Handles `404`/`409`/`410`/`429`/`5xx` as in §3.6.
- [ ] Kill the client mid-job and restart: no duplicate printout, and the result is reported.
- [ ] A job that prints for longer than the claim window (test with a delay) is **not** reprinted:
      the client keeps its claim alive.
- [ ] A first heartbeat after pairing (no Function chosen) already receives `print_realtime` when
      the server has realtime on.
- [ ] Loses the network for a few minutes mid-job: reports the result when it returns (or drops a
      `409`).
- [ ] With realtime off (`print_realtime: null`) or the socket blocked, still prints on polling alone.
- [ ] Two print servers at one site never see or claim each other's jobs.

## Known gaps

Tracked in `TODO.md` item 11 — repeated here only so a client implementer sees them:

- **Staff aren't notified** when a printer goes offline or errors; they see it only in the Print
  menu and on `/print-queue`.
- **Partly verified:** the CUPS 2.4 behaviour in §2 was checked on Linux against loopback queues.
  Still untried: USB and driver-based label printers (a USB backend fails differently from the
  network socket backend), the `print-scaling=none` effect on a real label's page size, the Windows
  state/print commands, and macOS. Correct this document as you learn.
