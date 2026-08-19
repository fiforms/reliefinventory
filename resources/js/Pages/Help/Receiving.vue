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
</script>

<template>
	<Head title="Help: Receiving" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<div>
				<h1 class="text-2xl font-bold">Receiving</h1>
				<p class="text-gray-600 mt-1">
					Log what comes off the truck — a donation, purchased equipment, supplies, or
					anything else arriving at the warehouse — before it's sorted into inventory.
				</p>
			</div>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">What Receiving is for</h2>
				<p class="text-sm text-gray-700">
					A Receiving record is the dock-side log entry for one arrival: who it came from
					(or "unknown" for now), roughly how much of it there is, and — for donations —
					the pallets that get tagged and sent on to
					<Link href="/donation-sorting" class="text-blue-600 underline">Donation Sorting</Link>.
					It is <em>not</em> the detailed item-by-item count; that happens later, during
					sorting.
				</p>
			</section>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Step by step</h2>
				<ol class="list-decimal list-inside text-sm text-gray-700 space-y-3">
					<li>
						Open <Link href="/receiving" class="text-blue-600 underline">Receiving</Link>
						and click <strong>New Record</strong> to start a record for the arrival in
						front of you.
					</li>
					<li>
						Pick a <strong>Category</strong> — Donation, Equipment, Supplies, or Other.
						Only Donations get pallets tracked through sorting; the others are logged for
						the record but don't flow into the sorting queue.
					</li>
					<li>
						Search for the <strong>Donor/Source</strong> by name or organization. If they're
						not in the system yet, use the inline "create new donor" option — a first name,
						last name, or organization is enough, they don't all have to be filled in.
					</li>
					<li>
						If you genuinely don't know who dropped this off, check
						<strong>"Donor unidentified — flag for follow-up"</strong> instead of guessing
						or leaving it blank. Flagged records stay visible in the Receiving list (even
						after the donation is marked Complete) until someone follows up and fixes the
						source.
					</li>
					<li>
						Fill in whatever you can of the <strong>Rough Container Count</strong>,
						<strong>Manifest</strong> (what the paperwork says came in), <strong>Manifest
						Weight</strong>, and <strong>Comments</strong>. None of these block sorting —
						they're context for whoever works this donation next.
					</li>
					<li>
						<strong>Save.</strong> For a Donation, the record now lets you add pallets.
					</li>
					<li>
						For each physical pallet, enter the quantity and a short description (and an
						item tag if you already know what's on it), then click
						<strong>Add Pallet(s)</strong>. Each one gets a barcode tag.
					</li>
					<li>
						Click <strong>Print All Labels</strong> to print one combined PDF of every
						pallet label for this donation, and tag the physical pallets before they leave
						the dock.
					</li>
					<li>
						That's it for Receiving — the donation now shows status <strong>Sorting</strong>
						and moves through <Link href="/donation-sorting" class="text-blue-600 underline">Donation Sorting</Link>
						from here (see the <Link href="/help/sorting" class="text-blue-600 underline">Sorting guide</Link>).
						A pallet with only one item on it is flagged automatically for expedited
						sorting.
					</li>
				</ol>
			</section>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Finding things in the list</h2>
				<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
					<li>Search by donor name or organization to find a record quickly.</li>
					<li>
						Check <strong>"Flagged for donor ID only"</strong> to see just the records
						waiting on a source follow-up.
					</li>
					<li>
						A <strong>donor ID pending</strong> badge marks a record flagged in step 4
						above; a <strong>ready to close out</strong> badge marks one down to its last
						pallet still in sorting.
					</li>
				</ul>
			</section>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Status and Daily Close-Out</h2>
				<p class="text-sm text-gray-700">
					A donation moves <strong>Received → Sorting → Complete</strong> on its own as its
					pallets get worked and emptied in Donation Sorting — there's nothing to set by
					hand. Once a donation is down to its last pallet still in sorting, a
					<strong>Close Out</strong> button appears on its record; use it to finalize the
					donation for the day once that last pallet is done.
				</p>
			</section>

			<p class="text-xs text-gray-500">
				Questions or something not working the way this guide describes? Use
				<strong>Report an Issue</strong> from the profile menu.
			</p>
		</div>
	</AuthenticatedLayout>
</template>
