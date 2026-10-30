<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

Take a partner's request — by phone, or from a paper form they sent back — and enter it so the warehouse can fill it.
Once submitted, it goes to [Order Filling](/help/order-filling).

## What Order Entry is for

Partners call these *requests*; this screen (and the staff side of the app) still calls them **orders**. They are the
same thing. You need the **manage-orders** permission to use the page.

Entry saves as you go: the order is created the moment you confirm the partner, and every item line is sent to the
server as soon as you add it. A dropped connection or a crash never loses more than the line you were typing.

## The order list

Open [Order Entry](/order-entry) to see two lists. Tap any row to open that order.

- **Open Orders - tap to open** — everything not yet finished: New Order, Ready to Fill, Filling, Filled, Ready to Ship,
  Shipped and Delivered (including Delivered waiting on a signed BOL). Columns: Date, Partner, Lines, Items, Status,
  Entered By.
- **Recently Completed** — the latest 25 orders that are finished (for example Completed or Closed - Unfulfilled).

**New Order** starts a request. **Offline Order Form** (a **Print**-style menu) produces a printable form listing the
item numbers we currently have some of, to send to a partner who will fill in quantities by hand and send it back for
you to key in. It never shows stock numbers.

## Step 1 — Who is this for?

1. Click **New Order**. The screen is titled **New Order — Who is this for?**
2. Search the **Partner** box by name, organization or city and pick the partner. (This is the receiving organization or
   distribution site placing the request.)
3. Not in the list? Type the name and choose the add option. A **New Partner** form opens: names, organization, phone,
   email, **Zip** first, then **Address**, City, State and County. You need either a first and last name, or an
   organization. When the zip/address is filled in, the app may look up the county and suggest a corrected address —
   accept or keep what you typed. If a county is missing from the list, type it in the County box to add it (enter the
   2-letter state first). Click **Save Partner**.
4. **Confirm Contact & Shipping Details** appears with the partner's details. This is where the order ships, so check it
   is current and fix anything that has changed. **Verify Address** re-checks the address if it hasn't been checked yet.
   Pressing Enter in a box moves to the next box.
5. Click **Start Order →**. If you changed anything the button says **Save Details & Start Order →**, and the changes
   are saved to the partner's record for everyone.

## Step 2 — Entering the items

The header shows **Order #** and the date, and a slim bar shows the partner's name, city/state and phone. **Change**
there lets you pick a different partner (while the order is still editable).

1. Type an item number (or part of its name) into the **Item #** box and choose it. The cursor jumps to **Qty**. A note
   beside the item name shows roughly how many are on hand (for example *~40 usable on hand*, or *none on hand*). This
   is only a hint for you — never read stock numbers out to the partner, and you can still enter more than we have.
2. Type the quantity (1 or more) and press **Enter** (or click **Add**). The line is saved and the cursor goes back to
   **Item #** for the next one. A **Line comment (optional)** box is also available for notes on that line.
3. When an item is running low (at or below its low-stock level), a **Need level (optional)** box appears: **Critical**,
   **Moderate** or **Low**. Record what the partner tells you about how badly they need it. It is only context for
   whoever divides up short items later — leave it blank if they don't say.
4. Entered the same item twice? A box titled **Oops** shows what you already entered. Type an **Additional qty** and
   click **Combine →** to add it to the existing line, or **Cancel** (or press Esc) to drop it.
5. Wrong line? Click the trash icon beside it, then **Delete?** to confirm (or **Keep** to back out). To change a
   quantity, delete the line and add it again, or use the Combine box to add more.

The top bar shows *Saving…*, *All changes saved*, or *N line(s) failed to save* with **Retry All** (each failed line
also has **Retry**; failed lines also retry on their own when the connection returns). Totals for **Lines** and **Total
items** appear under the table. **← All Orders** goes back to the list; the order stays open for later.

## Step 3 — Review & confirm

When every item is in, click **Complete Order** (top right). The **Review & Confirm — Order #** screen has:

- **Order Summary** — partner, number of lines and total items.
- **Comments** — anything the warehouse should know.
- **Needed But Not in Catalog** — things the partner needs that aren't in our item list (the same as "Other Needs" on
  the paper form).
- **Delivery or Pickup** — choose one, plus a **Needed By Date**. For delivery also pick the days they can accept it
  (**Any Day** or individual days) and a **Preferred Time**. For pickup, the warehouse sets days and times.
- **Delivery Contact** (or **Pickup Contact**) — name and phone, filled from the partner; change them if someone else is
  receiving it.
- **Special Delivery Instructions** — gate codes, dock location, who to contact on arrival. These carry through to the
  BOL when the order ships.

Click **Submit Order** to finish. **← Back** returns to the items without submitting.

## After you submit — the order lifecycle

Submitting moves the order from **New Order** to **Ready to Fill**. From there the system moves it along:

`New Order → Ready to Fill → Filling → Filled → Ready to Ship → Shipped →                               Delivered →
Completed`

- **Only a New Order can be edited.** Once submitted, lines and details are locked, because the floor is working from
  them. Opening such an order shows the items read-only with its status badge.
- Status is set by the app as work happens — you never pick it from a list. Filling is covered in [Order
  Filling](/help/order-filling) and shipping in [Shipping](/help/shipping).
- An order where nothing could be picked ends as **Closed - Unfulfilled** and never reaches Shipping.
