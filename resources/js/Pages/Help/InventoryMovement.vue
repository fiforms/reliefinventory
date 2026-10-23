<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Help/InventoryMovement.vue

	Static how-to guide for Inventory Movement (resources/js/Pages/PalletLocation.vue,
	/inventory-movement), with a short section on Pallet Management
	(resources/js/Pages/PalletManagement.vue). Reached from the "Help: ..." link
	on those pages; the printable copy is
	resources/views/reports/help/inventory-movement.blade.php — keep the two in step.
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
	<HelpPage guide="inventory-movement" title="Inventory Movement" :breadcrumb="breadcrumb">
		<template #intro>
			Keep track of where pallets are, and put sorted goods onto warehouse pallets so
			pickers can find them.
		</template>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">What this screen is for</h2>
			<p class="text-sm text-gray-700">
				Open <Link href="/inventory-movement" class="text-blue-600 underline">Inventory Movement</Link>
				(you need the Manage Pallets permission). It has three tabs:
				<strong>Pallets</strong> (the one you'll use all day — scan-driven),
				<strong>Locations</strong> and <strong>Recent Movements</strong> (look-up only).
			</p>
			<p class="text-sm text-gray-700">
				<strong>Unassigned means "still in the sorting area."</strong> When goods are
				sorted (see the <Link href="/help/sorting" class="text-blue-600 underline">Sorting</Link>
				guide) they count as stock on hand but sit in the sorting area. Putting them on a
				warehouse pallet moves them out of "unassigned" and gives them a place on the shelf.
				Stock is pooled by item type, so you tell the system <em>how many</em> of an item went
				onto a pallet — not which donation it came from.
			</p>
			<p class="text-sm text-gray-700">
				Pallet tags start with a letter for the kind: <strong>R</strong> Receiving,
				<strong>W</strong> Warehouse, <strong>S</strong> Shipping, <strong>H</strong> Hold,
				<strong>Q</strong> Quarantine, followed by eight digits (for example
				<code class="font-mono text-xs">W00000123</code>). A Warehouse pallet is either
				<em>sealed</em> (wrapped and closed), <em>open</em> or <em>empty</em>. Pickers can
				only take from open pallets — see the
				<Link href="/help/order-filling" class="text-blue-600 underline">Order Filling</Link>
				guide.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">The scan bar</h2>
			<p class="text-sm text-gray-700">
				At the top of the Pallets tab is one box, always ready for the next scan. After
				you click anywhere on the page, the cursor returns to it (unless you're typing in
				another field). Scan or type a code and press Enter or <strong>Find</strong>.
			</p>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>With no pallet showing, a scan is read as a <strong>pallet barcode</strong>.</li>
				<li>
					With a pallet showing, a scan that matches a <strong>location code</strong>
					moves that pallet there at once. Any other scan is read as a pallet and
					replaces the one on screen.
				</li>
				<li>
					A green message confirms what happened; a red one says what went wrong (for
					example, an unknown pallet). The speaker button at the right mutes or
					unmutes the scan sounds.
				</li>
			</ul>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Looking up a pallet</h2>
			<p class="text-sm text-gray-700">
				Scan a pallet's tag. The screen shows its tag and kind, where it is
				(<em>at</em> a location, or <em>no location recorded</em>), its status, when it
				was last updated and, for a Warehouse pallet, the earliest expiration date of
				what's on it. Below that:
			</p>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					<strong>Pallet Contents</strong> — for a Warehouse pallet, each item and its
					quantity. For a Receiving pallet, what sorting recorded coming off it (item,
					disposition, quantity). Other kinds don't track contents.
				</li>
				<li>
					<strong>Movement History</strong> — every move and status change, newest first
					(date, location, status, notes).
				</li>
			</ul>
			<p class="text-sm text-gray-700">
				<strong>Clear / new pallet</strong> removes the pallet from the screen so you
				can scan another or build a new one.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Moving a pallet</h2>
			<ol class="list-decimal list-inside text-sm text-gray-700 space-y-3">
				<li>Scan the pallet's tag.</li>
				<li>
					Scan the barcode on the shelf or rack location. The move is recorded
					immediately ("… moved to …"), and the pallet keeps its status. Scanning the
					location it's already at just says so.
				</li>
				<li>
					Prefer the form? Type or pick a code in <strong>Location</strong>, add
					<strong>Notes</strong> if you like, and click <strong>Record Movement</strong>.
					The location must be a known, active one.
				</li>
			</ol>
			<p class="text-sm text-gray-700">
				For Receiving, Shipping, Hold and Quarantine pallets there is also a
				<strong>Status</strong> drop-down; change it and click
				<strong>Record Status</strong> (the button reads Record Movement when the
				location changed). A Warehouse pallet has no Status drop-down — its status
				changes through sealing and unsealing below.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Building a new Warehouse pallet</h2>
			<p class="text-sm text-gray-700">
				A lead or forklift driver does this as a pallet leaves the sorting area. With
				no pallet showing, the page says <strong>Build a new Warehouse pallet</strong>.
			</p>
			<ol class="list-decimal list-inside text-sm text-gray-700 space-y-3">
				<li>
					Under <strong>In the sorting area</strong> is the pick-up list: each item
					still waiting, how many (<em>Waiting</em>) and its earliest expiration. Click
					<strong>Put all N on pallet</strong> to stage an item. Items that share an
					ACS item number are grouped into one row; click it to expand the individual
					items, or use its button to stage them all.
				</li>
				<li>
					Staged items appear in a table above. Change a <strong>Quantity</strong> if
					only part of it is going on this pallet (it can't exceed what's waiting), or
					click <strong>Remove</strong>.
				</li>
				<li>
					Check each item's <strong>Expires</strong> date in the list — it's the
					earliest date known for that item, and goes on its item label; change or clear it
					if it's wrong. Type or scan the <strong>Location</strong> the pallet is going to.
					The pallet's own <strong>Expires</strong> date is filled with the earliest date among
					the items; change it or click <strong>Auto</strong> to go back. Add
					<strong>Notes</strong> if needed.
				</li>
				<li>
					Click <strong>Seal &amp; Place</strong>. A new Warehouse pallet is created,
					sealed, and set at that location. A green line shows its tag and contents, with
					a <strong>Print Pallet Label</strong> button — print it and put it on the pallet —
					and a <strong>Print Item Labels</strong> button: one small label for each item you
					put on, showing the ACS item number and description, quantity, earliest expiration
					and pallet, with a barcode. Stick each on its cases. Item labels print on the small
					label size (set on the Printers page). To print them again later, scan the pallet:
					its <strong>Inventory Labels</strong> list has a Print button for each.
				</li>
			</ol>
			<p class="text-sm text-gray-700">
				The system won't let you put more of an item on pallets than is still in the
				sorting area ("Only N of … are not already on a pallet"). When everything is
				on a pallet the list says <em>Nothing waiting — everything sorted is already on a
				pallet.</em>
			</p>
			<p class="text-sm text-gray-700">
				<strong>Adding to an existing pallet:</strong> scan a Warehouse pallet that is
				<em>open</em>; the same list now reads <strong>Add items to W…</strong> and the
				button is <strong>Add to pallet</strong>. A sealed pallet must be unsealed first.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Sealing and unsealing</h2>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					A <strong>sealed</strong> Warehouse pallet can only be moved or unsealed. The
					page reminds you: unseal it to add items or adjust what is on it. Pickers can't
					take from a sealed pallet either — unless the pick takes the whole pallet, or
					someone unseals it first.
				</li>
				<li>Click <strong>Unseal</strong> to open it; click <strong>Re-seal</strong> when you're finished.</li>
				<li>
					A pallet that runs out of goods (a picker takes the last items) is marked
					empty automatically.
				</li>
			</ul>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Adjusting inventory on a pallet</h2>
			<p class="text-sm text-gray-700">
				Counts sometimes drift: something is damaged, a case is missing, or someone
				put the wrong item on. An adjustment corrects what's recorded on one pallet.
				It needs a separate permission, <strong>Adjust Inventory</strong> (normally
				Office and Administrator) — without it, you won't see the buttons below.
			</p>
			<p class="text-sm text-gray-700">
				It's only available for a Warehouse pallet that is <strong>open</strong> and
				already has a <strong>location</strong>. Then two buttons appear:
				<strong>Add from sorting area</strong> and <strong>Adjust inventory</strong> —
				you can use one at a time.
			</p>
			<ol class="list-decimal list-inside text-sm text-gray-700 space-y-3">
				<li>Click <strong>Adjust inventory</strong>.</li>
				<li>
					For each item, type a number in <strong>Change</strong>: a minus sign removes
					(<code class="font-mono text-xs">-3</code>), a plain number adds. <strong>After</strong>
					shows the result. To add an item that isn't on the pallet yet, search for it
					under <strong>Another item</strong>.
				</li>
				<li>
					Choose a <strong>Reason</strong>: Shrinkage, Damaged items, Correcting count,
					Correcting item type or Other. For Other you must explain in
					<strong>Notes</strong>.
				</li>
				<li>
					Click <strong>Record Adjustment</strong>. You can't take off more than the
					pallet has.
				</li>
			</ol>
			<p class="text-sm text-gray-700">
				Every adjustment is logged with who, when and why, and it changes stock on
				hand itself — it isn't a sorting entry.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Locations and Recent Movements</h2>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					<strong>Locations</strong> — a table of every active location with the number
					of live pallets there, broken out by status. Click a location to list its
					pallets, and click a pallet's tag to open it on the Pallets tab. Pallets with
					no location appear as <em>No location recorded</em>. Emptied, shipped,
					collected, released, condemned and missing pallets aren't counted.
				</li>
				<li>
					<strong>Recent Movements</strong> — the 100 most recent entries across all
					pallets (date, pallet, location, status, notes).
				</li>
			</ul>
			<p class="text-sm text-gray-700">
				The order of locations along the warehouse (the pick path) is set up under
				Setup by an administrator; it decides the order pickers walk.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Pallet Management</h2>
			<p class="text-sm text-gray-700">
				<Link href="/pallet-management" class="text-blue-600 underline">Pallet Management</Link>
				(also needs Manage Pallets) is the plain record list of pallets — use it to
				<strong>reprint a pallet label</strong> (Print Pallet Label) or to correct a
				pallet's record by hand. It lists pallets that are still in use; open one to
				see its Pallet Tag, Kind, Date Packed, Location, Status, Container Type
				(Pallet, Gaylord, Box, Bag or Tote) and its Pallet Status History. The kind can't be changed once
				a pallet is labeled.
			</p>
			<p class="text-sm text-gray-700">
				For everyday work — moving pallets, building them, adjusting them — use
				Inventory Movement instead: it records each change in the history and checks the
				rules for you, while Pallet Management lets you set the status directly (from a list of the statuses valid for that kind of pallet).
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Related guides</h2>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li><Link href="/help/sorting" class="text-blue-600 underline">Donation Sorting</Link> — how goods get into the sorting area.</li>
				<li><Link href="/help/order-filling" class="text-blue-600 underline">Order Filling</Link> — how pickers take goods from pallets.</li>
				<li><Link href="/help/shipping" class="text-blue-600 underline">Shipping</Link> — what happens after an order is filled.</li>
			</ul>
		</section>
	</HelpPage>
</template>
