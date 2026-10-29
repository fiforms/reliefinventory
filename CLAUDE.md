# CLAUDE.md

Guidance for Claude Code in this repo. Keep this file short: durable conventions and pointers only. Subsystem detail
lives in `dev/notes/`; the doc map is `dev/README.md`.

## What this is

Relief Inventory: a Laravel 13 + Inertia.js + Vue 3 warehouse management system for tracking donated goods in disaster
relief (built with Adventist Community Services). Donations arrive tagged to pallets, are sorted into a ledger of items,
and are later picked against orders from distribution points. Source (donor/pallet) is recorded at sort time, but stock is
pooled by item type once sorted, so a shelf item or filled order line can't be traced to a donor or pallet (no recall-style
tracing; don't claim it in docs or copy).

**Deployment:** Docker is the preferred and primary way to run it (multi-warehouse, the provisioner, self-update and
whole-instance backup all assume it). A bare Linux server (systemd) is still supported, but as a single warehouse without
the multi-warehouse features; don't build or maintain elaborate bare-metal paths.

**Sub-projects** (this repo, separate from the Laravel app; each has its own README):
- `launcher/` — **RI Launcher**: Rust (Tauri) desktop app that launches and manages the Docker install and acts as the
  whole-instance backup client (see "Instance trust" in `dev/notes/multi-warehouse.md`).
- `print-client/` — **RI Print**: Rust (Tauri) desktop print server for Windows/macOS/Linux; pairs with the web app's print queue.
- `rstation/` — **R Station**: Raspberry Pi device image: warehouse kiosk view, print client, and Docker host (can run a whole
  offline site). Wire protocols and specs live in its docs (`PRINT_SERVER.md`, `FEDERATION_AUTH.md`, OpenAPI files).

`dev/notes/PROJECT_ANALYSIS.md` is a stale collection of project notes (defects, a phased plan). It can help with detailed
work, but verify anything in it against the code before relying on it. Future ideas: `dev/ROADMAP.md`; open work: `dev/TODO.md`.

## Database schema reference

**Start with `dev/SCHEMA.md`** for any table or column question: every table in the warehouse, control and accounts schemas, built
from the migrations (use it instead of grepping 240 migrations). It is generated. After adding or changing a migration, run
`dev/scripts/schema-doc.sh` and commit the result with the migration. Never edit it by hand.

## Commands

```bash
composer install && npm install     # install deps
php artisan instance:migrate        # migrate control + accounts + every warehouse (plain `migrate` only does the default one)
php artisan db:seed                 # essential data (after creating a user)
composer run dev                    # server + queue + pail + vite
npm run build                       # production assets
vendor/bin/pest [file] [--filter=name]   # tests; phpunit.xml targets a disposable database, never the real one
vendor/bin/pint <files>             # style: pass only the files you changed (the repo isn't Pint-clean)
```

Read `scripts/TESTING.md` before touching test DB config. No JS test suite: verify Vue changes in the browser.
Demo data: `db:seed --class=WarehouseActivitySeeder` (demo instances only, never real data). Instance/warehouse/account
management commands: `instance:status` is the overview; the table is in MULTI_WAREHOUSE.md §18.

## Outline

- **Backend:** Laravel + Inertia. Page routes (`routes/web.php`) render Vue pages; data comes from `/json/*` endpoints gated by
  `permission:<key>` middleware. A page and its `/json` endpoints must use the same key. Permissions: `HasPermissions`,
  `PermissionsSeeder`.
- **Frontend:** `resources/js/Pages` (1:1 with routes), shared `Components/RIForm.vue` for CRUD pages (read its header comment),
  per-line-autosave screens for long workflows. → `dev/notes/frontend.md`. **Scanner screens: read
  `dev/notes/SCANNING_INTERFACE_NOTES.md` first.**
- **Core models** (rules are in each model's header comment): `Transaction` (orders and donations → `dev/notes/orders-and-filling.md`),
  `ItemLedger` (stock → `dev/notes/stock-and-locations.md`), pallets/donation offers (`dev/notes/receiving.md`), barcode
  resolution (`dev/notes/upc-scanning.md`), reports (`dev/notes/reports.md`).
- **People and logins:** one `Person` table; credentials live in the accounts schema. → `dev/notes/multi-warehouse.md`
- **Multi-warehouse:** an instance hosts several warehouses (own schema, files, key, DB user each) plus shared control and
  accounts schemas. Design: `MULTI_WAREHOUSE.md`. **Before changing sessions, auth, `storage_path()`, backups/restore, queue,
  cookies, emailed links or anything assuming one database, read the rules in the `WarehouseContext` class comment and
  `dev/notes/multi-warehouse.md`.**
- **Training warehouse:** a warehouse of its own, not a mode. → `dev/notes/training-warehouse.md`
