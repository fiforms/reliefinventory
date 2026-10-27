# ROADMAP.md

Future development ideas for Relief Inventory. **Nothing here is built.** This file records intent so ideas
aren't lost; it is not a commitment or a schedule. For the audit of what exists and the dependency-ordered
backlog, see `PROJECT_ANALYSIS.md` (especially Part 5, the facility network, and Part 11, sequencing) and
`TODO.md`. When an idea ships, move its description into `CLAUDE.md` and delete or mark it here.

Anyone may add or edit an idea here freely. Before anyone builds one, it becomes a proposal in `proposals/` that
both developers agree on; see `proposals/README.md` (bugs, suggestions, ideas, and who owns which design lane).

Added 2026-10-23.

---

## 1. Point-of-Distribution (PoD) Module — on-site, public-facing

### The idea

Today the warehouse ships goods *out* to partner organizations and distribution points, and the system's job
ends at delivery. This module lets a PoD operate **at the same site as the warehouse** (or as a place the
warehouse stocks directly), where members of the public pick up goods themselves. Inventory is tracked the same
way as in the warehouse, so one ledger covers both.

### Proposed capabilities

1. **PoD locations that hold inventory pallets.**
   Warehouse (`W`) pallets can be placed in a PoD location on the same site as the warehouse, using the existing
   pallet and location model (`pallet_stock_assignments`, `locations`). Moving a pallet from the warehouse floor
   to a PoD location is a recorded movement, like any other pallet move.

2. **Public shopping basket and checkout.**
   Customers (the public) fill a basket and "check out." Checkout is processed as an **order**, so it goes through
   the same inventory accounting as a warehouse order: fills are `item_ledgers` rows, stock on hand drops, and the
   result appears in the same reports. This reuses the order model rather than adding a parallel sales system.

3. **Reports by place, and flow through the PoD.**
   Reports can show inventory in the warehouse versus in the PoD, and track goods flowing through the on-site PoD
   (warehouse to PoD, PoD to the public).

### How it fits what already exists

- **Pallet stock by location** (`App\Services\PalletStock`, Inventory Movement > Pallets) already records where
  pallets are and what is on them. A PoD location is mostly a new *kind* of location plus rules about who may
  pick from it.
- **Orders and filling** already do the stock accounting (`OrderFillingController`, `PickPlanner`). Checkout
  would create and fill an order in one step, likely skipping the Ready to Fill / Ready to Ship / BOL stages
  that don't apply to a walk-up sale.
- **Flow report** (`/reports/flow`) already aggregates the ledger over time. Splitting it by location (or by
  warehouse/PoD) is a natural extension.
- **Facility network design** (`PROJECT_ANALYSIS.md` Part 5) already describes PoDs as facilities with their own
  approval, assignments and a *tally-based* recipient control (no ID or registration by default, based on FEMA
  PoD doctrine). This module is the **on-site** variant of that, and should be designed together with it, so a PoD
  is not modeled twice.

### Open questions to settle before building

- **Who is the "customer"?** A walk-up member of the public should not need an account. Does a checkout create a
  `Person`, use a shared "walk-in" record, or only a household tally (as Part 5 proposes)? Whatever is chosen
  must respect the existing rule that actor fields (`person_id_user`) come from `Auth::id()` server-side.
- **Who operates the checkout?** A staffed register (volunteer scans and checks out) is far simpler and safer than
  public self-service on their own device. Decide whether public self-service is a goal or only a later option.
- **Limits and fairness.** Per-household or per-visit quantity limits, and limits on scarce items. How they are
  configured, shown and enforced (hard block versus warning, like donor standing).
- **Prices.** Presumably goods are free. If anything is ever charged, that is a much larger change (payments,
  receipts) and is out of scope here.
- **Location semantics.** Does a PoD location behave like a normal location (stock picked by pick path) or like a
  self-service floor where pallets are opened and drawn down? How does a pallet's `sealed`/`open` state apply?
- **Pull lists and replenishment.** Whether the PoD needs restock requests back to the warehouse, and whether
  those are internal transfers (not orders) so they don't count as goods leaving.
- **Reporting definitions.** Whether goods moved warehouse-to-PoD count as "distributed" in existing reports
  (they should not, since they are still ours) versus checkout to the public (they should). The Flow, Partner and
  Unfulfilled reports will need explicit rules, plus FEMA-style outputs if those get built.
- **Offline use.** A PoD may have no reliable Internet. Relates to the R Station local server
  (`rstation/LOCAL_SERVER.md`), which already runs a whole offline site.
- **Scanning and labels.** A basket is naturally scan-driven; follow `SCANNING_INTERFACE_NOTES.md` and reuse
  inventory labels and UPC resolution where possible.

### Suggested staging (a starting point, not a plan)

1. Add a PoD location type and allow moving pallets there; show stock by place in the Inventory Report.
2. Staffed checkout screen that creates and fills an order in one step against PoD stock.
3. Reports by place and PoD flow.
4. Only then, consider public self-service and household limits.

### Not decided / out of scope for now

- Public accounts, payments, and delivery to the public's homes.
- Any claim that goods can be traced from a specific donor to a specific member of the public. Stock is pooled
  once sorted, and this module does not change that.

