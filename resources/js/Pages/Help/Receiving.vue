<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Help/Receiving.vue

	Static how-to guide for the Receiving page (resources/js/Pages/Receiving.vue).
	Part of the Help menu (#help) added alongside Reports/Setup on the main
	menu — see database/migrations/2026_08_18_170000_add_help_menu.php.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
	breadcrumb: {
		type: Array,
	},
});

function printGuide() {
	window.open('/report/help/receiving', '_blank');
}
</script>

<template>
	<Head title="Help: Receiving" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<div class="flex items-start justify-between gap-4">
				<div>
					<h1 class="text-2xl font-bold">Receiving</h1>
					<p class="text-gray-600 mt-1">
						Log what comes off the truck — a donation, purchased equipment, supplies, or
						anything else arriving at the warehouse — before it's sorted into inventory.
					</p>
				</div>
				<button
					type="button"
					@click="printGuide"
					class="shrink-0 px-3 py-2 text-sm rounded bg-blue-600 text-white hover:bg-blue-700"
				>
					Print these instructions
				</button>
			</div>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<p class="text-sm text-gray-700">
					In order to get the most out of these instructions, we suggest you print them so
					you can follow along step by step without switching between screens. You can also
					use a separate device, or if your device supports split-screen, pull up both pages
					side by side.
				</p>
			</section>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Welcoming the driver &amp; safety</h2>
				<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
					<li>Introduce yourself and welcome the driver.</li>
					<li>If it's their first time at this warehouse, let them know where the restrooms are.</li>
					<li>Let them know you'll need to get some information from them for our records.</li>
					<li>No forklifts are allowed on or in box trucks.</li>
					<li>
						Whenever warehouse personnel need to enter the truck or trailer to unload it, the
						driver must be in the warehouse — not in the truck — while that's happening. Wheel
						chocks should be in place, and, where the warehouse is equipped for it, the
						trailer lock engaged before anyone enters the trailer.
					</li>
				</ul>
			</section>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">What Receiving is for</h2>
				<p class="text-sm text-gray-700">
					A Receiving record is the dock-side log entry for one arrival: who it came from
					(or "unknown" for now), roughly how much of it there is, and — for donations —
					the labels that get tagged and sent on to
					<Link href="/help/sorting" class="text-blue-600 underline">Donation Sorting</Link>.
					It is <em>not</em> the detailed item-by-item count.
				</p>
			</section>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Step by step</h2>
				<ol class="list-decimal list-inside text-sm text-gray-700 space-y-3">
					<li>
						Open <Link href="/receiving" class="text-blue-600 underline">Receiving</Link>
						and click <strong>Record an Intake</strong> to start a record for the arrival in
						front of you.
					</li>
					<li>
						Pick a <strong>Category</strong> — Donation, Equipment, Supplies, or Other.
						Equipment and supply deliveries are for items the warehouse itself will use, not
						donations that will be distributed. Only Donations get labels tracked through
						sorting; the others are logged for the record but don't flow into the sorting
						queue.
					</li>
					<li>
						Search for the <strong>Donor/Source</strong> by name or organization. If they're
						not in the system yet, use the inline "create new donor" option — a first name,
						last name, or organization is enough, they don't all have to be filled in.
					</li>
					<li>
						Fill in <strong>Where did this donation come from?</strong> with whatever
						location information the driver can give you, every time — regardless of whether
						you know exactly who the donor is.
					</li>
					<li>
						If you genuinely don't know who dropped this off, check
						<strong>"Donor Unidentified"</strong> instead of guessing. This just flags the
						record for follow-up — it stays visible in the Receiving list (even after the
						donation is marked Complete) until someone follows up and fixes the source.
					</li>
					<li>
						Answer <strong>Did this shipment arrive on pallets?</strong> and fill in the
						<strong>Number of Pallets</strong> — fill in the count either way, even if it
						didn't arrive palletized (it will likely still be palletized here at the
						warehouse for the move to sorting).
					</li>
					<li>
						Pick <strong>How did this arrive?</strong> (Semi, Box Truck, Personal Vehicle, or
						Other), and fill in the <strong>Driver Name</strong> and <strong>Driver
						Phone</strong> you gathered when you welcomed them.
					</li>
					<li>
						Fill in whatever you can of the <strong>Manifest</strong> (what the paperwork
						says came in) and <strong>Comments</strong>. None of these block sorting —
						they're context for whoever works this donation next.
					</li>
					<li>
						<strong>Save.</strong> For a Donation, the record now lets you add labels.
					</li>
					<li>
						For each physical unit — pallet, gaylord, box, or bag — enter the quantity, pick
						the container type, and add a short description (and an item tag if you already
						know what's on it), then click <strong>Add Label(s)</strong>. Each one gets a
						barcode tag, whether or not it's on a pallet.
					</li>
					<li>
						Click <strong>Print All Labels</strong> to print one combined PDF of every label
						for this donation. Attach each printed label to its pallet, gaylord, box, or bag
						before it leaves the dock.
					</li>
					<li>
						The received donation is now ready to go to the staging area for
						<Link href="/help/sorting" class="text-blue-600 underline">sorting</Link>.
					</li>
				</ol>
			</section>

			<section class="space-y-3">
				<h2 class="text-lg font-semibold">What the pallet number means</h2>
				<p>
					Every printed label starts with a letter, then an 8-digit number — for example
					<strong>R00000042</strong>. The number just counts up (it's never reused, even if
					that pallet later empties out), and the letter says where in the warehouse that
					unit currently lives, not what's on it:
				</p>
				<ul class="list-disc list-inside space-y-1">
					<li><strong>R</strong> — Receiving (a fresh label from this page, not yet sorted)</li>
					<li><strong>W</strong> — Warehouse (sorted stock, on the shelf)</li>
					<li><strong>S</strong> — Shipping (staged and building toward an outgoing order)</li>
					<li><strong>H</strong> — Hold (set aside for a specific order being filled)</li>
					<li><strong>Q</strong> — Quarantine (flagged for review before it can move again)</li>
				</ul>
				<p>
					A pallet record is one load's trip, not the physical pallet underneath it — the
					letter is fixed for that trip and never changes. The tag Receiving prints (an
					<strong>R</strong> label) is what Sorting scans to pull up this same donation; once
					sorted stock moves onward — into the warehouse, out to Shipping, on Hold for an
					order, or into Quarantine — that's tracked as a new pallet record with its own tag
					and letter, not a relabel of the original.
				</p>
			</section>

			<p class="text-xs text-gray-500">
				Questions or something not working the way this guide describes? Use
				<strong>Report an Issue</strong> from the profile menu.
			</p>
		</div>
	</AuthenticatedLayout>
</template>
