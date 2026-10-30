<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

Turn the pallets logged at [Receiving](/help/receiving) into item-by-item ledger entries — this is where a donation
actually becomes counted inventory.

## What sorting is for

Sorting is scan-driven and saves as you go — every line you enter is sent to the server immediately, so a dropped
connection or a crash never loses more than the one line you were on. Work one pallet at a time: scan its tag once, then
every item you enter counts against that pallet until you scan (or clear) a different one. That records what each
donation and pallet actually contained — once sorted, stock is pooled by item type, so a shelf item can't later be
traced back to a particular donor or pallet.

Sorted goods stay in the **sorting area** until someone puts them on a warehouse pallet — see the [Inventory
Movement](/help/inventory-movement) guide.

## Starting or resuming a session

Open [Donation Sorting](/donation-sorting) to see up to three lists:

- **In Progress** — sessions someone already started. Tap one to resume exactly where they left off.
- **Ready to Sort** — donations logged at Receiving, waiting to be picked up. Tap one to start sorting it.
- **Recently Completed** — the last 25 finished sessions, open read-only.

A donation whose donor is still unknown shows a red *donor ID pending* tag in each list.

**Start Sorting (untagged)**, at the top of the page, opens a brand-new session with no Receiving record behind it — use
it only for goods that showed up without going through Receiving first. Its donor field is fully editable, unlike a
session that came from Receiving.

## Working a pallet

1. Type or scan the pallet's tag (e.g. `R00000042`) into **Pallet Tag**, or tap **Camera Scan** to use the device
   camera, then **Set Pallet**. Only Receiving pallets (tags starting with R) work here — a warehouse or other pallet's
   tag is refused, and so is a Receiving pallet that belongs to a different donation (open that donation from Ready to
   Sort instead). Everything you enter next is attributed to this pallet: the bar shows *Sorting from pallet …*. Lines
   entered with no pallet set still save, but won't be recorded against a source. Setting a pallet for the first time
   also marks it as being sorted.
2. For each item on the pallet, scan its barcode or search by name in the item box. The **Qty** fills in as 1 — press
   Enter to save one, type another number and press Enter, or keep scanning (see below). Then check the **disposition**
   button: **New** (unused, still in its original packaging — counts into inventory), **Usable** (used or opened, in
   good condition — counts into inventory), **Outdated** (expired on arrival), **Trash** (damaged/unusable) or
   **Divert** (usable, but passed to another organization). You can also click **Add**. The disposition you last used
   stays selected, since runs of the same kind of goods are common. Only New and Usable add to stock on hand; Outdated,
   Trash and Divert are recorded for donor-quality reporting.
3. Know the expiration date? Click the calendar button next to Qty, pick the date, then **Done** (**Clear** removes it).
   It's optional, and it's saved with that line and shown in the *Expires* column. It resets after each line you add.
4. Made a mistake on a line? Click the trash icon next to it, then **Delete?** to confirm — it's a two-tap delete, no
   popup.
5. When the pallet is empty, click **Pallet Empty**, then say whether it **looks OK** or should be **set aside —
   damaged?**. That's a one-tap note for a supervisor to follow up on later; it doesn't block anything here. Emptying
   the donation's last pallet automatically completes the donation. Use **Change Pallet** instead to move to a different
   pallet without emptying this one.

## Scanning barcodes

A barcode scanner works like a very fast typist, so a scan goes wherever the cursor is. After a click the page puts the
cursor back where the next scan should go: the item box, or the Qty box when an item is already chosen. Don't worry
about clicking around.

- **Scan, scan, scan.** After an item is picked, scan the next barcode straight into the **Qty** box: the item you were
  on is saved (quantity 1, or whatever the Qty box showed) and the new barcode is looked up. Type a number and press
  Enter instead when there's more than one.
- **Same item again?** Scanning the *same* item again, straight into the Qty box, counts it instead: scan each of ten
  identical items and the quantity climbs to 10. Scan a different item and the count is saved.
- **Cartons and cases.** A case barcode that's already set up picks the single item inside and fills Qty with the number
  in the case. You can still change the number.
- **Combine duplicate lines.** If you scanned every item one at a time, the lines list will have many identical rows.
  When there are any, a **Combine duplicate lines** button appears at the top; click it and confirm to add up lines with
  the same item, pallet, disposition, condition and expiration date (entered by the same person on the same day). It
  can't be undone, so a single mistaken scan can no longer be deleted on its own afterward. Totals don't change.

### What the beeps mean

- One short high beep — matched a single item.
- Two quick rising beeps — matched a carton or case.
- One lower, longer tone — the barcode isn't in the item list; a form opens.
- Two low buzzes — a misscan or failed lookup. Scan again or search by name.

Prefer quiet? Click the speaker button in the top bar to mute or unmute; the page remembers it for that browser.

## A barcode the system doesn't know

Nothing is added to the item list until you click **Save Item**.

1. An unknown **single-item** barcode opens **Add New Item** with the UPC already filled in. If the barcode is found in
   a free online product database (Open Food Facts / Open Products Facts), a suggested description appears a moment
   later, with a note naming the source — check it against the product in front of you. If nothing is found, or the
   lookup is off or busy, the note says so; type the description yourself. Keep scanning at this point and you'll just
   hear the low tone again — finish or cancel the form first.
2. Choose the **Item Type** (search by name or number). If this is a brand new type, choose to add one and fill in **New
   Item Type**, **Measured By** (a unit such as Each or Pound) and **Category** — all creatable inline. Then **Save
   Item**; the new item is selected and the cursor jumps to Qty.
3. Under *This barcode is on:*, choose **A case of items already in the list** if the barcode is really a carton of
   something you already carry. That opens **New Carton Barcode**.
4. An unknown **carton** barcode opens **New Carton Barcode** directly. Scan one of the single items inside it (or
   search for it) — if the carton's number matches an item, it's suggested, and items from the same manufacturer may be
   offered as buttons. Enter **Packs in the carton** and **Items in each pack** (6 packs of 5 makes 30 items per carton;
   for loose items with no inner packs, leave Packs at 1). Click **Confirm & Use**: from then on, scanning that carton
   fills in the count automatically. **This is a single item, not a carton** switches back to Add New Item.
5. Can't find the item by barcode at all? Type its name into the item search and choose **Add "…" as a new item** to
   open the same form.

## Session header & wrapping up

- For a session that came from Receiving, the donor shows read-only (it was already recorded at the dock) with an
  **Edit** link if it needs correcting. For an untagged session, the donor field is editable directly. Search for the
  donor, or add a new one from the search box.
- **Donor unidentified — flag for follow-up** and **Comments** both save automatically as you change them — nothing to
  submit.
- The table under the line entry lists every line (time, item, quantity, expiration, disposition, pallet), newest first,
  with running totals underneath: Usable, Outdated, Trash, Diverted and the discard rate.
- Watch the save indicator in the top bar: *Saving…*, *All changes saved*, or a failure count with a **Retry All**
  button. A failed line also has its own **Retry**. Failed lines retry automatically once your connection comes back.
- Click **Complete Session** when every pallet is done. An item you picked but haven't added yet is added first (you'll
  be asked for a quantity if it's missing). If any lines still haven't reached the server, it asks you to confirm before
  completing anyway.
- **All Sessions** goes back to the list without losing the item you were entering. Leaving the page with unsaved work
  shows a warning.
- A completed session reopens read-only. If you need to fix something, use **Reopen Session** to go back to editing it.
