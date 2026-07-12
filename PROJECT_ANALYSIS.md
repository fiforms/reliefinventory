# Relief Inventory — Codebase Analysis & Completion Plan

*Analysis date: July 9, 2026 — branch `master` @ d954362*

## Executive Summary

The project has a solid foundation: a well-normalized database schema (transactions/ledger design, pallets with status history, people/roles, menu-driven navigation), a reusable form framework (`RIForm`/`RISubform`/`ComboBox`), and working pages for order entry, donation sorting, pallet management, and setup screens.

However, the application is roughly **40–50% of the way** to the stated vision. The biggest problems fall into three groups:

1. **Broken plumbing** — several routes point at controller methods that don't exist, the donation workflow still references the dropped `users` table, and the role middleware doesn't actually implement bitmask permissions.
2. **The signature feature is unwired** — pallet tagging exists (labels print, `item_ledgers.pallet_id` column exists, a QR scanner component exists), but donation sorting never records which pallet an item came from, so **source traceability — the core requirement — is not functional end to end**.
3. **Missing workflows** — order filling, BOL generation, distribution-point applications, delivery/receiving with signed BOL upload, and all reports (including basic stock-on-hand) do not exist. Several of these are linked from the main menu and 404.

---

## Part 1 — Defects in Existing Code

### Critical (workflow-breaking)

| # | Issue | Location |
|---|-------|----------|
| 1 | **Donations validate against the dropped `users` table.** `DonationController` requires `user_id => exists:users,id`, but migration `2025_02_20_155051_drop_users_table.php` removed that table. Saving a donation should fail at validation with a database error. The `Transaction` model likewise still has `user_id` in `$fillable` and a `user()` relation, and `DonationSorting.vue` renders `record.user.name` — a column that doesn't exist on `people` (it has `first_name`/`last_name`). | `app/Http/Controllers/DonationController.php:16`, `app/Models/Transaction.php:21,53`, `resources/js/Pages/DonationSorting.vue:43` |
| 2 | **Item CRUD routes point at methods that don't exist.** `POST/PUT/DELETE /json/items` are routed to `ItemController::store/update/destroy`, but the controller only implements `index()`. Any call errors out. (The Item Entry page works only because it actually uses `/json/itemtypes`.) | `routes/web.php:126-128`, `app/Http/Controllers/ItemController.php` |
| 3 | **Warehouse routes are double-prefixed.** Inside the `prefix => 'json'` groups the paths are declared as `/json/warehouses`, producing `/json/json/warehouses`. Any client calling the intended `/json/warehouses` gets a 404. | `routes/web.php:143,223-225` |
| 4 | **Dead menu links.** The seeded menu links to `/order-filling`, `/reports/orders`, `/reports/inventory`, `/reports/flow`, `/reports/donors`, `/reports/customers`, and `/setup/users` — none of these routes exist. Users clicking the main menu hit 404s. | `database/migrations/2025_02_07_032546_fill_menu_tables.php` |

### High (security / data integrity)

| # | Issue | Location |
|---|-------|----------|
| 5 | **Role check isn't a bitmask check.** `CheckRole` compares `role_bitpack < $level` numerically. With bit-packed roles, a user holding only a single high bit (e.g. bit 6 = 64) passes every check below it, and users with multiple low roles can sum past a threshold they were never granted. Should be `($user->role_bitpack & $level) == 0 → deny` (or a documented "level" scheme, but then rename it). It also implicitly allows requests when `$user` is null (currently masked by the `auth` middleware). | `app/Http/Middleware/CheckRole.php:24` |
| 6 | **Client-controlled `person_id_user`.** `OrderController::store` accepts `person_id_user` from the request body, so the "entered by" user is spoofable. It should be set server-side from `Auth::id()` (update correctly strips it). | `app/Http/Controllers/OrderController.php:14-30,79` |
| 7 | **No database transactions around multi-row writes.** Order/donation store & update create the header then loop over ledger/line children. A failure mid-loop leaves partial records; the update path deletes removed children before inserting new ones with no rollback. Wrap in `DB::transaction()`. | `OrderController.php:79-172`, `DonationController.php:66-134` |
| 8 | **Racy hand-rolled pallet ID generation.** `PalletController::store` computes `max(id)+n` with a uniqueness scan over "created" pallets, outside any transaction/lock. Two concurrent creates can pick the same ID. The "unique last two digits" goal should be enforced differently (e.g. retry inside a transaction, or a dedicated short-code column) — overriding auto-increment IDs is fragile. | `app/Http/Controllers/PalletController.php:48-88` |
| 9 | **Route/middleware inconsistency on `/order-entry`.** The page route requires only `auth`, but the JSON endpoints it depends on (`GET /json/orders`, people, items) require `role:4`. Low-role users load a page that silently fails (RIForm logs errors only to the console). Decide the intended audience and align both layers. | `routes/web.php:36-39,114-189` |
| 10 | **`env()` used outside config files.** `routes/web.php:22-23` and `RegisteredUserController` call `env()` directly; these return `null` once `php artisan config:cache` runs in production. Move to `config/` entries. | `routes/web.php:22`, `app/Http/Controllers/Auth/RegisteredUserController.php:27,55` |

