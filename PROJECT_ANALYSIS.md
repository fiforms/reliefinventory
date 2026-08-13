# Relief Inventory — Codebase Analysis & Completion Plan

*Analysis date: July 9, 2026 — branch `master` @ d954362. Corrected/updated Aug 4, 2026 to reflect the scan-driven sorting rewrite and Part 5 planning — see inline notes below marked "Update (Aug 2026)".*

## Executive Summary

The project has a solid foundation: a well-normalized database schema (transactions/ledger design, pallets with status history, people/roles, menu-driven navigation), a reusable form framework (`RIForm`/`RISubform`/`ComboBox`), and working pages for order entry, donation sorting, pallet management, and setup screens.

**Update (Aug 2026): the signature feature is now wired.** Donation sorting was rewritten as a scan-driven session (`SortingSessionController` + `DonationSorting.vue`) since this analysis was written — the sorter scans (or types) a pallet tag once per session, and every line entered afterward carries that `pallet_id` automatically. **Source traceability — donor → pallet → sorted item — is functional end to end today**, including a working live demo path: create a pallet → print its label (PDF) → start a sorting session → scan/select the pallet tag → add item lines with disposition. Point 2 below is resolved; points 1 and 3 are still largely accurate.

The application is roughly **40–50% of the way** to the stated vision. The remaining problems fall into two groups:

1. **Broken plumbing (narrowing)** — most of the originally-flagged breakage (donation/users-table reference, item creation, warehouse route prefixing) has since been fixed; what's left is the dead menu links, item update/destroy routes, and the role middleware's bitmask correctness — see corrected items in Part 1.
2. **Missing workflows** — order filling, BOL generation, distribution-point applications, delivery/receiving with signed BOL upload, and all reports (including basic stock-on-hand) do not exist. Several of these are linked from the main menu and 404. Part 5 (added Aug 2026) expands this into a much larger vision — a network of facilities (not just one warehouse), FEMA-compliant volunteer hour tracking, and a fair-share request allocation engine.

---

## Part 1 — Defects in Existing Code

### Critical (workflow-breaking)

**Update (Aug 2026): items 1 and 3 below have since been fixed and item 2 partially fixed** — the donation/sorting flow was rewritten as the scan-driven session model (`SortingSessionController`) after this analysis was originally written, and it correctly uses `person_id_user`/`people` throughout (`DonationSorting.vue` also correctly renders `entered_by`/`person`, not `user.name`). Left struck-through rather than deleted so the doc keeps an accurate record of what was fixed and when.

| # | Issue | Location |
|---|-------|----------|
| ~~1~~ | ~~Donations validate against the dropped `users` table.~~ **FIXED** — `SortingSessionController` (which now owns donation creation) and `Transaction` correctly use `person_id_user`/`people` throughout. Note `DonationController` still has the old `Transaction`-based CRUD shape and appears unused by any current page (no `Donations`-consuming Vue page found) — worth confirming it's dead code and removing rather than leaving two divergent donation-creation paths. | `app/Http/Controllers/SortingSessionController.php`, `app/Http/Controllers/DonationController.php` |
| 2 | **Item update/destroy routes point at methods that don't exist.** `ItemController::store` now exists and works (used by the sorting page's quick-add-item flow) — **partially fixed** — but `PUT/DELETE /json/items/{id}` still route to `update`/`destroy` methods the controller doesn't implement. Any edit/delete call to an existing item still errors out. | `routes/web.php` (items routes), `app/Http/Controllers/ItemController.php` |
| ~~3~~ | ~~Warehouse routes are double-prefixed.~~ **FIXED** — both `/json/warehouses` routes are now correctly single-prefixed. | `routes/web.php` |
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
| **Sorting scans the tag so source is trackable** | ✅ Working (Aug 2026 update) — `DonationSorting.vue` scans or types a pallet tag once per session; every line saved afterward carries `pallet_id` automatically |
| Applications for approved distribution points | ❌ Missing — people/roles exist, but no application form, approval workflow, or distribution-point entity |
| Accept & process orders from distribution points | 🟡 Partial — staff can key in orders (OrderEntry); distribution points cannot submit their own; no approval gate |
| Order filling / picking | ❌ Missing — menu item exists, page doesn't; the "Order Filled Line Items" subform on OrderEntry is a stub with no stock awareness |
| BOL creation & printing | ❌ Missing — only a pallet-label PDF exists (the spatie/laravel-pdf plumbing is in place to build on) |
| Log received orders / upload signed BOLs | ❌ Missing — no file upload capability anywhere in the app |
| Reports (inventory, flow, donors, customers, outstanding orders) | ❌ Missing — all six report menu links are dead; there is no stock-on-hand calculation anywhere |
| Multi-warehouse | 🟡 Table + CRUD exist (behind the broken double-prefixed routes); nothing else references warehouses |

