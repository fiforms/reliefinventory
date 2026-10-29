Issues identified August 16, 2026

1. ~~User management and permissions~~ — done. `/setup/users` (`manage-users`): login-capable accounts, role presets over a flat permission checklist, per-person overrides, deactivate/reactivate.
 - **Known gap, deliberate:** the Customer/Client (now Partner) role carries no `manage-orders` grant, because that permission is resource-level, not row-level; granting it would
   let one partner see every other partner's orders. A Partner account can log in but can't order until order-ownership scoping is built (item 22).

2. ~~PDF page breaks~~ — done. Every PDF renders through WeasyPrint (`notes/PRINT_QUEUE.md`).

3. Spreadsheet export of reports: inventory, orders and flow already export as CSV (`/report/inventory.csv` etc.); XLSX is not built.

4. ~~Order Entry search and unit fixes~~ — done. 5. ~~Default Ordering Unit list and admin UI~~ — done (`/setup/units`, extra units added by migration).
   6. ~~Quick-add donor in Receiving and Donation Sorting~~ — done (the dropdown shows `search_label`).

Issues identified August 17, 2026

7. Provisioning for new instances: Docker instances are covered by the provisioner (`warehouse:create`, `docker/`). The bare-metal path still has no script; it needs a
   paired disposable test database (`scripts/TESTING.md`) and per-instance systemd units, but bare metal is single-warehouse and not worth elaborate tooling.

8. ~~Set FEEDBACK_NOTIFY_EMAIL on demo and wa26~~ — done.

9. Every server that renders PDFs needs the `weasyprint` binary (Docker images bundle it; bare metal installs it by hand: `apt install weasyprint`). After deploying, run
   `composer install` and print one pallet label to confirm.

10. ~~Stalled "Install Update" on a bare-metal instance~~ — fixed; the bare-metal updater is deprecated (see "Remove the deprecated bare-metal web updater" below).


Issues identified October 8, 2026

11. Print queue / R Station print server — web-app half built 2026-10-18 (branch `print-queue`); clients and hardware still open
 - Built: per-site printers and a job queue (`/print-queue`), a shared "Print ▾" menu on every
   print point, the device API, printer setup on `/setup/devices`, optional realtime (Reverb), an
   OpenAPI description of the print extension (`rstation/print-extension.openapi.yaml`) and a client
   spec (`rstation/PRINT_CLIENT_SPEC.md`). See `notes/PRINT_QUEUE.md`, `rstation/PRINT_SERVER.md`
   (wire protocol) and `scripts/REVERB.md` (provisioning). The fleet pairing/heartbeat tests now run and
   pass; nothing has been paired with a real device yet, and the Pi product (`rstation/product`) still
   doesn't act on the heartbeat's `kiosk_path`.
 - **Pi print daemon — built 2026-10-18** in `rstation/product/backend/` (`print_station.py`; uses IPP to the
   local CUPS, `cups_client.py` + `ipp.py`), with `image/packages` adding `cups` + `cups-filters`. Tested in
   virtual time against fakes, against a real CUPS with throwaway loopback queues (opt-in:
   `RI_CUPS_INTEGRATION=1`), and end to end against the real web app + Reverb on the disposable test DB
   (job queued -> printed in ~1 s via the wake-up; an unreachable printer fails with CUPS' real message).
   Run the tests with `PRODUCT_ROOT=$PWD/rstation/product PRODUCT=rstation rstation/platform/local-app/run-tests.sh`.
 - **Done 2026-10-18: ran the Pi daemon on a real Pi with a real printer.** Paired a Pi, added a printer in
   CUPS (by hand), set it up on `/setup/devices`, and printed a report from the browser — it spooled and
   printed instantly. The Settings > Printers GUI exists (`PrintersSettings.vue`) but adding a queue through it hasn't been exercised on a real Pi. Product `VERSION` / `image/VERSION`
   have not been bumped for this change (the `packages` change needs a full image, not a hotfix).
 - **Pi on-device UX:** built. The Settings > Printers page (`rstation/product/frontend/PrintersSettings.vue`, `backend/printers_api.py`) lists queues, prints a
   test page, adds and removes printers, and links to CUPS' admin UI under "Advanced"; see `PRINT_SERVER.md`, *Printer setup on the device*. Verify it on a real Pi.
 - **Desktop client — built 2026-10-19** in `print-client/` (Rust core + Tauri shell + Vue UI; Windows, macOS, Linux; see
   its README). Core tested against a mock server, real CUPS loopback queues, and end to end against the real app +
   Reverb on Linux. The CI workflows (`print-client-*.yml`) build it, and it has been tested on Linux and
   macOS (`CHANGELOG.md`). **Still open:** (1) the Windows build is untested (its backend has only seen sample PowerShell output); (2) an IPP backend for macOS/Linux so a
   CUPS-aborted job isn't reported as printed (`lpstat` can't tell); (3) code signing (Windows cert, Apple
   Developer ID + notarization) and the Tauri updater's signing key; (4) log file, token in the OS keychain.
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
   server that simply stops heartbeating produces no event, and the Docker scheduler (`docker/scheduler.sh`) only runs backups and
   `volunteers:sync-active-windows`, so this needs lazy detection on page loads or another scheduled task. Related: the
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
   test Reverb in the container;
   print a real label from the container (the amd64 image rebuilt at ~1.7 GB, PDF rendering verified in-container; arm64 not rebuilt).