### Medium (code quality / maintainability)

- **No error feedback in the UI.** `RIForm.saveRecord()`/`deleteRecord()` catch errors with `console.log` only. Users get no indication a save failed, no display of Laravel validation errors, and no success confirmation. This is the single biggest UI-quality gap and it affects every page built on RIForm. (`resources/js/Components/RIForm.vue:142-178`)
- **No delete confirmation** in RIForm — one tap on Delete permanently removes an order/donation/pallet.
- **Unsaved-work loss:** DonationSorting's "Create New Pallet Label" button navigates via `window.location.href`, discarding the in-progress sorting form. (`DonationSorting.vue:128-130`)
- **No pagination or search.** Every index endpoint returns the entire table (`Transaction::where(...)->get()`, `Item::all()`), and RIForm renders it all. This will degrade badly mid-disaster with thousands of transactions.
- **Duplicated CRUD boilerplate.** ~10 controllers repeat the same index/store/update/destroy + `records/templates` pattern with copy-pasted comments ("Store a new order" in DonationController). A shared base controller or trait would cut hundreds of lines and unify error handling.
- **Magic numbers:** `status_id => 4` hardcoded in DonationController templates; role levels `4` and `32768` scattered through routes with no named constants.
- **Naming inconsistencies:** `subrecord.packagetypes_id` (plural) in ItemEntry.vue vs `packagetype_id` elsewhere; `Transaction` model on the `orderdonations` table; `UseModel`; migration `2025-02-13_create-people-roles.php` uses hyphens.
- **Repo hygiene:** the `chatgpt/` directory (prompt dumps, SQL snapshots) and `make_chatgpt_files.sh` are committed; `storage/logs/laravel.log` and `storage/pail/` artifacts are tracked; `bootstrap/cache/*.php` compiled files are committed.
- **Effectively zero tests.** Only Breeze boilerplate (Example/Profile/Auth tests). No coverage of orders, donations, pallets, roles, or ledger math — the code most likely to regress.
- **Dashboard menu is hand-rolled** hash-based navigation with a dead `$route.query.page` watcher (no vue-router is installed), and mixed Options-API/inline-listener style that diverges from the rest of the app.

---

## Part 2 — Feature Gaps vs. Project Vision

Mapping the stated goals to what exists:

| Goal | Status |
|------|--------|
| Add inventory (item catalog, categories, units) | ✅ Working (via Item Types page) |
| Track location of inventory | 🟡 Partial — pallets have locations & movement history; no item-level stock-by-location view |
| **Bulk donations tagged with barcode** | 🟡 Partial — pallet labels print with barcode ID; but donation intake doesn't create/associate pallets |
| **Sorting scans the tag so source is trackable** | ❌ Not wired — `item_ledgers.pallet_id` column exists, but DonationSorting never sets it; the QrScanner component exists but is only used on a test page |
| Applications for approved distribution points | ❌ Missing — people/roles exist, but no application form, approval workflow, or distribution-point entity |
| Accept & process orders from distribution points | 🟡 Partial — staff can key in orders (OrderEntry); distribution points cannot submit their own; no approval gate |
| Order filling / picking | ❌ Missing — menu item exists, page doesn't; the "Order Filled Line Items" subform on OrderEntry is a stub with no stock awareness |
| BOL creation & printing | ❌ Missing — only a pallet-label PDF exists (the spatie/laravel-pdf plumbing is in place to build on) |
| Log received orders / upload signed BOLs | ❌ Missing — no file upload capability anywhere in the app |
| Reports (inventory, flow, donors, customers, outstanding orders) | ❌ Missing — all six report menu links are dead; there is no stock-on-hand calculation anywhere |
| Multi-warehouse | 🟡 Table + CRUD exist (behind the broken double-prefixed routes); nothing else references warehouses |

