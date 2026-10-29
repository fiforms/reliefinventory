# ROADMAP.md

Future development ideas for Relief Inventory. Nothing here is built. This file records intent so ideas
aren't lost; it is not a commitment or a schedule. Open work is in `TODO.md`. When an idea ships, move its description into `notes/` (and a pointer in `/CLAUDE.md` if it is a rule agents must always know) and delete or mark it here.

---

## 1. Point of Distribution (PoD)

A PoD at the warehouse's site where the public picks up goods (checkout processed as an order), plus a PoD-only mode that takes in
already-sorted goods from an upstream warehouse instead of receiving and sorting. Design and open questions: `POD.md`.

---

## 2. Interoperability

Exchange inventory, shipments and requests with other agencies' systems; first step is standardizing ACS item numbers/categories on GS1 GPC codes.
Design and open questions: `INTEROPERABILITY.md`.

---

## 3. Facility network

A network of warehouses, resource sites and PoDs within one incident: facility approval, fair-share allocation, resource sharing, shipment routes. Design: `FACILITY_NETWORK.md`.

---

## 4. Other ideas (designs not written yet)

- **Stock source tagging and equipment tracking:** tag stock by source, an `item_kind` on the catalog, and an `Asset` model for equipment.
- **Item conversion:** a recount-forced item/unit-of-measure reassignment (internal transfer).
- **Party roles:** explicit Donor/Partner role tagging, a `WarehouseContact`, a combined People & Organizations report.
- **Public needs list:** a public page listing what's needed.
- **Usage-rate and reorder alerts.**
- **Menu grouping and theming.**
- **Daily reporting:** an internal ops email (throughput, close-out candidates) and an external throughput PDF for recipients with no login, with its own recipient list.

---

Both ideas above (1 and 2) depend on a well-defined shipment/transfer message between instances, built on the existing trust layer (`notes/MULTI_WAREHOUSE_NOTES.md`).
