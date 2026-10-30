# Changelog

All notable changes to Relief Inventory are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/) (while below 1.0.0, minor releases may include breaking changes).

## [0.2.0] - Unreleased

### Highlights

#### Multi-warehouse instances

One Docker instance can now host several warehouses. Each warehouse has its own database schema, database user, uploaded files, encryption key and settings, so one warehouse can't read or break another. Logins are central: a person has one account and can belong to several warehouses, and an administrator can invite an existing login to join one. A shared control schema holds the warehouses, hostnames, queue and update state, and an accounts schema holds credentials and memberships.

Instance administrators create warehouses from the new Warehouses tab on Instance Admin (password-confirmed) or with `warehouse:create`, give them setup codes, and map hostnames to them. The login page offers a warehouse chooser when a host serves more than one. Federation and feedback work per warehouse, and label numbers (pallet tags, ORD-/BOL- numbers, inventory labels) start at a random point on a new warehouse, so parallel warehouses don't print the same low numbers.

Training is now a warehouse of its own instead of a mode (`warehouse:create --training`), reset by rebuilding that warehouse. An existing install keeps exactly one warehouse until an administrator adds more. Bare-metal installs stay single-warehouse. Design: `dev/notes/MULTI_WAREHOUSE.md`.

#### Federation trust

Instances, print servers and other clients now prove who they are with signed requests, in place of the feedback shared secret and bearer-token device pairing. Every instance has an Ed25519 identity published at `/.well-known/ri-instance.json`. Two parties pair with a short code and then hold an explicit trust with specific permissions, such as `print.share`, `feedback.send` or a backup client's read access, all managed in the new Trust Console (`/setup/trust`) and written to an audit log. Administrators can also invite another site to trust them, with domain verification.

RI Print and R Station moved onto signed requests. Feedback aggregation now travels over trusts: the central address accepts a bare domain, with connection codes and a Test Connection button, and reports can be collected from linked instances. The RI Launcher uses the same system to pull remote backups.

Because the old paths are removed, **devices paired the old way must be paired again** after upgrading. Requires `ext-sodium`. Details: `dev/notes/FEDERATION.md` and `rstation/FEDERATION_AUTH.md`.

#### RI Launcher

RI Launcher is a new desktop app (`launcher/`) that installs, runs and updates the Docker version of Relief Inventory on Windows, macOS and Linux, so a site can run a local server without Docker Desktop or command-line setup:

- **Windows:** installs its own WSL-based Docker. **macOS:** runs Docker in a private Lima VM. **Linux:** uses the host's Docker and shows the exact commands to fix a missing or stopped Docker.
- A pre-install notice explains the limits of a local install (no HTTPS or outside access without a proxy, never run two live copies of one warehouse). Advanced options set the channel (latest or edge), port and timezone.
- Tabs for the local server, status tray icons, a safe Update button (it also works while stopped), an Open Terminal button with `artisan` and `mysql` shortcuts, and an R Station SD card tab that downloads and verifies the Raspberry Pi image and opens Raspberry Pi Imager.
- **Remote backups:** pair with a server over a federation backup trust, list its backups, run a whole-instance "Back up now", and save all backups to the local computer on a schedule.

### Added

- **Backups:** per-warehouse backups (one folder each, one at a time); warehouse rollback snapshots in hourly, daily, weekly and monthly tiers, with password-confirmed roll back; scheduled whole-instance backups made by the provisioner, with "back up now" on Instance Admin. An instance recovery key seals the warehouse keys into every backup, so a stolen zip or compromised server gives nothing away and only the administrator can restore the encrypted settings. Whole-instance backup and restore is used as the pre-upgrade backup, and warehouse administrators can restore from the app. A backup can be restored into a warehouse awaiting setup.
- **Docker operations:** a host-side updater driven from Instance Admin or `instance:update`, a latest/edge software channel, optional built-in HTTPS (`RI_HTTPS=auto|internal`), and `instance:status`, `instance:maintenance`, `instance:audit`, `account:*` and more `warehouse:*` commands.
- **Training:** tap-your-name login, activity log and report cards; trainee self sign-up at `/join` with a QR code; a class archive; a certificate with trainer signatures; and stations that stage the trainer's real scanned goods. PIN-login devices now request access from the device and are approved or denied.
- **Design Votes:** try live UX demos and vote like or dislike with a reason, plus a "Have another idea?" box. The first poll is delivery days.
- **Printing:** warehouse signs on Letter paper (ready-made or typed), Avery 8160 mailing labels with a names and addresses CSV import, the pallet's location on its label, and reprint buttons for intake labels on saved Receiving records.
- **Help:** a Help tile listing every guide with Print all, and "Help: <guide>" links on each page.
- **User administration:** search and filters, admin-set passwords, bulk actions and CSV.
- **Feedback:** report numbers are shown, and a report can be found by number.
- **R Station:** the app image can be updated from the local server GUI, and `build-image.sh` builds the bundle and image.
- **Docs:** `docker/INSTALL.md` is rewritten for RI Launcher and a Linux server install, and `dev/RELEASE.md` describes the release process.

### Changed

- Printing to the SATO WS408 goes through the `usblp` node instead of CUPS's USB backend, fixing "Waiting for printer" on the Pi.
- A timed-out page sends the user to the login screen instead of showing "Unauthenticated."
- Sorting page tidy-ups. An administrator can move an item to another ACS #.
- The pick plan never suggests the sorting area and offers it as "look for others".
- Pallet movement scrolls to and sounds the Seal & Place confirmation.
- The Docker image is much smaller (about 383 MB down to 165 MB compressed), and one emoji font is served to every browser.
- Bare-metal backups run on the one scheduler; the systemd timer only calls `backup:scheduled`.
- `update.sh` waits for another update or backup instead of aborting.
- Instance Admin: updates and offline mode moved out of Updates & Backups.
- The sign-up page is rate limited to 80 per minute per IP.

### Fixed

- Email verification link no longer loops new accounts back to login.
- Register renders Turnstile on script load instead of a fixed 500 ms timer.
- 500 when adding a location.
- PIN login settings error when saving the trust time of day.
- Trainees can load Sorting's item lookups.

### Removed

- Federation legacy paths: the feedback shared secret, bearer-token devices, the in-place device upgrade and `/api/devices/pair`.
- The warehouse-level training switch (training is a warehouse now).

### Deprecated

- The in-app web updater for bare-metal installs (`git pull`, `npm run build` and `artisan migrate` from a web request). Bare-metal administrators update by hand (`dev/BUILDING.md`).

## [0.1.0] - 2026-10-22

Initial release.