The deepest structural gap: **nothing ever computes inventory on hand.** The ledger design (qty_added / qty_subtracted) supports it, but no endpoint, page, or report aggregates it — so order filling can't check stock, and no one can answer "what do we have?"

---

## Part 3 — Completion Plan

### Phase 0 — Stabilize what exists (≈1–2 weeks)

Fix everything in the Critical/High tables above. Concretely:

1. Convert donations to `person_id_user` (matching orders), remove `user_id`/`user()` from the `Transaction` model, fix `DonationSorting.vue` to show the person's name.
2. Add `store/update/destroy` to `ItemController` or remove those routes; fix the `/json/json/warehouses` prefix.
3. Rewrite `CheckRole` as a true bitmask test; define role constants in one place (e.g. a `Role` enum) and use them in routes.
4. Set `person_id_user` from `Auth::id()` server-side; wrap all multi-row saves in `DB::transaction()`.
5. Replace pallet ID generation with a transaction-safe approach.
6. Remove dead menu items (or stub their pages with "coming soon") so the menu never 404s.
7. **RIForm UX pass:** surface save/validation errors to the user, add a delete confirmation, success toasts, and a loading state. This one component fix improves every page.
8. Housekeeping: delete `chatgpt/`, untrack logs/cache, move `env()` calls into config.
9. Start a test suite: feature tests for order/donation/pallet CRUD and the role middleware (these lock in the Phase 0 fixes). Add CI (GitHub Actions: pest + `npm run build`).

### Phase 1 — Complete the core warehouse loop (≈2–3 weeks)

The goal: a donation can be received, tagged, sorted, and counted — with full source traceability.

1. **Donation intake page** (`/donation-entry` — the icon already exists): record donor (person), date, description; create one or more pallets from the intake and print their labels in one flow.
2. **Wire pallets into sorting:** DonationSorting gets a "scan pallet tag" field (integrate the existing `QrScanner` component + keyboard-wedge barcode input); every ledger line saved carries `pallet_id`. This closes the traceability chain.
   - **Requirement: autosave line-by-line.** Each sorted line commits to the server as it's entered (line-level POST, not one save at the end). A sorting session can run 45+ minutes; a browser crash, tab close, or network drop must never lose entered work. This means the sorting page moves off RIForm's save-at-end model — either extend RIForm with an autosave mode or build the sorting page on a dedicated session-based flow (create the donation transaction when sorting starts, append ledger lines incrementally, mark complete at the end).
3. **Stock-on-hand service + endpoint:** aggregate `item_ledgers` (added − subtracted) per item, optionally per pallet/location. This is the foundation for filling and reports.
4. **Inventory report page** (`/reports/inventory`): current stock by category/item, drill-down to source pallets/donors.

### Phase 2 — Orders, filling, and BOLs (≈3 weeks)

1. **Order Filling page** (`/order-filling`): pick an open order, show requested lines vs. available stock, scan items/pallets to fill, decrement via ledger entries with `pallet_id` so outbound goods keep their provenance. Enforce a status workflow (requested → approved → filling → filled → shipped → delivered) instead of a free-form status dropdown.
2. **BOL generation:** PDF (via the existing spatie/laravel-pdf setup) listing filled lines, ship-to distribution point, signatures block; BOL number stored on the transaction; print from the filling page.
3. **Outstanding Orders report** (`/reports/orders`).

### Phase 3 — Distribution points and receiving (≈2–3 weeks)

1. **Distribution-point application:** a self-service form for newly registered users (org, address, county, contact); an admin approval queue that grants the appropriate role on approval.
2. **Distribution-point order portal:** approved points submit orders themselves (this is why `/order-entry` was auth-only — formalize it with its own restricted page that only shows their own orders).
3. **Delivery logging:** mark an order delivered, capture date/receiver, and **upload the signed BOL** (file upload → `storage/app`, linked to the transaction; needs a `documents` table + secured download route).
4. **Remaining reports:** flow (in/out over time), donor report, customer report.

### Phase 4 — Hardening & polish (ongoing)

- Pagination + server-side search for all index endpoints and RIForm.
- Role-management UI (replace the dead `/setup/users` link; People page partially covers this).
- Warehouse selection actually used in flows (multi-warehouse), or defer and remove from UI.
- Mobile/scanner ergonomics for the sorting and filling pages (large touch targets, auto-advance after scan).
- Expand test coverage to the filling/BOL/receiving workflows; seeders for demo data.
- Documentation: user guide per workflow, deployment guide update.

