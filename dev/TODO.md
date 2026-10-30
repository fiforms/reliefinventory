# TODO

Open work only, briefly. Finished work goes in [../CHANGELOG.md](../CHANGELOG.md); how things work goes in [notes/](notes/); unbuilt
ideas go in [ROADMAP.md](ROADMAP.md). Don't record dates or build history here. Verify an item against the code before starting it.

## Cleanup

- **Dead code** (grep each before deleting):
  - Delete `DonationController` and its `/json/donations` routes; nothing in `resources/js` calls them and it keeps its own divergent create path.
  - Unreferenced: `app/Http/Middleware/ApiAuthenticate.php`; seeders `ItemSeederCsv`, `ItemTypeSeederCsv`, `LocationSeederCsv` (their CSVs don't exist);
    `resources/js/Pages/ComingSoon.vue`; `public/img/horse-and-buggy-icon.webp` and `splash_screen.webp`; the `.ri_qty_input` class in `resources/css/app.css`;
    `HasFactory` on the ~21 models with no factory.
  - Model methods with no caller, e.g. `Person::removeRole`, `VolunteerSignIn::getIsStaleOpenAttribute`, `DeviceTrustGrant::isExpired`, `ChunkedUpload::isComplete`, `Gtin::looksLikeBarcode`.
  - `/json` endpoints with no admin page: trucks, containers, container-types, streams (build the pages or drop the routes); `GET /json/building-safety/kiosk-operators`
    and `POST /json/volunteer-sign-ins/certify` (tested, but no UI calls them).
  - `warehouses.pallets_enabled` is written and never read: honour it (toggle for warehouses without pallets or a label printer) or drop it.
  - The County field on the profile form is a "Coming Soon" stub (`UpdateProfileInformationForm.vue`).
  - `WarehouseAccounts.php`: an erased warehouse must not remove an instance admin who still runs the instance.
- **Remove the deprecated bare-metal web updater** (the in-app "Install Update" button). A bare-metal administrator updates by hand (`dev/BUILDING.md`, "Updating").
  Delete: `SystemController::checkUpdates()` / `update()` / `startUnit()` / `readUpdateStatus()` / `versionInfo()`'s git branch / `requireSystemd()` for update, the
  `/json/system/check-updates` and `/json/system/update` routes, the systemd branch of the Software Update section in `SystemAdmin.vue` (and its polling),
  `scripts/systemd/reliefinventory-update.service`, its line in `reliefinventory-sudoers`, `config/system.php` `update_unit`/`update_status_file`, and the update tests in
  `SystemAdminTest`. **`scripts/update.sh` stays for now**, documented as deprecated (run from a shell only; its `--scheduled` backup mode is
  not deprecated). The `/json/system/*` update and offline-mode routes also duplicate `/json/instance/*`.
- **Delete `/setup/design-votes`** and its supporting features and data structures (controllers, routes, pages, models, tables via a new migration, permissions, menu
  entries, tests); this moved to a different app.
- **Remove the Setup menu button linking to `/setup/firmware`**: it is already reached from `/setup/devices`.
- Naming nits: `subrecord.packagetypes_id` in `ItemEntry.vue`; the `Transaction`-on-`orderdonations` and `UseModel` names; a migration with a hyphenated filename.
- Index endpoints need pagination and server-side search (`PalletController::index` has a limit; most others return everything).

## Docker and updates

- Run on real arm64 hardware; first CI run and publish to GHCR; print a real label from the container (arm64 image not rebuilt since the last change).
- A "managed by Docker" mode for the test Reverb in the container.
- **Verify the Docker self-updater for real:** against a live compose stack (pull, `down`, `up -d`, health wait, the page recovering), installed with
  `docker/updater/install.sh` on a clean Linux host, and in a browser. The suite uses `APP_MAINTENANCE_DRIVER=cache` (`phpunit.xml`), so the real `file`
  driver, including `down --secret`, is exercised by no test. R Station / RI Launcher monitors should speak the same two update calls (`launcher/core/src/compose.rs`).
- **R Station OS updates from a server:** the release manifest (`rstation-image.json`, `dev/RELEASE.md`) now carries the signed `.raucb` bundle (`rauc`: filename,
  size, sha256, urls). Let an instance either keep a copy of the bundle and serve it to its R Stations, or hand them the CDN URL, so devices on a site update without
  reaching the CDN themselves (`rstation/platform/docs/DEVICE_CONTRACT.md`, release types and artifact locations).
- **Move the image to PHP 8.6** once 8.6.x has had a few releases and `dunglas/frankenphp` publishes a `php8.6-trixie` tag (revisit early 2027). Change together:
  `PHP_IMAGE` and the hard-coded `PHP_VERSION=8.4` in the Dockerfile's runtime `ENV`, and `php-version` in `.github/workflows/ci.yml`. Then run the full Pest suite,
  an extension check (`gd`, `intl`, `opcache`, `sodium`), a PDF render comparison, and re-check the Dockerfile `system` stage purge list. Payoff to test:
  whether `frankenphp php-cli` works, which would let us delete the separate `/usr/local/bin/php`.
- Confirm the Docker image builds with `sodium` named explicitly and `ext-sodium` in `composer.json`.
- Bare-metal servers that render PDFs need the `weasyprint` binary (`apt install weasyprint`); after deploying, print one pallet label to confirm.

## Setup and backups

- First-run wizard: a way to remove example data from inside the app (today a clean instance means a fresh database; `demo_data_seeded_at` is recorded so a
  "contains demo data" banner is possible); `TextInput` doesn't put its `id` on the real `<input>` (labels don't link, app-wide); decide whether Docker should print a
  setup code in the log by default instead of leaving the wizard open to the first visitor.
