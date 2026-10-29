# Orders, filling and picking

## Transaction lifecycle
One `orderdonations` row (`Transaction`) is an order or a donation (`type`), linked to a `Person` and to `person_id_user` (staff; set server-side from `Auth::id()`, never from the client). The status is stored and server-controlled, with `status_changed_at` set in `Transaction::booted()`.
- Donations: `Received → Sorting → Complete` (or `Logged` for non-donation intake).
- Orders: `New Order → Ready to Fill → Filling → Filled → Ready to Ship → Shipped → Delivered → Completed`. A rejected signed BOL goes `Delivered → Delivered - Waiting on BOL → Delivered` on re-upload (the goods did arrive; never back to Shipped).
- Only `New Order` is intake-editable; lines are locked once filling starts (`OrderController::rejectIfLocked`).
- `donor_identification_pending` (boolean, not a status) flags a donation whose source needs follow-up. It gates nothing; a flagged donation stays visible in Receiving's list past `Complete`.

## Filling (`OrderFillingController` + `OrderFilling.vue`)
- `Ready to Fill → Filling` by starting one order (live-scan/manual) or by printing pick sheets for every `Ready to Fill` order (`POST /json/order-filling/print-pick-sheets`; transactional and row-locked so two staff can't grab the same order).
- A fill record is an `item_ledgers` row tied to its line by `order_line_id`; append-only (multiple fills per line are summed). Both paths use `POST .../lines/{lineId}/fills`.
- Completing requires every line with something to pick to have a fill record (a deliberate zero counts). If nothing was picked the order goes to terminal `Closed - Unfulfilled`, never to Shipping.
- **Review Allocation** panel: where requested across `Ready to Fill` orders exceeds on-hand for an item type, it shows a proportional suggested split per line plus the line's self-reported `need_level` (critical/moderate/low). Threshold: `config('inventory.low_stock_threshold')`, overridable per item type (`itemtypes.low_stock_threshold`). A manager may save `orderlines.qty_approved` (+ approver/time, only while `Ready to Fill`); pickers then see `OrderLine::qty_to_pick` (approved, else requested), and lines approved at 0 are dropped. `qty_requested` never changes. Approving is optional.

## Ledger details
- `item_ledgers` is append-only and the source of truth for stock. Sorting additions carry `pallet_id` (provenance), `disposition` (usable/outdated/trashed/diverted; only usable counts as stock) and, for usable rows, `item_condition` (new/used). Fill rows carry `order_line_id` and `person_id_user`, not `pallet_id`.
- Pooled stock: a filled line can't be traced to a donor or pallet.

## Pick path and plan
- The admin orders all locations once at `/setup/locations/pick-path` (`PickPath.vue`; saves `locations.PullSequence` 10,20,30… via `PUT /json/locations/order`).
- `App\Services\PickPlanner` turns a line's need into steps: sorting-area pool, then WHOLE sealed pallets (oldest first, while need ≥ pallet qty, so they ship intact), then open pallets oldest first, then the oldest sealed pallet opened for the remainder. Steps/lines come back in pick-path order. `GET /json/order-filling/{id}/plan` feeds `OrderFilling.vue`; `pickSheetsBuilder` plans the whole print batch together (`planMany`, reserving as it goes). Plans are advisory: nothing is reserved between calls.
- A named sealed pallet may be picked only if the pick takes the whole pallet (sealed→open→empty); partial picks need it opened first.
