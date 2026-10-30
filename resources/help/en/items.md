<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

The catalog of everything the warehouse counts. Sorting, ordering and filling all pick from this list, so keeping it
tidy keeps everything else tidy.

## Item types and SKU rows

The list has two levels. An **item type** is the thing the warehouse counts and orders (for example “Newborn diapers”);
stock is pooled by item type. Under each item type are one or more **SKU rows** (shown in the **Items** table on the
form) — the specific products and package sizes that count as that item type, such as a particular brand's barcode.
Everyone who sorts a SKU row ends up adding to the same item type's total.

You need both the Manage Items and Manage Item Types permissions to open [Setup → Master Item List](/setup/items).
Without both, the page won't open — ask an administrator.

## Finding an item

Type in the **Search by ACS Item# or Item Type...** box to narrow the list. Click a column heading (**Category**, **ACS
Item#**, **Item Type**, **Unit**, **Active**) to sort by it; click again to reverse. A check mark in **Active** means
the item type is in use. Click a row to open it.

## Adding or editing an item type

Click **Add New Item**, or open an existing row. The form has these fields:

- **Category** — which group the item belongs to (pick from the list).
- **Family** and **Variant (optional)** — the item number (see below).
- **Item Type Desc** — the name people will see everywhere (required).
- **Default Ordering Unit** — the unit quantities are counted in (for example each, case, lb). The totals in the Items
  table use this unit.
- **Active** — uncheck to take an item type out of use without deleting it.

Click **Save** when you're done. A new item type automatically gets a “GENERIC ITEM” SKU row so it can be sorted right
away.

## Item numbers: family and variant

The **ACS Item#** is made of two parts that are stored separately. The **Family** is one to four digits (`42` is saved
as `0042`). The **Variant** is one or two digits and tells apart versions within the family, such as sizes.

- Each family + variant pair can be used only once. If it is taken, you'll see “That family/variant number is already in
  use.”
- Leave Variant blank and it's saved as `00`, the standard item of that family. The number is then shown without the
  variant (for example `318`); other variants show as `318-90`.
- Most families are shown without their leading zero (`042`, not `0042`); families of 1000 and up show all four digits.

## Items that have no number yet

When a sorter meets something not in the catalog, they can add it right from the [Sorting](/help/sorting) page. An item
type added that way has no family or variant and is put on **sort hold**, so it can be sorted but is held out of order
forms until it gets a real number. Give it a Family here to number it. Note that this form has no field for sort hold
itself, so saving doesn't lift the hold on its own — if a numbered item still doesn't appear on order forms, tell an
administrator.

## SKU rows and UPC barcodes

The **Items** table inside the form lists the SKU rows. Click **New Line** to add a row. To change a row, click its
pencil icon; while a row is open, its trash icon removes it. Each row has:

- **Plus Code** — a short code (up to 4 characters) that tells rows apart.
- **Item Description** — what this specific product is.
- **Pk Unit**, **Size** and **Case Qty** — the package (box, bag, case...), how many of the unit it holds, and, if it is
  a case, how many packages are in it. **Total** shows the result (case quantity times size).
- **UPC** — the manufacturer's barcode on that product. A UPC belongs to one row and can't be reused on another.

A UPC on a SKU row is what lets a sorter scan the product on the Sorting page and have the right item type and
description fill in. UPC-A, EAN-13 and GTIN-14 spellings of the same number are recognised as one. If a product has two
different barcodes, make two SKU rows.

Deleting a row removes it from the item type. It doesn't change stock already counted for the item type.

## Carton Barcodes

A carton (case of several packs) often has its own barcode, different from the single items inside. When a sorter scans
a carton the system doesn't know, the Sorting page opens **New Carton Barcode** and asks which item is inside and how
many (packs × items per pack). Once saved, scanning that carton again fills in the contained item and the quantity, so
carton and single scans end up on the same line.

[Setup → Carton Barcodes](/setup/carton-codes) lists every confirmed carton (also needs Manage Items). Use the
**Search** box to find one by barcode, item or who added it. The table shows **Carton barcode**, **Contains**, **Items
per carton** and **Added**.

- Cartons can't be added or edited here. To remove one, click **Remove**, then **Confirm remove**.
- Removing doesn't change any item or anything already sorted. The next scan of that carton asks again what's inside. To
  fix a wrong entry, remove it and scan the carton again.
