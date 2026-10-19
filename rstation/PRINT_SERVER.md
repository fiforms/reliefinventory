# Print server plan (R Station fleet)

See also [IMPLEMENTATION.md](IMPLEMENTATION.md) for the wider kiosk product notes.

Status (2026-10-18, branch `print-queue`):

- **Web app — built:** printers/jobs, the queue and `/print-queue` screen, the "Print ▾" menu on
  every print point, the device API, and optional realtime (Reverb). "Wire protocol (as built)"
  below is what a print-server client implements.
- **Pi daemon — built** (`rstation/product/backend/`, see "Pi image / product" below), tested
  against fakes and a real CUPS with loopback queues, and end to end against the real web app and
  Reverb, and **verified on a real Pi with a real printer on 2026-10-18** (pair, heartbeat, printer
  discovered, enabled in `/setup/devices`, report printed from the browser). Not yet verified on
  hardware: adding printers from the Pi's Settings page, USB/label printers.
- **Desktop client — built 2026-10-19** (`print-client/`, Rust + Tauri; Windows/macOS/Linux): verified end to end against the
  real app and Reverb on Linux; Windows and macOS not yet run on real machines. See `print-client/README.md`.
- **Not built:** the Pi's on-device Printers settings page, and getting the CUPS
  admin UI reachable from the settings shell. Originally planned 2026-10-08; depends on the R
  Station fleet pairing/heartbeat in `app/Http/Controllers/DevicePairController.php` /
  `DeviceHeartbeatController.php`, `routes/api.php`, `/setup/devices`, and
  `rstation/platform/docs/DEVICE_CONTRACT.md`.

**Building a client? Start with [PRINT_CLIENT_SPEC.md](PRINT_CLIENT_SPEC.md)** — it lists every
document a client implementer needs and the client-side behaviour rules.

A print server is any paired device (`kiosk_function = print_station`) — an R Station Pi **or** a
PC/Mac running a software print server; the protocol below is client-agnostic. Both pair with a
normal pairing code and report `print_platform` (`rstation|windows|macos|linux`) in the heartbeat. A paired
device whose Function is still unset becomes `print_station` automatically the first time it reports
print queues (an admin-chosen Function is never overridden).

## Goal

Let people print from the web app without ever connecting a printer to the device they're
using (phones, tablets, laptops where drivers are difficult or unfriendly). The printer is
attached to a R Station Pi at a physical workspace; the web app keeps a server-side print
queue, and the Pi at that workspace polls it and prints automatically.

Example: a job needs a "Label Printer" at "Workspace A" → the R Station device at Workspace A
claims it and prints it on its attached label printer.

## Shape

```
web app (queue)  <--poll/claim/report--  Pi print daemon  --CUPS-->  printer
```

HTTP polling is always authoritative (same constraint as the rest of the device contract). An
optional realtime channel (Laravel Reverb) can *wake* the daemon the instant a job is waiting, but
it is only a hint — the daemon must keep a slow fallback poll, and everything works with realtime
off. See "Realtime wake-ups".

### Web app

- `printers` — one row per physical printer: name, **type** (e.g. "Label Printer", "Document
  Printer"), **workspace** (reuse `KioskLocation` unless a finer-grained "workspace" concept
  turns out to be needed within a site), the `ManagedDevice` it's attached to, active flag.
- `print_jobs` — target type + workspace (or a specific printer), payload reference, copies,
  requested-by (`person_id_user` from `Auth::id()`, never the request body), status
  `queued → claimed → printed | failed` (plus `cancelled`), timestamps, error text, attempt count.
  Status transitions follow the append-only status-log pattern used by `DonationOffer` /
  `FeedbackReport` if per-transition history proves useful.
- **The payload is always a PDF** (decided 2026-10-08). Rendering stays server-side (the app
  already generates pallet-label and report PDFs via `spatie/laravel-pdf`); a job stores or
  references the rendered PDF so the Pi only has to fetch it and hand it to CUPS. A job's page
  size must match the target printer's media (e.g. a label-sized page for a label printer), so
  the renderer needs to know the printer type when producing the PDF. No raw ZPL/EPL for now —
  thermal label printers are driven through their CUPS PDF filters. Revisit only if label
  quality or speed proves inadequate.
- Staff-facing: a "Print to…" picker wherever a print action exists (pallet labels first),
  filtered to active printers of the right type; a queue page showing job state with retry and
  cancel. Gate with its own permission key (add to `PermissionsSeeder`), separate from
  `manage-devices`.

### Device API (extends the contract; namespaced under `/api/devices`, same bearer token)

