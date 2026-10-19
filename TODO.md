Issues identified August 16, 2026

1. ~~Implement user management and permissions setup for administrators under the Setup Menu -> User Administration~~ — done 2026-08-18
 - Built `/setup/users` (permission: `manage-users`, Administrator-only by default): create a
   login-capable account (sends Laravel's stock password-reset email so the person sets their
   own password — no separate invite-token system), promote/change roles and per-person
   permission overrides, deactivate/reactivate (new `disabled_at` column on `people`, checked
   in both real login and PIN-unlock login paths), resend the setup email. A
   Warehouse Users/Customers toggle filters the same list by role bucket.
 - Two new roles added: "Sorting and Inventory" (warehouse-only bundle) and "Office"
   (everything `Volunteer`/`Team Leader` already granted, i.e. "everything except
   admin/setup", just under an honestly-named role — renamed from "...Staff" 2026-08-18,
   since "staff" implies paid employment and this app doesn't track that). `Customer` role is
   reused as the login-capable "Customer/Client (Ordering Only)" role rather than adding a
   separate one.
 - `/setup/people` no longer shows permission-override checkboxes at all, and its Roles picker
   is now filtered to Customer/Donor only (`/json/roles?context=people`) — staff roles are
   assigned exclusively from the new page. `Role` gained two visibility flags
   (`visible_in_people_form`/`visible_in_user_admin`) driving both pickers.
 - On `/setup/users`, roles are one-tap, mutually-exclusive presets over a flat permission
   checklist (Grant All/Revoke All also available) rather than a role multi-select + separate
   3-way override table — tapping a role fully replaces the checklist with exactly that role's
   bundle (switching Admin -> Customer clears everything, doesn't union), then individual
   permissions can be hand-adjusted. `RoleController::index` now always eager-loads each role's
   permission keys so the page can preview/apply them. Backend role-default + per-person-override
   semantics are unchanged — this is a UI reinterpretation only.
 - "Volunteer" is no longer a role at all — it's `people.is_volunteer` (a fact about the person,
   independent of their permission role or party role; an administrator or office role-holder
   can still be a volunteer). Editable from both `/setup/people` and `/setup/users`. Feeds the
   not-yet-built volunteer-hours/FEMA-reporting tracking in `PROJECT_ANALYSIS.md` Part 5. The
   `Volunteer` role row still exists in the database (not deleted, for historical/FK safety) but
   is hidden from both pickers (`visible_in_people_form`/`visible_in_user_admin` both false);
   anyone who held it got `is_volunteer` backfilled to true by the migration.
 - **Known gap, deliberate**: the Customer/Client role currently carries no `manage-orders`
   grant — that permission is resource-level (all orders), not row-level, so granting it today
   would let one customer see/edit every other customer's orders. A Customer-role account can
   log in but can't do anything yet, until order-ownership scoping is built (see the note
   below).
 - **Deferred, not built**: a much bigger idea surfaced while scoping this — full self-service
   customer registration with an approval funnel (pending → approved, blocking order access
   until approved), plus "customers see/edit only their own orders." `PROJECT_ANALYSIS.md`
   Part 5 (Facility Network) already designs a general approval-workflow mechanism
   (`facility_assignments`, `approval_status`/`active_status`) that's the right home for this
   rather than a one-off field bolted onto `Person`. Needs its own design/build pass — row-level
   order ownership in `OrderController` especially, since nothing in the permission model
   today expresses "this resource, but only rows you own."
 - Found and fixed a real latent bug while touching `AuthenticatedSessionController`: the
   `MustVerifyEmail` unverified-email check referenced the class with no `use` import — any
   login attempt that actually hit that branch would have fatal-errored instead of showing the
   verification-required message. Never previously exercised by a test.

2. ~~Troubleshoot page breaks on PDF reports~~ — done 2026-08-17
 - Inventory Report PDF (/report/inventory.pdf) breaks pages in the middle of a table.
 - Same issue on Order Entry Offline Order Form (/report/order-form.pdf)
 - Fixed, then reworked further: both reports now render via a WeasyPrint driver (real CSS
   Paged Media — `@page`, `@page :first`, margin-box running header/page-numbers) instead of
   headless Chrome, giving correct per-page margins, a running header absent on page 1, and
   category headings that repeat on continuation pages. (2026-10-19: every other PDF — labels,
   sitrep, help guide — followed, and headless Chrome was removed entirely.) See CLAUDE.md's
   PDF/label generation section for the technical detail.

