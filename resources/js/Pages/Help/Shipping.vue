<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Help/Shipping.vue

	Static how-to guide for Shipping (resources/js/Pages/Shipping.vue) and the
	Driver Portal (resources/js/Pages/DriverPortal.vue). Reached from the help
	button on the Shipping page itself.
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
	<HelpPage guide="shipping" title="Shipping" :breadcrumb="breadcrumb">
		<template #intro>
			Hand finished orders to a driver, record when the truck leaves, and review the
			signed Bill of Lading (BOL) that comes back. Orders get here once
			<Link href="/help/order-filling" class="text-blue-600 underline">Order Filling</Link>
			marks them Filled.
		</template>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">How an order moves</h2>
			<p class="text-sm text-gray-700">
				Open <Link href="/shipping" class="text-blue-600 underline">Shipping</Link>. It
				has one table per stage, in this order, each with a count in its heading. You
				need the Manage Orders permission to use the page.
			</p>
			<ol class="list-decimal list-inside text-sm text-gray-700 space-y-2">
				<li><strong>Filled — Ready to Assign a Driver</strong> — picking is done and no driver is assigned yet.</li>
				<li><strong>Ready to Ship</strong> — a driver is assigned, but the truck hasn't left.</li>
				<li><strong>Shipped</strong> — staff confirmed the load left the dock.</li>
				<li><strong>Delivered — Pending Review</strong> — the signed BOL has been uploaded and a manager needs to check it.</li>
				<li><strong>Delivered — Waiting on BOL</strong> — the uploaded BOL was rejected; a usable one is still needed.</li>
				<li><strong>Recently Completed</strong> — the signed BOL was approved (the 25 most recent).</li>
			</ol>
			<p class="text-sm text-gray-700">
				Nothing moves on its own: each step below is something a person does. The page
				has no other shipping steps, such as tracking numbers or delivery dates.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Assigning a driver</h2>
			<ol class="list-decimal list-inside text-sm text-gray-700 space-y-2">
				<li>
					In <strong>Filled — Ready to Assign a Driver</strong>, each row shows the order
					number, partner, number of lines and total quantity.
				</li>
				<li>
					Pick a name from <strong>Select a driver...</strong>, then click
					<strong>Assign</strong>. The order moves to Ready to Ship.
				</li>
			</ol>
			<p class="text-sm text-gray-700">
				A driver has to be in the <strong>Drivers</strong> list at the bottom of the page
				before you can pick them (see below).
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Printing the BOL</h2>
			<p class="text-sm text-gray-700">
				In the <strong>Ready to Ship</strong> and <strong>Shipped</strong> tables, the
				<strong>Generated BOL</strong> column has a <strong>View BOL</strong> menu. Use it to
				download the PDF or send it to a printer set up for your site. Print it for the driver
				to sign. The BOL number is assigned the first time it's generated and stays the same if
				you print it again.
			</p>
			<p class="text-sm text-gray-700">
				Next to it is a <strong>Shipping Label</strong> menu. It makes a 4 x 6.5 in label to stick
				on the shipment, showing the order number (with a barcode), who and where it is going,
				the contact, fulfillment method, number of pallets, date packed, requested date and the
				special instructions (cut short if they are long). Only printers loaded with large
				labels are offered; the PDF download is always available. The pallet count comes from
				<strong>Pallets packed</strong> in Order Filling, and the label is blank there if it
				wasn't entered. The date packed is when the last item was picked.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Marking an order Shipped</h2>
			<p class="text-sm text-gray-700">
				When the truck actually leaves the dock, click <strong>Mark Shipped</strong> on that
				order in the <strong>Ready to Ship</strong> table. It is a manual step &mdash; nothing
				marks it for you.
			</p>
			<p class="text-sm text-gray-700">
				Picked the wrong driver? The Ready to Ship table has no way to change the driver
				once one is assigned, so check the name before you click Assign.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Drivers and the Driver Portal</h2>
			<p class="text-sm text-gray-700">
				A driver doesn't need an account. The <strong>Drivers</strong> table at the bottom
				lists each driver's name, phone, carrier and whether a Driver Portal PIN is set.
			</p>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					<strong>+ New Driver</strong> — enter a name (required), phone, and carrier (if a
					trucking company), then <strong>Save Driver</strong>.
				</li>
				<li>
					<strong>Set PIN</strong> / <strong>Reset PIN</strong> — enter a 5-digit PIN twice and
					click <strong>Save PIN</strong>. Easy-to-guess PINs are refused.
				</li>
			</ul>
			<p class="text-sm text-gray-700">
				Give the driver their phone number and PIN. They sign in at
				<Link href="/driver-portal" class="text-blue-600 underline">/driver-portal</Link>
				using <strong>Phone Number</strong> and <strong>PIN</strong>, and see only the loads
				assigned to them under <strong>Current Loads</strong> (Ready to Ship, Shipped, or
				waiting on a re-uploaded BOL). Each load shows delivery details (address, contact,
				window) under <strong>Delivery Details</strong> when the order has them.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">The signed BOL</h2>
			<p class="text-sm text-gray-700">
				After delivery, the driver taps <strong>Upload Signed BOL</strong> on the load and
				chooses a photo or PDF (JPG, PNG or PDF, up to 10 MB). The upload moves the order
				straight to <strong>Delivered</strong> &mdash; it doesn't have to be marked Shipped
				first. If a staff member opens the portal while logged in, they get a read-only list of
				every load currently out, with no upload button.
			</p>
			<p class="text-sm text-gray-700">
				The order then appears under <strong>Delivered — Pending Review</strong> on the Shipping
				page. A manager clicks <strong>Review</strong>, which opens the uploaded file:
			</p>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					<strong>Approve</strong> — accepts it and moves the order to Completed. For a photo you
					can first drag a rectangle over the part to keep, and the button becomes
					<strong>Approve Cropped</strong> (a PDF can't be cropped, so open it in a new tab).
				</li>
				<li>
					<strong>Reject</strong>, then <strong>Confirm Reject</strong> — optionally say what's
					wrong. The bad file is discarded and the order goes to
					<strong>Delivered — Waiting on BOL</strong>. The driver sees the load again with your
					note and uploads a new one, which sends it back for review.
				</li>
			</ul>
			<p class="text-sm text-gray-700">
				Approved orders list a <strong>View Signed BOL</strong> link under
				<strong>Recently Completed</strong>.
			</p>
		</section>
	</HelpPage>
</template>