---

## 2. Multi-tenant: several warehouses in one system, with per-warehouse permissions

### The idea

One installation manages more than one warehouse. Each warehouse has its own stock, pallets, locations, donations
and orders, and a person's permissions can differ by warehouse (for example, a lead at Warehouse A who is only a
sorter at Warehouse B).

### Where the current system stands

- The app is effectively **single-site**. A `Warehouse` model/table with CRUD exists and has a `pallets_enabled`
  toggle, but donations, orders, pallets, locations and the ledger are not scoped by warehouse, and every report
  and `WarehouseMetrics` aggregate is install-wide.
- **Permissions are global.** A person's keys are their roles' grants plus per-person overrides
  (`HasPermissions`, `role_permissions`, `person_permissions`); `CheckPermission` gates routes on a key alone, so
  there is no "where" dimension.
- `PROJECT_ANALYSIS.md` Part 5 already sketches the shape: a `Facility` that generalizes `Warehouse` (types
  warehouse / resource site / pod), every pallet/order/request carrying a `facility_id`, and a
  `facility_assignments` table (person, facility, facility role) that says *where* a global permission applies.
  That sketch predates the granular permission-key model and says it needs validating against a real
  multi-assignment scenario.

### What it would touch

- A `facility_id` (or `warehouse_id`) on the core tables: `orderdonations`, `pallets`, `locations`, `item_ledgers`,
  `pallet_stock_assignments`, print jobs and printers (printers are already tied to a `KioskLocation` site).
- A central scoping layer (global query scope or a resolver in `HasPermissions`) so no controller can forget it.
  The risky failure is a cross-warehouse data leak, so this should be enforced in one place and tested heavily.
- Reports, the Inventory Report, `WarehouseMetrics`, the Flow/Donor/Partner reports and the dashboards each need a
  warehouse filter and an "all warehouses" view for people allowed to see it.
- `assertNoEscalation()` in `PeopleController` reasons about permission keys only; it must also consider scope
  (you shouldn't grant a permission at a warehouse you don't hold it at).
- Item types, the master item list, partners and donors are probably **shared** across warehouses; stock, pallets
  and locations are **per** warehouse. Standing (`donor_standing`) probably stays global.
- The warehouse simulator and the seeders assume one site.

### Open questions

- Is one organization running several warehouses the target, or genuinely separate organizations sharing a server?
  The latter is real tenancy (data isolation between agencies) and is much harder, and overlaps with idea 4.
- Transfers between warehouses: a stock movement that is neither a donation nor an order, which must not count as
  goods received or distributed in the Flow report.
- Do users switch "the warehouse they are working in," or see everything they have access to at once?
- Training mode and the first-run wizard assume one instance, one site.

---

## 3. PoD-only mode: intake of already-sorted goods from an upstream warehouse

### The idea

An instance can run with **no raw receiving or sorting**. A PoD receives goods that an upstream warehouse already
sorted and counts them in as a single intake, then goes straight to inventory and distribution (see idea 1).

### Where the current system stands

- Receiving (`Receiving.vue`, labels, drivers, offers), Sorting (`DonationSorting.vue`, scan-driven, with
  disposition and condition) and the pallet/label workflow all feed `item_ledgers`. Stock on hand is
  "usable additions minus subtractions" from that ledger.
- There is no way to add stock except through sorting or an adjustment (`type='adjustment'`, requires a reason and
  an existing pallet). So a PoD-only site has no honest way to say "100 cases of water arrived, already sorted."
- Menus and permissions are already per feature, and `pallets_enabled` shows a precedent for turning a workflow
  off per site.

### What it would touch

- A new, simple **transfer-in / intake** transaction type: items and quantities, the upstream source, and no
  sorting disposition. Rows must be clearly distinguishable in the ledger so Flow and the Sorting Area reports
  stay correct, and so goods arriving *from our own warehouse* are not counted as new donations or donors.
- A site-level mode setting that hides Receiving, Sorting, Donation Offers and donor reporting, and simplifies the
  main menu and the Help guides to match.
- Ideally the intake can be **created from the upstream warehouse's outgoing order/shipment**, so the PoD does not
  retype it (this is where ideas 2, 4 and 5 meet). At minimum a printable manifest with scannable lines.
- Reports and `DonorReportController`, `SortingAreaReportController` and Inventory "received new" must handle a site
  with no sorting rows without showing misleading zeros.

### Open questions

- Is PoD-only a **mode of the same codebase** (configuration), or a lighter deployment (for example an R Station
  local server, `rstation/LOCAL_SERVER.md`)?
- Does the PoD still need pallets and locations, or only a quantity per item?
- Expiration dates and condition (new/used) arrive from upstream or are dropped?

---

## 4. Federated instances with "trusts" between agencies

### The idea

Independent installations run at separate locations, each keeping its own data, with explicit, revocable **trust
relationships** between agencies so they can exchange limited information (and perhaps shipments) without sharing a
database.

### Where the current system stands

- Every instance is **standalone**. The R Station local server is explicitly "not a sync target." Backups and
  restore (`backup:create`, `backup:restore`) move a whole instance, not records between instances.
