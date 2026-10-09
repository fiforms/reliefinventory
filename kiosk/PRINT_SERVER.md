# Print server plan (kiosk fleet)

See also [IMPLEMENTATION.md](IMPLEMENTATION.md) for the wider kiosk product notes.

Status: **planned, not built** (written 2026-10-08). Depends on the kiosk fleet management
server half, which exists as of the same date but hasn't been run against a real device yet —
see `app/Http/Controllers/DevicePairController.php` / `DeviceHeartbeatController.php`,
`routes/api.php`, `/setup/devices`, and `kiosk/reliefkiosk/docs/DEVICE_CONTRACT.md`.

## Goal

Let people print from the web app without ever connecting a printer to the device they're
using (phones, tablets, laptops where drivers are difficult or unfriendly). The printer is
attached to a reliefkiosk Pi at a physical workspace; the web app keeps a server-side print
queue, and the Pi at that workspace polls it and prints automatically.

Example: a job needs a "Label Printer" at "Workspace A" → the reliefkiosk device at Workspace A
claims it and prints it on its attached label printer.

## Shape

```
web app (queue)  <--poll/claim/report--  Pi print daemon  --CUPS-->  printer
```

The server never pushes (same constraint as the rest of the device contract): the daemon polls.

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

- **Reaching it:** link from the product's settings pages (`kiosk/product/frontend/`) to the CUPS
  UI. The kiosk's Chromium is on the device itself, so `localhost:631` works directly; if we'd
  rather it live under the same origin/URL scheme as the rest of the settings shell, proxy it
  through the platform's nginx (e.g. `/cups/` → `127.0.0.1:631`). If proxying, CUPS checks the
  `Host` header and refuses unexpected names, so it needs a matching `ServerAlias`.
- **Keep it local-only:** leave CUPS listening on localhost (its default) — no LAN-exposed admin
  interface, no remote printer sharing. Admin actions need a CUPS admin user (`lpadmin` group);
  provision one in the image build and gate the settings entry behind the shell's existing PIN
  lock.
- **Our own pieces** (`kiosk/product/backend/` + `frontend/`) are the glue between CUPS and the
  website, not a replacement for the CUPS UI:
  - backend lists the CUPS queues (via `lpstat`/pycups) and their state (idle, offline, out of
    media) and includes them in the heartbeat as `print_printers`;
  - backend prints jobs for the daemon (`lp`/pycups) and reads back job completion/failure;
  - frontend adds a small "Printers" page to the settings shell: the CUPS link, the detected
    queues and their status, and a "print test page" button.
- **Naming/typing happens in the web app:** the Pi only reports what CUPS has; staff assign each
  reported queue a type ("Label Printer") and workspace on the `/setup/devices` side, which
  creates/updates the `printers` rows. So a queue is unusable for jobs until it's been typed.

### Pi image / product

- Install **CUPS** in the image (product-level `image/` packages, not the platform), enabled at
  boot, with printer setup handled either on-device in the settings shell or via the web app's
  device page (open question below).
- A Python daemon in the product's `backend/` (this is the `print_station` kiosk function,
  already defined in `config/devices.php` as headless): poll → claim → fetch → `lp`/CUPS →
  report. Keep working from local retries through network drops; never print the same job twice
  (claim ID is the idempotency key).
- Because the platform is consumed as an unmodified submodule (`kiosk/reliefkiosk`), all of this
  lives in `kiosk/product`, not the submodule.

## Open questions

1. **Printer registration details:** how a reported CUPS queue gets claimed/typed in the web
   app, and what happens to `printers` rows when a queue disappears from the heartbeat (mark
   offline, don't delete). Approach is decided — see "Printer setup on the device".
2. **Workspace model:** is `KioskLocation` enough, or do we need workspaces within a site?
3. **Trust:** the claim/payload endpoints reuse the device token, and payload URLs should be
   unguessable like release artifacts; decide whether jobs ever carry sensitive content.
4. **Failure UX:** retry policy, who gets notified when a printer is offline or out of labels.
5. **Dual role:** can one Pi be both a display kiosk and a print station, or is print_station
   always headless? (The `kiosk_function` is currently a single value per device.)

## Suggested build order

1. Run the fleet pairing/heartbeat end to end on a real Pi first.
2. `printers` + `print_jobs` tables, queue API, and the device-side claim/result endpoints, with
   tests (claim atomicity, wrong-device access, stale-claim expiry).
3. Pi daemon + CUPS in the product image; test against a real printer.
4. "Print to…" integration, starting with pallet labels; then the queue page.