The deepest structural gap: **nothing ever computes inventory on hand.** The ledger design (qty_added / qty_subtracted) supports it, but no endpoint, page, or report aggregates it — so order filling can't check stock, and no one can answer "what do we have?"

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

---

## Part 5 — Facility Network Expansion (planning session, Aug 2026)

The original design (Parts 1–4) assumed a single warehouse serving distribution points that place staff-keyed orders. The actual need is broader: a **network of facilities** — dedicated warehouses, church/school sites holding donated resources, and Points of Distribution (PODs) — cooperating within one disaster **incident**, where different people need scoped views (a warehouse manager needs inventory/movement/shipping status; a POD requester needs to browse availability and request quantities; a church resource contact needs to release resources on request) rather than the single flat dashboard the app has today.

This does **not** replace Parts 1–4 — sorting, ledger traceability, and stock-on-hand are still exactly as needed, just pointed at `facility_id` instead of `warehouse_id`. It re-sequences the plan by inserting foundational and feature phases between the existing ones.

### Architecture additions

- **`Facility`** generalizes the existing `Warehouse` model. `type`: `warehouse` | `resource_site` | `pod`. Two orthogonal status fields in one table: `approval_status` (`pending` / `approved` / `denied` / `blocked`) and `active_status` (`active` / `inactive`, only meaningful once `approval_status = approved`) — index both together for cheap "approved AND active" filtering. State machine: a facility is `pending` exactly once and never returns to it; `approved`/`denied` is a one-way decision out of pending; `active_status` doesn't apply outside `approved`; `denied` never becomes active; `blocked` is reachable from `approved` (active or inactive) as an edge case and clears `active_status`. No required note field, but every transition is audit-logged (who, when, optional note). Pallets, orders, and requests reference `facility_id`. **This whole approval workflow is itself optional** — see "Approval Required is a general toggleable pattern" below; a deployment or facility type can skip it entirely and treat every facility as auto-active.
- **`Incident`** — a scoping boundary above Facility, so a network only shows/notifies what's relevant to the response it's part of (a Washington-based deployment shouldn't surface North Carolina noise). Build this as a scoping concept *within one instance*; do **not** build cross-instance federation/sync now — that's a distributed-systems project on its own. For a genuinely separate incident, the existing answer already works: spin up a separate instance (each install is independently deployable).
- **Permissions: global role + `facility_assignments`.** Keep `role_bitpack` (or its Phase-0 bitmask fix) as *what* a person can do system-wide; add a `facility_assignments` table (person_id, facility_id, `facility_role`: `orderer` | `admin`) for *where* that applies and what they can do there. Being physically present/working at a facility does not imply an assignment row — only the (typically one or two) people who need ordering/facility-admin access get one; `admin` additionally manages facility-level settings (sharing_mode, item threshold overrides). A person can hold multiple assignments (e.g. requester at one POD, resource contact at one church). This needs a validating pass against a real multi-assignment scenario before being considered final.
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
- Sources: [2 CFR § 200.336](https://www.law.cornell.edu/cfr/text/2/200.336), [2 CFR § 200.334](https://www.law.cornell.edu/cfr/text/2/200.334), [DAP 9525.2 – Donated Resources](https://www.fema.gov/pdf/government/grant/pa/9525_2.pdf), [FEMA Donated Resources appeal page](https://www.fema.gov/appeal/donated-resources-2).

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
- Mark named the full pipeline as "receiving, sorting, storing, and shipping" — **Storing** (goods at rest in inventory between sorting and shipment, the existing W-pallet sealed/open/empty concept from Part 4/picking-and-inventory-inference) was named for the first time here. Working assumption, not yet confirmed: doesn't need its own top-level nav item since it's a resting state rather than an active work queue — covered by the Inventory Report + W-pallet management instead.

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
