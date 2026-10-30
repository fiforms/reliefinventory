<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

Hand finished orders to a driver, record when the truck leaves, and review the signed Bill of Lading (BOL) that comes
back. Orders get here once [Order Filling](/help/order-filling) marks them Filled.

## How an order moves

Open [Shipping](/shipping). It has one table per stage, in this order, each with a count in its heading. You need the
Manage Orders permission to use the page.

1. **Filled — Ready to Assign a Driver** — picking is done and no driver is assigned yet.
2. **Ready to Ship** — a driver is assigned, but the truck hasn't left.
3. **Shipped** — staff confirmed the load left the dock.
4. **Delivered — Pending Review** — the signed BOL has been uploaded and a manager needs to check it.
5. **Delivered — Waiting on BOL** — the uploaded BOL was rejected; a usable one is still needed.
6. **Recently Completed** — the signed BOL was approved (the 25 most recent).

Nothing moves on its own: each step below is something a person does. The page has no other shipping steps, such as
tracking numbers or delivery dates.

## Assigning a driver

1. In **Filled — Ready to Assign a Driver**, each row shows the order number, partner, number of lines and total
   quantity.
2. Pick a name from **Select a driver...**, then click **Assign**. The order moves to Ready to Ship.

A driver has to be in the **Drivers** list at the bottom of the page before you can pick them (see below).

## Printing the BOL

In the **Ready to Ship** and **Shipped** tables, the **Generated BOL** column has a **View BOL** menu. Use it to
download the PDF or send it to a printer set up for your site. Print it for the driver to sign. The BOL number is
assigned the first time it's generated and stays the same if you print it again.

Next to it is a **Shipping Label** menu. It makes a 4 x 6.5 in label to stick on the shipment, showing the order number
(with a barcode), who and where it is going, the contact, fulfillment method, number of pallets, date packed, requested
date and the special instructions (cut short if they are long). Only printers loaded with large labels are offered; the
PDF download is always available. The pallet count comes from **Pallets packed** in Order Filling, and the label is
blank there if it wasn't entered. The date packed is when the last item was picked.

## Marking an order Shipped

When the truck actually leaves the dock, click **Mark Shipped** on that order in the **Ready to Ship** table. It is a
manual step — nothing marks it for you.

Picked the wrong driver? The Ready to Ship table has no way to change the driver once one is assigned, so check the name
before you click Assign.

## Drivers and the Driver Portal

A driver doesn't need an account. The **Drivers** table at the bottom lists each driver's name, phone, carrier and
whether a Driver Portal PIN is set.

- **+ New Driver** — enter a name (required), phone, and carrier (if a trucking company), then **Save Driver**.
- **Set PIN** / **Reset PIN** — enter a 5-digit PIN twice and click **Save PIN**. Easy-to-guess PINs are refused.

Give the driver their phone number and PIN. They sign in at [/driver-portal](/driver-portal) using **Phone Number** and
**PIN**, and see only the loads assigned to them under **Current Loads** (Ready to Ship, Shipped, or waiting on a
re-uploaded BOL). Each load shows delivery details (address, contact, window) under **Delivery Details** when the order
has them.

## The signed BOL

After delivery, the driver taps **Upload Signed BOL** on the load and chooses a photo or PDF (JPG, PNG or PDF, up to 10
MB). The upload moves the order straight to **Delivered** — it doesn't have to be marked Shipped first. If a staff
member opens the portal while logged in, they get a read-only list of every load currently out, with no upload button.

The order then appears under **Delivered — Pending Review** on the Shipping page. A manager clicks **Review**, which
opens the uploaded file:

- **Approve** — accepts it and moves the order to Completed. For a photo you can first drag a rectangle over the part to
  keep, and the button becomes **Approve Cropped** (a PDF can't be cropped, so open it in a new tab).
- **Reject**, then **Confirm Reject** — optionally say what's wrong. The bad file is discarded and the order goes to
  **Delivered — Waiting on BOL**. The driver sees the load again with your note and uploads a new one, which sends it
  back for review.

Approved orders list a **View Signed BOL** link under **Recently Completed**.