- **Update 2026-10-26 — the trust layer is built** (branch `federation-trust`; `CLAUDE.md`, "Federation"): every instance has an Ed25519
  identity, an admin pairs two instances with an invitation (each side approves its own side), the pairing is a **trust** with explicit
  permissions (`print.share`, `feedback.send`/`receive`, `offers.send`/`receive`, `inventory.query_exact`, `supplies.request`/
  `query_available`, `operator.full_read`), site-to-site trusts can be domain-verified, and feedback aggregation and device pairing already
  run on it. What is **still not built** is everything on top of it: no endpoint yet shares offers, supplies, inventory or reports, and
  there is still no record-level sync. Primary keys are local auto-increment ids.
- Building blocks that do exist: the federation trust layer above and the key-based device pairing built on it
  (`ManagedDevice`, `/api/devices`, `trust-auth`), a documented wire protocol with an OpenAPI file and a drift test (`rstation/PRINT_SERVER.md`,
  `print-extension.openapi.yaml`), an immutable-log pattern for state changes (`donation_offer_status_log`,
  `print_job_status_log`), and Cloudflare Turnstile/`TurnstileService` for public endpoints.

### Design directions

- **Trust record** *(built 2026-10-26)*: a pairing between two instances, created by an administrator on each side, with a
  key pair, a scope (the permission grants), an audit trail, and instant revocation. Still open: an expiry, and key rotation.
- **Share the least that works.** Start with read-only, aggregated data (stock by item type, availability, capacity)
  and with partner/agency identity, before anything that moves records. Never replicate people, notes, photos or
  anything the donor/partner didn't expect to leave the site.
- **Shipments between trusted instances** as messages (an outgoing order on one side produces an incoming intake
  on the other, tying into idea 3), not as shared tables.
- Use globally unique identifiers on anything exchanged (UUIDs or instance-qualified ids), and keep the existing
  ids internal.

### Open questions

- Topology: peer-to-peer, hub-and-spoke through a coordinating agency, or both?
- Legal and privacy: what each agency is allowed to see; per-trust data sharing agreements; how an agency leaves.
- Offline: instances will often be offline. Is this store-and-forward, and how are conflicts avoided
  (the answer is probably "no two instances ever write the same record")?
- How it differs from idea 2: multi-tenant is one database with isolation; federation is many databases with
  consent. Many use cases may be met by one or the other, not both.

---

## 5. Interoperability with other agencies' inventory systems

### The idea

Exchange inventory, shipments and requests with whatever systems other agencies already use, instead of requiring
everyone to adopt this one.

### Where the current system stands

- Standard identifiers are already used: GTIN/UPC normalization (`App\Helpers\Gtin`), carton codes, and a barcode
  lookup service (`Upc\UpcResolver`), so product identity is the one thing that is already comparable across
  systems. Items also carry an ACS item number (family-variant) that is **local** to this organization.
- **Exports:** CSV output exists on several reports (Flow, Donors, Partners, Unfulfilled Requests, Sorting Area)
  and PDF for many documents. There is no import path for external inventory or orders.
- **APIs:** the `/json/*` endpoints are session-authenticated and built for the app's own pages, not for third
  parties; the only token-based API is `/api/devices` for paired devices. Nothing is versioned or documented for
  outside use.
- People carry a `source_system` column (used by the simulator), which suggests imported records can already be
  marked by origin.

### Design directions

- A **versioned, documented integration API** (token auth, per-agency scopes, rate limits, an OpenAPI file with a
  drift test like the print protocol) separate from the page-support `/json` routes.
- **Item mapping**: a table mapping an outside system's SKUs/codes to our item types (and back), seeded from
  GTIN where possible, with a review screen for unmatched items.
- **Start with files**: import and export CSV (and later standards such as EDI or GS1 messages) for stock, shipments
  and requests. This is cheap, human-checkable, and works with agencies that have no API.
- Per-agency adapters that translate to one internal shape, so adding an agency doesn't touch the core.
- Reuse the trust and shipment-message concepts from idea 4, so interoperability with another instance of this
  system is just the simplest adapter.

### Open questions

- Which systems matter first? (Probably decided by the agencies actually using this app or asking for it; do not
  design adapters for hypothetical systems.)
- Which direction is needed: pulling their availability, pushing our shipments, accepting their requests, or all?
- Identity: how do we recognize the same partner or product across systems without sharing personal data?
- Errors and audit: an inbound record we can't map must be held for a person, never guessed at, and every import
  needs a log of who or what created each row.

---

## How these ideas relate

- **2 (multi-tenant)** and **4 (federation)** are two answers to "more than one site." Decide which problem is real
  before building either; they share the need for a site identity on every record.
- **3 (PoD-only)** and **1 (PoD module)** are the two halves of a PoD: stock in, and the public out.
- **4 and 5** both depend on a well-defined **shipment/transfer** message. Designing that once, for ideas 3, 4 and 5
  together, is the most valuable shared piece of work.
- All of them extend `PROJECT_ANALYSIS.md` Part 5 rather than replace it; reconcile with it before starting any.