13. First-run setup wizard — built 2026-10-19 (branch `first-run-wizard`), see `notes/FIRST_RUN.md`
 - **Open:** a way to remove example data from inside the app (today a clean instance means a fresh database);
   `demo_data_seeded_at` is recorded so the app could show a "contains demo data" banner; `TextInput` doesn't put
   its `id` on the real `<input>` (labels don't link, app-wide); decide whether Docker should print a setup code in
   the log by default instead of leaving the wizard open to the first visitor.

14. Backup tiers: one scheduler for Docker and bare metal
 - **Done:** every platform now makes the same zip (`php artisan backup:create`, scope database | full | storage), restores
   through the same pipeline (`backup:restore`, first-run wizard with an optional second files zip), and shows one merged
   Updates & Backups page that hides what the platform can't do. See `scripts/BACKUPS.md`.
 - **Done (branch `scheduled-backups`):** the Docker / R Station path now has per-warehouse hourly/daily/weekly/monthly tiers with a
   schedule form on Warehouse Backups, roll back to a listed snapshot, scheduled whole-instance backups on Instance Admin, and an
   instance-level trust so the RI Launcher can pull them (`scripts/BACKUPS.md`).
 - **Done:** bare metal no longer has a backup system of its own. Its hourly systemd timer runs `update.sh --scheduled`, which only
   delegates to the app's `backup:scheduled` (same schedule table, tiers, form and roll-back as Docker); the old tier logic in
   `update.sh`, `backup-settings.conf` (migrated once by `LegacyBackupSettings`), the `/json/system/backup*` endpoints, the tier download
   route and the systemd branch of the page are gone. Moving `demo` and `wa26`: `scripts/BACKUPS.md` "Moving an existing bare-metal install".
 - **Still open:** the yearly tier is not carried over (there is none; up to 36 monthlies instead). Zips are now written by the web user
   rather than root-owned folders, so anything that compromises the web app can delete them: keep an off-box copy (the RI Launcher).
   A scheduled `--scope=full` is now just a tier's "include uploaded files" box. The `/json/system/*` update and offline-mode routes
   still duplicate `/json/instance/*`. `setup:reset` exists for starting over after a failed forced restore (typed-phrase, terminal-only).

15. Barcode (UPC) lookup — built 2026-10-21 (branch `upc-lookup`), see `notes/UPC_SCANNING.md`
 - **Scanning interface playbook:** `notes/SCANNING_INTERFACE_NOTES.md` records what we learned making Donation Sorting
   scanner-first (audio cues, focus rules, Qty-field hazards, leave-page guards, a checklist). Apply it to Order Filling picking,
   Receiving and Inventory Movement.
 - **Open:** using the same scan resolver on Order Filling / Order Entry; how a "Case" row's quantity should count in orders and
   the inventory report (sorting records single units: the scan prefills `case_qty × size`); who confirmed/where a UPC came from
   isn't recorded (items has no such columns); a Help guide paragraph.
 - **Other free sources, deliberately not wired in yet** (add under `config/upc.php` `sources` + a case in
   `UpcLookupService::parse()`): UPCitemdb (broad general merchandise; free Explorer plan 100 lookups+searches/day, no key, `/prod/trial`
   — read its terms before storing anything from it); BarcodeFinder (advertises 1,000/day free, unverified against its own docs).
   Paid/tiny-free and not worth it now: Go-UPC, Barcode Lookup, Barcode Report.
 - **Bulk dump, if online-per-scan ever isn't enough** (e.g. a site with no Internet): Open Food Facts and Open Products Facts publish
   full exports (JSONL, CSV, Parquet, Mongo dump; ODbL — attribution required). Import a filtered copy into a local table and query
   it ahead of the online chain; the food dataset is large, so filter by country/category. Not built.


16. "Empty pallets" report (idea, 2026-10-21) — Inventory Movement > Locations no longer lists or counts pallets whose status is `empty`
   (they are finished, not stock). A separate report of empty pallets (which, where last seen, when emptied) would let staff find and
   reuse/recycle them.

17. Move the Docker image to PHP 8.6 (revisit ~February 2027; 8.6.0 GA is scheduled for 2026-11-19, noted 2026-10-26)
   - Wait for a few 8.6.x releases and for `dunglas/frankenphp` to publish a `php8.6-trixie` tag; confirm Laravel, our Composer
     packages and the extensions (`gd`, `intl`, `opcache`, `sodium`, …) support it. Not automatic: the image is pinned to `1-php8.4-trixie`.
   - Change together: `PHP_IMAGE` and the hard-coded `PHP_VERSION=8.4` in the flattened runtime `ENV` (Dockerfile), `php-version` in
     `.github/workflows/ci.yml`. `composer.json`'s `^8.4` already allows 8.6.
   - Then: full Pest suite, extension check, PDF render comparison, and re-check the purge list in the Dockerfile `system` stage (Debian-versioned
     names like `perl-modules-5.40` are skipped if absent, but something could be left behind).
   - Payoff to test: whether `frankenphp php-cli` works there. If so, delete the separate `/usr/local/bin/php` (a second PHP engine, ~22 MB,
     ~8 MB compressed) and make `php` a wrapper. Its availability on the current 8.4 image was not confirmed to be absent for good — re-test
     it on the FrankenPHP version in use (1.13.3 at the time of writing).

18. Federation trust system — built 2026-10-26 (branch `federation-trust`), see `notes/FEDERATION.md` and `rstation/FEDERATION_AUTH.md`; follow-ups
   - **Before shipping:** build the image and try it on a real Pi (pair with a code; boot with the clock far behind and check it recovers).
     The Python was run only against a live server on a dev machine, with the clock faked. Also not run: the Tauri window and the Windows/macOS
     builds of the print client. **The legacy paths were removed 2026-10-26** (feedback shared secret, bearer-token devices, in-place upgrade,
     `/api/devices/pair`): every already-paired Pi, print client and feedback remote must be paired again with a code, so do this when each can
     be reached by someone. A Pi paired the old way shows its pairing screen after its first 401.
   - **Endpoints for the permissions that exist but do nothing yet:** `offers.send`/`receive` (needs an origin uuid on `DonationOffer` and a
     remote-donor `Person`), `supplies.request`/`query_available` (an approved Partner `Person` per site, orders left at `New Order`; first extract
     the three duplicated on-hand calculations into one `InventoryAvailability` service), `inventory.query_exact`, `operator.full_read`, remote
     print submission (`printer_shares`, a `peer` on `PrintJob`), and `feedback.receive`.
   - **Self-service pairing by account** (planned): a free account on a central instance (Turnstile + email verification), then the user confirms a
     pairing from their own instance; `auth_method = account_verified`, restricted default grants, approver recorded in `approved_by_label`.
   - Key rotation (a key change on a pinned trust currently fails closed and needs re-approval), trust expiry, pruning used pairing codes and old
     `trust_audit_log` rows, and a per-trust rate limit beyond the route throttles.
   - Ask the slideannouncer maintainers for a `Product.pair` hook and a bearer-provider hook, so the Pi product can drop the `pairing.pair` module-attribute
     swap and the credential file (`rstation/FEDERATION_AUTH.md`, "The Raspberry Pi", lists what it depends on today).
   - The Docker image now names `sodium` explicitly and `composer.json` requires `ext-sodium`; not rebuilt here, so confirm the image builds.

- **Remove the deprecated bare-metal web updater.** Marked deprecated when the Docker host-side updater (`docker/updater/`, `InstanceUpdate`) was added:
  it runs `git pull`, `artisan migrate` and `npm run build` from a web request and can leave a broken system. To delete in one go:
  `SystemController::checkUpdates()` / `update()` / `startUnit()` / `readUpdateStatus()` / `versionInfo()`'s git branch / `requireSystemd()` for update, the
  `/json/system/check-updates` and `/json/system/update` routes, the systemd branch of the Software Update section in `SystemAdmin.vue` (and its polling),
  `scripts/update.sh`'s update path (its `--scheduled` backup mode is a separate thing and stays), `scripts/systemd/reliefinventory-update.service`,
  its line in `reliefinventory-sudoers`, `config/system.php` `update_unit`/`update_status_file`, and the update tests in `SystemAdminTest`.
  Still to do for the Docker updater itself: R Station / RI-Launcher monitors that speak the same two calls (`launcher/core/src/compose.rs`).

- **Verify the Docker self-updater for real.** Built and unit-tested against a fake docker (`docker/updater/test_ri_updater.py`, `InstanceUpdateTest`), but not yet:
  run against a live compose stack (pull, `down`, `up -d`, health wait, the page recovering afterwards), installed with `docker/updater/install.sh` on a clean Linux host, or looked at in a
  browser (the new Docker section and the bare-metal "Deprecated" banner on Updates & Backups). Also: the suite now uses `APP_MAINTENANCE_DRIVER=cache` with the `array` store
  (`phpunit.xml`; the shared `storage/framework/down` file made `pest --parallel` flaky), so the real `file` driver, including `down --secret`, is no longer exercised by any test.

19. Dead code and loose ends found in the 2026-10-29 sweep (verify each with grep before deleting)
   - Delete `DonationController` and its `/json/donations` routes (`routes/web.php`); nothing in `resources/js` calls them and it keeps its own divergent create path.
   - Unreferenced: `app/Http/Middleware/ApiAuthenticate.php`; seeders `ItemSeederCsv`, `ItemTypeSeederCsv`, `LocationSeederCsv` (their CSVs don't exist); `resources/js/Pages/ComingSoon.vue`;
     `public/img/horse-and-buggy-icon.webp` and `splash_screen.webp`; the `.ri_qty_input` class in `resources/css/app.css`; `HasFactory` on the ~21 models with no factory.
   - Model methods with no caller, e.g. `Person::removeRole`, `VolunteerSignIn::getIsStaleOpenAttribute`, `DeviceTrustGrant::isExpired`, `ChunkedUpload::isComplete`, `Gtin::looksLikeBarcode`.
   - `/json` endpoints with no admin page: trucks, containers, container-types, streams. Either build the pages or drop the routes. Also `GET /json/building-safety/kiosk-operators` and
     `POST /json/volunteer-sign-ins/certify` (tested, but no UI calls them).
   - `warehouses.pallets_enabled` is written and never read. Either honour it (admin toggle for warehouses without pallets or a label printer) or drop it.
   - The County field on the profile form is a "Coming Soon" stub (`UpdateProfileInformationForm.vue`).
   - `WarehouseAccounts.php` TODO: an erased warehouse must not remove an instance admin who still runs the instance.

20. Index endpoints need pagination and server-side search (`PalletController::index` has a limit; most others return everything).

21. Receiving / sorting follow-ups
   - Sorting doesn't consume `pallets.quick_sort_candidate` yet (the express lane); only `notes/RECEIVING.md` mentions the flag.
   - Non-donation receiving categories (`equipment`, `supplies`, `other`) are recorded but have no downstream tracking; they need a design (closer to an asset register than the sorting pipeline).
   - Idea: AI-assisted extraction from a photographed shipping label.

22. Permissions and people follow-ups
   - `review-form-submissions` has no menu entry; the only way in is `/setup/forms/{id}/submissions`.
   - The Order Entry menu tile has a NULL `permission_key` (the route requires `manage-orders`). Set it before Partner/Donor logins exist.
   - Rework the Partner/Donor roles and replace hard delete of a `Person` with deactivate/hide (deleting destroys source traceability).
   - Move the donation-offer decision flow to its own page, separate from the offers worklist.
   - Partner/Donor self-login with own-orders-only access (row-level order ownership in `OrderController`); see item 1's known-gap note.

23. Naming nits: `subrecord.packagetypes_id` in `ItemEntry.vue`; the `Transaction`-on-`orderdonations` and `UseModel` names; a migration with a hyphenated filename.

24. Multi-warehouse: not built (design in `notes/MULTI_WAREHOUSE.md`)
   - **Instance console (phase 5):** a browser UI for what the operator commands do (create/suspend/archive/remove warehouses, setup codes, global backups, audit-log page, account lock,
     instance-admin management). Mail settings and Turnstile keys move there, with a step-up password re-authentication (never a PIN). Also support sessions (synthetic Administrator,
     time-boxed, audited), and splitting `SystemAdmin.vue`, `/setup/email` and `/setup/testing` into instance and warehouse parts.
   - **Hold-all upgrades:** per-warehouse `migration_state` and pre-upgrade backup ids, holding every warehouse if one migration fails, the unblock command with its commit-point warning,
     a disk-space check. The pending-migration check still looks at the default warehouse only.
   - **Restore gaps:** the first-run wizard restores only warehouse 1 (instance restore is CLI-only); the whole-instance backup leaves out removed warehouses' backups (`removed-warehouses/`).
   - **Smaller:** emailing a setup code to its holder; self-service email change (password plus an APP_KEY-signed confirmation link); deleting a login from the CLI;
     CLI subcommands for mail and Turnstile settings; a `/w/<id>` landing doesn't check a warehouse's status beyond "active"; `skip_chooser` has no use yet.
   - **Never run outside the test suite:** two warehouses on one Docker stack; a Pi and a print client on a prefixed `/w/<id>` URL; the R Station local server and the RI Launcher with
     the new compose changes; a federation identity surviving the Docker upgrade.
