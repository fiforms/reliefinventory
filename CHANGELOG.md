# Changelog

All notable changes to Relief Inventory are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/) (while below 1.0.0, minor releases may include breaking changes).

## 

## Changes in 0.2.0

### Highlights

- **Multi-warehouse instances** (Docker), with central accounts, a provisioner, setup codes and a warehouse chooser.
- **Federation trust** between instances, replacing the old shared-secret and bearer-token paths. Devices paired the old way must be paired again.
- **Whole-instance backups**, per-warehouse rollback snapshots, and a host-side Docker updater.
- **RI Launcher**, a new desktop app for Windows, macOS and Linux that installs, runs and backs up the Docker install.

### Added

- **Multi-warehouse:** several isolated warehouses per instance (own schema, files, key and database user), a shared control and accounts schema, invitations to join a warehouse with an existing login, setup codes, `warehouse:create`, hostname mapping, a login-page chooser, and an Instance Admin Warehouses tab with password-confirmed management. Federation and feedback work per warehouse (`dev/notes/MULTI_WAREHOUSE.md`).
- **Federation:** Ed25519 request signing, trust registry, pairing codes, a Trust Console, site-to-site invitations with domain verification, and feedback aggregation over trusts. RI Print and R Station moved onto signed requests. Feedback central address accepts a bare domain, with connection codes and Test Connection (`dev/notes/FEDERATION.md`). Requires `ext-sodium`.
- **Backups:**
  - Per-warehouse backups, one folder each and one at a time.
  - Warehouse rollback snapshots in hourly, daily, weekly and monthly tiers, with roll back from the Backups list.
  - Scheduled whole-instance backups made by the provisioner, with "back up now" on Instance Admin.
  - An instance recovery key so automatic backups carry the warehouse keys, sealed to a key only the administrator holds.
  - Whole-instance backup and restore, used as the pre-upgrade backup, and warehouse keys imported when restoring onto a new instance.
  - Warehouse administrators can restore from the app, with a lineage check, files replaced, jobs purged and untrusted relink.
  - A backup can be restored into a warehouse awaiting setup.
- **Docker updates and operations:** a host-side updater driven by a control-schema flag, `instance:status`, `instance:maintenance` and `instance:audit`, `account:*` and more `warehouse:*` commands, optional built-in HTTPS (`RI_HTTPS=auto|internal`), npm scripts to build, start and stop the local Docker image, and a latest/edge software channel.
- **RI Launcher** (`launcher/`): a Docker-Desktop-free Windows installer, a Linux backend (the host's own Docker) and a macOS version (Lima VM). It has status tray icons, a Local RI Server tab, an R Station SD card tab, a pre-install notice with advanced options (channel, port, timezone), Open Terminal with artisan and mysql shortcuts, a safe update button, and remote backups over a federation `backup_client` trust (list, back up now, and save all backups to this computer).
- **Training:** Training Mode with tap-your-name login, activity log and report cards; trainee self sign-up at `/join` with a QR code and follow-up list; PIN login devices that ask from the device and are approved or denied; a class archive on "turn off and reset"; a certificate with trainer signatures and the Relief Inventory logo; and stations that stage the trainer's real scanned goods. Training is now a warehouse of its own, reset by rebuilding that warehouse, with `warehouse:create --training` and `warehouse:training` (`dev/notes/TRAINING_WAREHOUSE.md`).
- **Design Votes:** try live UX demos and vote. Each design can be liked or disliked with a reason, with optional email and "OK to contact me", and a "Have another idea?" box. The first poll is delivery days.
- **Printing:** warehouse signs on Letter paper (ready-made or typed), mailing labels on Avery 8160 sheets with a names and addresses CSV import, the pallet's location printed on its label, intake labels with reprint buttons on saved Receiving records, and label numbers that start at a random point on fresh warehouses.
- **Help:** a Help tile, a list of every guide with Print all, and "Help: <guide>" text links on each page. A help guide and localization plan is added.
- **User administration:** search and filters, admin-set passwords, bulk actions and CSV.
- **Feedback:** report numbers are shown on the feedback page, and a report can be found by number.
- **R Station:** the app image can be updated from the local server GUI, and a signed `.raucb` bundle is included in the image manifest. `build-image.sh` wraps the bundle and image build.
- **Docs:** `docker/INSTALL.md` rewritten for RI Launcher and a step-by-step Linux server install; `dev/` docs, code map, TODO split, generated `dev/SCHEMA.md` (kept fresh by a test), `dev/RELEASE.md` for the release process, and a report engine pre-plan.
- `build:offline-bundle` npm script, which takes an optional version argument.

### Changed

- **Printing to the SATO WS408** goes through the `usblp` node instead of CUPS's USB backend. The Pi USB 3.0 port problem and the investigation are documented in the driver README.
- A timed-out page now sends the user to the login screen instead of showing "Unauthenticated."
- Sorting page tidy-ups, and an administrator can move an item to another ACS #. Trainees can load Sorting's lookups.
- Pick plan never suggests the sorting area, and offers it as "look for others".
- Pallet movement scrolls to and sounds the Seal & Place confirmation.
- The Docker image is slimmer (no build toolchain, flattened, trimmed), and one emoji font is served to every browser.
- Restore runs the database client in `--sandbox` with local-infile off.
- Per-warehouse backups leave out live-state tables, and restores no longer use `artisan down`.
- Bare-metal backups run on the one scheduler; the systemd timer only calls `backup:scheduled`.
- `update.sh` waits for another instance's update or backup instead of aborting.
- The queue worker uses the file cache so it runs with several warehouses.
- Instance Admin: updates and offline mode moved out of Updates & Backups.
- `routes/web.php` split into `routes/web/<area>.php`. Pint was run over every tracked PHP file.
- Project URL points at reliefinventory.app. The Docker workflow only builds the image for `v*` releases.
- The sign-up page is rate limited to 80/min per IP.
- Docs: personal TASKS.md and dashboard.html are ignored, `CLAUDE.md` is slimmed, and stale docs are retired.
- RI Launcher and RI Print show the release tag or commit as their version. The Launcher's install is described as a large download that can take several minutes.
- The Launcher backups tab lists as root and offers a whole-instance Backup now, with a one-sentence reason when a whole-instance backup was not possible. Restore from file is dropped.

### Fixed

- Email verification link no longer loops new accounts back to login.
- Register renders Turnstile on script load instead of a fixed 500 ms timer.
- 500 when adding a location (`warehouse_id` not set).
- PIN login accepts `H:i:s` for the trust time of day.
- Pi credentials are stamped with the server's time and retried on 419.
- Backups stuck queued with several warehouses, and the Warehouse Backups helpers (including the date helper that blanked `/setup/system`).
- Instance Admin loads the backups tab on a direct open and shows the password dialog on every tab.
- Moving uploads no longer drops the checkout's `storage/app/private/.gitignore`.
- Fixes from running the upgrade and restore for real.
- The RI Launcher installer version has no `-`, which an `.rpm` rejects.

### Removed

- Federation legacy paths: the feedback shared secret, bearer-token devices, the in-place device upgrade and `/api/devices/pair`.
- Warehouse-level training switch (training is a warehouse now).

### Deprecated

- The in-app web updater for bare-metal installs (`git pull`, `npm run build` and `artisan migrate` from a web request). Bare-metal administrators update by hand (`dev/BUILDING.md`).

## [0.1.0] - 2026-10-22

Initial release.
