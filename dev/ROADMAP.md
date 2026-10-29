# ROADMAP.md

Future development ideas for Relief Inventory. Nothing here is built. This file records intent so ideas
aren't lost; it is not a commitment or a schedule. For the audit of what exists and the dependency-ordered
backlog, see `notes/PROJECT_ANALYSIS.md` (especially Part 5, the facility network, and Part 11, sequencing) and
`TODO.md`. When an idea ships, move its description into `notes/` (and a pointer in `/CLAUDE.md` if it is a rule agents must always know) and delete or mark it here.

---

## 1. Point of Distribution (PoD)

A PoD at the warehouse's site where the public picks up goods (checkout processed as an order), plus a PoD-only mode that takes in
already-sorted goods from an upstream warehouse instead of receiving and sorting. Design and open questions: `pod.md`.

---

## 2. Interoperability

Exchange inventory, shipments and requests with other agencies' systems; first step is standardizing ACS item numbers/categories on GS1 GPC codes.
Design and open questions: `interoperability.md`.

---

Both ideas depend on a well-defined shipment/transfer message between instances, built on the existing trust layer (`notes/multi-warehouse.md`).