3. Important PDF Reports such as the inventory should also be available as a CVS (or XLSX) Spreadsheet Download

4. Order Entry
 - ~~There's a bug in the search box: when I type an item number or name, it shows only numbers in the search results.~~ — done 2026-08-18
   - Added a reusable `secondary` prop to `SearchSelect.vue` (any page searching item types, not
     just this one, can now show a primary + secondary field with a wider dropdown) and used it
     for the item # search here. Also fixed the item # field not clearing after adding a line
     (a focus-timing race), the dropdown popping open with the top row pre-selected on refocus,
     and reworked the duplicate-item flow into a proper modal with a Tab focus trap — see git
     history 2026-08-18 for the full set of order-entry UX fixes from this session.

 - ~~There is no place to enter a UNIT in conjunction with a quantity~~ — not a bug: an item type
   is only ever orderable in the single unit set for it elsewhere (Master Item List), so there's
   nothing to pick per line on the order sheet itself. That unit still shows on every order
   line (`OrderController` loads `itemtype.unit`). Item 5 below (expanding/managing that unit
   list) is the real remaining work in this area, not a per-line picker here.

 5. Master Item List
  - Unit (Measured By) should be labeled "Default Ordering Unit."  This list should be configurable per instance (separate table) and should be expanded to include at least the following:
    - Case
    - Bag
    - Pallet
    - Jug
  - Create a user interface to add and manage default ordering units


 6.  Under "Receiving" and "Donation Sorting" there should be an option (perhaps a lightbox) that would allow quickly adding a donor without navigating away from the page. 
  - Also in the dropdown, a number of entries are showing blank lines. It's showing the organization field, but not the name, so individual's names are appearing blank

Issues identified August 17, 2026

7. Provisioning/install script for new instances (scoped for later — see scripts/TESTING.md)
 - Standing up a new instance currently requires manually creating both the app database and a
   separate disposable test database (with the app's DB user granted on both), then pointing
   phpunit.xml at the test one — done by hand for demo/wa26 on 2026-08-17 after discovering the
   test suite had been wiping real data (it fell through to the real DB when no override existed).
 - A future install/provisioning script should do this automatically for any new instance:
   create app DB + paired test DB, grant the app user on both, and set phpunit.xml's DB_DATABASE
   to match — so a fresh instance is protected from day one instead of needing this fix repeated.
 - Also needs to create that instance's systemd units (`<name>-update.service`, `<name>-backup.service`,
   `<name>-backup.timer`, `<name>-queue.service`), following the pattern the wa26 units already show —
   `Environment=APP_DIR=/var/www/reliefinventory-<name>`, `BACKUP_DIR=/var/backups/reliefinventory-<name>`,
   `QUEUE_SERVICE=reliefinventory-<name>-queue` — each pointing at that instance's own directory. Without
   these, only a manual `cd <instance-dir> && sudo bash scripts/update.sh` works for that instance (fixed
   2026-08-18 to correctly default to whichever instance dir it's run from — see git history on
   scripts/update.sh); there's no admin-panel "update now" button or scheduled backup until the units
   exist. The app directory must be named `reliefinventory-<name>` for `update.sh`'s defaults
   (`QUEUE_SERVICE`, `BACKUP_DIR`) to resolve correctly without explicit overrides.

8. ~~Set FEEDBACK_NOTIFY_EMAIL in .env on demo and wa26~~ — done 2026-08-17
   (daniel@pastordaniel.net, hellopastormark@gmail.com on both instances).

9. Deploy step: install the `weasyprint` binary on any server this app runs on
 - Every PDF in the app (see item 2 above) renders via a `weasyprint` driver; headless Chrome is
   no longer used. This needs the `weasyprint` Python package (with its native Pango/Cairo deps)
   provisioned on the box — `apt install weasyprint` or `pip install weasyprint` depending on
   distro. Not part of `scripts/update.sh`; needs doing manually once per server. **Servers set up
   for the old Chrome path (Chrome libraries, `LARAVEL_PDF_NO_SANDBOX=true` in `.env`) can drop
   those — they're harmless but unused — and need `weasyprint` if they don't already have it.**
   After deploying, run `composer install` (browsershot is gone) and print one pallet label to
   confirm.

