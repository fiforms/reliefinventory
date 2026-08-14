# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Relief Inventory: a Laravel 11 + Inertia.js + Vue 3 warehouse management system for tracking donated
goods in disaster relief (originally built with Adventist Community Services). Core workflow: donations
come in tagged to pallets, get sorted into a ledger of items, and are later picked against orders from
distribution points, with full source (donor/pallet) traceability.

`PROJECT_ANALYSIS.md` at the repo root is a detailed audit of known defects and a phased completion plan
— read it before starting non-trivial work here, since it documents which "existing" features are
actually broken or unwired (e.g. donation sorting not yet recording pallet provenance end-to-end,
several dead menu links). Note that the item flagging "a role middleware that isn't a real bitmask
check" has since been resolved — see the granular permissions model described below.

## Commands

```bash
composer install && npm install     # install PHP + JS deps
php artisan migrate                 # run migrations
php artisan db:seed                 # seed essential data (run after creating a user)
php artisan db:seed --class=TestDataSeeder   # optional test data
composer run dev                    # run server + queue listener + pail logs + vite, concurrently
npm run dev                         # vite only
npm run build                       # production asset build

vendor/bin/pest                     # run full test suite (Pest, Feature + Unit)
vendor/bin/pest tests/Feature/ProfileTest.php   # run a single test file
vendor/bin/pest --filter="test name"            # run a single test by name
vendor/bin/pint                     # PHP code style (Laravel Pint)
```

There is effectively no frontend test suite (no JS unit tests configured); rely on manual verification
in the browser for Vue changes.

Useful artisan commands specific to this project: `php artisan user:create`, `php artisan user:promote`,
`php artisan user:import-banned <file>`, `php artisan counties:import <file>`.

## Architecture

### Backend: Laravel + Inertia, JSON API under `/json`

Routes live in `routes/web.php`. Page routes render Inertia views directly from closures (not
controllers) and pass a `breadcrumb` prop via `MenuItem::getBreadcrumb('/path')`. Data for those pages
is then fetched client-side from a parallel REST-ish API namespaced under `/json/*`, gated by
`permission:<key>` middleware (`CheckPermission`) — one permission key per resource (e.g.
`manage-people`, `admin-people`, `manage-orders`), not a numeric role hierarchy. A person's effective
permissions are their roles' default grants (`role_permissions`) with any per-person overrides
(`person_permissions`, `granted` true/false) layered on top; see `HasPermissions` (shared by `Person`
and `User`) and `PermissionsSeeder` for the full key list and default role bundles.

When adding a route, match this pattern: page route with `->middleware(['auth', 'permission:<key>'])`
rendering an Inertia component, plus `/json/...` endpoints for the data that component needs, gated on
the same permission key (there are known historical auth-level mismatches between page routes and their
JSON endpoints — see `PROJECT_ANALYSIS.md` item 9 — don't repeat that pattern).

### Data model core

- `orderdonations` table / `Transaction` model — a single "transaction" row is either an order or a
  donation (`type` column), linked to a `Person` (the donor/recipient) and `person_id_user` (staff who
  entered it — must be set server-side from `Auth::id()`, never trusted from the client).
- `item_ledgers` — the append-only ledger of item movement (`qty_added`/`qty_subtracted` style), the
  source of truth for inventory. Carries `pallet_id` for source traceability and (as of the July 2026
  migration) a `disposition` field for scan-driven sorting (usable/trashed/diverted). **Nothing in the
  app currently aggregates this ledger into a stock-on-hand figure** — that's a known gap, not an
  oversight, if you're asked to build inventory reporting.
- `pallets` / `PalletStatus` — pallets get a printed barcode label and a status history; they are the
  unit that donation sorting scans to establish provenance.
- `people` / `roles` / `people_roles` — a single `Person` table replaced a dropped `users` table (see
  migration `2025_02_20_155051_drop_users_table.php`); auth is on `Person`, not a `User` model (both map
  to the same `people` table/row — `User` is what `Auth::user()` returns, `Person` is the general party
  record; they share permission-resolution logic via the `HasPermissions` trait rather than a common
  class). `people_roles` links a person to zero or more `roles`; `permissions` / `role_permissions` /
  `person_permissions` implement the granular permission-key model described above. There is no
  `role_bitpack` column — it was dropped when the permission-key model replaced it.
- `MenuItem` — drives the main nav and supplies `getBreadcrumb($path)` used by page routes.

### Frontend: Inertia pages + a shared CRUD form framework

`resources/js/Pages/*.vue` are Inertia page components (routed 1:1 from `routes/web.php`).
`resources/js/Layouts/AuthenticatedLayout.vue` / `GuestLayout.vue` wrap them.

Most CRUD-style admin pages (Items, Categories, Locations, People, Units, ...) are built on
`resources/js/Components/RIForm.vue`, a generic list/detail form component bound to a `/json/...`
datasource: it fetches `{records, templates}`, renders a list view via the `#thead`/`#tbody` slots and an
edit view via the `#default` slot (scoped with `{record, editing, templates}`), and does
save/cancel/delete against the datasource URL using REST verbs inferred from whether `record.id` is set.
`RISubform.vue` is the equivalent for nested child collections (e.g. order lines) within an `RIForm`.
Read the doc comment at the top of `RIForm.vue` before touching it — it documents the slot contracts.
Note RIForm currently only logs save/delete errors to the console (no user-facing error surfacing, no
delete confirmation) — a known gap, see `PROJECT_ANALYSIS.md`.

**Donation sorting is the one exception to the RIForm pattern.** `DonationSorting.vue` +
`SortingSessionController` implement a scan-driven, autosaving flow instead: a sorting session is created
when scanning starts, and each sorted line is POSTed to the server individually
(`POST /json/sorting-sessions/{id}/lines`) as it's entered, rather than batching everything into one save
at the end. This is intentional — sorting sessions can run 45+ minutes and must survive a browser crash
or network drop without losing entered work. Follow this per-line-autosave pattern (not RIForm's
save-at-end model) for any other long-running, scan-driven workflow (e.g. future order filling/picking).

`QrScanner.vue` wraps `html5-qrcode` for camera-based barcode/QR scanning; keyboard-wedge USB/Bluetooth
scanners work via plain text input + Enter-to-submit and don't need this component.

### PDF / label generation

Pallet labels and reports are generated via `spatie/laravel-pdf` (`PalletReportController`) and
`milon/barcode` for barcode rendering. `UPCGenerator` (`app/Helpers`) derives a valid check-digit UPC-A
from a 5-digit item number for printed item labels.

## Conventions to follow

- Set audit/actor fields (`person_id_user`, etc.) from `Auth::id()` server-side in controllers — never
  accept them from the request body.
- Wrap multi-row writes (a transaction header plus its ledger/order lines) in `DB::transaction()`.
- Don't use `env()` outside `config/*.php` files — read from `config()` instead, since `env()` returns
  null once config is cached in production.
- New JSON endpoints go under the `prefix => 'json'` route groups in `routes/web.php`, gated by
  `permission:<key>` matching the resource being accessed (see the permission-key model above). If a new
  resource needs a new permission key, add it to `PermissionsSeeder`'s key list and default role bundles.
- Granting a role or per-person permission override (`PeopleController`) requires the acting user to
  already hold that permission themselves, and to hold every permission the target person currently has
  — even for edits that don't touch permissions at all, and even for a pure revoke. This is deliberately
  conservative (prevents using an unrelated edit as cover to touch someone you shouldn't be able to
  touch) — see `PeopleController::assertNoEscalation()`.
