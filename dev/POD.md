# Point of Distribution (PoD): design notes

Unbuilt ideas, kept so they aren't lost. Not a commitment or a schedule. Two halves of one PoD: goods out to the public (Part 1) and
goods in from an upstream warehouse (Part 2). Design them together with the facility network in `PROJECT_ANALYSIS.md` Part 5.

## Part 1. PoD module: on-site, public-facing

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
- **Scanning and labels.** A basket is naturally scan-driven; follow `notes/SCANNING_INTERFACE_NOTES.md` and reuse
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

## Part 2. PoD-only mode: intake of already-sorted goods from an upstream warehouse

### The idea

An instance can run with **no raw receiving or sorting**. A PoD receives goods that an upstream warehouse already
sorted and counts them in as a single intake, then goes straight to inventory and distribution (see Part 1).

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
  retype it (this is where Part 2 and interoperability meet). At minimum a printable manifest with scannable lines.
- Reports and `DonorReportController`, `SortingAreaReportController` and Inventory "received new" must handle a site
  with no sorting rows without showing misleading zeros.

### Open questions

- Is PoD-only a **mode of the same codebase** (configuration), or a lighter deployment (for example an R Station
  local server, `rstation/LOCAL_SERVER.md`)?
- Does the PoD still need pallets and locations, or only a quantity per item?
- Expiration dates and condition (new/used) arrive from upstream or are dropped?
