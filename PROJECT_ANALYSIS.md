# Relief Inventory — Codebase Analysis & Completion Plan

*Analysis date: July 9, 2026 — branch `master` @ d954362. Corrected/updated Aug 4, 2026 to reflect the scan-driven sorting rewrite and Part 5 planning; Aug 12–15, 2026 to reflect the live beta (Part 6), the granular permissions model (Part 7), the Order Entry rebuild + reporting/dashboard suite (Part 8), and incomplete-intake-information handling (Part 9); Aug 21, 2026 to reflect the Receiving intake redesign (Part 10); Aug 22–23, 2026 to reflect the Outstanding Orders Report, CSV report exports, the Customer→Partner rename, and the pre-arrival Donation Offer workflow; Aug 23, 2026 to add Part 11, sequencing the full pending-design backlog into one dependency-ordered list — see inline notes below marked "Update (Aug 2026)".*

## Executive Summary

The project has a solid foundation: a well-normalized database schema (transactions/ledger design, pallets with status history, people/roles, menu-driven navigation), a reusable form framework (`RIForm`/`RISubform`/`ComboBox`), and working pages for order entry, donation sorting, pallet management, and setup screens.

**Update (Aug 2026): the signature feature is now wired.** Donation sorting was rewritten as a scan-driven session (`SortingSessionController` + `DonationSorting.vue`) since this analysis was written — the sorter scans (or types) a pallet tag once per session, and every line entered afterward carries that `pallet_id` automatically. **Source traceability — donor → pallet → sorted item — is functional end to end today**, including a working live demo path: create a pallet → print its label (PDF) → start a sorting session → scan/select the pallet tag → add item lines with disposition. Point 2 below is resolved; points 1 and 3 are still largely accurate.

**Update (Aug 12–15, 2026): stock-on-hand, reporting, and the order intake flow are now built too.** An unplanned live beta (Part 6) drove Receiving/Sorting separation, the five-kind pallet-container model, and the granular permissions model (Part 7, which fully replaces the old `role_bitpack`/`CheckRole` mechanism — item 5 below is resolved, not just patched). Order Entry was rebuilt off RIForm onto the same per-line-autosave pattern as Sorting, with a customer-confirmation screen split from line entry (Part 8). `WarehouseMetrics` now aggregates `item_ledgers` into a real stock-on-hand figure — the "deepest structural gap" called out below is closed — feeding an Inventory Report, an internal Warehouse Dashboard, and an external, PII-restricted Situation Report (all with PDF export), plus an offline Order Request Form PDF. Part 9 covers handling donations with incomplete or genuinely unknown source information, a real operational case surfaced during the beta.

**Update (Aug 21, 2026): Receiving intake redesigned into a wizard, and per-pallet item tagging removed.** Comparing against the real-world MachForm Manifest form used by the original Statesville warehouse surfaced several gaps — a driver directory, a shipment-specific contact distinct from the donor, multi-type container composition (a load can be pallets *or* a mix of boxes/bags/totes/loose), and a reference photo — all built (Part 10). The bigger structural change: Receiving no longer asks staff to identify a pallet's specific contents at intake (that required opening boxes/bags, which is Sorting's job) — replaced with a coarse, donation-level `quick_sort_candidate` judgment call, and pallet/container label creation is now auto-generated from the quantities already declared on the Details screen instead of a manual per-batch entry step. `RIForm.vue` gained a real extension point (`#actions` slot + `saveRecord(keepOpen)`) to support this without a full custom rebuild like Sorting/Order Entry's.

**Update (Aug 22–23, 2026): a report gap closed, a naming fix, and the pre-arrival Donation Offer workflow.** `/reports/orders` is no longer a "Coming Soon" placeholder — `OutstandingOrdersReportController` + `OutstandingOrdersReport.vue` list every order not yet Shipped, with PDF and CSV export; the Inventory Report gained a matching CSV export, and both share a new `ReportDownloadButton` component. The "Customer" role was renamed to "Partner" throughout (Role row + menu item text) per Tim's feedback that "Customer" reads as retail — the food-bank/disaster-relief term for this tier is "partner agency." Separately, the long-designed **Donation Offer** workflow (see the `donation-offer-workflow` design memory) is now built: a new `DonationOffer`/`DonationOfferStatusLog` pair tracks a donation from a phoned-in offer through an approve/refuse/divert decision, an ETA-sorted "pending" worklist, and matching to the real Receiving intake (either at intake time or after the fact) — with a full append-only audit trail of every transition. Lives inside Receiving (`/receiving/offers`, no new top-level nav item); decision actions are gated on a new `manage-donation-offers` permission, granted to the Office role by default.

**Update (Aug 23, 2026): the pending-design backlog got reconciled into one sequence.** Nine separate
"approved, NOT built" designs had piled up as independent memories with no ordering against each other —
**Part 11 is now the single dependency-ordered "what's next" list**; treat it as the starting point for
any future planning session in this repo rather than re-deriving priority from scratch or picking whichever
memory got mentioned most recently.

The application is roughly **60–65% of the way** to the stated vision as of Aug 2026 (revised up from 40–50%). The remaining problems fall into two groups:

1. **Broken plumbing (narrowing further)** — most of the originally-flagged breakage is fixed; what's left is mainly the still-dead `DonationController` (confirmed unused, not yet removed), `ItemController` update/destroy routes, and a handful of still-unbuilt report pages (`/reports/flow`, `/reports/donors`, `/reports/partners` — `/reports/orders` is built as of Aug 2026) — see corrected items in Part 1.
2. **Missing workflows** — order filling/picking, BOL generation, distribution-point applications, and delivery/receiving with signed BOL upload still do not exist; see **Part 11** for the sequencing across these and the rest of the pending-design backlog. Part 5 (added Aug 2026) expands the vision further — a network of facilities (not just one warehouse), FEMA-compliant volunteer hour tracking, and a fair-share request allocation engine — deliberately sequenced last in Part 11, none of it built yet.

---

## Part 1 — Defects in Existing Code

### Critical (workflow-breaking)

**Update (Aug 2026): items 1 and 3 below have since been fixed and item 2 partially fixed** — the donation/sorting flow was rewritten as the scan-driven session model (`SortingSessionController`) after this analysis was originally written, and it correctly uses `person_id_user`/`people` throughout (`DonationSorting.vue` also correctly renders `entered_by`/`person`, not `user.name`). Left struck-through rather than deleted so the doc keeps an accurate record of what was fixed and when.

| # | Issue | Location |
|---|-------|----------|
| ~~1~~ | ~~Donations validate against the dropped `users` table.~~ **FIXED** — `SortingSessionController` (which now owns donation creation) and `Transaction` correctly use `person_id_user`/`people` throughout. `DonationController` still has the old `Transaction`-based CRUD shape and is **confirmed dead code** (no consuming Vue page, routed only to avoid silently orphaning the URLs — see the route comment in `routes/web.php`). Still not removed; low priority since it's inert, but worth deleting next time this area is touched to stop carrying two divergent donation-creation code paths. | `app/Http/Controllers/SortingSessionController.php`, `app/Http/Controllers/DonationController.php` |
| 2 | **Item update/destroy routes point at methods that don't exist.** `ItemController::store` now exists and works (used by the sorting page's quick-add-item flow) — **partially fixed** — but `PUT/DELETE /json/items/{id}` still route to `update`/`destroy` methods the controller doesn't implement. Any edit/delete call to an existing item still errors out. **Still open as of Aug 15.** | `routes/web.php` (items routes), `app/Http/Controllers/ItemController.php` |
| ~~3~~ | ~~Warehouse routes are double-prefixed.~~ **FIXED** — both `/json/warehouses` routes are now correctly single-prefixed. | `routes/web.php` |
| ~~4~~ | ~~Dead menu links.~~ **PARTIALLY FIXED.** `/reports/inventory`, (Aug 2026) `/reports/dashboard` and `/reports/sitrep`, and (Aug 22, 2026) `/reports/orders`, are now real pages with real data. Nothing 404s anymore either way — the rest (`/order-filling`, `/reports/flow`, `/reports/donors`, `/reports/customers`, `/setup/users`) render an honest "Coming Soon" placeholder (`ComingSoon.vue`) instead of dead-linking, per Phase 0 item 6 below. Still genuinely unbuilt: Order Filling and the flow/donors/customers reports. | `database/migrations/2025_02_07_032546_fill_menu_tables.php`, `routes/web.php` |

### High (security / data integrity)