- Heartbeat gains namespaced extension keys: the request reports the CUPS printers it can see
  (`print_printers`: name, state); the response can carry per-device print settings.
  Unknown keys are ignored by both sides, so this is backward compatible.
- `GET  /api/devices/print-jobs` — queued jobs for printers attached to *this* device.
- `POST /api/devices/print-jobs/{id}/claim` — atomic (row-locked, as in the order-filling
  pick-sheet batch) so a job can't be double-printed; stale claims (device died mid-job) expire
  back to `queued` after a timeout.
- `GET  /api/devices/print-jobs/{id}/payload` — fetch the PDF to print.
- `POST /api/devices/print-jobs/{id}/result` — `printed` or `failed` + error message.

### Printer setup on the device (decided 2026-10-08)

Adding/configuring a printer (drivers, media size, test page) is done through CUPS's own web
interface (`http://localhost:631`) rather than a UI we build. The product shims it into the
device's on-device settings shell, so staff reach it from the same Settings screens as Wi-Fi and
audio, and we don't reimplement driver/PPD selection.

- **Reaching it:** link from the product's settings pages (`rstation/product/frontend/`) to the CUPS
  UI. The kiosk's Chromium is on the device itself, so `localhost:631` works directly; if we'd
  rather it live under the same origin/URL scheme as the rest of the settings shell, proxy it
  through the platform's nginx (e.g. `/cups/` → `127.0.0.1:631`). If proxying, CUPS checks the
  `Host` header and refuses unexpected names, so it needs a matching `ServerAlias`.
- **Keep it local-only:** leave CUPS listening on localhost (its default) — no LAN-exposed admin
  interface, no remote printer sharing. Admin actions need a CUPS admin user (`lpadmin` group);
  provision one in the image build and gate the settings entry behind the shell's existing PIN
  lock.
