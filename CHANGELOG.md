# Changelog

All notable changes to Relief Inventory are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/) (while below 1.0.0, minor releases may include breaking changes).

## [Unreleased]

> **This list is incomplete.** It was assembled from the old TODO notes, not from the commit history. Before a release, rebuild it
> from `git log v0.1.0..HEAD` and add anything missing.

### Added

- **Federation trust** between instances: identity, pairing codes, permissioned trusts and signed requests (`dev/notes/FEDERATION.md`). Devices paired the old way must be paired again.
- **Multi-warehouse instances** (Docker): several isolated warehouses per instance with central accounts and memberships, a provisioner, one-time setup codes, hostname mapping and a chooser (`dev/notes/MULTI_WAREHOUSE.md`). Training is now a warehouse of its own (`dev/notes/TRAINING_WAREHOUSE.md`).
- **Instance admin** page and operator commands (`instance:*`, `warehouse:*`, `account:*`).
- **Docker host-side updater** (`instance:update`) and whole-instance backups that the RI Launcher can pull.
- **Backups:** one zip format and restore pipeline on every platform; per-warehouse scheduled hourly, daily, weekly and monthly tiers with roll-back; one Updates & Backups page (`dev/notes/BACKUPS.md`).
- **Print queue:** per-site printers and a job queue, a shared Print menu on every print point, the device API, printer setup on `/setup/devices`, optional realtime via Reverb (`dev/notes/PRINT_QUEUE.md`). The R Station print daemon and the **RI Print** desktop client (Windows, macOS, Linux) pair with it.
- **First-run setup wizard** (`dev/notes/FIRST_RUN.md`).
- **Barcode (UPC) lookup** at sorting, with carton codes and an online lookup chain (`dev/notes/UPC_SCANNING.md`); scanner-first Donation Sorting.
- **Docker distribution** for amd64 and arm64: FrankenPHP, MariaDB, queue, scheduler, optional Reverb, WeasyPrint bundled.
- **User management:** `/setup/users` with login-capable accounts, role presets over a flat permission checklist, per-person overrides, deactivate/reactivate.
- **Reports:** flow, donor and partner reports; CSV export of inventory, orders and flow.

### Changed

- All PDFs render through WeasyPrint (page breaks fixed).
- Quick-add donor in Receiving and Donation Sorting; Order Entry search and unit fixes; Default Ordering Unit list and admin page (`/setup/units`).
- Federation legacy paths removed: feedback shared secret, bearer-token devices, in-place upgrade, `/api/devices/pair`.

### Deprecated

- The in-app web updater for bare-metal installs (it ran `git pull`, `npm run build` and `artisan migrate` from a web request). Bare-metal administrators update by hand (`dev/BUILDING.md`).

## [0.1.0] - 2026-10-22

Initial release.

### Downloads

- **Docker image** (linux/amd64 and linux/arm64) — `ghcr.io/fiforms/reliefinventory:0.1.0` (also `:latest`), built automatically when this release is published. See `docker/README.md`.
- **ri-print** (desktop print server) — binaries attached to this release. Tested on Linux and macOS; the Windows build is untested.
- **R Station images** (Raspberry Pi) — too large to attach; download from the project's separate download site: _link TBD_.

[Unreleased]: https://github.com/fiforms/reliefinventory/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/fiforms/reliefinventory/releases/tag/v0.1.0
