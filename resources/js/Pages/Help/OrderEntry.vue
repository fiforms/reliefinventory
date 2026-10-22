<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Help/OrderEntry.vue

	Static how-to guide for Order Entry (resources/js/Pages/OrderEntry.vue).
	Reached from the (?) button on that page (HelpReportController::PAGE_GUIDES) —
	there is no Help menu any more. Hand-kept in step with that page and with
	resources/views/reports/help/order-entry.blade.php.
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
	<HelpPage guide="order-entry" title="Order Entry" :breadcrumb="breadcrumb">
		<template #intro>
			Take a partner's request — by phone, or from a paper form they sent back — and
			enter it so the warehouse can fill it. Once submitted, it goes to
			<Link href="/help/order-filling" class="text-blue-600 underline">Order Filling</Link>.
		</template>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">What Order Entry is for</h2>
			<p class="text-sm text-gray-700">
				Partners call these <em>requests</em>; this screen (and the staff side of the
				app) still calls them <strong>orders</strong>. They are the same thing. You
				need the <strong>manage-orders</strong> permission to use the page.
			</p>
			<p class="text-sm text-gray-700">
				Entry saves as you go: the order is created the moment you confirm the partner,
				and every item line is sent to the server as soon as you add it. A dropped
				connection or a crash never loses more than the line you were typing.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">The order list</h2>
			<p class="text-sm text-gray-700">
				Open <Link href="/order-entry" class="text-blue-600 underline">Order Entry</Link>
				to see two lists. Tap any row to open that order.
			</p>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					<strong>Open Orders - tap to open</strong> — everything not yet finished:
					New Order, Ready to Fill, Filling, Filled, Ready to Ship, Shipped and
					Delivered (including Delivered waiting on a signed BOL). Columns: Date,
					Partner, Lines, Items, Status, Entered By.
				</li>
				<li>
					<strong>Recently Completed</strong> — the latest 25 orders that are finished
					(for example Completed or Closed - Unfulfilled).
				</li>
			</ul>
			<p class="text-sm text-gray-700">
				<strong>New Order</strong> starts a request. <strong>Offline Order Form</strong>
				(a <strong>Print</strong>-style menu) produces a printable form listing the item
				numbers we currently have some of, to send to a partner who will fill in
				quantities by hand and send it back for you to key in. It never shows stock
				numbers.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Step 1 — Who is this for?</h2>
			<ol class="list-decimal list-inside text-sm text-gray-700 space-y-3">
				<li>
					Click <strong>New Order</strong>. The screen is titled
					<strong>New Order — Who is this for?</strong>
				</li>
				<li>
					Search the <strong>Partner</strong> box by name, organization or city and pick
					the partner. (This is the receiving organization or distribution site placing
					the request.)
				</li>
				<li>
					Not in the list? Type the name and choose the add option. A
					<strong>New Partner</strong> form opens: names, organization, phone, email,
					<strong>Zip</strong> first, then <strong>Address</strong>, City, State and
					County. You need either a first and last name, or an organization. When the
					zip/address is filled in, the app may look up the county and suggest a
					corrected address &mdash; accept or keep what you typed. If a county is
					missing from the list, type it in the County box to add it (enter the 2-letter
					state first). Click <strong>Save Partner</strong>.
				</li>
				<li>
					<strong>Confirm Contact &amp; Shipping Details</strong> appears with the
					partner's details. This is where the order ships, so check it is current and fix
					anything that has changed. <strong>Verify Address</strong> re-checks the address
					if it hasn't been checked yet. Pressing Enter in a box moves to the next box.
				</li>
				<li>
					Click <strong>Start Order →</strong>. If you changed anything the button says
					<strong>Save Details &amp; Start Order →</strong>, and the changes are saved to the
					partner's record for everyone.
				</li>
			</ol>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Step 2 — Entering the items</h2>
			<p class="text-sm text-gray-700">
				The header shows <strong>Order #</strong> and the date, and a slim bar shows the
				partner's name, city/state and phone. <strong>Change</strong> there lets you
				pick a different partner (while the order is still editable).
			</p>
			<ol class="list-decimal list-inside text-sm text-gray-700 space-y-3">
				<li>
					Type an item number (or part of its name) into the <strong>Item #</strong> box
					and choose it. The cursor jumps to <strong>Qty</strong>. A note beside the item
					name shows roughly how many are on hand (for example
					<em>~40 usable on hand</em>, or <em>none on hand</em>). This is only a hint for
					you &mdash; never read stock numbers out to the partner, and you can still
					enter more than we have.
				</li>
				<li>
					Type the quantity (1 or more) and press <strong>Enter</strong> (or click
					<strong>Add</strong>). The line is saved and the cursor goes back to
					<strong>Item #</strong> for the next one. A <strong>Line comment
					(optional)</strong> box is also available for notes on that line.
				</li>
				<li>
					When an item is running low (at or below its low-stock level), a
					<strong>Need level (optional)</strong> box appears: <strong>Critical</strong>,
					<strong>Moderate</strong> or <strong>Low</strong>. Record what the partner
					tells you about how badly they need it. It is only context for whoever divides
					up short items later &mdash; leave it blank if they don't say.
				</li>
				<li>
					Entered the same item twice? A box titled <strong>Oops</strong> shows what you
					already entered. Type an <strong>Additional qty</strong> and click
					<strong>Combine →</strong> to add it to the existing line, or
					<strong>Cancel</strong> (or press Esc) to drop it.
				</li>
				<li>
					Wrong line? Click the trash icon beside it, then <strong>Delete?</strong> to
					confirm (or <strong>Keep</strong> to back out). To change a quantity, delete
					the line and add it again, or use the Combine box to add more.
				</li>
			</ol>
			<p class="text-sm text-gray-700">
				The top bar shows <em>Saving…</em>, <em>All changes saved</em>, or
				<em>N line(s) failed to save</em> with <strong>Retry All</strong> (each failed line
				also has <strong>Retry</strong>; failed lines also retry on their own when the
				connection returns). Totals for <strong>Lines</strong> and
				<strong>Total items</strong> appear under the table. <strong>← All Orders</strong>
				goes back to the list; the order stays open for later.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Step 3 — Review &amp; confirm</h2>
			<p class="text-sm text-gray-700">
				When every item is in, click <strong>Complete Order</strong> (top right). The
				<strong>Review &amp; Confirm — Order #</strong> screen has:
			</p>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li><strong>Order Summary</strong> — partner, number of lines and total items.</li>
				<li><strong>Comments</strong> — anything the warehouse should know.</li>
				<li>
					<strong>Needed But Not in Catalog</strong> — things the partner needs that
					aren't in our item list (the same as "Other Needs" on the paper form).
				</li>
				<li>
					<strong>Delivery or Pickup</strong> — choose one, plus a
					<strong>Needed By Date</strong>. For delivery also pick the days they can accept
					it (<strong>Any Day</strong> or individual days) and a
					<strong>Preferred Time</strong>. For pickup, the warehouse sets days and times.
				</li>
				<li>
					<strong>Delivery Contact</strong> (or <strong>Pickup Contact</strong>) — name and
					phone, filled from the partner; change them if someone else is receiving it.
				</li>
				<li>
					<strong>Special Delivery Instructions</strong> — gate codes, dock location, who
					to contact on arrival. These carry through to the BOL when the order ships.
				</li>
			</ul>
			<p class="text-sm text-gray-700">
				Click <strong>Submit Order</strong> to finish. <strong>← Back</strong> returns to
				the items without submitting.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">After you submit — the order lifecycle</h2>
			<p class="text-sm text-gray-700">
				Submitting moves the order from <strong>New Order</strong> to
				<strong>Ready to Fill</strong>. From there the system moves it along:
			</p>
			<p class="text-sm text-gray-700 font-mono">
				New Order → Ready to Fill → Filling → Filled → Ready to Ship → Shipped →
				Delivered → Completed
			</p>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					<strong>Only a New Order can be edited.</strong> Once submitted, lines and
					details are locked, because the floor is working from them. Opening such an
					order shows the items read-only with its status badge.
				</li>
				<li>
					Status is set by the app as work happens &mdash; you never pick it from a list.
					Filling is covered in
					<Link href="/help/order-filling" class="text-blue-600 underline">Order Filling</Link>
					and shipping in
					<Link href="/help/shipping" class="text-blue-600 underline">Shipping</Link>.
				</li>
				<li>
					An order where nothing could be picked ends as
					<strong>Closed - Unfulfilled</strong> and never reaches Shipping.
				</li>
			</ul>
		</section>
	</HelpPage>
</template>