---

## Part 4 — Scan-Driven Sorting: Balancing Speed and Accuracy

### The key insight: scan pallets, not items

The speed cost of scanning depends almost entirely on *granularity*. Industry data shows a barcode scan takes under a second while keying an ID takes 5–10 seconds with a ~1-in-300-character error rate — but the real question is how many scans the workflow demands.

- **One scan per pallet, per sorting session** — the sorter scans the pallet tag once when they start, and every ledger line entered afterward inherits that `pallet_id` automatically (a sticky session default, not a per-line field). A pallet takes 30–60 minutes to sort; one scan adds ~2 seconds. This gives full donor-level provenance at essentially zero speed cost.
- **Item identification should be scan-optional.** If an item has a UPC, scanning it is *faster* than picking from a combo box. If it doesn't (loose used clothing, etc.), fall back to quick-pick buttons for the ~20 most common item types. Never make a scan blocking — always allow "unknown pallet / no barcode" with later reconciliation, so sorting never stalls on a data problem.

### Tiered precision: be exact where it matters, approximate where it doesn't

Different data has different downstream consequences, so it deserves different accuracy budgets:

| Data | Downstream use | Precision needed |
|---|---|---|
| Pallet identity (source) | Donor accountability, recalls | **Exact** — one scan, near-zero cost |
| Item type + qty of *usable* goods | Order filling, stock-on-hand | **Exact counts** — this drives everything |
| Trashed/unusable goods | Donor quality scoring only | **Coarse estimate** — count by container (gaylord/bin) or weight, not per item |

This is the acceptable tradeoff point: a donor sending 40% vs. 45% trash leads to the same action, so per-item precision on trash is wasted labor. Counting "3 bins of trash" or weighing a trash gaylord on a floor scale takes seconds. Formal QC theory (AQL acceptance sampling) supports this — 100% manual inspection is slow *and* unreliable; sampling/coarse measures achieve comparable decision-quality at a fraction of the effort.

### Schema: disposition on the ledger line

Add to `item_ledgers` (or a parallel coarse-measure table):

- `disposition` enum: `usable` | `trashed` | `diverted` (recycled/donated onward)
- Optionally `measure` (`count` | `weight_lbs` | `containers`) so trash can be logged coarsely

Because every line already links pallet → donation → donor, **the donor trash-rate report falls out for free**: `SUM(trashed) / SUM(total)` grouped by donor organization. No extra sorting-floor work beyond one extra quantity column on the sorting form ("Trash qty" next to "Usable qty").

### Donor quality scoring and the refusal workflow

Research on the "second disaster" shows 50–70% of unsolicited goods in emergencies are unneeded or unusable, and as much as half of donated food is discarded — so donor-level feedback is genuinely high-value, not just bookkeeping. Suggested workflow:

1. **Donor report page**: trash % by donor org, trended across donations, with volume context (a donor's first bad pallet isn't a pattern).
2. **Thresholds → status on the people/org record**: e.g. `preferred` / `normal` / `watch` (>30% trash over 2+ donations) / `decline`. Show the status prominently at donation intake so the receiving dock sees it before accepting.
3. **Notify before refusing.** The sector-standard approach is donor education (needed-items lists, "send money" guidance). Generate a friendly notification letter from the report ("X% of your last shipment could not be used; here's our current needs list") — refusal is the last resort, and the status field gives intake staff the authority to apply it.

### Sorting UX speed checklist (Phase 1 implementation notes)

- Keyboard-wedge scanner support: scan fills the field and auto-advances focus (scanners send Enter) — works with cheap USB/Bluetooth scanners, no camera latency.
- Camera QR scanning (existing `QrScanner` component) as fallback for phones/tablets.
- Sticky pallet context banner: "Sorting pallet P00000042 from ACME Corp — 14 lines entered."
- "Repeat last item" button and top-item-type quick buttons.
- Large touch targets; numeric keypad input mode for quantities.
- Autosave lines as they're entered (don't lose 45 minutes of sorting to a browser crash — the current save-at-end RIForm model is risky for this page).

### Suggested priority rationale

Phase 0 is first because several "existing" features are silently broken and every later phase builds on RIForm and the role system. Phase 1 before Phase 2 because order filling is meaningless without stock-on-hand, and stock-on-hand is meaningless until sorting actually records what came in and from where.