- Backups: no yearly tier (up to 36 monthlies instead). Zips are written by the web user, so anything that compromises the web app can delete them; keep an off-box
  copy (the RI Launcher).

## Printing and devices

- **Real-hardware verification:** adding a queue through `PrintersSettings.vue` on a real Pi; USB and driver-based label printers; the `print-scaling=none` effect on a
  real label's page size; the `label_address` paper size (no document uses it yet); the claim keep-alive with a genuinely slow printer. Update `rstation/PRINT_CLIENT_SPEC.md`
  with whatever turns out wrong. The Pi product doesn't act on the heartbeat's `kiosk_path` yet, and its `VERSION` / `image/VERSION` need a bump with a full image
  (the `packages` change needs one, not a hotfix).
- **Desktop print client:** the Windows build is untested (its backend has only seen sample PowerShell output); an IPP backend for macOS/Linux so a CUPS-aborted job isn't
  reported as printed; code signing (Windows cert, Apple Developer ID and notarization) and the Tauri updater key; a log file and the token in the OS keychain.
- **Tell staff when a printer goes offline or errors.** It shows only as a dot in the Print menu and on `/print-queue`. Needs a design: who is told (by site or role,
  e.g. `manage-print-queue` holders), how (email, the notifications bell, a banner), how to avoid flapping, and how to detect a print server that simply stops
  heartbeating (the Docker scheduler only runs backups and `volunteers:sync-active-windows`, so this needs lazy detection or a scheduled task). The offline threshold is
  fleet-wide (`devices.offline_after_minutes`) though print servers heartbeat every 30–60 s; a per-function threshold would make "offline" honest sooner.
- Kiosk follow-ups (escape-hatch key via labwc, first-run site-URL step before pairing, heartbeat extensions) are in `rstation/IMPLEMENTATION.md`.

## Receiving, sorting and scanning

- Apply the scanning playbook (`notes/SCANNING_INTERFACE_NOTES.md`) to Order Filling picking, Receiving and Inventory Movement, and use the same scan resolver on Order Filling
  and Order Entry.
- UPC: how a "Case" row's quantity should count in orders and the inventory report (sorting records single units); record who confirmed a UPC and where it came from
  (`items` has no such columns); a Help guide paragraph. Other lookup sources and a bulk dump: `notes/UPC_SCANNING.md`.