10. ~~System Admin "Install Update" silently triggered the wrong instance's systemd unit on wa26~~
    — fixed 2026-08-18
 - Incident: clicking "Install Update" on wa26's `/setup/system` panel wrote wa26's own
   "Update requested…" status file, but then told `sudo systemctl start` to fire
   `reliefinventory-update.service` (demo's unit) instead of
   `reliefinventory-wa26-update.service` — because wa26's `.env` never set
   `SYSTEM_UPDATE_UNIT`/`SYSTEM_BACKUP_NOW_UNIT`, so `config('system.update_unit')` silently
   fell back to the literal default value, which happens to be demo's unit name. Demo only
   "worked" by coincidence (its unit name matches the default). `journalctl -u
   reliefinventory-wa26-update.service` showed zero activity ever; `reliefinventory-update.service`
   (demo's) fired at the same instant instead, ran a harmless no-op against demo, and never
   touched wa26's status file — leaving the wa26 panel stuck on "waiting for the updater to
   start" forever, and (worse) permanently 409-refusing any retry since the backend blocks a
   new update whenever `state === 'running'`, with no timeout anywhere.
 - Fixed both instance-specific gap and the underlying design gap:
   - Added the missing `SYSTEM_UPDATE_UNIT=reliefinventory-wa26-update.service` /
     `SYSTEM_BACKUP_NOW_UNIT=reliefinventory-wa26-backup-now.service` to wa26's `.env` (and made
     demo's explicit too, instead of relying on the default matching by coincidence).
   - `SystemController::readUpdateStatus()` now treats a `running` status whose `updated_at` is
     more than 5 minutes stale as `stalled` (computed on read, nothing written back — a real
     update that does start simply supersedes it on the next write). `update()`'s "already
     running" 409 guard naturally stops blocking once a status is stale. The panel
     (`SystemAdmin.vue`) now shows a clear "Update stalled: …" error instead of polling a dead
     status forever.
 - **Any future new instance's `.env` must set these two vars explicitly** — there's no
   provisioning script yet (see item 7) to catch this automatically.

Issues identified October 8, 2026

11. Print queue / R Station print server — web-app half built 2026-10-18 (branch `print-queue`); clients and hardware still open
 - Built: per-site printers and a job queue (`/print-queue`), a shared "Print ▾" menu on every
   print point, the device API, printer setup on `/setup/devices`, optional realtime (Reverb), an
   OpenAPI description of the print extension (`rstation/print-extension.openapi.yaml`) and a client
   spec (`rstation/PRINT_CLIENT_SPEC.md`). See `CLAUDE.md`'s "Print queue" section, `rstation/PRINT_SERVER.md`
   (wire protocol) and `scripts/REVERB.md` (provisioning). The fleet pairing/heartbeat tests now run and
   pass; nothing has been paired with a real device yet, and the Pi product (`rstation/product`) still
   doesn't act on the heartbeat's `kiosk_path`.
 - **Pi print daemon — built 2026-10-18** in `rstation/product/backend/` (`print_station.py`; uses IPP to the
   local CUPS, `cups_client.py` + `ipp.py`), with `image/packages` adding `cups` + `cups-filters`. Tested in
   virtual time against fakes, against a real CUPS with throwaway loopback queues (opt-in:
   `RI_CUPS_INTEGRATION=1`), and end to end against the real web app + Reverb on the disposable test DB
   (job queued -> printed in ~1 s via the wake-up; an unreachable printer fails with CUPS' real message).
   Run the tests with `PRODUCT_ROOT=$PWD/rstation/product PRODUCT=portal rstation/platform/local-app/run-tests.sh`.
 - **Done 2026-10-18: ran the Pi daemon on a real Pi with a real printer.** Paired a Pi, added a printer in
   CUPS (by hand), set it up on `/setup/devices`, and printed a report from the browser — it spooled and
   printed instantly. Not yet exercised: adding the queue through the Settings > Printers GUI. Product `VERSION` / `image/VERSION`
   have not been bumped for this change (the `packages` change needs a full image, not a hotfix).
 - **Open: Pi on-device UX.** A "Printers" page in the settings shell (`rstation/product/frontend/`: the
   detected queues and their state, a test-page button, a link to CUPS' admin UI) and making CUPS' web UI
   reachable and administrable from there (the `lpadmin` account, PIN-gated) — see `PRINT_SERVER.md`,
   *Printer setup on the device*. None of it is built; for now queues must be added over SSH
   (`lpadmin`) or CUPS' UI on `localhost:631`.
 - **Desktop client — built 2026-10-19** in `print-client/` (Rust core + Tauri shell + Vue UI; Windows, macOS, Linux; see
   its README). Core tested against a mock server, real CUPS loopback queues, and end to end against the real app +
   Reverb on Linux. **Still open:** (1) install the Tauri build deps on a dev machine
   (`sudo apt install build-essential pkg-config libwebkit2gtk-4.1-dev libxdo-dev libssl-dev
   libayatana-appindicator3-dev librsvg2-dev cups-client`) and compile/run the shell — it was written against the
   Tauri 2.12 sources but never built, because webkit2gtk wasn't installed; (2) run it on real Windows and macOS
   machines (the Windows backend has only seen sample PowerShell output); (3) an IPP backend for macOS/Linux so a
   CUPS-aborted job isn't reported as printed (`lpstat` can't tell); (4) code signing (Windows cert, Apple
   Developer ID + notarization) and the Tauri updater's signing key; (5) log file, token in the OS keychain.
 - **Open: verify the remaining client-side assumptions on real printers.** The CUPS 2.4 state behaviour is
   now verified on Linux loopback queues (including that an unreachable *network* printer looks "idle" in
   `lpstat` and only shows in `printer-state-message` while a job is attempted — `PRINT_CLIENT_SPEC.md` §2).
   Still unexercised: USB and driver-based label printers (a USB backend fails differently), the
   `print-scaling=none` effect on a real label's page size, the Windows commands, macOS, the `label_address`
   paper size (no document uses it yet — there are no address labels), and the claim keep-alive with a
   genuinely slow printer. Update the spec with whatever turns out wrong.
 - **Open: tell staff when a printer goes offline or errors.** Today it only shows as a dot in the
   Print menu and on `/print-queue`. Needs a design: who is told (by site? by role — e.g.
   `manage-print-queue` holders), through what (email, the existing notifications bell, a site banner),
   how to avoid flapping/spam (debounce; one "recovered" notice), and how to *detect* it — a print
   server that simply stops heartbeating produces no event, and the app has no scheduler, so this
   needs either lazy detection on page loads or a systemd timer like `volunteer-sync`. Related: the
   offline threshold is fleet-wide (`devices.offline_after_minutes`, 15) and print servers heartbeat every
   30–60 s, so a per-function (print_station) threshold of a few minutes would make the Print menu's
   "offline" honest sooner.
 - Related kiosk notes (escape-hatch key via labwc, first-run site-URL step before pairing,
   heartbeat extensions, and a parked "dockerized local copy of the app" idea) are in
   `rstation/IMPLEMENTATION.md`.

12. Docker distribution (x64 + arm64) — built and tested on amd64 2026-10-19 (branch `docker-image`); remaining items below
 - Package the web app + MariaDB as a multi-arch compose stack others can run. Feasible with no app changes;
   the real work is bundling `weasyprint` into the image, a
   first-run entrypoint, volumes, and replacing the systemd timers with a scheduler sidecar.
   Plan and estimate (~2–4 days to working, ~1 more week to distributable) in `scripts/DOCKER_PLAN.md`.
 - **Built:** Dockerfile + `docker/` compose stack (FrankenPHP, MariaDB, queue, scheduler, optional Reverb),
   first-run migrate/seed/admin bootstrap, WeasyPrint bundled (no Chromium/Node — dropped 2026-10-19), manual per-arch CI workflows. See `docker/README.md`.
 - **Open:** run on real arm64 hardware; first CI run + publishing to GHCR; a "managed by Docker" mode for the
   System Administration panel (update/backup buttons are systemd-only); test Reverb in the container;
   print a real label from the container (the amd64 image rebuilt at ~1.7 GB, PDF rendering verified in-container; arm64 not rebuilt).

13. First-run setup wizard — built 2026-10-19 (branch `first-run-wizard`), see CLAUDE.md
 - **Open:** a way to remove example data from inside the app (today a clean instance means a fresh database);
   `demo_data_seeded_at` is recorded so the app could show a "contains demo data" banner; `TextInput` doesn't put
   its `id` on the real `<input>` (labels don't link, app-wide); decide whether Docker should print a setup code in
   the log by default instead of leaving the wizard open to the first visitor.

