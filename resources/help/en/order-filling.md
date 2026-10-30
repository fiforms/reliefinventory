<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

Pick the items for requests that have been entered at [Order Entry](/help/order-entry), and record what was actually
pulled. This is where stock leaves the inventory.

## How filling works

You need the **manage-orders** permission. Open [Order Filling](/order-filling). There are two ways to work, and they
end up in the same place:

- **On paper** — print pick sheets, walk the warehouse with them, then type in what was picked.
- **On screen** — open one order with **Start Filling** and enter each pick as you go.

Every pick is saved as its own **fill record** the moment you add it. Records are only ever added, never overwritten: if
a line is picked in two trips, both records are kept and the line's **Filled** number is their total. A fill record says
how many were taken and, if they came off a warehouse pallet, which pallet — that is where the goods were, not who
donated them.

## The two queues

- **Ready to Fill** — submitted orders waiting to be worked (Order, Partner, Lines). **Start Filling** on a row moves
  just that order to Filling and opens it.
- **Filling** — orders already started, with progress such as *2 of 5 lines filled*. **Continue** reopens the order; the
  **Print** menu on that row reprints its pick sheet.

## Paper path: Print Pick Sheets

**Print Pick Sheets** (top right) prints one sheet per order for *every* order in Ready to Fill, listing where to find
each item along the warehouse's pick path. Choose **Download PDF** or a printer.

Important: choosing any option here moves **all** the Ready to Fill orders into Filling at once, and from then on their
picker quantities are fixed. The button is greyed out when nothing is Ready to Fill. After picking, click **Continue**
on each order and enter what was pulled, as below.

## Optional: Review Allocation (when there isn't enough)

**Review Allocation** (top right; click again to **Hide** it) lists items where the total requested across all Ready to
Fill orders is more than what is on hand. For each item you see a table with **Order**, **Partner**, **Requested**,
**Suggested**, **Approve** and **Need**.

- **Suggested** is a plain proportional split of what we have. It is a starting point, not a rule.
- **Need** is the partner's own Critical / Moderate / Low note from Order Entry (a dash if none). Lines are listed
  most-urgent first.
- Type the quantity you want in **Approve** and click **Save Approved Quantities**. Pickers then see only the approved
  number as **Qty to Pick** on the pick sheet and the fill screen. Approve **0** and the line is left off entirely.
  Leave a box blank to give the picker the full request. A check mark shows a saved approval.
- You can't approve more than was requested, and approvals can only be changed while the order is still Ready to Fill.
  The original request is never changed, so reports can still compare requested, approved and filled.
- Nothing here is required. If you'd rather go first-come, first-served, ignore this panel and print or start filling.

## The fill screen

The screen is titled **Order #** with the partner's name. **← Back** returns to the queues. The table has columns
**Item**, **Qty to Pick**, **Filled**, **On Hand** and **Add Fill**, listed in the order you would walk the warehouse.

1. Look at the **Pick:** suggestions under a line (see the next section).
2. If the item comes in more than one variety, search or scan the right one into **Scan or search item...** (or use
   **Scan** for the camera). If there is only one variety it is chosen for you.
3. Type the **Qty** you picked. If it came off a specific warehouse pallet, type or scan that pallet's tag into **From
   open pallet (optional)**; the tag stays in the box for your next pick. Press **Enter** or click **Add**.
4. The pick appears in a list under the line (quantity and item). To remove a mistake, click the trash icon, then
   **Delete?** (or **Keep**). The stock goes back, and a pallet that had been marked empty by that pick is reopened.

You can enter **0** on purpose when you looked and there was none: that counts as "dealt with" and doesn't take any
stock.

## "Pick:" suggestions and where stock is

Under each line, **Pick:** shows boxes saying where to take the item from, recalculated from live stock each time. They
are advice only — nothing is reserved and you can pick differently.

- **Sorting area ×N** — loose stock not yet put on a pallet. It is used first.
- **Location, pallet tag ×N** — take N from that pallet (with an expiration date if one is known). Pallets that expire
  first, then older pallets, are suggested first. Whole sealed pallets are suggested before opening any new one, so full
  pallets can ship intact.
- **open** means you can pick from that pallet now. **sealed — take the whole pallet** means the whole pallet is going
  on this order, so it can go as it is. **sealed — open it first** means part of it is needed: click **Open pallet**
  first. (Use is greyed out until you do.)
- **Use** copies a suggestion into the entry row (the pallet tag, the quantity and, where relevant, the item variety).
  Check it and click **Add**.
- *N not found in stock* means the system thinks we're short by N. *fully picked* means nothing more is needed on that
  line.

More on pallets, sealing and locations: [Inventory Movement](/help/inventory-movement).

## When the numbers don't match

- **No pallet named:** the pick comes from the sorting area first, then from the oldest *open* pallets, split into
  several fill records if needed.
- **Sealed pallets hold the rest:** you will get a message such as *Only 30 of 50 can come from the sorting area and
  open pallets. Open a sealed pallet to pick the other 20.* Nothing is saved. Open a pallet (use **Open pallet** on the
  suggestion) and add the pick again.
- **Naming a sealed pallet:** it only works if you are taking everything on it. Otherwise open it first. A tag that
  isn't a warehouse pallet is refused (*Unknown Warehouse pallet tag*), as is a pallet that is empty or otherwise can't
  be picked from.
- **More than the system says we have:** the pick is accepted, with a warning such as *That's 5 more than the database
  says is in stock — check the count.* It means the records and the shelf disagree: count the shelf, and tell a
  supervisor.
- A pallet is marked empty automatically when its recorded total reaches zero.

## Finishing the order

1. Every line that has something to pick needs at least one fill record. A line with nothing approved to pick (approved
   at 0) is not on the screen and needs none. Until all are done, **Complete Filling** is greyed out and the message
   says *Every line needs at least one fill record (even 0)…*.
2. Optionally enter **Pallets packed** (how many pallets the order ended up on) — it is recorded on the order and
   printed on the shipping label.
3. Click **Complete Filling**. The order becomes **Filled** and moves on to [Shipping](/help/shipping).

**If nothing at all was picked** (every fill is 0), the screen warns *Nothing has been picked — completing will close
this request as Unfulfilled instead of sending it to Shipping.* The order then ends as **Closed - Unfulfilled** and
never reaches Shipping.

Once an order is Filled, its fill records can no longer be changed here.
