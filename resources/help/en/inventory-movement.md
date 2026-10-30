<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

Keep track of where pallets are, and put sorted goods onto warehouse pallets so pickers can find them.

## What this screen is for

Open [Inventory Movement](/inventory-movement) (you need the Manage Pallets permission). It has three tabs: **Pallets**
(the one you'll use all day — scan-driven), **Locations** and **Recent Movements** (look-up only).

**Unassigned means "still in the sorting area."** When goods are sorted (see the [Sorting](/help/sorting) guide) they
count as stock on hand but sit in the sorting area. Putting them on a warehouse pallet moves them out of "unassigned"
and gives them a place on the shelf. Stock is pooled by item type, so you tell the system *how many* of an item went
onto a pallet — not which donation it came from.

Pallet tags start with a letter for the kind: **R** Receiving, **W** Warehouse, **S** Shipping, **H** Hold, **Q**
Quarantine, followed by eight digits (for example `W00000123`). A Warehouse pallet is either *sealed* (wrapped and
closed), *open* or *empty*. Pickers can only take from open pallets — see the [Order Filling](/help/order-filling)
guide.

## The scan bar

At the top of the Pallets tab is one box, always ready for the next scan. After you click anywhere on the page, the
cursor returns to it (unless you're typing in another field). Scan or type a code and press Enter or **Find**.

- With no pallet showing, a scan is read as a **pallet barcode**.
- With a pallet showing, a scan that matches a **location code** moves that pallet there at once. Any other scan is read
  as a pallet and replaces the one on screen.
- A green message confirms what happened; a red one says what went wrong (for example, an unknown pallet). The speaker
  button at the right mutes or unmutes the scan sounds.

## Looking up a pallet

Scan a pallet's tag. The screen shows its tag and kind, where it is (*at* a location, or *no location recorded*), its
status, when it was last updated and, for a Warehouse pallet, the earliest expiration date of what's on it. Below that:

- **Pallet Contents** — for a Warehouse pallet, each item and its quantity. For a Receiving pallet, what sorting
  recorded coming off it (item, disposition, quantity). Other kinds don't track contents.
- **Movement History** — every move and status change, newest first (date, location, status, notes).

**Clear / new pallet** removes the pallet from the screen so you can scan another or build a new one.

## Moving a pallet

1. Scan the pallet's tag.
2. Scan the barcode on the shelf or rack location. The move is recorded immediately ("… moved to …"), and the pallet
   keeps its status. Scanning the location it's already at just says so.
3. Prefer the form? Type or pick a code in **Location**, add **Notes** if you like, and click **Record Movement**. The
   location must be a known, active one.

For Receiving, Shipping, Hold and Quarantine pallets there is also a **Status** drop-down; change it and click **Record
Status** (the button reads Record Movement when the location changed). A Warehouse pallet has no Status drop-down — its
status changes through sealing and unsealing below.

## Building a new Warehouse pallet

A lead or forklift driver does this as a pallet leaves the sorting area. With no pallet showing, the page says **Build a
new Warehouse pallet**.

1. Under **In the sorting area** is the pick-up list: each item still waiting, how many (*Waiting*) and its earliest
   expiration. Click **Put all N on pallet** to stage an item. Items that share an ACS item number are grouped into one
   row; click it to expand the individual items, or use its button to stage them all.
2. Staged items appear in a table above. Change a **Quantity** if only part of it is going on this pallet (it can't
   exceed what's waiting), or click **Remove**.
3. Check each item's **Expires** date in the list — it's the earliest date known for that item, and goes on its item
   label; change or clear it if it's wrong. Type or scan the **Location** the pallet is going to. The pallet's own
   **Expires** date is filled with the earliest date among the items; change it or click **Auto** to go back. Add
   **Notes** if needed.
4. Click **Seal & Place**. A new Warehouse pallet is created, sealed, and set at that location. A green line shows its
   tag and contents, with a **Print Pallet Label** button — print it and put it on the pallet — and a **Print Item
   Labels** button: one small label for each item you put on, showing the ACS item number and description, quantity,
   earliest expiration and pallet, with a barcode. Stick each on its cases. Item labels print on the small label size
   (set on the Printers page). To print them again later, scan the pallet: its **Inventory Labels** list has a Print
   button for each.

The system won't let you put more of an item on pallets than is still in the sorting area ("Only N of … are not already
on a pallet"). When everything is on a pallet the list says *Nothing waiting — everything sorted is already on a
pallet.*

**Adding to an existing pallet:** scan a Warehouse pallet that is *open*; the same list now reads **Add items to W…**
and the button is **Add to pallet**. A sealed pallet must be unsealed first.

## Sealing and unsealing

- A **sealed** Warehouse pallet can only be moved or unsealed. The page reminds you: unseal it to add items or adjust
  what is on it. Pickers can't take from a sealed pallet either — unless the pick takes the whole pallet, or someone
  unseals it first.
- Click **Unseal** to open it; click **Re-seal** when you're finished.
- A pallet that runs out of goods (a picker takes the last items) is marked empty automatically.

## Adjusting inventory on a pallet

Counts sometimes drift: something is damaged, a case is missing, or someone put the wrong item on. An adjustment
corrects what's recorded on one pallet. It needs a separate permission, **Adjust Inventory** (normally Office and
Administrator) — without it, you won't see the buttons below.

It's only available for a Warehouse pallet that is **open** and already has a **location**. Then two buttons appear:
**Add from sorting area** and **Adjust inventory** — you can use one at a time.

1. Click **Adjust inventory**.
2. For each item, type a number in **Change**: a minus sign removes (`-3`), a plain number adds. **After** shows the
   result. To add an item that isn't on the pallet yet, search for it under **Another item**.
3. Choose a **Reason**: Shrinkage, Damaged items, Correcting count, Correcting item type or Other. For Other you must
   explain in **Notes**.
4. Click **Record Adjustment**. You can't take off more than the pallet has.

Every adjustment is logged with who, when and why, and it changes stock on hand itself — it isn't a sorting entry.

## Locations and Recent Movements

- **Locations** — a table of every active location with the number of live pallets there, broken out by status. Click a
  location to list its pallets, and click a pallet's tag to open it on the Pallets tab. Pallets with no location appear
  as *No location recorded*. Emptied, shipped, collected, released, condemned and missing pallets aren't counted.
- **Recent Movements** — the 100 most recent entries across all pallets (date, pallet, location, status, notes).

The order of locations along the warehouse (the pick path) is set up under Setup by an administrator; it decides the
order pickers walk.

## Pallet Management

[Pallet Management](/pallet-management) (also needs Manage Pallets) is the plain record list of pallets — use it to
**reprint a pallet label** (Print Pallet Label) or to correct a pallet's record by hand. It lists pallets that are still
in use; open one to see its Pallet Tag, Kind, Date Packed, Location, Status, Container Type (Pallet, Gaylord, Box, Bag
or Tote) and its Pallet Status History. The kind can't be changed once a pallet is labeled.

For everyday work — moving pallets, building them, adjusting them — use Inventory Movement instead: it records each
change in the history and checks the rules for you, while Pallet Management lets you set the status directly (from a
list of the statuses valid for that kind of pallet).

## Related guides

- [Donation Sorting](/help/sorting) — how goods get into the sorting area.
- [Order Filling](/help/order-filling) — how pickers take goods from pallets.
- [Shipping](/help/shipping) — what happens after an order is filled.
