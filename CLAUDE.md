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
several dead menu links, a role middleware that isn't a real bitmask check).

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
is then fetched client-side from a parallel REST-ish API namespaced under `/json/*`, grouped into three
tiers by `role:N` middleware (`CheckRole`, bitmask against `people.role_bitpack`):
- unrestricted (any authenticated user): menu data, statuses, counties, order creation
- `role:4` (Volunteer and above): most CRUD — items, pallets, donations, orders, sorting sessions
- `role:32768` (Administrator only): destructive/structural ops — deleting people/categories/locations,
  role management, warehouse writes

When adding a route, match this pattern: page route with `->middleware(['auth', 'role:N'])` rendering an
Inertia component, plus `/json/...` endpoints for the data that component needs, gated at the same or a
compatible role level (there are known auth-level mismatches between page routes and their JSON
endpoints — see `PROJECT_ANALYSIS.md` item 9 — don't repeat that pattern).

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
  migration `2025_02_20_155051_drop_users_table.php`); auth is on `Person`, not a `User` model. Roles are
  bit-packed onto `role_bitpack` (values like 1, 2, 4, 8... not sequential IDs) — always check role logic
  against the actual bit values in `database/migrations/2025_02_25_155049_reseed_roles_table.php` and
  similar, don't assume a numeric hierarchy.
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
- New JSON endpoints go under the `prefix => 'json'` route groups in `routes/web.php`, at the role tier
  matching their sensitivity (see the three tiers above).
