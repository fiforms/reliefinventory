<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Help/Sorting.vue

	Static how-to guide for Donation Sorting (resources/js/Pages/DonationSorting.vue).
	Reached from the help "Help: ..." link on that page; the printable copy is
	resources/views/reports/help/sorting.blade.php — keep the two in step.
-->

<script setup>
import HelpPage from '@/Components/HelpPage.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
	breadcrumb: {
		type: Array,
	},
});
</script>

<template>
	<HelpPage guide="sorting" title="Donation Sorting" :breadcrumb="breadcrumb">
		<template #intro>
			Turn the pallets logged at
			<Link href="/help/receiving" class="text-blue-600 underline">Receiving</Link>
			into item-by-item ledger entries — this is where a donation actually becomes
			counted inventory.
		</template>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">What sorting is for</h2>
			<p class="text-sm text-gray-700">
				Sorting is scan-driven and saves as you go — every line you enter is sent to
				the server immediately, so a dropped connection or a crash never loses more
				than the one line you were on. Work one pallet at a time: scan its tag once,
				then every item you enter counts against that pallet until you scan (or clear)
				a different one. That records what each donation and pallet actually
				contained — once sorted, stock is pooled by item type, so a shelf item can't
				later be traced back to a particular donor or pallet.
			</p>
			<p class="text-sm text-gray-700">
				Sorted goods stay in the <strong>sorting area</strong> until someone puts them
				on a warehouse pallet — see the
				<Link href="/help/inventory-movement" class="text-blue-600 underline">Inventory Movement</Link>
				guide.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Starting or resuming a session</h2>
			<p class="text-sm text-gray-700">
				Open <Link href="/donation-sorting" class="text-blue-600 underline">Donation Sorting</Link>
				to see up to three lists:
			</p>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					<strong>In Progress</strong> — sessions someone already started. Tap one to
					resume exactly where they left off.
				</li>
				<li>
					<strong>Ready to Sort</strong> — donations logged at Receiving, waiting to be
					picked up. Tap one to start sorting it.
				</li>
				<li>
					<strong>Recently Completed</strong> — the last 25 finished sessions, open
					read-only.
				</li>
			</ul>
			<p class="text-sm text-gray-700">
				A donation whose donor is still unknown shows a red <em>donor ID pending</em>
				tag in each list.
			</p>
			<p class="text-sm text-gray-700">
				<strong>Start Sorting (untagged)</strong>, at the top of the page, opens a
				brand-new session with no Receiving record behind it — use it only for goods
				that showed up without going through Receiving first. Its donor field is fully
				editable, unlike a session that came from Receiving.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Working a pallet</h2>
			<ol class="list-decimal list-inside text-sm text-gray-700 space-y-3">
				<li>
					Type or scan the pallet's tag (e.g. <code class="font-mono text-xs">R00000042</code>)
					into <strong>Pallet Tag</strong>, or tap <strong>Camera Scan</strong> to use
					the device camera, then <strong>Set Pallet</strong>. Only Receiving pallets
					(tags starting with R) work here — a warehouse or other pallet's tag is refused, and so is a Receiving pallet that belongs to a different donation (open that donation from Ready to Sort instead). Everything you enter next is attributed to this pallet: the bar shows
					<em>Sorting from pallet …</em>. Lines entered with no pallet set still save,
					but won't be recorded against a source. Setting a pallet for the first time
					also marks it as being sorted.
				</li>
				<li>
					For each item on the pallet, scan its barcode or search by name in the item
					box. The <strong>Qty</strong> fills in as 1 — press Enter to save one, type
					another number and press Enter, or keep scanning (see below). Then check the
					<strong>disposition</strong> button:
					<strong>New</strong> (unused, still in its original packaging — counts into
					inventory), <strong>Usable</strong> (used or opened, in good condition — counts
					into inventory), <strong>Outdated</strong> (expired on arrival),
					<strong>Trash</strong> (damaged/unusable) or <strong>Divert</strong> (usable,
					but passed to another organization). You can also click <strong>Add</strong>.
					The disposition you last used stays selected, since runs of the same kind of
					goods are common. Only New and Usable add to stock on hand; Outdated, Trash and
					Divert are recorded for donor-quality reporting.
				</li>
				<li>
					Know the expiration date? Click the calendar button next to Qty, pick the
					date, then <strong>Done</strong> (<strong>Clear</strong> removes it). It's
					optional, and it's saved with that line and shown in the <em>Expires</em>
					column. It resets after each line you add.
				</li>
				<li>
					Made a mistake on a line? Click the trash icon next to it, then
					<strong>Delete?</strong> to confirm — it's a two-tap delete, no popup.
				</li>
				<li>
					When the pallet is empty, click <strong>Pallet Empty</strong>, then say
					whether it <strong>looks OK</strong> or should be
					<strong>set aside — damaged?</strong>. That's a one-tap note for a
					supervisor to follow up on later; it doesn't block anything here. Emptying
					the donation's last pallet automatically completes the donation. Use
					<strong>Change Pallet</strong> instead to move to a different pallet without
					emptying this one.
				</li>
			</ol>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Scanning barcodes</h2>
			<p class="text-sm text-gray-700">
				A barcode scanner works like a very fast typist, so a scan goes wherever the
				cursor is. After a click the page puts the cursor back where the next scan
				should go: the item box, or the Qty box when an item is already chosen. Don't
				worry about clicking around.
			</p>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					<strong>Scan, scan, scan.</strong> After an item is picked, scan the next
					barcode straight into the <strong>Qty</strong> box: the item you were on is
					saved (quantity 1, or whatever the Qty box showed) and the new barcode is
					looked up. Type a number and press Enter instead when there's more than one.
				</li>
				<li>
					<strong>Same item again?</strong> Scanning the <em>same</em> item again,
					straight into the Qty box, counts it instead: scan each of ten identical
					items and the quantity climbs to 10. Scan a different item and the count is
					saved.
				</li>
				<li>
					<strong>Cartons and cases.</strong> A case barcode that's already set up picks the
					single item inside and fills Qty with the number in the case. You can still
					change the number.
				</li>
				<li>
					<strong>Combine duplicate lines.</strong> If you scanned every item one at a
					time, the lines list will have many identical rows. When there are any, a
					<strong>Combine duplicate lines</strong> button appears at the top; click it
					and confirm to add up lines with the same item, pallet, disposition, condition
					and expiration date (entered by the same person on the same day). It can't be
					undone, so a single mistaken scan can no longer be deleted on its own
					afterward. Totals don't change.
				</li>
			</ul>
			<h3 class="font-semibold">What the beeps mean</h3>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>One short high beep — matched a single item.</li>
				<li>Two quick rising beeps — matched a carton or case.</li>
				<li>One lower, longer tone — the barcode isn't in the item list; a form opens.</li>
				<li>Two low buzzes — a misscan or failed lookup. Scan again or search by name.</li>
			</ul>
			<p class="text-sm text-gray-700">
				Prefer quiet? Click the speaker button in the top bar to mute or unmute; the
				page remembers it for that browser.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">A barcode the system doesn't know</h2>
			<p class="text-sm text-gray-700">
				Nothing is added to the item list until you click <strong>Save Item</strong>.
			</p>
			<ol class="list-decimal list-inside text-sm text-gray-700 space-y-3">
				<li>
					An unknown <strong>single-item</strong> barcode opens <strong>Add New Item</strong>
					with the UPC already filled in. If the barcode is found in a free online
					product database (Open Food Facts / Open Products Facts), a suggested
					description appears a moment later, with a note naming the source — check it
					against the product in front of you. If nothing is found, or the lookup is
					off or busy, the note says so; type the description yourself. Keep scanning
					at this point and you'll just hear the low tone again — finish or cancel the
					form first.
				</li>
				<li>
					Choose the <strong>Item Type</strong> (search by name or number). If this is
					a brand new type, choose to add one and fill in <strong>New Item Type</strong>,
					<strong>Measured By</strong> (a unit such as Each or Pound) and
					<strong>Category</strong> — all creatable inline. Then <strong>Save Item</strong>;
					the new item is selected and the cursor jumps to Qty.
				</li>
				<li>
					Under <em>This barcode is on:</em>, choose <strong>A case of items already in
					the list</strong> if the barcode is really a carton of something you already
					carry. That opens <strong>New Carton Barcode</strong>.
				</li>
				<li>
					An unknown <strong>carton</strong> barcode opens <strong>New Carton Barcode</strong>
					directly. Scan one of the single items inside it (or search for it) — if the
					carton's number matches an item, it's suggested, and items from the same
					manufacturer may be offered as buttons. Enter <strong>Packs in the carton</strong>
					and <strong>Items in each pack</strong> (6 packs of 5 makes 30 items per
					carton; for loose items with no inner packs, leave Packs at 1). Click
					<strong>Confirm &amp; Use</strong>: from then on, scanning that carton
					fills in the count automatically. <strong>This is a single item, not a
					carton</strong> switches back to Add New Item.
				</li>
				<li>
					Can't find the item by barcode at all? Type its name into the item search and
					choose <strong>Add "…" as a new item</strong> to open the same form.
				</li>
			</ol>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Session header &amp; wrapping up</h2>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					For a session that came from Receiving, the donor shows read-only (it was
					already recorded at the dock) with an <strong>Edit</strong> link if it needs
					correcting. For an untagged session, the donor field is editable directly.
					Search for the donor, or add a new one from the search box.
				</li>
				<li>
					<strong>Donor unidentified — flag for follow-up</strong> and
					<strong>Comments</strong> both save automatically as you change them —
					nothing to submit.
				</li>
				<li>
					The table under the line entry lists every line (time, item, quantity,
					expiration, disposition, pallet), newest first, with running totals underneath:
					Usable, Outdated, Trash, Diverted and the discard rate.
				</li>
				<li>
					Watch the save indicator in the top bar: <em>Saving…</em>, <em>All changes
					saved</em>, or a failure count with a <strong>Retry All</strong> button.
					A failed line also has its own <strong>Retry</strong>. Failed lines retry
					automatically once your connection comes back.
				</li>
				<li>
					Click <strong>Complete Session</strong> when every pallet is done. An item you
					picked but haven't added yet is added first (you'll be asked for a quantity if
					it's missing). If any lines still haven't reached the server, it asks you to
					confirm before completing anyway.
				</li>
				<li>
					<strong>All Sessions</strong> goes back to the list without losing the item
					you were entering. Leaving the page with unsaved work shows a warning.
				</li>
				<li>
					A completed session reopens read-only. If you need to fix something, use
					<strong>Reopen Session</strong> to go back to editing it.
				</li>
			</ul>
		</section>
	</HelpPage>
</template>