| # | Issue | Location |
|---|-------|----------|
| ~~5~~ | ~~Role check isn't a bitmask check.~~ **FIXED — not patched, replaced.** `role_bitpack`/`CheckRole` are gone entirely; the granular permission-key model (Part 7) replaced the mechanism rather than fixing the comparison. See "Data model core" in `CLAUDE.md` and `PermissionsSeeder`. | `app/Http/Middleware/CheckPermission.php`, `database/seeders/PermissionsSeeder.php` |
| ~~6~~ | ~~Client-controlled `person_id_user`.~~ **FIXED.** Every current controller (`OrderController`, `ReceivingController`, `SortingSessionController`) sets it server-side from `Auth::id()`; verified by dedicated tests (`OrderControllerTest`, etc.). | `app/Http/Controllers/OrderController.php` |
| ~~7~~ | ~~No database transactions around multi-row writes.~~ **FIXED** for every live code path (`OrderController::store`, `ReceivingController::createPallets`, `Pallet::transitionTo`, etc., all wrapped in `DB::transaction()`). `DonationController` (dead code, see item 1) was never fixed since it's unused. | `OrderController.php`, `ReceivingController.php` |
| 8 | **Racy hand-rolled pallet ID generation.** `PalletController::store` computes `max(id)+n` with a uniqueness scan over "created" pallets, outside any transaction/lock. Two concurrent creates can pick the same ID. The "unique last two digits" goal should be enforced differently (e.g. retry inside a transaction, or a dedicated short-code column) — overriding auto-increment IDs is fragile. **Still open as of Aug 15** — note the five-kind pallet model (Part 6) may have superseded the original "unique last two digits" requirement; verify against current `Pallet`/`PalletController` before assuming this still applies as written. | `app/Http/Controllers/PalletController.php:48-88` |
| ~~9~~ | ~~Route/middleware inconsistency on `/order-entry`.~~ **FIXED.** The page route and its JSON endpoints are both gated on `permission:manage-orders`. | `routes/web.php` |
| 10 | **`env()` used outside config files.** `routes/web.php:22-23` and `RegisteredUserController` call `env()` directly; these return `null` once `php artisan config:cache` runs in production. Move to `config/` entries. **Still open as of Aug 15** — verify against current line numbers, several routes/config files have changed since this was flagged. | `routes/web.php`, `app/Http/Controllers/Auth/RegisteredUserController.php` |

### Medium (code quality / maintainability)

- ~~No error feedback in the UI.~~ **FIXED.** `RIForm.saveRecord()`/`deleteRecord()` surface `saveError` inline; every autosave page (Sorting, Order Entry, Receiving's action posts) does the same with its own inline error state.
- ~~No delete confirmation~~ in RIForm. **FIXED** — two-step inline confirm (tap once to arm, again to confirm), no `window.confirm`. Same pattern used everywhere records get deleted.
- **Unsaved-work loss:** DonationSorting's "Create New Pallet Label" button navigates via `window.location.href`, discarding the in-progress sorting form. **Still open as of Aug 15** — verify still true, `DonationSorting.vue` has changed substantially since this was flagged.
- **No pagination**, still open. **Search/filtering is now partially fixed**: `RIForm.vue` gained an optional `filter` prop + `#listactions` slot (Aug 15) — `Receiving.vue` is the first page using it (donor search + a flagged-only toggle). Every index endpoint still returns the entire table server-side, though, so this doesn't help until a list is large enough that the initial fetch itself is the bottleneck, not just finding a row once loaded.
- **Duplicated CRUD boilerplate.** ~10 controllers repeat the same index/store/update/destroy + `records/templates` pattern with copy-pasted comments. A shared base controller or trait would cut hundreds of lines and unify error handling. **Still open as of Aug 15.**
- ~~Magic numbers: role levels `4`/`32768`~~ **FIXED** — moot, replaced by the permission-key model (item 5 above).
- **Naming inconsistencies:** `subrecord.packagetypes_id` (plural) in ItemEntry.vue vs `packagetype_id` elsewhere; `Transaction` model on the `orderdonations` table; `UseModel`; migration `2025-02-13_create-people-roles.php` uses hyphens. **Still open as of Aug 15.**
- **Repo hygiene:** the `chatgpt/` directory (prompt dumps, SQL snapshots) and `make_chatgpt_files.sh` are committed; `storage/logs/laravel.log` and `storage/pail/` artifacts are tracked; `bootstrap/cache/*.php` compiled files are committed. **Still open as of Aug 15** — not re-verified this pass.
- ~~Effectively zero tests.~~ **FIXED.** 153 Pest tests as of Aug 15, covering orders, donations/receiving, pallets, permissions, the dashboard/report metrics, PDF export permission gating, and the intake-info fixes below. No CI configured yet (GitHub Actions running `pest` + `npm run build` on push is still just a suggestion, not set up).
- **Dashboard menu is hand-rolled** hash-based navigation with a dead `$route.query.page` watcher (no vue-router is installed), and mixed Options-API/inline-listener style that diverges from the rest of the app. **Still open as of Aug 15** — note `/dashboard` (this menu) and the new `/reports/dashboard` (Warehouse Dashboard, Part 8) are two different pages despite the similar name; don't confuse them.

---

## Part 2 — Feature Gaps vs. Project Vision

Mapping the stated goals to what exists:

| Goal | Status |
|------|--------|
| Add inventory (item catalog, categories, units) | ✅ Working (via Item Types page; family/variant numbering as of Aug 2026) |
| Track location of inventory | 🟡 Partial — pallets have locations & movement history; no item-level stock-by-location view (Inventory Report is stock-on-hand only, not location-broken-down) |
| **Bulk donations tagged with barcode** | ✅ Working (Aug 2026 update, Part 6) — Receiving creates R pallets per donation, printed/scanned tag per pallet |
| **Sorting scans the tag so source is trackable** | ✅ Working (Aug 2026 update) — `DonationSorting.vue` scans or types a pallet tag once per session; every line saved afterward carries `pallet_id` automatically |
| Applications for approved distribution points | ❌ Missing — people/roles exist, but no application form, approval workflow, or distribution-point entity. Part 5 (Facility Network) designs this; not built. |
| Accept & process orders from distribution points | 🟡 Partial, improved — Order Entry (Aug 2026 rebuild) covers staff-keyed intake well (customer confirm → line-autosave → status lifecycle) plus an offline PDF order form for POD contacts to fill by hand and have staff key in; distribution points still can't log in and submit their own orders directly — that's Part 5's self-service portal, not built. |
| Order filling / picking | ❌ Missing — still just a "Coming Soon" placeholder; the "Order Filled Line Items" subform concept was dropped when Order Entry was rebuilt (Part 8) rather than built out, since filling is now understood to be a separate, later stage of work — deliberately not decided yet between a scan-driven UI and a PDF-batch approach (see `riform-vs-autosave-scope` design notes). |
| BOL creation & printing | ❌ Missing — pallet-label, Situation Report, Inventory Report, and Order Form PDFs all exist now (spatie/laravel-pdf plumbing well-exercised), but no BOL specifically. |
| Log received orders / upload signed BOLs | ❌ Missing — no file upload capability anywhere in the app |
| Reports (inventory, flow, donors, customers, outstanding orders) | 🟡 Partial, much improved (Aug 2026, Part 8; outstanding orders added Aug 22) — **Inventory Report** (on-screen + PDF + CSV), an **Outstanding Orders Report** (on-screen + PDF + CSV), and a **Warehouse Dashboard** (internal, full detail) + **Situation Report** (external, restricted, PDF-exportable) are built and live. Flow, donor-quality, and customer/partner reports are still dead "Coming Soon" links. |
| Multi-warehouse | 🟡 Table + CRUD exist; `pallets_enabled` toggle added (Part 6); Part 5's `Facility` generalization not built |

**Resolved (Aug 2026):** the deepest structural gap called out here — "nothing ever computes inventory on hand" — is fixed. `WarehouseMetrics` (`app/Services`) and `InventoryReportController` both aggregate `item_ledgers` (usable additions − subtractions) into a real stock-on-hand figure, feeding the Inventory Report, Warehouse Dashboard, and Situation Report. Order filling still doesn't check stock against it (order filling itself isn't built), but "what do we have?" now has a real answer.

**Update (Aug 2026):** the scope has expanded beyond a single warehouse to a network of facilities (warehouses, PODs, church/school resource sites) scoped to a disaster incident, with request-based distribution instead of staff-keyed orders only. See **Part 5** for the full design and how it re-sequences the phases below. Phases 0–4 here remain accurate for the single-warehouse core; treat the "N.5" phases in Part 5 as interleaved additions, not a replacement.

---

## Part 3 — Completion Plan

### Phase 0 — Stabilize what exists (≈1–2 weeks)

Fix everything in the Critical/High tables above. Concretely:

1. Convert donations to `person_id_user` (matching orders), remove `user_id`/`user()` from the `Transaction` model, fix `DonationSorting.vue` to show the person's name.
2. Add `store/update/destroy` to `ItemController` or remove those routes; fix the `/json/json/warehouses` prefix.
3. ~~Rewrite `CheckRole` as a true bitmask test; define role constants in one place (e.g. a `Role` enum) and use them in routes.~~ **Superseded by Part 7** (Aug 2026) — don't patch the bitmask comparison, replace the mechanism with the granular permissions model.
4. Set `person_id_user` from `Auth::id()` server-side; wrap all multi-row saves in `DB::transaction()`.
5. Replace pallet ID generation with a transaction-safe approach.
6. Remove dead menu items (or stub their pages with "coming soon") so the menu never 404s.
7. **RIForm UX pass:** surface save/validation errors to the user, add a delete confirmation, success toasts, and a loading state. This one component fix improves every page.
8. Housekeeping: delete `chatgpt/`, untrack logs/cache, move `env()` calls into config.
9. Start a test suite: feature tests for order/donation/pallet CRUD and the role middleware (these lock in the Phase 0 fixes). Add CI (GitHub Actions: pest + `npm run build`).

### Phase 1 — Complete the core warehouse loop (≈2–3 weeks)

The goal: a donation can be received, tagged, sorted, and counted — with full source traceability.

**Update (Aug 2026): items 1–2 superseded by Part 6 (Receiving/Sorting separation), items 3–4 done via Part 8.**

