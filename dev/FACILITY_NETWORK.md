# FACILITY_NETWORK.md

Design for a **network of facilities** (warehouses, church/school resource sites, Points of Distribution) cooperating within one
disaster **incident**, with scoped views per person. **Not built.** Planning notes from August 2026, moved here from the retired
`PROJECT_ANALYSIS.md`; verify against the code before building. Related designs: [POD.md](POD.md), [INTEROPERABILITY.md](INTEROPERABILITY.md).

## What exists today

- One instance hosts several warehouses (own schema each), plus a federation **trust layer** between instances
  (`notes/MULTI_WAREHOUSE_NOTES.md`, `notes/FEDERATION.md`). No inventory, order or people data is shared between instances.
- There is no `Facility`, `Incident` or `facility_assignments` table. `Warehouse` is the only site concept.
- Built from this design ahead of the rest: `orderlines.need_level`, `orderlines.qty_approved` (manager-approved quantity, see
  `notes/ORDERS_AND_FILLING.md`), `itemtypes.low_stock_threshold`, and the on-demand "Review Allocation" panel. The
  `/reports/unfulfilled` report reads requested vs. approved vs. filled.
- Built and now documented elsewhere: the sign-in kiosk and volunteer-hours certification (`VolunteerSignIn`, `BuildingSafetyController`).
  The FEMA research behind them is kept below as rationale.

## Architecture

- **`Facility`** generalizes `Warehouse`. `type`: `warehouse` | `resource_site` | `pod`. Two status fields: `approval_status`
  (`pending` / `approved` / `denied` / `blocked`) and `active_status` (`active` / `inactive`, meaningful only once approved).
  A facility is `pending` exactly once; `approved`/`denied` is a one-way decision out of pending; `blocked` is reachable from
  `approved` and clears `active_status`. Every transition is audit-logged (who, when, optional note). The approval workflow is
  itself optional (see "Approval Required" below). Pallets, orders and requests would reference `facility_id`.
- **`Incident`** is a scoping boundary above Facility, within one instance. Don't build cross-instance record sync for this; a
  genuinely separate incident gets its own instance.
- **Permissions: global role + `facility_assignments`.** Roles and `role_permissions`/`person_permissions` stay as *what* a person
  can do system-wide. A `facility_assignments` table (person_id, facility_id, `facility_role`: `orderer` | `admin`) says *where*.
  Working at a facility does not imply an assignment row. A person can hold several assignments. Validate against a real
  multi-assignment scenario before treating it as final.
- **Scanning is never a hard requirement.** Every scan step must have a manual type/select equivalent (`notes/SCANNING_INTERFACE_NOTES.md`).

## Approval Required is a general toggleable pattern

Three independently toggleable applications, not one:
- **Facility approval**: is this site legitimate. Defaults probably differ by facility `type`; the toggle should be scopable per type.
- **Donor approval**: ties into the donor standing design (`DonorStanding`, `donor_standing_log`). When on, new donors start pending
  instead of being auto-accepted.
- **Recipient approval**: only for facilities distributing direct to the public. **Off by default.**

## Point-of-distribution recipient control: tally, not accounts