- Sorting doesn't consume `pallets.quick_sort_candidate` yet (the express lane).
- Non-donation receiving categories (`equipment`, `supplies`, `other`) are recorded but have no downstream tracking; they need a design closer to an asset register.
- An "empty pallets" report: Inventory Movement > Locations no longer lists pallets with status `empty`; staff need a way to find them to reuse or recycle.
- Idea: AI-assisted extraction from a photographed shipping label.
- Spreadsheet (XLSX) export of reports; inventory, orders and flow export as CSV today.

## Permissions and people

- **Partners can't order yet.** The Partner role has no `manage-orders` grant because that permission is resource-level, not row-level; granting it would let one partner
  see every other partner's orders. Needs row-level order ownership in `OrderController`; then Partner/Donor self-login with own-orders-only access.
- `review-form-submissions` has no menu entry (only `/setup/forms/{id}/submissions`). The Order Entry menu tile has a NULL `permission_key` (the route requires `manage-orders`);
  set it before Partner/Donor logins exist.
- Rework the Partner/Donor roles and replace hard delete of a `Person` with deactivate/hide (deleting destroys source traceability).
- Move the donation-offer decision flow to its own page, separate from the offers worklist.

## Federation ([notes/FEDERATION.md](notes/FEDERATION.md), `rstation/FEDERATION_AUTH.md`)

- **Before shipping:** build the image and try it on a real Pi (pair with a code; boot with the clock far behind and check it recovers). The Python has run only against a live
  server on a dev machine with a faked clock; the Tauri window and the Windows/macOS print-client builds have not run. Every already-paired Pi, print client and feedback remote
  must be paired again with a code, so do this when each can be reached by someone.
- **Endpoints for permissions that exist but do nothing yet:** `offers.send`/`receive` (needs an origin uuid on `DonationOffer` and a remote-donor `Person`),
  `supplies.request`/`query_available` (an approved Partner `Person` per site; first extract the three duplicated on-hand calculations into one `InventoryAvailability`
  service), `inventory.query_exact`, `operator.full_read`, remote print submission (`printer_shares`, a `peer` on `PrintJob`), and `feedback.receive`.
- Self-service pairing by account: a free account on a central instance (Turnstile + email verification), the user confirms a pairing from their own instance;
  `auth_method = account_verified`, restricted default grants, approver recorded in `approved_by_label`.
- Key rotation (a key change on a pinned trust fails closed and needs re-approval), trust expiry, pruning used pairing codes and old `trust_audit_log` rows, a per-trust rate
  limit beyond the route throttles.
- Ask the slideannouncer maintainers for a `Product.pair` hook and a bearer-provider hook so the Pi product can drop its `pairing.pair` module-attribute swap and credential file.

## Multi-warehouse ([notes/MULTI_WAREHOUSE.md](notes/MULTI_WAREHOUSE.md))

- **Instance console:** a browser UI for everything the operator commands do (create/suspend/archive/remove warehouses, setup codes, global backups, audit-log page, account
  lock, instance-admin management). Mail settings and Turnstile keys move there, with a step-up password re-authentication (never a PIN). Also support sessions
  (synthetic Administrator, time-boxed, audited), and splitting `SystemAdmin.vue`, `/setup/email` and `/setup/testing` into instance and warehouse parts.
- **Hold-all upgrades:** per-warehouse `migration_state` and pre-upgrade backup ids, holding every warehouse if one migration fails, the unblock command with its
  commit-point warning, a disk-space check. The pending-migration check still looks at the default warehouse only.
- **Restore gaps:** the first-run wizard restores only warehouse 1 (instance restore is CLI-only); the whole-instance backup leaves out removed warehouses' backups
  (`removed-warehouses/`).
- Smaller: emailing a setup code to its holder; self-service email change (password plus an APP_KEY-signed confirmation link); deleting a login from the CLI; CLI
  subcommands for mail and Turnstile settings; a `/w/<id>` landing doesn't check a warehouse's status beyond "active"; `skip_chooser` has no use yet.
- Never run outside the test suite: two warehouses on one Docker stack; a Pi and a print client on a prefixed `/w/<id>` URL; the R Station local server and the RI Launcher
  with the new compose changes; a federation identity surviving the Docker upgrade.
