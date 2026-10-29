# Interoperability: design notes

Unbuilt ideas, kept so they aren't lost. Not a commitment or a schedule.

## Interoperability with other agencies' inventory systems

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

- **Standardize ACS item numbers/categories on GS1 GPC (Global Product Classification) codes.** ACS numbers are local to this organization; mapping each item type to a GPC brick (and keeping the ACS number as an alias) is the biggest interoperability feature still to build, since it gives agencies a shared category vocabulary for inventory, shipments and requests.
- A **versioned, documented integration API** (token auth, per-agency scopes, rate limits, an OpenAPI file with a
  drift test like the print protocol) separate from the page-support `/json` routes.
- **Item mapping**: a table mapping an outside system's SKUs/codes to our item types (and back), seeded from
  GTIN where possible, with a review screen for unmatched items.
- **Start with files**: import and export CSV (and later standards such as EDI or GS1 messages) for stock, shipments
  and requests. This is cheap, human-checkable, and works with agencies that have no API.
- Per-agency adapters that translate to one internal shape, so adding an agency doesn't touch the core.
- Reuse the existing trust layer and the shipment/transfer message a PoD intake would need (`POD.md`), so interoperability with another instance of this
  system is just the simplest adapter.

### Open questions

- Which systems matter first? (Probably decided by the agencies actually using this app or asking for it; do not
  design adapters for hypothetical systems.)
- Which direction is needed: pulling their availability, pushing our shipments, accepting their requests, or all?
- Identity: how do we recognize the same partner or product across systems without sharing personal data?
- Errors and audit: an inbound record we can't map must be held for a person, never guessed at, and every import
  needs a log of who or what created each row.