1. ~~Donation intake page~~ **superseded** — split into a dedicated `Receiving.vue`/`ReceivingController` stage per Part 6, not a single combined intake+sorting page as originally scoped.
2. ~~Wire pallets into sorting~~ **done**, exactly as scoped (scan pallet tag, `pallet_id` on every ledger line, line-by-line autosave) — see `DonationSorting.vue` + `SortingSessionController`.
3. ~~Stock-on-hand service + endpoint~~ **done** — `WarehouseMetrics::inventorySummary()` / `InventoryReportController::buildRecords()`, aggregated per item type (not yet per pallet/location — that drill-down still doesn't exist).
4. ~~Inventory report page~~ **done** (`/reports/inventory`) — current stock by category/item, with a per-SKU breakdown. No drill-down to source pallets/donors yet (donor provenance ends at sorting per design, not a data gap — see `picking-and-inventory-inference` design notes referenced in Part 6).

**Update (Aug 23, 2026): superseded as the "what's next" source.** This Phase 2/3/4 breakdown predates most
of the Part 5+ planning sessions and the standalone approved-but-unbuilt design memories (order
fulfillment, facility network, item conversion, stock source tagging, etc.) — none of those are folded in
here, and this list was never reconciled against them. **Part 11 below is the current, dependency-ordered
backlog across all of it; treat this Phase 2/3/4 breakdown as a historical record of the original
completion plan, not the live one.**

### Phase 2 — Orders, filling, and BOLs (≈3 weeks)

1. **Order Filling page** (`/order-filling`): pick an open order, show requested lines vs. available stock, scan items/pallets to fill, decrement via ledger entries with `pallet_id` so outbound goods keep their provenance. Enforce a status workflow (requested → approved → filling → filled → shipped → delivered) instead of a free-form status dropdown.
2. **BOL generation:** PDF (via the existing spatie/laravel-pdf setup) listing filled lines, ship-to distribution point, signatures block; BOL number stored on the transaction; print from the filling page.
3. ~~Outstanding Orders report~~ **done** (`/reports/orders`, Aug 22, 2026) — `OutstandingOrdersReportController` + `OutstandingOrdersReport.vue`, every order not yet Shipped, with PDF and CSV export.

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

---

## Part 5 — Facility Network Expansion (planning session, Aug 2026)

The original design (Parts 1–4) assumed a single warehouse serving distribution points that place staff-keyed orders. The actual need is broader: a **network of facilities** — dedicated warehouses, church/school sites holding donated resources, and Points of Distribution (PODs) — cooperating within one disaster **incident**, where different people need scoped views (a warehouse manager needs inventory/movement/shipping status; a POD requester needs to browse availability and request quantities; a church resource contact needs to release resources on request) rather than the single flat dashboard the app has today.

This does **not** replace Parts 1–4 — sorting, ledger traceability, and stock-on-hand are still exactly as needed, just pointed at `facility_id` instead of `warehouse_id`. It re-sequences the plan by inserting foundational and feature phases between the existing ones.

### Architecture additions

- **`Facility`** generalizes the existing `Warehouse` model. `type`: `warehouse` | `resource_site` | `pod`. Two orthogonal status fields in one table: `approval_status` (`pending` / `approved` / `denied` / `blocked`) and `active_status` (`active` / `inactive`, only meaningful once `approval_status = approved`) — index both together for cheap "approved AND active" filtering. State machine: a facility is `pending` exactly once and never returns to it; `approved`/`denied` is a one-way decision out of pending; `active_status` doesn't apply outside `approved`; `denied` never becomes active; `blocked` is reachable from `approved` (active or inactive) as an edge case and clears `active_status`. No required note field, but every transition is audit-logged (who, when, optional note). Pallets, orders, and requests reference `facility_id`. **This whole approval workflow is itself optional** — see "Approval Required is a general toggleable pattern" below; a deployment or facility type can skip it entirely and treat every facility as auto-active.
- **`Incident`** — a scoping boundary above Facility, so a network only shows/notifies what's relevant to the response it's part of (a Washington-based deployment shouldn't surface North Carolina noise). Build this as a scoping concept *within one instance*; do **not** build cross-instance federation/sync now — that's a distributed-systems project on its own. For a genuinely separate incident, the existing answer already works: spin up a separate instance (each install is independently deployable).
- **Permissions: global role + `facility_assignments`.** *(Update Aug 2026: written before Part 7's granular permission-key model shipped — "keep `role_bitpack`" below is stale, that column no longer exists. The intent still holds with Part 7's model substituted in: roles + `role_permissions`/`person_permissions` remain* what *a person can do system-wide;* `facility_assignments` *is still needed for* where *it applies.)* Keep the existing role/permission model as *what* a person can do system-wide; add a `facility_assignments` table (person_id, facility_id, `facility_role`: `orderer` | `admin`) for *where* that applies and what they can do there. Being physically present/working at a facility does not imply an assignment row — only the (typically one or two) people who need ordering/facility-admin access get one; `admin` additionally manages facility-level settings (sharing_mode, item threshold overrides). A person can hold multiple assignments (e.g. requester at one POD, resource contact at one church). This needs a validating pass against a real multi-assignment scenario before being considered final.
- **Scanning is never a hard requirement.** The scan-driven sorting design (Part 4) is a speed optimization on top of manual entry, not a prerequisite — the warehouse has run entirely on keyed-in quantities before. Every scan step (pallet tag scan, unseal scan, pick scan) must have a manual type/select equivalent; a facility with no scanning hardware or smartphones must be able to run the full workflow by typing. Audit this explicitly when Phase 1 is implemented.

### Approval Required is a general toggleable pattern, not just a Facility thing

The pending/approved/denied/blocked machinery below (Facility approval) shouldn't be mandatory — some deployments won't want it at all, and the same *shape* of control turns out to apply to more than facilities. There are (at least) three independently toggleable applications, not one:

- **Facility approval** — is this site legitimate. Defaults likely differ by facility `type` (a warehouse joining the network is a bigger commitment than standing up a POD quickly during a response) — the toggle should be scopable per facility type, not just one global on/off. Exact per-type defaults still open.
- **Donor approval** — ties into the existing donor-quality-scoring design (preferred/normal/watch/decline status, Part 4 above) rather than being a new mechanism: when on, new/unrecognized donors start pending before their donations are logged, instead of the current default (auto-accepted, reputation tracked after the fact via trash-rate reporting).
- **Recipient approval** — only relevant when a facility does *direct-to-public* distribution (bypassing PODs entirely). **Off by default** — see below.

### Point-of-distribution recipient control: tally-based by default, not an account system

Researched against actual FEMA/emergency-management doctrine rather than assumed. Standard commodity PODs (water/ice/tarps/food — distinct from the medical-dispensing kind of "POD") run with **no ID or registration required**. Control is a **tally**, not an account:

- Count vehicles/people served against a per-vehicle-or-household ration guideline — FEMA's own planning basis is "one vehicle ≈ a household of 3." Enforced by staff observation at the handout point (plus "head of household" public messaging so one person doesn't cycle through repeatedly), not a database lookup.
- **Default build for direct-distribution facilities should be a simple dispensed-count log (no names captured)**, not a recipient account/approval system — matches the tally model and is far less to build.
- **Recipient Approval Required**, when a facility turns it on, is the exception path for facilities wanting formal pre-registration or per-person approval (e.g. distributing something scarce/restricted, or serving a known vulnerable-population list) — not the baseline.
- Source-quality note: triangulated from several FEMA/county-EOP search summaries (IS-26 doctrine, Distribution Management Plan Guide 2.0) converging on the same model; the primary PDFs didn't extract as clean text, so this is solid enough to design against but worth confirming with an actual POD operator before it's load-bearing for anything compliance-sensitive.
- Sources: [FEMA — Points of Distribution (POD) glossary](https://www.fema.gov/about/glossary/points-distribution-pod), [FEMA Distribution Management Plan Guide 2.0](https://www.fema.gov/sites/default/files/documents/fema_distribution-management-plan-guide-2.0.pdf), [FEMA IS-26 course overview](https://training.fema.gov/is/courseoverview.aspx?code=is-26&lang=en).

### Stock visibility & fair-share allocation

**Update (2026-08-27): the single-warehouse core of this shipped early**, pulled forward into the
Order Filling/Picking build rather than waiting on the Facility/Incident model below — see the
`facility-network-and-allocation-model` memory's update note. What shipped: `need_level` on order
lines (self-reported, staff-entered, never a formula input), a per-itemtype `low_stock_threshold`
override, and a non-blocking "Review Allocation" panel computing the straight-proportional suggestion
live/on-demand. What's still exactly as described below and NOT built: cross-facility aggregation, a
persisted batch "allocation run," and the fuzzed three-state display for POD-type requesters (moot
until a partner-facing/POD surface exists at all).

Three honest states per item, not a fuzzed range: **Available** (above a per-item threshold, no flag) / **Limited** (below threshold but > 0, "Limited availability" flag) / **Unavailable** (zero on hand, shown honestly — never disguised as "limited"). Threshold has a global default, overridable per item. Only POD-type requesters get this fuzzing; warehouse managers always see exact counts, and coordinators requesting from a resource site see real-ish numbers since they're making sourcing decisions.

Requesting is always allowed regardless of displayed availability:
- **Revised (Aug 2026, second pass): batch-run straight-proportional allocation + a manager-facing need meter, replacing an earlier water-filling design.** The original "satisfy small requesters first" logic assumed requested quantity is a proxy for actual need — it isn't (a request for 5 can be more desperate than a request for 80). Any formula that mathematically rewards a quantity pattern is as gameable as one that rewards the opposite pattern; it just relocates the incentive to lie.
  - **Allocation runs as an explicit batch**, not continuously — a manual "Run Allocation" action, optionally schedulable (e.g. daily 8am), aggregating all open requests for an item against available + pledged stock at that moment. Required for any fairness math to mean anything: continuous allocation degrades into de-facto first-come-first-served, since early requesters get processed before later, possibly-more-urgent ones even submit.
  - **Default math is straight proportional** (e.g. 500 requested against 100 on hand → everyone suggested 20% of their ask) — simple, honest, and doesn't embed an unproven assumption about who deserves more.
  - **A 3-level need meter is manager-facing context, not a formula input**, and only appears on request lines for items already flagged Limited/Unavailable (or on freeform/other-need entries) — never on healthy-stock items, to keep it a real signal rather than a reflexive habit. Labels: **Critical** ("Immediate / high-priority need") / **Moderate** ("Would help; we're managing without it") / **Low** ("Can wait — give priority to others if stock is tight"). Deliberately avoids "urgent" for the top tier (overused, selected reflexively); "Critical" carries more real weight. The bottom tier is framed as a positive, cooperative choice, not a demotion, to encourage honest use of it. The manager sees the proportional suggestion and each line's self-reported need level side by side and adjusts by hand — need level never mathematically changes the allocation, since the moment it does it becomes worth lying about.
  - **No anti-gaming system on top of this, by design.** The system runs on trust; once that's broken there's no algorithmic fix for it, so don't try to build one (reputation scoring, need-level auditing, etc.) — a manager who sees the same site mark every request "Critical" over several cycles will notice on their own. Consistent with the existing "allocation logic not publicized to requesters" principle, now extended to need levels.
  - **Substitutions require explicit per-instance confirmation, never auto-swap.** "Willing to accept substitutes" can be set as an optional hint at request time, but the actual substitution is a separate confirm/decline step before it counts fulfilled — general willingness doesn't mean yes to *this specific* substitute. Same symmetric-approval shape as the resource-pull design.
  - Still a suggestion only — the manager has final approve/adjust authority and can fold backordered stock into the pool before finalizing.
- Requests carry a "usable within N days" window (global default, per-item override) so aggregate requested quantity is meaningful.
- Requests against Unavailable (zero-stock) items are **not** pooled into allocation — they route to a staff-facing **sourcing/procurement queue** ("500 requests for dog food, none in stock → reach out to SPCA for a donation"). If stock later arrives, these pending requests should automatically re-enter the normal allocation flow.
- **Zero-stock items must be displayed in a separate section of the request form, never intermixed with Available/Limited items** — mixing them risks a requester skimming past the "unavailable" label and assuming it's requestable like its in-stock neighbors. This section can reasonably merge with the freeform "other need" field (echoing the old free-form write-in box at the end of the legacy order form), since both are "things not in the normal stock list."
- Item requests support the standardized catalog (checkbox + qty) plus a freeform "other need" field for items not catalogued — feeds after-action reporting on what was actually needed (used historically to inform the last disaster's response).
- **Attribution lives at the line-item level, not just the request header.** Each line tracks `added_by_person_id`/`last_modified_by_person_id`; the request header keeps `opened_by_person_id`. All set server-side from `Auth::id()` (never client-supplied, per the Phase 0 fix for `person_id_user`).

### Shared requests within a facility (avoid duplicate/stacked requests)

Requests must be visible to the people with ordering access at the requesting facility, not just whoever created them — otherwise coworkers can't tell a request has already been submitted and end up stacking duplicates. Design:
- **Visibility scoped to `facility_assignments` rows with `facility_role = orderer/admin`**, not to everyone working at the facility and not to just the individual submitter.
- **At most one open request per (requesting facility, fulfilling facility) pair**, functioning as a shared cart — the first person to add an item opens it; anyone with ordering access at that facility can pick up editing it. (Defaulted to scoping "open" per facility-pair, not one-total-per-facility, so a facility can run independent open requests to different fulfilling facilities at once — revisit if a stricter single-request cap turns out to be wanted.)
- **Exclusive single-editor lock with takeover, not simultaneous multi-editing.** Only one person can be actively editing a request at a time; a second person sees "in edit mode by X" and can force a takeover, which kicks the first editor out. Pair with a heartbeat-based lock expiry of **~10–30 minutes of inactivity** (a phone call mid-order shouldn't cancel the session) so an abandoned session doesn't leave the request stuck showing "in edit mode" — takeover remains the manual backstop regardless.
- **Locking on fulfillment is whole-request, not per-line.** Once the fulfilling facility's manager begins fulfilling any part of the request, the entire request locks against further requester edits: `open` (editable, single-editor-locked while someone's in it) → `locked` (fulfillment in progress) → `closed` (fully filled).
- **A proper edit-audit log**, not just last-modified-by fields: keep `opened_by_person_id` on the header, but log every line/field change (added/removed/qty changed/status changed) to a `request_audit_log` (who, what, old/new value, when) — mirrors the facility-status audit trail already in this plan.

### Resource sharing (generalized beyond churches)

Resource pulls are symmetric in both directions: the source facility's contact/manager must approve or decline, never a bare pull. This applies not just to church/school resource sites but to **warehouse-to-warehouse mutual aid** — any inventory-holding facility can opt into sharing with other facilities in its incident network. Sharing is opt-in and configurable per facility: `sharing_mode` = `none` (default) | `selected_items` | `all` | `all_above_threshold`, with per-item overrides for the selected-items/threshold cases. Sharing mode only controls discoverability/requestability, not automatic fulfillment — the approval step still applies.

### Volunteer hours tracking (FEMA reporting) — core, on by default, still toggleable

Every facility logs hours by default; this feeds FEMA Public Assistance donated-resources/volunteer-labor reimbursement claims. **Correction (Aug 2026): earlier framed as "not optional" — that overstated it.** Consistent with the general modular-toggle philosophy (dashboard, facility approval, etc.), this defaults ON for every facility but stays switchable off — "core" describes its importance and default state, not that it's locked on. Researched against the governing regulation rather than assumed:

- **Digital records are explicitly permitted — no paper requirement.** The governing authority is **2 CFR § 200.336** (Uniform Guidance, applies to all federal grants including FEMA PA): *"The recipient or subrecipient does not need to create and retain paper copies when original records are electronic and cannot be altered."* FEMA's own Donated Resources policy (DAP 9525.2) lists "sign-in sheets, rosters, and logs" as acceptable documentation — not paper-specific.
- **What the regulation actually requires is tamper-evidence and official certification, not a medium.** Both map onto patterns already in this plan:
  1. **Tamper-evidence** ("cannot be altered") — reuse the same append-only audit-log pattern as `request_audit_log`/the facility-status audit trail; corrections write new entries, never overwrite silently.
  2. **Official certification** — FEMA requires documentation "by a local public official or a person designated by a local public official." A `facility_role = admin` person periodically certifies a batch of hours — the digital equivalent of a supervisor co-signing a paper log, and the actual compliance-critical step (not the sign-in mechanism).
- **Auto-close/confirm flow**: a forgotten sign-out is flagged `pending_confirmation`, not guessed — the volunteer confirms/corrects at next sign-in, with a manager-override backstop. Every step is audit-logged, so the certified number always traces to a real decision.
- Required record content (DAP 9525.2 / PAPPG): hours worked, work site, description of work, per volunteer.
- **No per-volunteer signature (wet-ink or otherwise) is required — confirmed directly against primary-source text (Aug 2026), not just inferred from the "digital records permitted" rule above.** Two FEMA-sourced documents were read in full: a Region "Donated Resources Criteria for Public Assistance" handout and a PAPPG-derived "Donated Resources Guide – Emergency Work" checklist. Neither uses the word "signature" for volunteer labor. Their actual requirements are content, not a signing act — full name, date, hours worked, location, description of work performed, "documented by a local public official or a person designated by a local public official." The PAPPG checklist lists "Sign-in sheet" as one checklist item among several (name, hours, location, work performed) — shorthand for an attendance record, not a mandate that each entry carry a handwritten or e-signature. This confirms the design already in this doc: append-only audit log + periodic admin certification (the one place something signature-like appears — a batch-level certification by an authorized person, not a per-sign-in signature from each volunteer) fully satisfies what's actually required.
- Sources: [2 CFR § 200.336](https://www.law.cornell.edu/cfr/text/2/200.336), [2 CFR § 200.334](https://www.law.cornell.edu/cfr/text/2/200.334), [DAP 9525.2 – Donated Resources](https://www.fema.gov/pdf/government/grant/pa/9525_2.pdf), [FEMA Donated Resources appeal page](https://www.fema.gov/appeal/donated-resources-2), [FEMA Region — Donated Resources Criteria for Public Assistance](https://www.pa.gov/content/dam/copapwp-pagov/en/pema/documents/recovery/public-assistance/forms/donated-resources/fema-public-assistance-donated-resources-criteria.pdf), [Donated Resources Guide – Emergency Work (PAPPG-derived checklist)](https://portal.floridadisaster.org/projects/FROC/Discussion%20Documents/Donated%20Resources%20Emergency%20Work%20Checklist.pdf).

### Facility sign-in kiosk (core, on by default, still toggleable — life safety, not just FEMA reporting)

Broader in scope than volunteer-hour tracking: this is a **building occupancy roster** — every person in the facility signs in, so anyone present can be accounted for during a fire/evacuation, not just people whose hours get reported. FEMA volunteer-hours reporting is a downstream use of the `Volunteer`-category subset, not the primary purpose. Same correction as above: default-on for every facility, but switchable off — not hard-mandatory.

- **Not gated by `facility_assignments`** — has to work for walk-ins with no pre-existing account (a state representative visiting once, a maintenance worker who's never been in the building), unlike ordering access.
- **Category at sign-in: `Volunteer` / `Other`**, where `Other` expands to a **per-facility-configurable list** (e.g. "State Representative," "Maintenance/Repair") plus a free-text catch-all — same admin-editable-lookup pattern as the existing `ItemType`/`PackageType` tables.
- **Optional expected-duration/departure estimate**, emphasized for `Other` (more often unplanned/one-off) and available but not forced for `Volunteer`. When given, it's the trigger for an overdue-sign-out nudge/flag — more useful than a fixed end-of-day cutoff for evacuation-headcount accuracy.
- **Always prompt to sign out on the way out** — a persistent, hard-to-miss reminder, not a buried action.
- **Check-in/out method: tap-to-select/search is the required baseline, not a barcode/QR scan.** It has to work for every visitor including first-timers, so nothing can replace it — a scan can only ever be a redundant accelerant on top of it. **Physical keychain barcode fobs are rejected**: they don't help one-off visitors, add badge issuance/printing/replacement logistics, and a lost fob is a routine failure mode that still needs the tap-search fallback anyway — cost without removing required work. A **system-generated personal QR code** (shown from a phone or printed card) is a reasonable optional accelerant later, since it reuses `QrScanner.vue` (already built for pallet scanning) at zero new hardware cost — but build tap-search first.
- **Reuses the tamper-evident-log + admin-certification pattern** above: forgotten sign-outs resolve via self-confirmation at next sign-in or manager override, every correction audit-logged, with the FEMA-reportable `Volunteer` subset periodically certified by a `facility_role = admin` person.

### Shipment scheduling / routing module

Fully optional per facility — some warehouses are pickup-only and offer no delivery at all; the module should be invisible to them, not just empty.
- **v1 (in scope): defined/static routes.** A `Route` entity (name, recurring schedule, ordered list of facility stops); shipments get scheduled against a route + date. No optimization needed.
- **v2 (explicitly deferred): suggested/optimized routing** (given pending deliveries, suggest an efficient stop order) — a real vehicle-routing problem, significant effort; if ever pursued, lean on an external mapping/directions API rather than building routing algorithms in-house.

### Facility discovery

A map/list view of active facilities (for coordinators finding the nearest POD/resource site) is valuable but flagged as likely to balloon in scope if built in-house. Prefer outsourcing — e.g. a generated Google Maps link/embed from stored addresses — over building custom geocoding/mapping.

### Onboarding & modular dashboard

- **First-login wizard, skippable, revisitable later** (not just a one-time gate): asks "what best describes you" (warehouse / resource site / POD), completes profile, orients the user to their scoped dashboard.
- **Modular dashboard**: menu items filtered by (a) enabled module/feature toggles, (b) role, (c) facility assignment/type — a POD requester's dashboard should never show warehouse-manager tooling, and disabled modules (e.g. shipment scheduling for a pickup-only warehouse) should be genuinely absent, not just empty.
- **Notifications** for approval events (facility status changes, request approved/denied, shipment scheduled) — no notification infra exists today beyond password-reset email.
- **Audit trail** for facility status changes (who, when, note) — ties to the status enum above.

### Re-sequenced phase plan

| Phase | Content |
|---|---|
| 0 | (unchanged) Stabilize what's broken today. |
| **0.5 (new)** | `Facility` (generalizes `Warehouse`), `Incident`, `facility_assignments`, per-item threshold + request-window fields, three-state availability. Schema-first, minimal UI — repoint pallets/orders at `facility_id`. |
| 1 | (unchanged) Scan-driven sorting/traceability — audited for manual-entry parity on every scan step. |
| **1.5 (new)** | Facility approval workflow (pending/active/inactive/denied/blocked + notes), skippable/revisitable onboarding wizard, modular dashboard (module toggles + role + facility assignment). |
| **1.75 (new)** | Facility sign-in kiosk (occupancy roster, all facilities, on by default but toggleable) + volunteer hours/FEMA certification workflow, plus the generalized Approval Required toggle (Facility/Donor/Recipient) and tally-based point-of-distribution recipient control — shares the tamper-evident audit-log + admin-certification pattern established in 1.5's audit trail work. |
| 2 | (generalized) Order filling becomes facility-to-facility Distribution Requests; build the fair-share allocation engine and the zero-stock sourcing queue here. |
| **2.5 (new)** | Resource sharing/pull — generalized to warehouse-to-warehouse mutual aid and church/school resource sites, with per-facility `sharing_mode` config and symmetric approval. |
| 3 | (mostly absorbed by 1.5) Remaining piece: delivery/receiving + signed BOL upload. |
| **3.5 (new)** | Shipment scheduling — v1 defined/static routes module, toggleable off for pickup-only facilities; v2 (optimized routing) explicitly deferred. |
| 4 | (unchanged, expanded) Polish + notifications infra + facility-status audit trail. Facility map/list view: outsource, don't build in-house.

## Part 6 — Receiving, Sorting Separation & Container Hierarchy (planning session, Aug 2026)

Triggered by a real scenario during the live beta (see below): a warehouse manager received 8 pallets of donated dog food with no manifest detail beyond a pallet count, and needed to inventory it coarsely on intake but distribute it precisely (by bag) later. Working through that case exposed that **Phase 1 item 1 ("Donation intake page") as originally scoped is too thin** — it assumed intake and sorting happen together. They need to be two separate stages, separated in time, each with its own dashboard. This section supersedes Phase 1 item 1 and item 2's donor handling; it does not change Phase 1 items 3–4 (stock-on-hand, inventory report) or Parts 1–5 otherwise.

**Context**: this design session happened during an actual, unplanned live beta test at a real warehouse responding to a real disaster incident (call came in 2026-08-12). Treat the operational scenarios that drove it as real production pressure-tests of the sorting/pallet design in Parts 1, 4, and 5 — not hypothetical edge cases.

### Receiving is a separate stage from Sorting, with its own dashboard

Today's `DonationSorting.vue` conflates donor entry with item-level sorting. Splitting them:

- **Receiving** (new top-level nav item, opens the Receiving dashboard): donor search-or-create, a rough container count, a free-text paragraph description of contents (the "manifest"), and optional shipment-level weight (see below). Creates the donation (`Transaction`, type `donation`) in `received` status. Fast, dock-side entry — must not block on item-level detail.
- **Sorting** stays close to today's implementation, minus donor entry — donor arrives already attached from Receiving, so the sorting session no longer needs a donor search field at all (this was already flagged as a to-do in the sorting-page design notes: donor ties to the receiving pallet at the dock, not the session).
- **Simplified top-level nav** replacing today's flat menu: **Receiving | Sorting | Orders | Shipping** — one entry point per stage of a donation's life through the warehouse, each opening that stage's dashboard. Shipping maps to the currently-stubbed `/order-filling` "Coming Soon" placeholder (Phase 2's Order Filling page) — claim the nav position now, keep it routed to the placeholder until Phase 2 is built.
- **Photo of the incoming load**: valuable, explicitly deferred — not required for Phase 1 completion, but keep on the design list. Requires new infrastructure (there is currently no file/photo upload capability anywhere in the app), not just a form field.

### Manifest weight — shipment-level, advisory, never derived into a count

A driver's manifest may state total shipment weight without a pallet-by-pallet or bag-by-bag breakdown. Capture what's actually known, at the level it's actually known, and never fabricate precision:

- `manifest_weight_lbs`, nullable, **on the donation record** (shipment-level) — this is the common case (one manifest weight for the whole truckload). Never split/estimated across pallets; pallets are rarely equal weight.
- An optional per-pallet weight field exists too, for the rarer case where pallets were individually weighed/tagged at origin.
- **Never derive an item/bag count from weight.** Weight is receipt documentation (useful for early dashboard visibility — "~35,000 lbs inbound, sorting pending" — and FEMA paperwork), not inventory. The real, ledger-true count comes only from sorting.

### Intake precision: manifest count when trustworthy, count-while-sorting when not

Resolves the original dog-food scenario. Two honest cases, not one fudged rule:

- **When a reliable per-container count exists** (donor's packing manifest, a known standard case-pack), trust it — enter that count directly at sorting rather than re-verifying by hand.
- **When no manifest exists**, count while sorting rather than guessing. This isn't extra labor: sorting already requires touching every unit to assess its disposition (usable/outdated/trashed/diverted), so tallying while doing that is additive, not a separate headcount exercise. A volunteer recount pass is unnecessary precision the design was never meant to require — pallet contents are explicitly advisory pre-sort; sorting is one of the "hard events" contents are supposed to be established at (see Part 4/pallet-container-model's "hard truth at location level, pallet contents advisory" rule).
- **Item granularity should not over-specify.** For goods a warehouse treats interchangeably regardless of exact size (e.g. "a bag of dry dog food" whether 5 lb or 35 lb), register one `Item` with `size` left null rather than one Item per exact size — avoids manufacturing precision nobody needs.

### Container hierarchy: Truck → Pallet/Gaylord → generic Container

Extends the five-kind pallet model with a tier above and a tier alongside it, resolved along **handling equipment required**, not container size or "is it technically a box":

- **Truck** (new top tier): the physical vehicle/trailer a donation arrives on. Gets received the moment it's dropped off — donor, manifest weight, rough pallet estimate, contents summary — even before it's unloaded, so it shows up on the Receiving dashboard as "waiting to be sorted" and can't be forgotten sitting in a parking lot. Lifecycle: **`received → unloaded`** (two states — unloaded is to a Truck what `empty` is to a Pallet: children fully accounted for, done). Optional nullable trailer/truck ID/number field, captured when available.
- **Pallet** (existing R/W/S/H/Q model, unchanged): stays first-class with its own model/controller/workflow, specifically *because* moving one requires a pallet jack or forklift — a real physical distinction, not just a labeling one. Gets a `container_type` sub-field distinguishing **`pallet` vs `gaylord`** — a gaylord is "just a big box" functionally but needs the same equipment to move, so it shares Pallet's table and lifecycle rather than living in the lighter generic Container model. `pallet_tag` in the sorting UI stays exactly as named; no rename needed.
- **Generic Container** (new, separate, simpler model): everything hand-liftable — box, bin, bag — that doesn't need equipment to move. Gets its own `container_type` lookup (extensible). Containment is one-directional and enforced structurally, not just by convention: Container has a nullable `pallet_id` (which pallet it currently sits on, if any); Pallet has no equivalent field pointing at a container. A box can sit on a pallet; a pallet can never sit inside a box. Pallet is the largest container inside the warehouse.
- **Per-warehouse toggle**: a small warehouse running boxes-and-bins-only shouldn't have Pallet-specific UI/controller surface cluttering its workflow. Add a `pallets_enabled` boolean to the existing `Warehouse` model (already built, currently has no settings/config columns) — cheap to add now, and carries forward cleanly when Part 5's `Facility` model eventually generalizes `Warehouse`.

### Donation status: stored, transactionally consistent, asymmetric rollup

- Donation (`Transaction`, type `donation`) gets a stored `status`: **`received → sorting → complete`** — same three-stage shape as Pallet's `received → sorting → empty`, container-appropriate terminal label for each (a donation isn't a physical thing that empties; it's a process that completes). Chosen to be stored rather than computed-on-read specifically because it needs to support fast counts and daily reports (see below) — a computed rollup would also have worked for correctness, but stored is the right call given the read pattern.
- **Rollup is asymmetric — first pallet starts it, last pallet finishes it**, not a simple mirror of any single pallet:
  - `received → sorting` fires the moment **any one** of the donation's pallets/trucks leaves `received` (first one touched).
  - `sorting → complete` fires only once **every** pallet belonging to the donation has reached `empty`.
- **Must update in the same transaction as the triggering pallet status change** (`DB::transaction()`, per existing project convention), and that update logic must live in one shared code path — never duplicated across controllers — to avoid the stored status silently drifting out of sync with its pallets.
- Add `status_changed_at` (set whenever status changes) directly on the donation, so the close-out report below is a plain indexed query rather than reconstructing history from pallet logs.

### Daily close-out: a state condition, not a timer

A donation can legitimately sit in `sorting` for days while many pallets are still being worked — that's healthy, not stale, and shouldn't be flagged. The actual neglect signal is narrower: **a donation down to exactly one non-`empty` pallet, and that pallet already in `sorting`** — i.e., probably actually finished, just never marked. No arbitrary "N days" threshold needed; the condition itself is the flag, checked once daily.

- Daily close-out view (Sorting dashboard, local management): lists every such donation with enough context to judge (donor, which pallet, last activity). Two actions: **confirm still open** (no state change — legitimately still in progress, re-list tomorrow if still true) or **close out** (correct the forgotten pallet to `empty`, which rolls the donation to `complete`).
- No "acknowledged" suppression state — if a donation is still genuinely open, reappearing on tomorrow's list is correct, not noise.

### Daily reporting — two separate outputs, one internal, one external

There is currently **no mail/notification infrastructure anywhere in the app** — `resend/resend-php` is a dependency but nothing uses it yet. This is real work to build, not a config flip.

- **Internal ops email** (local management only): today's throughput (pallets sorted, reusing the existing timestamped `palletstatus` history — no new schema needed for this figure) plus the close-out candidate list. Actions happen in-app, not via email links.
- **External throughput report** (broader distribution — potentially state/federal reps with no login to the app): aggregate numbers only, no internal operational detail (the close-out list has no place here). Must be a self-contained document, not a dashboard summary with a link — generate as a PDF via the existing `spatie/laravel-pdf` setup (already used for pallet labels), matching the FEMA-documentation bar the rest of this design targets.
- External recipients are not `people`/role-based system users (they need to receive a report, not log into the app) — needs its own lightweight recipient list, not the People/roles table.

Related design docs: the pallet-container-model, picking-and-inventory-inference, and sorting-page-design-decisions memory files carry the fuller reasoning trail for this session.

### Donation Offers (pre-arrival tracking, lives inside Receiving)

Real case: a company calls ahead to offer a donation before anything ships. Not every donation goes through this — some show up unannounced, others get weeks of advance notice — `DonationOffer` exists to track the ones with advance notice and drive an accept/refuse/divert decision before goods arrive.

```
offered ──┬─→ refused    (terminal — free-text reason, reference only)
          ├─→ diverted   (terminal here — where-to; real cross-warehouse routing waits on
          │               Part 5's facility network, not built yet)
          └─→ accepted   (who/when/how — captures ETA + transit notes, only known once
                           logistics are coordinated after saying yes)
                  │
                  ▼
              pending    (accepted, awaiting arrival — an ETA-sorted "expected donations"
                           worklist inside Receiving)
                  ├─→ cancelled  (terminal, distinct from `refused` — accepted then fell
                  │               through is different information than refused outright.
                  │               reason/notes/who/when/how.)
                  └─→ received  (matched at Receiving when goods arrive — named to match the
                                  Donation's own status vocabulary rather than invent a new
                                  word. Produces a Donation starting at the already-designed
                                  `received` status; no new donation-side status needed.)
```

Matching an arrival to a `pending` offer doesn't have to happen at the dock — if it's not obvious, receiving still proceeds and the donation is flagged for later matching (same asynchronous-resolution shape as the sorting close-out list above).

- **Audit as one log table, not per-status column pairs**: `donation_offer_status_log` (offer_id, from_status, to_status, changed_by_person_id [server-set from `Auth::id()`], changed_at, contact_method, notes) — one row per transition. Matches the tamper-evident audit-log pattern already used for facility-status changes and request edits in Part 5, and answers "who do we ask" for any transition, not just acceptance.
- **Donor history must be visible at decision time** (accept/refuse/divert screen) — past donations, past offers and outcomes, donor-quality-scoring metrics from Part 4 once built. The decision shouldn't be made blind.
- **Approval authority is a granted permission, not a hardcoded role check** — see Part 7. First concrete permission: `approve_donation_offers`.
- Mark named the full pipeline as "receiving, sorting, storing, and shipping" — **Storing** (goods at rest in inventory between sorting and shipment, the existing W-pallet sealed/open/empty concept from Part 4/picking-and-inventory-inference) was named for the first time here. **Resolved (Aug 22, 2026): confirmed no separate surface needed** — a received donation just sits in a staging area awaiting sorting, covered by the Inventory Report + existing pallet status, exactly as assumed.

**Update (Aug 22–23, 2026): built.** `DonationOffer` + `DonationOfferStatusLog` (mirroring `FeedbackReportStatusLog`'s append-only log pattern), a `DonationOfferController` with the full offered→accepted/refused/diverted, pending→cancelled/received lifecycle, and a `DonationOffers.vue` worklist at `/receiving/offers` (still inside Receiving, no new top-level nav item) — decision history (who/when/how/notes) always visible, matching this section's "who do we ask" requirement exactly. Matching works both from `ReceivingController::store()` at intake time and after the fact from the worklist, per the "doesn't have to happen at the dock" note above. Approval authority landed as `manage-donation-offers` (not the originally-sketched `approve_donation_offers` name), granted to the Office role bundle by default — see Part 7 below for the permission model this uses.

### Not everything received is a donation

The warehouse also receives non-donation shipments — equipment, supplies, other operational inbound. "Manifest" is really the general Receiving-event record; donation is one category, not the only one it handles.

- Manifest/receiving record gets a `category`: **`donation` | `equipment` | `supplies` | `other`**.
- Only `donation` proceeds into the Donation pipeline (sorting, item-ledger, donor-quality scoring) described above. **Open, not designed**: whether `equipment`/`supplies`/`other` need their own downstream tracking (closer to an asset register — what it is, condition, location, assigned-to — than a sortable consumable-goods pipeline) rather than being forced through Sorting/item_ledger, which doesn't obviously fit equipment. Needs its own design pass before being built, not an assumption that it reuses the donation path as-is.
- "Who it's from" stays on the same Person-search infrastructure regardless of category (a vendor for equipment/supplies is still just an org in `people`) — just not labeled "donor" outside the donation category.
- **Manifest log/audit view required**: a reviewable list of manifest entries, filterable by category, so `other`-categorized entries (and any entry generally) can be checked later for correct categorization.

## Part 7 — Granular Permissions Model (planning session, Aug 2026)

Surfaced by the Donation Offers design above when "who can approve a donation offer" didn't map onto the existing role tiers (Volunteer / Team Leader / Administrator). **Supersedes Phase 0 item 3** ("rewrite `CheckRole` as a true bitmask test") — don't patch the bitmask comparison, replace the mechanism.

**The problem**: today's `role_bitpack` is a single numeric level per person (`CheckRole` does `role_bitpack < $level`) — already flagged as not a real bitmask test. Roles are coarse, fixed bundles; there's no way to say "this specific volunteer, who isn't a Team Leader, should still be able to approve donation offers" without changing their whole role.

**The model**:
- **`permissions`**: id, key (slug, e.g. `approve_donation_offers`, `edit_master_items`), name, description — one row per distinct capability.
- **`role_permissions`**: role_id, permission_id — the **default** permission set a role grants. Roles stay meaningful, named bundles; this table is what each bundle actually contains, replacing a single opaque numeric level with an explicit, listable set.
- **`person_permissions`**: person_id, permission_id, granted (boolean) — **per-person override** on top of role defaults. `true` adds a capability beyond the person's roles; `false` explicitly revokes one a role would otherwise grant. Only needed where a person deviates from their role's defaults.
- **Effective check**: an explicit `person_permissions` row for permission X wins if present (either direction); otherwise fall back to whether any of the person's roles grants X via `role_permissions`.

Strictly additive to the existing `Role`/`people_roles` structure — roles don't go away, they become "a named default bundle of permissions" instead of "a single bit implying an opaque privilege level." Mirrors how Part 5's `facility_assignments` already separates *global* role from *where/what* it applies — this does the analogous thing for *which specific capabilities*, system-wide. Route/action gates become permission-key checks (e.g. `can('approve_donation_offers')`) rather than numeric level comparisons.

**First concrete use**: `approve_donation_offers`, defaulted onto whichever role(s) represent warehouse/office/receiving management, with per-person grants for volunteers who function as managers without holding that role.

## Part 8 — Order Entry Rebuild + Reporting/Dashboard Suite (build session, Aug 14–15, 2026)

**Order Entry rebuilt off RIForm.** Following the same per-line-autosave reasoning as Sorting (Part 4/6) — a real order can run to many lines, and RIForm's save-at-end model risked losing an entire order to a crash before the final save — Order Entry moved to the same pattern: a customer-confirmation screen (pick/quick-add a person, verify contact details) creates the order header immediately on confirm, then a separate line-entry screen (deliberately slim on customer detail — focus/screen-space, not just architecture) autosaves each requested line as it's entered. Orders now carry a real, server-controlled status lifecycle — `New Order → Filling → Filled → Shipped` — replacing the free-form status dropdown Part 3 called for; only `New Order` is intake-editable. An **offline Order Request Form PDF** (`/report/order-form.pdf`) closes the loop for POD/customer contacts without system access: in-stock, orderable item types only, grouped by category, with a blank quantity box per line and a freeform "Other Needs" section — printed/emailed, filled by hand, returned, hand-keyed in by a volunteer through the same intake screen. Deliberately never prints an actual stock number (see the customer-facing display rule below) — presence on the list is a request-eligible signal, not a promise.

**Reporting/dashboard suite**, built on one shared aggregation layer (`app/Services/WarehouseMetrics.php`) so nothing downstream can silently disagree about a number:

- **Inventory Report** (`/reports/inventory` + PDF) — stock-on-hand per item type (usable additions − subtractions, same rule Sorting/Order Entry already use for disposition), with a per-SKU drill-down and outdated/trashed/diverted context. Closes the "nothing computes stock-on-hand" gap called out in Part 2/the original executive summary.
- **Warehouse Dashboard** (`/reports/dashboard`, `view-dashboard` permission) — internal, full-detail: orders-fulfilled/donations-completed counts across today/7-day/30-day/all-time trailing windows with up/down/static trend vs. the prior week, live pipeline counts, orders-by-county, inventory summary, donor-quality loss rate. `view-dashboard` is the **first real Volunteer/Team Leader permission split** — Team Leader + Administrator only, not the base volunteer tier — where Part 7's permission model first gets used to actually differentiate those two roles rather than granting them identical bundles.
- **Situation Report** (`/reports/sitrep` + PDF, `view-sitrep` permission) — a deliberately restricted subset of the same data, meant to be shared outside the organization (FEMA/state liaisons): movement counts, trends, county-level order distribution (never names/orgs), coarse stock summary. No donor-quality figures (internal-only). The restriction happens server-side in `SitrepController`, never by hiding a field in the template. `view-sitrep` defaults to Administrator only — meant for lightweight external stakeholder accounts granted the permission individually via the existing per-person override, not a role bundle. **"Never show a real stock number to a customer-facing surface" is now a standing rule** (first stated for Order Entry's advisory stock hints, reused here) — apply it to any future customer/external-facing page.
- **Customer-facing display rule, restated for emphasis since it'll matter again**: exact quantities are fine for staff (`view-reports`/`view-dashboard`), never for anything a customer, POD, or external stakeholder can see. Available/Limited/Unavailable (Part 5's three-state model) is the customer-safe vocabulary when that's eventually built; a flat "in stock" boolean (the order form) or no number at all (Situation Report) works today.

Two real, previously-undetected bugs were found and fixed while building this (not part of the original scope, but both blocking it):
- `Person.county_id` was missing from both `PeopleController`'s validation rules and `Person::$fillable` — the People page's county picker had silently done nothing since it was built, on every instance ever deployed.
- `spatie/browsershot` was never actually a `composer.json` dependency (only "suggested" by `spatie/laravel-pdf`) — pallet label PDF generation had likely never worked in production. Fixed, plus the Chrome/AppArmor sandbox provisioning gap this uncovered on the actual server — see `CLAUDE.md`'s PDF section.

## Part 9 — Incomplete/Unknown Intake Information (build session, Aug 15, 2026)

A disaster-response warehouse frequently doesn't have full information at intake. Two distinct real cases, both surfaced by Mark during the live beta, both fixed:

1. **Donor known only as an organization, no contact person** ("it came from Walmart, no contact given"). Fixed at the data-model level: `people.last_name` is no longer `NOT NULL` (matching `first_name`, already nullable); `PeopleController` requires at least one of first_name/last_name/organization (`required_without_all` both ways), not both name fields unconditionally. Both donor/customer quick-add flows (Receiving, Order Entry) previously told staff to "use the organization name for both" name fields as a workaround — that workaround is gone, replaced with a real organization-only path.
2. **Donation source genuinely unknown** — not even an organization name. A blank `person_id` is ambiguous (forgot to fill it in vs. deliberately unknown), so a canonical, seeded **"Unknown Donor" Person record** (`people.system_key = 'unknown-donor'`) is selectable from the same donor search UI as any real donor, and protected from deletion (`Person::isSystem()` guard). "Matching on unknown" later — finding donations to reconcile once the real donor becomes known — works by filtering on that one record. Separately, `orderdonations.donor_identification_pending` (a plain boolean, not a status — unlike item types' `sort_hold`, it never gates anything downstream since the goods are real/usable regardless of who donated them) flags a donation for follow-up from either Receiving or an ad-hoc Sorting session; a flagged donation stays visible in Receiving's list even past `Complete` status, or the entire point of the flag (find it later) would break the moment enough other donations pass through and it aged out of the normal "open" list. The two mechanisms are deliberately independent — picking "Unknown Donor" doesn't auto-flag, and vice versa.

Building "find it later" for case 2 exposed that `RIForm.vue` had **no filtering/search capability at all** (already a known gap per Part 1's Medium list) — fixed as a shared-component enhancement (`filter` prop + `#listactions` slot) rather than a Receiving-only hack, so every RIForm-based page can add a filter bar cheaply going forward. Found and fixed a latent bug in the same pass: `RIForm` selected rows by position in its internal `records` array, which would have opened the wrong record the instant any page's list got filtered or reordered — it now selects by the record object itself.

## Part 10 — Receiving Intake Redesign (build session, Aug 21, 2026)

Prompted by comparing Receiving.vue against a real, field-tested precedent: the MachForm "Manifest" form the original Statesville, NC warehouse used for years (`MachForm_Archive/Manifest_Form_Structure_Handoff.md`). That comparison surfaced several fields the real form had that Receiving didn't — a driver directory, a shipment-specific contact, multiple simultaneous container types, a reference photo — and led to a bigger rethink of what receiving should and shouldn't be asking for.

**Field-level additions, all on `orderdonations` unless noted:**
- **Driver directory** (`drivers` table — name/phone/carrier, optionally linked to a `Person` via `person_id`) replaces free-text `driver_name`/`driver_phone`, with search/quick-add on Receiving (same UX pattern as the donor picker) and a "this driver is also the donor" shortcut for walk-up personal-vehicle donations. Deliberately not `Person` — drivers aren't staff/donors/customers and don't need permissions.
- **Shipment contact** (`contact_person_id`) — the person to contact about *this delivery*, distinct from `person_id` (the donor/org itself), reusing the org-contact model (`is_organization`/`parent_person_id`/`contact_role` on `Person`, from the person-tagging work) rather than inventing a parallel free-text pair. A picker scoped to the selected org's existing contacts (`PeopleController@index`'s new `parent_person_id` filter), quick-addable the same way.
- **Container composition** — "How did this arrive?" is Pallets (single, exclusive) vs. Other, which reveals a multi-select of Box/Bag/Tote/Loose (`container_types`, a JSON array — a mixed load can be several of the latter at once, but never combined with Pallets; enforced both client-side and server-side). Each selected type gets its own quantity field (`container_type_counts`, a JSON map); `container_count` is the derived total, computed client-side and used everywhere the rest of the page already read a single number (list column, label-creation progress).
- **`quick_sort_candidate`** (boolean) — replaces per-pallet item tagging (see below) as the receiving-level signal for whether sorting can use an "express lane": mostly one item, a quick date check and count/palletize instead of line-by-line sorting. A dock-side judgment call from what's visible, not a computed value — Sorting still makes the real determination once a container is opened. The Sorting-side consumption of this flag (an actual express lane) isn't built yet.
- **Reference photo** (`photo_path`) — one photo per shipment, stored/served the same way as `FeedbackReport` screenshots (`Storage::disk('local')`, guarded existence check). A dedicated camera-capture button (`capture="environment"` file input) alongside a normal file picker.
- Also: editable `order_date` (defaults to today, backdatable — supports the paper-batch-then-transcribe workflow the Statesville SOP review found is how sorting/receiving actually happens at scale), a `Trailer (pulled by pickup truck)` truck-size option, `Carrier` shown for every truck size that plausibly has one (not just parcel delivery) and auto-filled from the selected driver, and a structured pickup address (street/city/state/zip) replacing a single freeform text field.

**The bigger change — per-pallet item tagging removed from Receiving entirely.** The wizard's pallet-creation step used to let staff optionally tag a pallet with a specific catalog item (`content_item_id`) and description, for single-item pallets to skip line-by-line sorting later. That required identifying exact contents at the dock — which usually means opening a box or bag, decided to be Sorting's job, not Receiving's. `ReceivingController::createPallets()` no longer accepts either field (silently drops them even if a client sends them); the columns stay on `Pallet` for Sorting's own display and historical data. `quick_sort_candidate` is the replacement signal, at the donation level instead of per-pallet.

**Receiving became a three-step wizard** (Details → Photo → Print Labels, donations only for the last step) instead of RIForm's single save-at-end screen, without a full custom rebuild like Sorting/Order Entry's per-line-autosave pattern. This needed one real extension to `RIForm.vue` itself: an optional `#actions` slot (replacing the default Save/Cancel/Delete/Back-to-List bar) and a `keepOpen` option on `saveRecord()` (leaves the form open on the saved record instead of closing to the list) — both opt-in, so every other RIForm page's behavior is unchanged. The Photo step's file input only renders once the record has a real id to attach the photo to (fixing a bug where it showed but silently couldn't be used); "Add Photo" always saves first. The Print Labels step auto-creates exactly the pallets/containers already declared by quantity on the Details screen — idempotent, topping up only what's still short if re-entered — instead of the old manual "enter a batch, repeat" flow; a manual add-line stays available underneath for one-off corrections.

Also fixed along the way: `ChipSelect.vue` never carried the `ri_formcontrol` class its sibling `SearchSelect.vue` always had, which is why a long chip list (Truck Size, once it grew past 4 short options) would wrap its whole control onto a new line instead of just wrapping its chips within the aligned column — a systemic fix, not a per-page patch.

**Not done, deliberately deferred:** AI-assisted extraction from a photographed shipping label (there's a real precedent to reference, from the ChurchToolbox facility tracker); a `Warehouse.pallets_enabled`-style admin toggle for warehouses without pallets or a label printer (the `pallets_enabled` column and its JSON CRUD API already exist from earlier facility-network work, just no admin page was ever built); Sorting-side consumption of `quick_sort_candidate` (the actual express lane).

---

## Part 11 — What's Next: Sequencing the Pending Design Backlog (Aug 23, 2026)

By this point there are ~9 designs sitting in the "approved (or discussed), NOT built" state, each captured
in its own memory rather than one ordered list — genuinely useful for context on *why* each one looks the
way it does, but nobody had reconciled them against each other or against the original Phase 2–4 plan into
one "start here" sequence. This section is that reconciliation, ordered by actual technical dependency
(what unblocks what), not by when each was designed. Re-derive this ordering rather than trusting it
blindly if enough has shipped since Aug 23, 2026 that the reasoning below no longer holds — check each
item's own memory for a more recent "Update" note first.

**Tier 0 — one open decision, not a build task.** ~~Order Filling/Picking (Phase 2 item 1 above) is the
single biggest unblocker in the whole backlog (see Tier 1), but it's blocked on an actual undecided fork,
not on time: scan-driven flow vs. PDF-batch entry, same real-world "paper batch → office transcription"
pattern the Statesville SOP review found sorting already works this way at scale (`statesville-sop-review`,
`picking-and-inventory-inference` — STALE, marked with this exact open fork). Resolve this the way
Sorting's own scan-vs-batch question was resolved (a short design conversation, not a coin flip) before
writing any filling code.~~

**Update (2026-08-27): resolved — build both, not either/or.** Mark's call: some warehouses have scan
capability, some work from paper, and the two aren't mutually exclusive. Both modes share one backend (a
single "record a filled quantity for this order line" write, same as `item_ledgers` subtraction either
way) — live scan mirrors `DonationSorting.vue`'s per-line-autosave session pattern; paper/batch mode prints
one PDF per filling batch (hard page-break between orders, via the weasyprint driver already used for
`orderFormPdf`/the Inventory Report) and reconciles through a plain-number "enter actual picked qty per
line" screen afterward (a scan-assisted re-entry option, via a barcode/QR per line on the printed sheet,
was considered and deliberately deferred past v1). Either path locks the order into `Filling` the instant
it starts. Full detail in the `picking-and-inventory-inference` memory. Tier 0 is no longer blocking —
Tier 1 can now be scoped and built.

**Tier 1 — build once Tier 0 is decided; unblocks everything below it.** **Order Filling/Picking**
(Phase 2 item 1) is what actually makes `item_ledgers.qty_subtracted` real — right now nothing in the live
app writes a nonzero value there (confirmed while scoping `stock-source-tagging-and-equipment-tracking-design`),
so "current inventory" today is cumulative intake only. Everything downstream of "an order got filled" is
blocked on this:
- **BOL generation** (Phase 2 item 2) — needs filled lines to print.
- **`order-fulfillment-lifecycle-design`** (Ready to Ship, pickup-vs-ship terminus, Shipped-not-final) —
  the stage immediately after filling.
- **Usage-rate/reorder-threshold alerting**, part E of `stock-source-tagging-and-equipment-tracking-design`
  — meaningless without real subtraction data to compute a trailing rate from. (Parts A–D of that same
  design — `source_type` tagging, `item_kind`, the `Asset` model for equipment — are *not* blocked on this
  and can land independently; only the alerting part needs to wait.)

**Update (2026-08-27): Order Filling/Picking is built.** `OrderFillingController` +
`OrderFilling.vue` (`tests/Feature/OrderFillingTest.php`) — `item_ledgers.qty_subtracted` is now
written for real for the first time. Both capture modes shipped together on one shared backend (live
scan and paper/batch pick sheets — see the `picking-and-inventory-inference` memory for the full
design and what's still deliberately out of scope: pallet/location/FEFO sourcing precision, and the
case-vs-each unit-conversion gap). A right-sized piece of Part 5's fair-share allocation design (the
proportional-suggestion + need-meter panel, not the full multi-facility model) was pulled forward into
this same build — see `facility-network-and-allocation-model`'s update note. This unblocks the three
items above; none of them are built yet.

**Tier 2 — additive, no hard blockers either direction; interleave with Tier 1 or do first, whichever fits
available time.**
- `stock-source-tagging-and-equipment-tracking-design` parts A–D (source tagging, catalog `item_kind`,
  the equipment `Asset` model).
- `item-conversion-internal-transfer-design` (recount-forced item/UOM reassignment) — touches the catalog
  and ledger, not order fulfillment.
- `party-roles-warehouse-contacts-and-people-report-design` (explicit Donor/Partner role tagging,
  `WarehouseContact`, combined People & Organizations report).
- `public-needs-list-design` — conceptually could ship before Tier 1 (order lines already exist without
  filling), though it becomes more meaningful once real fulfillment history exists behind it.
- `volunteer-hours-tracking-design` — its own kiosk feature, doesn't touch orders/inventory at all.
- `menu-grouping-and-theming` — pure UI polish, smallest item in the backlog.

**Tier 3 — do last.** `facility-network-and-allocation-model` (Part 5's Facility/Incident-scoped network,
fair-share allocation) is the most structurally invasive item here — it reshapes scoping for orders,
inventory, and permissions everywhere else in the app. Building it before the single-warehouse core loop
(Tier 0/1) is solid means re-scoping all of the above a second time once it lands. Sequence it after
Tiers 1–2, not alongside them.

**Remaining Phase 3/4 items** (distribution-point self-service application + approval queue, delivery
logging + signed BOL upload, flow/donor/customer reports, pagination) still apply as originally scoped in
Part 3 above and aren't reordered by this section — they weren't in conflict with anything newer, just
not yet reconciled into one list until now.
