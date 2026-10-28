# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Relief Inventory: a Laravel 13 + Inertia.js + Vue 3 warehouse management system for tracking donated
goods in disaster relief (originally built with Adventist Community Services). Core workflow: donations
come in tagged to pallets, get sorted into a ledger of items, and are later picked against orders from
distribution points. Source (donor/pallet) is recorded at sort time — what each donation/pallet contained and
in what condition — but stock is pooled by item type once sorted, so a shelf item or a filled order line can't
be traced back to a specific donor or pallet (no recall-style item tracing; don't claim it in docs or copy).

`PROJECT_ANALYSIS.md` at the repo root is a detailed audit of known defects and a phased completion plan
— read it before starting non-trivial work here. Most of Phase 0/1's original defect list is now fixed
(source traceability, stock-on-hand, the permission model, DB transactions, RIForm error/delete UX, a real
test suite, order filling/picking as of 2026-08-27); what's still genuinely open is BOL upload, the
order fulfillment lifecycle past Filled (Ready to Ship/Shipped), the facility-network expansion
(Part 5), the endpoints that would actually share data between federated instances (the trust layer itself — identity, pairing, permissions — is built: see "Federation"), and several report pages (`/reports/flow`, `/reports/donors` and `/reports/partners` were built 2026-10-22 — see "Flow, Donor and Partner reports" below —
so no report is a placeholder any more; `/reports/orders`, the Outstanding Orders Report, is built as of
2026-08-22, and `/reports/unfulfilled`, the Unfulfilled Requests Report — requested vs. filled per item, by the
partner's county and zip, finished requests only — as of 2026-10-20) — check the doc's own inline "Update" notes for the current state of any given item before
assuming it's still broken.

## Commands

```bash
composer install && npm install     # install PHP + JS deps
php artisan instance:migrate        # run migrations: control + accounts schemas, then every warehouse
                                     # (a plain `migrate` only migrates the default warehouse's schema)
php artisan db:seed                 # seed essential data (run after creating a user)
php artisan db:seed --class=WarehouseActivitySeeder   # optional realistic demo data (donations/pallets/
                                     # orders across every lifecycle stage) — demo instances only, never
                                     # run against an instance with real operational data. (The old
                                     # Test*Seeder classes were deleted — they predated the family/variant
                                     # item numbering and permission model.)
composer run dev                    # run server + queue listener + pail logs + vite, concurrently
npm run dev                         # vite only
npm run build                       # production asset build

vendor/bin/pest                     # run full test suite (Pest, Feature + Unit) — phpunit.xml points
                                     # this at a separate, disposable database (never the real app
                                     # database — RefreshDatabase truncates whatever it's pointed at).
                                     # See scripts/TESTING.md before touching that config, and before
                                     # setting up a new instance (a fresh box needs the same disposable
                                     # test database + grant created manually until a provisioning
                                     # script exists — see TODO.md item 7).
vendor/bin/pest tests/Feature/ProfileTest.php   # run a single test file
vendor/bin/pest --filter="test name"            # run a single test by name
vendor/bin/pint <files>             # PHP code style (Laravel Pint). Always pass the files you changed: the repo isn't Pint-clean,
                                     # so running it on a directory reformats unrelated files (and it can't read docker/backups).
```

There is effectively no frontend test suite (no JS unit tests configured); rely on manual verification
in the browser for Vue changes.

Useful artisan commands specific to this project: `php artisan user:create`, `php artisan user:promote`,
`php artisan user:import-banned <file>`, `php artisan counties:import <file>`.

## Architecture

**Multi-warehouse instances are partly built** (designed 2026-10-27) — `MULTI_WAREHOUSE.md` is the design: one MariaDB schema,
storage directory, `WAREHOUSE_KEY` and DB user per warehouse; a control schema; central `accounts` (credentials) with per-warehouse
`people`; a standard training warehouse; per-warehouse maintenance/backup/restore. Phases 0–2 are built (§18 of that doc): requests, jobs and commands each
run in one warehouse (`WarehouseContext`; `ResolveWarehouse` picks it from the hostname, `/w/<id>` on machine URLs, `?w=` or the
`ri_warehouse` cookie), but nothing creates a second warehouse yet (phase 3, begun: on Docker every warehouse — the default one too, whose schema is
renamed once to `reliefinventory_<id>` — has its own DB user `ri_<id>`, made by a separate `provisioner` container; see §18), so an
instance still has exactly one and everything below is accurate as written. Read that doc before changing sessions, login/auth, `storage_path()` use, backups/restore, the queue or
scheduler, broadcast channels, cookies, emailed links, or anything else that assumes "one instance = one database". In short: every
request names its warehouse (`X-RI-Warehouse`, `GuardWarehouseTab`); emailed links come from `Warehouse::publicUrl()`, never the Host
header; long-lived cookies use `WarehouseContext::cookieName()`; broadcast channels use `Warehouse::channel()`; commands take
`--warehouse`; only the `provisioner` container holds the database administrator password (the `admin-secrets` volume) — never read it
from application code, ask it through `ProvisioningClient`; tests that need a second warehouse use `tests/SecondWarehouse.php`. A new warehouse is made with `php artisan warehouse:create` (`WarehouseCreator`) and left `awaiting_setup`: it answers only `/activate`, where the holder of its
one-time setup code (`SetupCodes`, hash only, 7 days, wrong tries counted per warehouse) makes themselves its Administrator (never an instance admin) and it goes
`active` (`WarehouseSetup`). The holder may instead restore a backup into it (`/activate/restore/*`, a claim file in the warehouse's own restore folder `<backup dir>/warehouses/<id>/restore`; the backup is untrusted: its logins become invitations, a failed load returns that warehouse to empty). The login page shows the warehouse's name and, on the shared address only, a chooser (`listed` warehouses + a typed warehouse id, `/choose-warehouse`); `php artisan warehouse:domain` maps hostnames (`WarehouseDomains`; a mapped host is locked to its warehouse and never shows a chooser; Turnstile hosts are flagged until a check succeeds on them). Removing a warehouse is `warehouse:archive` (undoable) then `warehouse:remove` (typed name, final backup in `<backup dir>/removed-warehouses/`, drop, files, the logins that belonged only to it, control rows; `WarehouseRemoval`); instance-level actions are written to `instance_audit_log` (`InstanceAuditLog::record`). Backups too are per warehouse (`<backup dir>/warehouses/<id>/`, one at a time instance-wide via `.instance-backup.lock`; `config/backup.php` `live_state_tables` keep their rows out of dumps and are emptied by a restore); a forced restore takes only that warehouse out of service (status `restoring`, ended by `warehouse:resume`), never `artisan down`. A warehouse admin can restore a backup into their own warehouse from the app (`/setup/restore`, `RestoreService::stageOwnRestore`): same-lineage backups only (the uuid in the manifest; an instance admin may clone another's), files folder replaced, jobs purged, accounts relinked untrusted, and the admin who ran it stays Administrator. Progress is polled at `/w/<id>/restore-status`. **Whole-instance backup/restore** (`InstanceBackupService`, `instance:backup` / `restore:instance`, CLI in the `provisioner` container only because they need the database administrator's password): one zip of the control + accounts + every warehouse schema (one consistent dump), optionally every warehouse's files and the warehouse keys (passphrase-encrypted entries); it is also the pre-upgrade backup (`ri-db-backup premigrate` asks the provisioner for it). Trusted input: no relinking, and nothing here is for a warehouse admin. Restore folders are per warehouse and `restore:run` takes `--warehouse`; whole-instance backup/restore isn't built (phase 4).
Attaching a login a warehouse doesn't own is an **invitation** the login's owner accepts with its password (`MembershipInvitations`, accounts table
`membership_invitations`; the emailed link `/invitations/{token}` and `/account/invitations`) — an admin adding an email that already has a login, an untrusted
restore, a future importer: never write a `memberships` row for someone else's account directly. Restores are untrusted input: they run as the warehouse's own DB user with the `mariadb` client in `--sandbox --local-infile=0`
(`DatabaseTools::clientCommand()`); the grants bound the SQL and the flags bound the client, and the dump is deliberately not parsed
for keywords — don't add a filter. Rule of thumb: an instance admin is assumed able to run any command in the container or on the
hardware, so never enforce a rule *against* the instance admin in software (retention periods, read-only access); enforce isolation
between warehouses. It also decides that training is a warehouse of its own, not a mode of a live one ("Training warehouse" below): don't add anything to a real warehouse's behaviour for a class.

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
the same permission key (there was a historical auth-level mismatch between `/order-entry` and its JSON
endpoints — fixed, but keep matching page-route and JSON-endpoint gates in sync when adding new pages).

### Data model core

- `orderdonations` table / `Transaction` model — a single "transaction" row is either an order or a
  donation (`type` column), linked to a `Person` (the donor/recipient) and `person_id_user` (staff who
  entered it — must be set server-side from `Auth::id()`, never trusted from the client). Both sides carry
  a stored, server-controlled status lifecycle (never a free-form dropdown) with `status_changed_at`
  auto-tracked on every transition (see `Transaction::booted()`): donations go
  `Received → Sorting → Complete` (or `Logged`, for non-donation intake categories); orders go
  `New Order → Ready to Fill → Filling → Filled → Ready to Ship → Shipped → Delivered → Completed`
  (a rejected signed BOL goes `Delivered → Delivered - Waiting on BOL → Delivered` on re-upload — the
  goods did arrive, so it never drops back to Shipped), and only `New Order` is intake-editable —
  order lines can't be changed once filling has started (`OrderController::rejectIfLocked`). Filling
  itself (Order Filling/Picking, 2026-08-27 — `OrderFillingController` + `OrderFilling.vue`) is a
  separate write surface layered on the same order: `Ready to Fill` → `Filling` happens either by
  starting one order directly (the live-scan/manual path) or by printing a batch of pick sheets for
  every `Ready to Fill` order at once (the paper path, `POST /json/order-filling/print-pick-sheets`,
  transactional + row-locked so two staff printing at once can't double-grab an order); a fill record
  is an `item_ledgers` row tied to the `OrderLine` it satisfies via `order_line_id`, append-only
  (multiple fills per line are summed, never overwritten) regardless of which path produced it — both
  ultimately call the same `POST .../lines/{lineId}/fills` endpoint. Completing filling requires every
  line with something to pick to have at least one fill record (a deliberate zero counts as "don't have
  this"); if nothing at all was picked the order goes to the terminal `Closed - Unfulfilled` instead of
  `Filled`, and never reaches Shipping. The same build also added a "Review Allocation" panel to
  `OrderFilling.vue`: wherever total requested across `Ready to Fill` orders exceeds on-hand for an
  itemtype, it shows a straight-proportional suggested split per line alongside that line's optional,
  self-reported `need_level` (critical/moderate/low, on `orderlines`), computed live from
  `config('inventory.low_stock_threshold')` (overridable per itemtype via
  `itemtypes.low_stock_threshold`). Since 2026-10-19 a manager can save an approved quantity per line
  there (`orderlines.qty_approved` + approver/time, only while `Ready to Fill`); pickers then see only
  `OrderLine::qty_to_pick` (approved, else requested) on pick sheets and the fill screen, and lines
  approved at 0 are left off and need no fill. `qty_requested` is never changed, so requested vs.
  approved vs. filled stays reportable. Approving is optional — Print Pick Sheets and Start Filling
  both work without ever opening the panel. `donor_identification_pending`
  (plain boolean, not a status) flags a donation whose source needs follow-up — unlike `sort_hold` on item
  types, it never gates anything downstream (the goods are real/usable regardless), it's purely a
  find-it-later reminder. A donation flagged this way stays visible in Receiving's list even past
  `Complete`, or it would silently age out the moment enough other donations pass through.
- `item_ledgers` — the append-only ledger of item movement (`qty_added`/`qty_subtracted` style), the
  source of truth for inventory. Carries `pallet_id` on sorting's addition rows (per-donation/pallet provenance — fill/subtraction rows
  don't carry it, since stock is pooled once sorted) and a `disposition` field
  (usable/outdated/trashed/diverted) from scan-driven sorting — only `usable` counts toward stock-on-hand;
  the others feed donor-quality reporting. Usable sorting rows also carry `item_condition` (`new`/`used`,
  null on older rows; named so because CONDITION is reserved in MariaDB) — sorters pick New or Usable, both
  count toward on-hand, and the Inventory Report's "Received New" column sums the new ones (all-time
  received, not new-on-shelf, since fills don't record condition). `WarehouseMetrics` (`app/Services`) and
  `InventoryReportController` both aggregate this ledger (usable additions − subtractions) — that used to
  be a known gap ("nothing computes stock-on-hand"); it isn't anymore. `qty_subtracted` itself was always
  0 in production until Order Filling/Picking (2026-08-27) — see that section below. Also carries
  `order_line_id` (nullable, set-null on delete — links a fill record back to the `OrderLine` it
  satisfies) and `person_id_user` (nullable, actor audit; the table had none before Order Filling added
  it, and Sorting's own writes were updated in the same pass to stamp it too, so it isn't half-null by
  design).
- **Stock by pallet / location** (2026-10-20) — `item_ledgers` stays pooled; where goods physically are comes from two append-only
  sources summed by `App\Services\PalletStock`: `pallet_stock_assignments` (signed qty put on a Warehouse `W` pallet) and ledger rows
  carrying `stock_pallet_id` (a fill picked from a pallet; sorting straight onto a pallet is a supported-but-unbuilt add-on). Nothing
  stores a running total, so it can't drift. Core flow: goods are sorted into the sorting area, then a lead/forklift driver builds the W
  pallet as it leaves (`PalletStockController::fill`, Inventory Movement > Pallets tab, which is one scan-driven screen: an always-focused scan bar takes a pallet (shows location/status/contents/history, move or change status, add items if it's a W pallet) or, with a pallet showing, a location barcode (records the move at once); with no pallet showing it builds a new W pallet. Locations and Recent Movements are separate tabs with no scan bar. A sealed W pallet can only be moved or unsealed; an open one can be re-sealed, take items from the sorting area, or (if it already has a location and the user holds `adjust-inventory`, a permission separate from `manage-pallets`, default Office/Administrator) have its contents adjusted by hand with a required reason (shrinkage, damaged, correcting count/item type, other) — mutually exclusive in the UI. An adjustment is its own `orderdonations` row (`type='adjustment'`, `adjustment_reason`) whose `item_ledgers` rows carry `stock_pallet_id`, so it changes stock itself; sorting additions never carry `stock_pallet_id`, which the sorting-area and donor-quality reports rely on to ignore adjustments — items come from `/json/pallet-stock/staging`,
  the pick-up list), sealing it and setting its location in one step; a pallet can't take more than `PalletStock::unassigned()`
  (on-hand minus everything on pallets). "Unassigned" == what's still in the sorting area (plus pre-feature stock). Picking: a *sealed* pallet can never
  be picked from until someone opens it (`POST /json/order-filling/pallets/{id}/open`); only `open` pallets are picked from. A fill with no
  pallet named takes the unassigned pool first, then the oldest *open* pallets (split across them; one ledger row per source, the POST
  returns `records`); if it's still short and *sealed* pallets hold the item it is refused (422, nothing written, `sealed_pallets` lists
  what to open) rather than guessing; if no pallet holds it, it's accepted as a true over-pick with a warning (the only way unassigned goes
  negative). A pallet empties automatically when its total hits 0. `OrderFilling.vue` shows each line's "Where it is" (pool + pallets with
  status/location, Open pallet button on sealed ones, `GET /json/order-filling/stock/{itemtypeId}`). The Inventory Report's item-type detail shows quantity by location (pallet's
  *current* location) plus the unassigned remainder. This corrects the older "no item-level stock-by-location" note — but items *not*
  put on a pallet (or picked without naming one) still can't be located. Not built yet: an end-of-day "sorted but never moved" report
  sorter→pallet tracking at sort time. The end-of-day check exists: `/reports/sorting-area` (`SortingAreaReportController`, on-screen + CSV,
  no PDF/print yet) — per item sorted vs. put-on-pallets in a date range, plus what is *still* in the sorting area now and since when.
  **Inventory labels** (2026-10-22, `InventoryLabelController`, document `inventory-labels`): one label per `pallet_stock_assignments` row with qty > 0
  (what one Fill Pallet line put on a pallet), numbered `ACS:<family>-<variant>:<row id>` (`App\Support\InventoryLabelCode` — full 4-digit family and 2-digit variant,
  `parse()` returns the row id for a future scan), showing the ACS item number, item type name, qty, earliest expiration and pallet. The assignment now
  carries its own `expires_on` (Fill Pallet sends one per line; the pallet's `earliest_expiry` is still its overall minimum; rows from before are null).
  Prints on `LabelSetting::inventoryMedia()` (default `label_small`, set next to the pallet label size on `/setup/printers`; shared prop
  `printing.inventoryLabelMedia`). The ~230-module Code 128 won't fit 2.312 in across, so on the compact layout it is rotated to run down the 4 in length;
  `App\Support\LabelBarcode` draws every bar in whole 203-dpi dots with a quarter-dot inset (Ghostscript without anti-aliasing otherwise grows each bar
  by a dot) — `InventoryLabelTest` rasterizes a label and fails if any bar or gap isn't a whole number of modules. A label shows quantities as added; later
  picks/corrections aren't reflected. The scan-an-inventory-label-to-find-the-stock screen isn't built. After Fill Pallet the result line offers Print Item
  Labels; a scanned W pallet lists its labels (`labels` on the pallet lookup/payload) for reprinting.
  **Pick path & pick plan:** the admin orders all locations once at `/setup/locations/pick-path` (`PickPath.vue`, drag-and-drop +
  "auto-order by aisle, snaking"; saves `locations.PullSequence` as 10,20,30… via `PUT /json/locations/order`). `App\Services\PickPlanner`
  turns a line's need into steps — sorting-area pool, then WHOLE sealed pallets (oldest first, while the need ≥ the pallet's qty, so
  they ship intact), then open pallets oldest first, then the oldest sealed pallet opened for the last of it — to minimise broken
  pallets in a shipment; steps/lines come back in pick-path order. `GET /json/order-filling/{id}/plan` feeds `OrderFilling.vue` (lines
  sorted along the path, a "Pick:" row per line with Use / Open pallet), and `pickSheetsBuilder` plans the whole print batch together
  (`planMany`, reserving as it goes) and prints Where per line. A named sealed pallet may be picked only if the pick takes the whole
  pallet (recorded sealed→open→empty); partial picks need it opened first. Plans are advisory — nothing is reserved between calls.
- **Barcode scan → item** (2026-10-21, `UpcController` + `App\Services\Upc\{UpcResolver,UpcLookupService}` + `App\Helpers\Gtin`,
  UI in `DonationSorting.vue` via `SearchSelect`'s `scanDigits`/`scan`) — Enter on an all-digit 8/12/13/14-character entry calls
  `GET /json/upc/resolve`. There is **no separate table for single-item UPCs**: a manufacturer UPC lives on a SKU row of the master item list
  (`items.upc`, Setup > Edit Master Item List — many SKU rows, each with its own UPC, under one ACS item type), matched in any spelling
  (every code is normalized to 14 digits by `Gtin::normalize`, so UPC-A/EAN-13/GTIN-14 are one key). A **carton** (a 14-digit GTIN with indicator 1–8) is mapped, not added as a product: `carton_codes` (`code` → the single-unit
  `item_id` it contains + `qty_per_carton`, entered as packs × items per pack, 6 × 5 = 30) is checked first, and a hit selects the
  *contained* item row with the quantity filled in — so carton and single scans land on one row and "Combine duplicate lines" merges
  them. (An older-style case SKU row — its own `items.upc` with `case_qty` > 1 — still works: the match returns `qty` = `case_qty × size`.)
  A miss runs the check digit (bad = misscan, 422),
  classifies the code (14 digits with indicator 1–8 = carton; else single unit — a retail multipack with an ordinary UPC is told apart
  only by the person), guesses a carton's single-unit code (`Gtin::guessEachCode`, GS1's one-item-case convention — often right,
  e.g. 10845561000871 → 845561000874, often not, e.g. 10842515008167 vs 842515006715, where only the manufacturer prefix is shared, so
  `candidates` lists items with that prefix) and, unless a single-unit row is found, asks Open Food Facts then Open Products Facts for a *suggestion* (`config/upc.php`; sends only
  the barcode; ~10 requests/min install-wide under their 15/min/IP ban line; found cached 30 days, "none" 3 days, outages/throttling never
  cached; off in Offline Mode or `RI_UPC_LOOKUP=false`; never throws). The sorting page then opens its normal **Add New Item** modal
  prefilled (UPC, suggested description); an unknown carton opens the **New Carton Barcode** dialog instead (scan one of the singles inside —
  or pick it, or choose a same-manufacturer candidate — then packs × items per pack; `POST /json/upc/cartons`). **Nothing is stored until
  the sorter saves**, via `POST /json/items` / `POST /json/upc/cartons`. Lookups and confirmations are `manage-sorting`; carton codes can be
  listed/removed via `GET/DELETE /json/carton-codes` (`manage-items`, no admin page yet). The online lookup is a separate, slower request
  (`/json/upc/suggest`) so the "not found" tone and the form don't wait on the network. Items hold one UPC each, so two barcodes for the
  same product are two rows.
- `pallets` / `PalletStatus` — pallets get a printed barcode label and a status history; they are the
  unit that donation sorting scans to establish provenance. `container_type` widened over time to
  `pallet|gaylord|box|bag|tote` — a non-palletized arrival still gets a printable, trackable label.
  Receiving no longer collects per-pallet contents (`content_description`/`content_item_id` stay on the
  model/columns for historical data and Sorting's own display, but the Receiving UI never writes them
  anymore) — identifying what's actually in a container means opening it, which is Sorting's job, not
  Receiving's. The donation-level `quick_sort_candidate` boolean is Receiving's replacement: a rough,
  visible-only dock judgment call ("is this mostly one item, eligible for sorting's express lane?"), not
  a per-pallet catalog tag. `drivers` is a separate lightweight table (name/phone/carrier, optionally
  linked to a `Person` via `person_id` for the "driver is also the donor" case) — deliberately not
  `Person`, since drivers aren't staff/donors/customers and don't need permissions. `orderdonations` also
  carries `contact_person_id` (the person to contact about *this shipment*, reusing the org-contact model
  — `is_organization`/`parent_person_id`/`contact_role` on `Person` — rather than free text), a JSON
  `container_types` array (`['pallet']`, exclusive, or any subset of `box`/`bag`/`tote`/`loose` — a mixed
  load can be several of the latter at once) with a matching `container_type_counts` JSON map of
  type → quantity, and `photo_path` (a single reference photo of the shipment, served through
  `ReceivingController::photo()` the same way `FeedbackReport` screenshots are).
- `donation_offers` / `donation_offer_status_log` — pre-arrival tracking for a donation offered by phone
  before anything ships (not every donation goes through this; a walk-in `Transaction` can exist with no
  offer behind it). Lifecycle: `offered` → `refused`/`diverted` (terminal) or `pending` (accepted, ETA-
  sorted worklist) → `cancelled`/`received`. `DonationOffer::transitionTo()` (mirrors `Pallet::
  transitionTo()`) writes the status column and appends a `donation_offer_status_log` row — one append-
  only log table for every transition (who/when/how/notes), not per-status column pairs, following the
  same pattern as `FeedbackReportStatusLog`. Matching a `pending` offer to the real `Transaction` happens
  either at Receiving intake time (`ReceivingController::store()` accepts an optional
  `donation_offer_id`) or after the fact from `/receiving/offers`'s worklist — receiving doesn't have to
  wait on an exact match at the dock. Decision actions (approve/refuse/divert/cancel/match) are gated on
  `manage-donation-offers`, deliberately separate from `manage-receiving` (logging a call is looser than
  deciding it) and not granted to every role by default.
- `people` / `roles` / `people_roles` — a single `Person` table replaced a dropped `users` table (see
  migration `2025_02_20_155051_drop_users_table.php`); auth is on `Person`, not a `User` model (both map
  to the same `people` table/row — `User` is what `Auth::user()` returns, `Person` is the general party
  record; they share permission-resolution logic via the `HasPermissions` trait rather than a common
  class). **Credentials live in the accounts schema, not on `people`** (2026-10-27, multi-warehouse phase 0b):
  `accounts` (email, password hash, verification, lock, `is_instance_admin`, `auth_epoch`) + `memberships` (account →
  warehouse → person, the only record of who belongs where); `people.account_id` is a cached link and `people.email`
  mirrors the login email. `password` and `email_verified_at` on Person/User are virtual attributes backed by the account
  (`Concerns\HasAccount`, which overrides `save()`/`delete()` — not model events, so `Event::fake()` can't skip it — and
  refuses a signed-in user changing *someone else's* login unless this warehouse is its sole home). Login goes through
  `App\Auth\AccountUserProvider` (account first, then this warehouse's person via `memberships`); a User is "has an account,
  or is a trainee". Reset tokens and login throttling are in the accounts schema; a password change/reset/lock bumps
  `auth_epoch`, which ends the account's other sessions (`AccountSession`, `EnsureAccountSessionCurrent`). Backups carry
  `database/accounts.json` (`WarehouseAccounts`), relinked after every restore. See MULTI_WAREHOUSE.md §6/§18.
  `people_roles` links a person to zero or more `roles`; `permissions` / `role_permissions` /
  `person_permissions` implement the granular permission-key model described above. There is no
  `role_bitpack` column — it was dropped when the permission-key model replaced it. Neither `first_name`
  nor `last_name` is required — a disaster-response donation often arrives with only an organization known
  (or, via the seeded `system_key = 'unknown-donor'` Person, not even that); `PeopleController` requires
  at least one of first_name/last_name/organization via `required_without_all`, and
  `Person::getFullNameAttribute()` falls back to organization when no personal name is set. A Person with
  a non-null `system_key` is system-provided (currently just the one "Unknown Donor" placeholder) and
  can't be deleted (`Person::isSystem()` guard in `PeopleController::destroy`) — `system_key` is
  deliberately not in `$fillable`.
- `MenuItem` — drives the main nav and supplies `getBreadcrumb($path)` used by page routes.

### Frontend: Inertia pages + a shared CRUD form framework

`resources/js/Pages/*.vue` are Inertia page components (routed 1:1 from `routes/web.php`).
`resources/js/Layouts/AuthenticatedLayout.vue` / `GuestLayout.vue` wrap them.

Most CRUD-style admin pages (Items, Categories, Locations, People, Units, Receiving, ...) are built on
`resources/js/Components/RIForm.vue`, a generic list/detail form component bound to a `/json/...`
datasource: it fetches `{records, templates}`, renders a list view via the `#thead`/`#tbody` slots and an
edit view via the `#default` slot (scoped with `{record, editing, templates}`), and does
save/cancel/delete against the datasource URL using REST verbs inferred from whether `record.id` is set.
`RISubform.vue` is the equivalent for nested child collections (e.g. order lines) within an `RIForm`.
Read the doc comment at the top of `RIForm.vue` before touching it — it documents the slot contracts.
RIForm surfaces save/delete errors inline (`saveError`) and uses a two-step inline delete confirm (no
`window.confirm`); its detail-view buttons sit in a `.ri_formactions` flex action bar styled in `app.css`.
It also supports an optional `filter` prop (`(record) => boolean`) plus a `#listactions` slot for
whatever filter UI a page wants above the list (search box, checkbox, ...) — `Receiving.vue` is the
reference implementation (donor search + a "flagged for donor ID only" toggle). A separate `#titleactions`
slot (added 2026-08-22) renders in the title bar itself, next to the default New Record-style button —
for a page-level navigation button that should sit alongside it rather than down in `#listactions`
(`Receiving.vue`'s "Donation Offers" link to `/receiving/offers`); it's wrapped in a `.ri_titleactions`
span (float + margin, styled in `app.css`) so the spacing lives in RIForm itself, not per-caller. There's
still no pagination in RIForm (a known gap), so lists that only grow are bounded server-side instead — pallet history newest 300, pallets live-only unless a kind/status or `?all=1`, offers open + 150 closed (`ListPayloadLimitsTest`); do the same for any new history-style list, and `selectRecord` selects by the record object itself,
not list position, so it stays correct under filtering. RIForm also supports an optional `#actions` slot
(scoped: `editing, record, confirmingDelete, save, cancel, delete, keepRecord`) replacing its default
Save/Cancel/Delete/Back-to-List bar for a page that needs a different action flow — `save(keepOpen)`
behaves like the default Save button, except `keepOpen: true` leaves the form open on the saved record
(refreshed with the server's response, e.g. to pick up a newly-assigned id) instead of returning to the
list. Omitting the slot keeps every other RIForm page's behavior unchanged. RIForm also emits `select`
(with the record), `new` (with the new record — the template default, or the server's response if
`precreate`), and `saved` (with the server's record after every successful save), letting a parent reset
its own local state (e.g. a wizard step) alongside RIForm's.

**Donation sorting, order entry, order filling, and receiving are all exceptions to RIForm's
single-screen pattern**, in two different ways. `DonationSorting.vue` + `SortingSessionController`,
`OrderEntry.vue` + `OrderController`, and `OrderFilling.vue` + `OrderFillingController` all implement a
scan/keyboard-driven, autosaving flow: a session/order header is created the moment work starts (pallet
scanned / customer confirmed / filling started), and each line is POSTed to the server individually as
it's entered (`POST /json/sorting-sessions/{id}/lines`, `POST /json/orders/{id}/lines`,
`POST /json/order-filling/{id}/lines/{lineId}/fills`) rather than batching everything into one save at
the end. This is intentional — these are long-running, line-heavy entry sessions that must survive a
browser crash or network drop without losing entered work. Follow this per-line-autosave pattern (not
RIForm's save-at-end model) for any other long-running, high-line-count workflow. `OrderEntry.vue` also
splits customer selection/confirmation into its own screen before line entry — deliberately, so the
line-entry screen isn't crowded with contact-detail fields. None of these three introduce a separate
"session" table — the order/donation `Transaction` row itself is the session in every case, tracked
purely via its own status column.

`Receiving.vue` takes a lighter approach on top of RIForm itself (via the `#actions` slot above) rather
than a full custom rebuild: a local `wizardStep` (`'details' | 'photo' | 'labels'`) walks a donation
through **Details** (category, arrival details, donor/driver/contact, container composition) → **Photo**
(a file input only rendered once the record has a real id — attaching a photo needs somewhere to attach
it to, so "Add Photo" always saves first) → **Print Labels** (donations only; other categories finish
after the photo step). The Labels step auto-creates exactly the pallets/containers already declared by
quantity on the Details screen (summed via `computeContainerCount()`) instead of asking staff to
re-enter those numbers as manual batches — it's idempotent, topping up only whatever's still short if
re-entered, and a manual "Add Label(s)" line stays available underneath for one-off corrections.

In-app Help guides (`resources/js/Pages/Help/*.vue`) are built on `Components/HelpPage.vue`, which always
renders the "Print these instructions" `PrintMenu`. They are reached from a "Help: <guide>" text link on the page they
describe (`Components/HelpLink.vue`, `<HelpLink guide="receiving">Receiving Guide</HelpLink>`, opens in a new tab — RIForm
pages put it in `#listactions`, the others next to their title; the nav-bar ❓ of 2026-10-22 was replaced by these on
2026-10-23, and a test fails if a guide isn't linked from any page); one `/help/{guide}` route serves every guide — and from the main-menu
**Help** tile (restored 2026-10-23, a direct link, not a sub-menu page), which opens `/help` (`Help/Index.vue`): every guide in
`GUIDES` with Open/Print, plus **Print all guides** — one PDF, `/report/help/all` / print document `help-all`
(`HelpReportController::allBuilder`, `reports/help/all.blade.php`: a contents list, then each guide's own `content` section
on a new page, so there's no separate copy to keep in sync). Adding a guide: add its slug to
`HelpReportController::GUIDES`, put a `<HelpLink>` on its page, write `resources/views/reports/help/<slug>.blade.php` extending
`reports.help.layout`, and wrap the page in `<HelpPage guide="<slug>">` — the `/report/help/{guide}` route,
the `help-<slug>` print-queue entry and the button all follow from `GUIDES`. The PDF content is a hand-kept
copy of the Vue page's, so edit both.

Camera scanning (phones/webcams) is one `ScanButton.vue` per scan screen: it shows a `QrScanner.vue` (`html5-qrcode`) overlay that
closes on the first read, then types the code into the page's scan field as a wedge scanner would (`scanInject.js`) — no per-page camera
handlers; needs HTTPS. Keyboard-wedge USB/Bluetooth scanners work via plain text input + Enter-to-submit. Details: `SCANNING_INTERFACE_NOTES.md`.

**Building or changing any scanner-driven screen (sorting, picking, receiving)? Read `SCANNING_INTERFACE_NOTES.md` first** — how
keyboard-wedge scanners really behave, the audio-feedback vocabulary (`scanSounds.js`), keeping the scan target focused,
making Qty-style fields safe when a barcode lands in them, `SearchSelect`'s `smartOpen`/`debounce`/`scanDigits`, never losing a
pending line on exit, and a checklist for a new scan screen. `DonationSorting.vue` is the reference implementation.

### Flow, Donor and Partner reports (2026-10-22)

Three reports, each built like the Unfulfilled Requests Report: a controller with `index` / `pdf` / `pdfBuilder` / `csv`, a public
`FILTER_RULES`, a `buildReport()`, a Blade PDF view, a `DocumentRegistry` entry and a Vue page with `PrintMenu`; all gated on
`view-reports`. Charts use Chart.js via `Components/ChartCanvas.vue` (only bar/line registered; every chart has its numbers in a
table beside it, and no dual-axis charts); the PDFs are tables only.
- **Flow** (`FlowReportController`, `/reports/flow`): everything from `item_ledgers` joined to the transaction `type` —
  donation → received (usable) and lost (outdated/trashed/diverted, not stock); order → picked; anything else (adjustments) →
  adjustments, split by `adjustment_reason`. A row's stock effect is (usable ? added : 0) − subtracted, so opening + received −
  picked + adjustments equals stock-on-hand (tested against the Inventory Report). Buckets are day/week (Monday)/month, default last
  90 days by week, refused above `MAX_BUCKETS`. Pallet moves aren't stock changes, so they're absent.
- **Partner** (`PartnerReportController`, `/reports/partners`): per partner (names shown, unlike the Unfulfilled report) requests,
  units requested/filled, shipped/delivered counts, last request, plus a county roll-up and per-request drill-down. Fill rate counts only
  finished requests (`UnfulfilledRequestsReportController::FILLED_STATUSES`); shortfall detail stays in `/reports/unfulfilled`.
- **Donor** (`DonorReportController`, `/reports/donors`): per donor, sorted units by disposition, loss % ((outdated + trashed) ÷
  everything sorted; diverted not counted against a donor), loss % of the last three donations, per-donation loss. A donor's
  **standing** (`people.donor_standing` preferred/normal/watch/decline + note/set_by/set_at, history in `donor_standing_log`) is a human
  decision set with `PUT /json/people/{id}/donor-standing` (`manage-donor-standing`, Office/Administrator by default; decline needs a
  note; the Unknown Donor can't have one; not mass-assignable). `App\Services\DonorStanding::suggest()` only *suggests* one from
  `config('inventory.donor_standing')` (starting values: needs 2+ sorted donations and 50+ units; watch ≥30% loss; decline ≥50% over 3+
  donations; preferred ≤5% over 5+ donations and 500+ units — meant to be tested and refined). Receiving shows a warning under the donor
  for watch/decline; it never blocks anything (like `donor_identification_pending`). Not built: the donor notification letter, and the
  badge on Donation Offers / People.

### Training warehouse (`/setup/training`, `manage-training`) — 2026-10-23, moved into its own warehouse 2026-10-29

For a hands-on class (cs26): 6 stations (1 Receiving, 2 Sorting, 3 Inventory, 4 Picking, 5 Shipping, 6 Delivery/Ordering),
5 teams, two 50-minute segments — team N starts at station N, each slot every team moves on one (6 → 1), Round 1 activities in
segment 1, Round 2 in segment 2 (`App\Support\TrainingGuide` is the single source for the printed team sheets).
**Training is a warehouse, not a mode** (MULTI_WAREHOUSE.md §12). A training warehouse is an ordinary warehouse with `is_training`
(`php artisan warehouse:create "<name>" --training`, then the usual setup code makes the trainer its Administrator); it is the only
place the class features exist, and `TrainingMode::active()` (the current warehouse is training) is the one test. The old switch
is gone: the permission cap, the `training_login_person_id` session marker, the "needs a full login" page, the reset-point backup and
`training_settings.enabled` were removed. **Off** is the warehouse's `suspended` status (`warehouse:training disable|enable <id>`): it then refuses
everything (logins, `/join`, `/training-login`, machine URLs) and leaves the chooser, data kept. Nothing about a class can touch a real warehouse.
- **In a real warehouse the class tools are inert:** `HasPermissions` drops `manage-training` unless the warehouse is training (so its pages, menu
  tiles and routes aren't there), the activity log records nothing, `/join` and `/training-login` don't open, Prepare Training refuses, and the Trainee
  role is hidden from the user lists (migration `retire_training_mode_switch`). The schema (`people.is_trainee`, `activity_log`, `training_*`) is the
  same in every warehouse, since every warehouse runs the same migrations; it is just empty and unused outside a training one.
- **Tap-login:** an approved `TrustedDevice` (the same approval PIN unlock uses) sends `/login` to `/training-login`
  (`TrainingLoginController`, `Auth/TrainingLogin.vue`): trainee name tiles grouped by team, tap → confirm team → logged in, no password; **Add New**
  creates one on the spot. A trainee has what the **Trainee** role grants (`TrainingMode::TRAINING_KEYS`; no cap on top of it: the warehouse is
  disposable). Trainees are `people.is_trainee` + `training_team`, with a placeholder `trainee-<id>@training.invalid` login email, no password and no
  account. A yellow strip in `AuthenticatedLayout` says "Training warehouse" (shared prop `warehouse.training`).
- **Reset** (`App\Services\TrainingReset`, `training:reset`, `warehouse:training reset <id>`, Setup > Training Warehouse, `manage-training`, typed `RESET`): drops
  and rebuilds *that one warehouse* with its own database user (no dump/restore, no pre-class backup, no maintenance mode for anyone else; status
  `restoring` meanwhile). First a **class archive** (sign-ups CSV, report cards PDF + CSV, certificates) is saved to the warehouse's files, which a reset keeps
  (with `training-signatures/`); every other upload goes. A **snapshot file** in `<backup dir>/warehouses/<id>/training/` (outside the database, so a crash can be
  finished by running the reset again) keeps who logs in (name, account, roles: they come back as new person rows on the same accounts, and their memberships
  follow) and the certificate details. Everything else — trainees, sign-ups, practice records, the activity log, catalog items added, approved devices — is gone. On
  failure the warehouse stays `restoring` with the reason in `reset-status.json`.
- **Activity log** (`activity_log`, `App\Services\ActivityRecorder`) — recorded **in training warehouses only**, from model events (Transaction
  created/status changes, ItemLedger fills/sorted rows, PalletStockAssignment, W-pallet PalletStatus rows, donation offer
  log rows), not controller edits, so every path is covered. Actor = `Auth::id()`; nothing is logged with nobody logged
  in (artisan, simulator) except a Driver Portal BOL upload (session `driver_id`). Team is snapshotted from a trainee's
  (or team driver's, `drivers.training_team`) team. Never throws.
- **Report cards** (`TrainingReportController`, `/reports/training`): per trainee per station in a date range (default
  today); a station is done with ≥1 action; a team driver's delivery counts for everyone who worked on that team.
  Certificates (landscape PDF, no station list) for trainees who did all six, showing the event title and location
  entered on the Training Warehouse page (`training_settings.event_title`/`event_location`, `PUT /json/training/event`); report-card PDF + CSV.
- **Training products** (2026-10-23, `/setup/training/products`, `TrainingProductController`, `TrainingProducts.vue`): the
  trainer scans the *real* goods that start out "already in the warehouse" (Stations 3–5), one unit per scan (UpcResolver,
  catalog only; an unknown barcode opens the shared `Components/NewItemModal.vue` — extracted from `DonationSorting.vue`,
  which uses it too). `training_products` (item_id → qty) is a list of props, **not stock**. Receiving's goods are the
  trainer's own and never scanned. The page shows progress against `TrainingPreparer::plan()`.
- **Prepare Training** (`TrainingPreparer`, refused outside a training warehouse, and with fewer scanned units than
  `plan()['target']`): only the first team at each station is staged, since teams move N → N+1 and then work on goods they
  handled one station earlier (`config/training.php` `stage`). Station 1 gets pending offers only (the trainer's own goods
  arrive for real); Station 2 gets nothing (it sorts what Receiving brings in — no R pallets are made); 3 scanned goods sorted
  into the sorting area; 4 sealed W pallets at table spots `A-1`…`C-4` (`spotCodes()`) — plus every scanned unit beyond the
  target — and Ready to Fill orders they can cover; 5 Filled orders; 6 nothing (each team delivers the load it shipped to its
  own team driver). Every scanned unit appears in the summary exactly once (tested). Through the real models, one transaction,
  ActivityRecorder paused; also retires the old `TR-DOCK`/`TR-SORT`/`TR-A-nn` locations (deleted if unused, else archived).
  Summary in `training_settings.prepared_summary` → the **setup packet** (offers, placement per station, a contents slip per
  tray/load, and a plain "Station N / Name" sign per station), the training pallet labels (the W trays,
  `PalletReportController::trainingLabelsBuilder`) and the **table spot labels** (`training-spot-labels`, pallet label stock,
  barcode = the location code). Running again adds another set.
- **Self sign-up** (`/join`, `TraineeSignupController`, `Join.vue`, public; QR code + poster + follow-up CSV on the Training
  Warehouse page): only in a training warehouse; first + last name required, then optional email, phone, project updates,
  how they'd help (checkboxes), someone to recommend, comments. Makes a trainee via `TrainingLoginController::createTrainee`
  (same name again updates that person) and keeps the answers in `training_registrations` (contact email lives there, not
  on `people.email`, which is the trainee's placeholder login). **Never logs anyone in.** A whole room shares one Wi-Fi IP,
  so the POST limit is 80/min per IP (enough for ~55 people at once) and Turnstile is the bot gate — don't tighten it to a per-person-sized one.
- Print documents: `training-report-cards`, `training-certificates`, `training-setup-packet`, `training-pallet-labels`
  (manage-training), `training-team-{1..5}` / `training-all-teams` (anyone signed in, listed on `/help`).
- Driver Portal shows staff (manage-orders) the staff view, so the Round 2 delivery is done on a device not logged in —
  the team sheet says so.

