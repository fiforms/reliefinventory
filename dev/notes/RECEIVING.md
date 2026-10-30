# Receiving, pallets and donation offers

## Pallets and containers
- `pallets` / `PalletStatus`: a printed barcode label plus status history; the unit sorting scans to establish provenance. `container_type` is `pallet|gaylord|box|bag|tote`, so non-palletized arrivals get a trackable label too.
- Receiving no longer collects per-pallet contents (`content_description`/`content_item_id` remain for old data and Sorting's display, never written by Receiving): identifying contents is Sorting's job. Replacement: donation-level `quick_sort_candidate`, a rough dock judgment ("mostly one item, eligible for sorting's express lane?").
- Label numbers (pallet tag, `ORD-`/`BOL-`, inventory label) are the row id, so a fresh warehouse starts its `pallets`, `orderdonations` and `pallet_stock_assignments` counters at a random point (`RandomNumberStart`, run when essentials are first seeded) to make cross-warehouse collisions unlikely. Not a uniqueness guarantee.
- `drivers`: lightweight table (name/phone/carrier, optional `person_id` for "driver is also the donor"); deliberately not `Person`.
- `orderdonations` also has `contact_person_id` (who to contact about this shipment; org-contact model via `is_organization`/`parent_person_id`/`contact_role`), JSON `container_types` (`['pallet']` exclusive, or any subset of box/bag/tote/loose) with `container_type_counts` (type → qty), and `photo_path` (one reference photo, served by `ReceivingController::photo()` like `FeedbackReport` screenshots).

## Receiving.vue wizard
Built on RIForm via the `#actions` slot with a local `wizardStep` (`details` → `photo` → `labels`): **Details** (category, arrival, donor/driver/contact, container composition) → **Photo** (file input only once the record has an id, so "Add Photo" saves first) → **Print Labels** (donations only). Labels auto-creates exactly the pallets/containers declared by quantity (`computeContainerCount()`), idempotently topping up what's short; a manual "Add Label(s)" line stays for corrections.

## Donation offers
`donation_offers` / `donation_offer_status_log`: pre-arrival tracking for a phoned-in offer (walk-ins can exist with no offer). `offered` → `refused`/`diverted` (terminal) or `pending` (accepted, ETA-sorted) → `cancelled`/`received`. `DonationOffer::transitionTo()` (like `Pallet::transitionTo()`) writes the status and appends one `donation_offer_status_log` row (who/when/how/notes), the same pattern as `FeedbackReportStatusLog`. Matching a pending offer to the real `Transaction` happens at intake (`ReceivingController::store()` accepts `donation_offer_id`) or later from `/receiving/offers`. Decisions (approve/refuse/divert/cancel/match) need `manage-donation-offers`, separate from `manage-receiving` and not granted to every role.