- **Our own pieces** (`rstation/product/backend/` + `frontend/`) are the glue between CUPS and the
  website, not a replacement for the CUPS UI:
  - backend lists the CUPS queues (via `lpstat`/pycups) and their state (idle, offline, out of
    media) and includes them in the heartbeat as `print_printers`;
  - backend prints jobs for the daemon (`lp`/pycups) and reads back job completion/failure;
  - frontend adds a small "Printers" page to the settings shell (**built** — `frontend/
    PrintersSettings.vue`, `backend/printers_api.py`): the detected queues with status, device and
    queue length (refreshed every 5 s), a "print test page" and a "Remove" button per queue, an
    **Add a printer** panel (below), and an "Open printer setup" button under *Advanced* that
    navigates the kiosk tab to `http://localhost:631/admin` (the remote's Back key returns, same as
    the captive-portal sign-in). Vendor drivers, pause/resume and media size stay in CUPS: those
    need a CUPS admin login, which the backend deliberately doesn't hold.
    **Add a printer** (built 2026-10-18, so the CUPS "Add Printer" form — whose Connection field is
    garbled on Debian's CUPS 2.4.10 — and its login aren't needed for the usual case): "Find printers"
    lists what CUPS discovers on the network and USB, one entry per physical printer with its best
    connection (`backend/printer_setup.py` `curate()` folds CUPS's per-protocol and per-Bonjour-service
    entries together; two identical printers stay two when a serial or Bonjour name tells them apart),
    plus "Add by address" (a host or IP becomes `ipp://host/ipp/print`) for printers discovery can't
    see. Adding picks a driver: **Automatic (IPP Everywhere, driverless)** for printers reached over
    IPP, or **Generic PostScript** / **Generic PCL** (`drv:///sample.drv/generic.ppd` /
    `laserjet.ppd`, from `cups-common` — the image ships no other drivers) for printers that only
    take raw jobs on port 9100, which many older lasers do (a Brother MFC-9970CDW advertised only
    `_pdl-datastream`). Real CUPS refuses driverless on a `socket://` URI ("IPP Everywhere driver
    requires an IPP connection"), so the page defaults those to a generic driver. Like the password,
    it needs admin rights the backend doesn't have, so it uses the same root-helper pattern:
    `printer_setup.py` drops a request in `/run/slide-announcer/printer-setup` and starts
    `slide-announcer-printer-setup.service`; the helper (`image/system/scripts/
    slide-announcer-printer-setup`, root, self-contained) runs `lpinfo -l -v` / `lpadmin`. It is where
    the rules live: queue names are `[A-Za-z0-9][A-Za-z0-9_.-]{0,62}`, URI schemes are limited to
    ipp/ipps/dnssd/socket/lpd/usb (no `file:`/`pipe:`), the driver comes from a fixed list, an existing
    queue is never overwritten, and a failed add leaves no queue behind. No password is required to
    use it (a Settings-wide PIN, if wanted, would gate the whole Settings area, not just this). Tested
    against fakes and against a real CUPS's `lpadmin`/`lpinfo` on a laptop; **adding a printer through the Settings GUI is still untested on a Pi** (the first real printer was added manually) —
    polkit start of the unit, the unit's sandboxing, and generic-driver output on a real printer
    (test page) are unverified.
    The CUPS UI's login is the device's local `slideadmin` account (password set at image build);
    `image/system/rstation-printer-admin.service` adds it to `lpadmin` on every boot.
    `image/system/rstation-cups-setup.service` starts cupsd at boot (stock Debian only socket-activates
    it on a Unix socket, so nothing listens on TCP 631 otherwise) and sets `WebInterface Yes` (stock
    Debian ships `No`, which shows "Web Interface Disabled" after login).
    **The password is set from the same page** — on a first-run or factory-reset device no old
    password is needed; after that the current one is. The rule is enforced by a root helper, not
    the UI: the backend (`admin_password.py`) drops a request file in `/run/slide-announcer/
    admin-password` and starts `slide-announcer-admin-password.service` (the platform's polkit rule
    already lets it start `slide-announcer-*` units); the helper
    (`image/system/scripts/slide-announcer-admin-password`, root-owned, self-contained) checks the
    current password against `/etc/shadow`, throttles wrong guesses (3 s each, 5 in a row = 5 min
    lockout, persisted), and sets it with `chpasswd`. "Is a password set?" is a marker in `/etc/rstation`,
    which lives on the `/data` overlay — so a factory reset clears the marker and restores the
    build-time password together, and a lost password can always be recovered that way. Until a
    password is set the page hides "Open printer setup". Not yet exercised on a real device (polkit
    start, `chpasswd` under the unit's sandboxing, CUPS accepting the new password).
- **Naming/typing happens in the web app:** the Pi only reports what CUPS has; staff assign each
  reported queue a type ("Label Printer") and workspace on the `/setup/devices` side, which
  creates/updates the `printers` rows. So a queue is unusable for jobs until it's been typed.

### Pi image / product

- **CUPS in the image — done:** `rstation/product/image/packages` lists `cups` and `cups-filters`
  (Debian enables CUPS on install); the image upgrades both to a backported CUPS 2.4.20 — see `CUPS_BACKPORT.md`.
  Printer *drivers* are not listed — they depend on the printers
  actually used. Printer setup is through CUPS' own web UI (above); making that reachable from the
  settings shell, and the admin account it needs, is **not built**.
- **The daemon — built** (2026-10-18), in `rstation/product/backend/`: poll → claim → fetch → CUPS
  → follow the job → report, with claim keep-alive, a persistent ledger so a restart never prints a
  job twice or loses a result, and the failure handling in `PRINT_CLIENT_SPEC.md`. It uses IPP to the
  local CUPS (`ipp.py`, `cups_client.py`) rather than `lp`/pycups — see the spec §4. Tested in virtual
  time against fakes (`test_print_station.py`, run with the platform's
  `local-app/run-tests.sh`), and against a real CUPS with throwaway loopback queues
  (`test_cups_integration.py`, opt-in: `RI_CUPS_INTEGRATION=1`). Run on a real Pi with a real
  printer on 2026-10-18 (queue -> print worked); USB and label printers are still unexercised.
- Because the platform is consumed as an unmodified submodule (`rstation/platform`), all of this
  lives in `rstation/product`, not the submodule.

## Wire protocol (as built)

Machine-readable form: [print-extension.openapi.yaml](print-extension.openapi.yaml) (checked against the
server by `tests/Feature/PrintExtensionOpenApiTest.php`); the prose below is normative for behaviour.

All under `/api/devices`, `Authorization: Bearer <device token>` (the same token as the
heartbeat), throttled to 240 requests/minute per IP. Anything about another device's job is a
`404`, never `403`.

**Heartbeat** — `POST /heartbeat`. Print servers additionally send these keys (namespaced `print_*`, per
the contract's extension rules):

```json
{ "print_platform": "windows",
  "print_printers": [ { "queue_name": "Zebra ZD421", "state": "idle|printing|error|offline",
                        "state_message": "Out of labels" } ] }
```

Send the *complete* list every time (an absent `print_printers` key leaves the stored snapshot
alone; `[]` means "no queues"). A new `queue_name` creates an inactive, untyped printer row for
staff to name/site/type on `/setup/devices`; a queue that disappears keeps its row and shows as
unavailable. The contract's 5-minute heartbeat is too slow for printing; recommended cadence and
state mapping are in [PRINT_CLIENT_SPEC.md](PRINT_CLIENT_SPEC.md) §2. A server is shown offline
after `config('devices.offline_after_minutes')` (15) without a heartbeat. The response also
carries `print_realtime` (below).

**Jobs**

| Call | Result |
|---|---|
| `GET /print-jobs` | `{jobs:[{id, queue_name, title, copies, media, width_in, height_in, created_at}]}` — queued jobs on this device's *active* printers, oldest first. Also reclaims expired claims and purges old files. |
| `POST /print-jobs/{id}/claim` | `200 {id, queue_name, copies, claim_expires_at}`, atomic. Re-claiming a job you already hold is idempotent **and extends the claim** (no new attempt, no log entry) — call it periodically while a long job prints. `409` if it's no longer claimable. |
| `GET /print-jobs/{id}/payload` | The PDF (`application/pdf`). `409` unless you hold the claim; `410` if the file was cleaned up. |
| `POST /print-jobs/{id}/result` | `{status: "printed"|"failed", error?}` (error ≤ 2000 chars — make it useful to the person reading the queue). Idempotent for the same result; `409` otherwise. |

Claims expire after `config('printing.claim_expires_minutes')` (5) unless kept alive by re-claiming
(see above); a claim that never reports back returns to the queue (up to `max_attempts`, then it shows as failed). Report `failed` for
anything you *know* went wrong (out of paper, printer offline) — reported failures are never
retried automatically; staff retry or divert from `/print-queue`. The claim id is the
idempotency key: never print a job you didn't claim. Page size: `width_in` x `height_in` is the
media the job was rendered for — print "actual size", no scaling.

How a client should *behave* with these endpoints (poll cadence, error handling, crash recovery,
what to put in `error`) is in [PRINT_CLIENT_SPEC.md](PRINT_CLIENT_SPEC.md) §3 — this section only
defines the wire format.

## Realtime wake-ups (optional, Laravel Reverb)

When the app has realtime on (`scripts/REVERB.md`), the heartbeat response contains
`print_realtime`; otherwise it is `null` and the client just polls.

```json
"print_realtime": { "driver": "reverb", "key": "<public app key>", "host": "ri.example.org",
                    "port": 443, "scheme": "https", "path": "",
                    "channel": "wake.<random 40 chars>", "event": "print-job.available" }
```

Connect a WebSocket speaking the Pusher protocol to
`{wss|ws}://{host}:{port}{path}/app/{key}?protocol=7&client=...&version=...`, and on
`pusher:connection_established` send
`{"event":"pusher:subscribe","data":{"channel":"<channel>"}}`. The channel is **public** (no
signing handshake): its unguessable name is the only secret, unique to this device, rotated on
re-pair and cleared on revoke. When the `print-job.available` event arrives (empty payload), run
the polling loop immediately. It means only "poll now" — claiming and downloading still need the
bearer token. Handle `pusher:ping` with `pusher:pong`, reconnect with backoff, and re-read
`print_realtime` from each heartbeat response (the channel can change). Any Pusher client library
works; so does a few lines of raw WebSocket (verified against a live Reverb with plain Node).

## Open questions

1. ~~**Printer registration details**~~ — *decided and built:* reported queues become inactive,
   untyped `printers` rows; staff name, site, type and switch them on at `/setup/devices`; a
   vanished queue keeps its row and reads as unavailable.
2. ~~**Workspace model**~~ — *decided:* `KioskLocation` is the site; no finer "workspace".
3. ~~**Trust**~~ — *decided:* payloads need the claiming device's bearer token (no public URLs);
   the realtime wake channel carries no data.
4. **Failure UX:** *partly built:* reported failures stay visible (not auto-retried) in
   `/print-queue`, the requester gets a "failed: …" notice when realtime is on, and staff can
   retry/divert. Still open: who is *notified* (email/banner) when a printer goes offline or
   runs out of labels, and a per-function offline threshold shorter than 15 minutes for print
   servers that heartbeat every 30–60 s.
5. **Dual role:** can one Pi be both a display kiosk and a print station, or is print_station
   always headless? (The `kiosk_function` is currently a single value per device.)

## Suggested build order

1. Run the fleet pairing/heartbeat end to end on a real Pi first. *(done 2026-10-18)*
2. ~~`printers` + `print_jobs` tables, queue API, and the device-side claim/result endpoints,
   with tests~~ *(done: claim atomicity, wrong-device access, stale-claim expiry, idempotent
   results)*
3. Print-server clients — the Pi daemon + CUPS in the product image, and a PC/Mac reference
   client — tested against a real printer. *(not done)*
4. ~~"Print to…" integration on every print point, then the queue page~~ *(done)*
5. ~~Realtime wake-ups and live queue updates (optional Reverb)~~ *(server and browser side
   done; the clients' side is part of step 3)*
