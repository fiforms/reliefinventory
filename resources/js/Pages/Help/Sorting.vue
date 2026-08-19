<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Help/Sorting.vue

	Static how-to guide for Donation Sorting (resources/js/Pages/DonationSorting.vue).
	Part of the Help menu (#help) — see database/migrations/2026_08_18_170000_add_help_menu.php
	and 2026_08_18_180000_add_sorting_help_menu_item.php.
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
	<Head title="Help: Donation Sorting" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<div>
				<h1 class="text-2xl font-bold">Donation Sorting</h1>
				<p class="text-gray-600 mt-1">
					Turn the pallets logged at
					<Link href="/help/receiving" class="text-blue-600 underline">Receiving</Link>
					into item-by-item ledger entries — this is where a donation actually becomes
					counted inventory.
				</p>
			</div>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">What sorting is for</h2>
				<p class="text-sm text-gray-700">
					Sorting is scan-driven and saves as you go — every line you enter is sent to
					the server immediately, so a dropped connection or a crash never loses more
					than the one line you were on. Work one pallet at a time: scan its tag once,
					then every item you enter counts against that pallet until you scan (or clear)
					a different one. That's what keeps each item traceable back to its source
					donation.
				</p>
			</section>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Starting or resuming a session</h2>
				<p class="text-sm text-gray-700">
					Open <Link href="/donation-sorting" class="text-blue-600 underline">Donation Sorting</Link>
					to see three lists:
				</p>
				<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
					<li>
						<strong>Ready to Sort</strong> — donations logged at Receiving, waiting to be
						picked up. Tap one to start sorting it.
					</li>
					<li>
						<strong>In Progress</strong> — sessions someone already started. Tap to
						resume exactly where they left off.
					</li>
					<li>
						<strong>Recently Completed</strong> — finished sessions, open read-only.
					</li>
				</ul>
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
						the device camera, then <strong>Set Pallet</strong>. Everything you enter
						next is attributed to this pallet — lines entered with no pallet set still
						save, but won't be traceable back to a source.
					</li>
					<li>
						For each item on the pallet: search or scan its UPC into the item field,
						enter a <strong>Qty</strong>, pick a <strong>disposition</strong> —
						<strong>Usable</strong> (counts into inventory), <strong>Outdated</strong>
						(expired on arrival), <strong>Trash</strong> (damaged/unusable), or
						<strong>Divert</strong> (usable, but passed to another organization) — then
						click <strong>Add</strong>. The disposition button you last used stays
						selected, since runs of the same kind of goods are common.
					</li>
					<li>
						Don't recognize an item? Type its name into the item search and choose
						<strong>Add "…" as a new item</strong> to open the quick-add form. A brand
						new item type needs a description, a unit (e.g. Each, Pound), and a
						category — all creatable inline without leaving the modal.
					</li>
					<li>
						Made a mistake on a line? Click the trash icon next to it, then confirm —
						it's a two-tap delete, no popup.
					</li>
					<li>
						When the pallet is empty, click <strong>Pallet Empty</strong>, then say
						whether it <strong>looks OK</strong> or should be
						<strong>set aside — damaged</strong>. That's a one-tap note for a
						supervisor to follow up on later; it doesn't block anything here. Emptying
						the donation's last pallet automatically completes the donation.
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
					</li>
					<li>
						<strong>Donor unidentified — flag for follow-up</strong> and
						<strong>Comments</strong> both save automatically as you change them —
						nothing to submit.
					</li>
					<li>
						Watch the save indicator in the top bar: <em>Saving…</em>, <em>All changes
						saved</em>, or a failure count with a <strong>Retry All</strong> button.
						Failed lines also retry automatically once your connection comes back.
					</li>
					<li>
						Click <strong>Complete Session</strong> when every pallet is done. If any
						lines haven't reached the server yet, it asks you to confirm before
						completing anyway.
					</li>
					<li>
						A completed session reopens read-only. If you need to fix something, use
						<strong>Reopen Session</strong> to go back to editing it.
					</li>
				</ul>
			</section>

			<p class="text-xs text-gray-500">
				Questions or something not working the way this guide describes? Use
				<strong>Report an Issue</strong> from the profile menu.
			</p>
		</div>
	</AuthenticatedLayout>
</template>