Researched against FEMA doctrine (IS-26, Distribution Management Plan Guide 2.0; triangulated from summaries, so confirm with a real
POD operator before anything compliance-sensitive depends on it). Commodity PODs run with no ID or registration; control is a **tally**
(vehicles/people served against a ration guideline; FEMA's planning basis is one vehicle ≈ a household of 3). The default build for
direct-distribution facilities should be a simple dispensed-count log with no names. Recipient Approval is the exception path.

## Stock visibility and fair-share allocation

- Three honest states per item: **Available** / **Limited** (below threshold, above 0) / **Unavailable** (zero, never disguised as
  limited). Threshold has a global default and per-item override. Only POD-type requesters get the fuzzed view; warehouse managers see
  exact counts.
- Requesting is always allowed regardless of displayed availability.
- **Allocation runs as an explicit batch** (manual, optionally scheduled), aggregating all open requests against available plus pledged
  stock. Continuous allocation degrades into first-come-first-served.
- **Default math is straight proportional** (500 requested against 100 on hand: everyone is suggested 20%).
- **The 3-level need meter is manager-facing context, never a formula input** (Critical / Moderate / Low), shown only on lines for
  Limited/Unavailable items. Any formula that rewards a need level becomes worth lying about. **No anti-gaming system on top, by design.**
- **Substitutions need explicit per-instance confirmation**, never auto-swap.
- Allocation is a suggestion; the manager has final authority.
- Requests carry a "usable within N days" window (global default, per-item override).
- Requests against Unavailable items are **not** pooled; they route to a staff-facing **sourcing queue** and re-enter allocation if
  stock arrives. Show zero-stock items in a separate section of the request form, merged with the freeform "other need" field.
- Attribution at line level (`added_by_person_id` / `last_modified_by_person_id`), set server-side from `Auth::id()`.

## Shared requests within a facility

- Visible to everyone with `orderer`/`admin` assignment at the requesting facility.
- At most one open request per (requesting facility, fulfilling facility) pair, acting as a shared cart.
- **Single-editor lock with takeover**, heartbeat expiry of about 10-30 minutes of inactivity.
- Locking on fulfillment is whole-request: `open` -> `locked` -> `closed`.
- A `request_audit_log` of every line/field change (who, what, old/new, when).

## Resource sharing

Pulls are symmetric: the source facility must approve or decline, never a bare pull. Applies to church/school resource sites and
warehouse-to-warehouse mutual aid. Opt-in per facility: `sharing_mode` = `none` (default) | `selected_items` | `all` |
`all_above_threshold`, with per-item overrides. It only controls discoverability; the approval step still applies.

## Volunteer hours and sign-in kiosk: FEMA rationale (feature is built)

- **Digital records are permitted.** 2 CFR 200.336: no paper copies needed when original records are electronic and cannot be altered.
  FEMA's Donated Resources policy (DAP 9525.2) lists "sign-in sheets, rosters, and logs" as acceptable documentation.
- What is required is **tamper-evidence** (append-only audit log; corrections write new entries) and **official certification** by a
  local public official or designee (a periodic batch certification by an admin, not a per-volunteer signature). Required content:
  name, date, hours, location, description of work. Confirmed against two FEMA documents (a Region handout and the PAPPG-derived
  Donated Resources checklist).
- The kiosk is a **building occupancy roster** for evacuation headcounts; FEMA reporting is a downstream use of the `Volunteer` subset.
  Tap-to-select/search is the required baseline. Physical barcode fobs were rejected (no help for one-off visitors, badge logistics).
  A system-generated personal QR code is a possible later accelerant (`QrScanner.vue` already exists).
- Sources: [2 CFR 200.336](https://www.law.cornell.edu/cfr/text/2/200.336), [DAP 9525.2](https://www.fema.gov/pdf/government/grant/pa/9525_2.pdf),
  [FEMA POD glossary](https://www.fema.gov/about/glossary/points-distribution-pod),
  [Distribution Management Plan Guide 2.0](https://www.fema.gov/sites/default/files/documents/fema_distribution-management-plan-guide-2.0.pdf).

## Shipment scheduling and routing

Optional per facility; invisible to pickup-only warehouses. **v1:** a `Route` entity (name, recurring schedule, ordered facility
stops) with shipments scheduled against a route and date. **v2, deferred:** optimized routing; use an external directions API.

## Facility discovery, onboarding, dashboard

- Map/list of active facilities: outsource (generated Google Maps link or embed from stored addresses) rather than build geocoding.
- Skippable, revisitable first-login wizard ("what best describes you").
- Modular dashboard: menu filtered by enabled module toggles, role, and facility assignment/type; disabled modules are absent, not empty.
- Notifications for approval events, and an audit trail for facility status changes. (Mail and notification infrastructure exists:
  `app/Mail`, `app/Notifications`.)

## Suggested sequence

Build this after the single-warehouse core loop is solid: it re-scopes orders, inventory and permissions everywhere.
1. `Facility` (generalizing `Warehouse`), `Incident`, `facility_assignments`; repoint pallets and orders at `facility_id`.
2. Facility approval workflow, onboarding wizard, modular dashboard.
3. Generalized Approval Required toggle and tally-based POD control.
4. Facility-to-facility Distribution Requests, batch allocation run, zero-stock sourcing queue.
5. Resource sharing with `sharing_mode`.
6. Shipment routes (v1). Facility map via an external service.
