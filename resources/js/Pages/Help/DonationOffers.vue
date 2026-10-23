<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Help/DonationOffers.vue

	Static how-to guide for the Donation Offers page
	(resources/js/Pages/DonationOffers.vue, /receiving/offers). Reached from the
	"Help: ..." link on that page — there is no Help
	menu any more. Hand-kept in step with
	resources/views/reports/help/donation-offers.blade.php.
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
	<HelpPage guide="donation-offers" title="Donation Offers" :breadcrumb="breadcrumb">
		<template #intro>
			Keep track of donations that are offered by phone (or email, or in person) before
			anything ships, decide whether to accept them, and tie each accepted one to the
			load when it arrives at
			<Link href="/help/receiving" class="text-blue-600 underline">Receiving</Link>.
		</template>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">What offers are for</h2>
			<p class="text-sm text-gray-700">
				Not every donation is phoned in first &mdash; a walk-in or a surprise truck is
				logged straight at Receiving and never needs an offer. But when someone calls
				ahead, an offer gives everyone one place to see what's coming, who agreed to
				take it, and when it's expected. Each offer keeps a full history of every call
				and decision.
			</p>
			<p class="text-sm text-gray-700">
				Open it from the <strong>Donation Offers</strong> button on
				<Link href="/receiving" class="text-blue-600 underline">Receiving</Link>. Anyone
				who can use Receiving can open the page, log a call and edit an open offer.
				<strong>Deciding</strong> an offer (Approve, Refuse, Divert, Cancel Offer, Match to
				Arrival) needs the separate <strong>Donation Offers</strong> permission. Without it
				you can still log and update offers, and the decision buttons are replaced by a
				note saying the permission is required.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">The life of an offer</h2>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li><strong>Offered</strong> &mdash; someone called; nobody has decided yet.</li>
				<li>
					<strong>Pending Arrival</strong> &mdash; accepted (Approve). We're expecting it
					by the ETA window.
				</li>
				<li>
					<strong>Received</strong> &mdash; it arrived and was matched to a Receiving
					record. Finished.
				</li>
				<li>
					<strong>Refused</strong> or <strong>Diverted</strong> &mdash; we said no, or
					sent the donor to another organization. Finished.
				</li>
				<li>
					<strong>Cancelled</strong> &mdash; an accepted offer that fell through. Finished.
				</li>
			</ul>
			<p class="text-sm text-gray-700">
				Only Offered and Pending Arrival offers can be edited. Once an offer is Refused,
				Diverted, Cancelled or Received it is kept as history and locked. A pending offer whose ETA window (or single ETA day) has fully passed shows an <strong>overdue</strong> tag in the list.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Logging a new offer</h2>
			<ol class="list-decimal list-inside text-sm text-gray-700 space-y-3">
				<li>
					Click <strong>Log a Donation Offer</strong>.
				</li>
				<li>
					Pick the <strong>Donor / Source</strong>. If they aren't in the system, use the
					create option (a first name, last name, or organization is enough).
				</li>
				<li>
					If the donor is an organization, a <strong>Contact Person</strong> box appears.
					Pick or add the person who made the call.
				</li>
				<li>
					Describe <strong>What's being offered</strong>, and, if the donor knows it, fill
					in the <strong>Rough ETA Window (if known)</strong> dates. You can also record <strong>How did the offer come in?</strong> and any <strong>Notes from the conversation</strong>; they go in the offer's first history entry.
				</li>
				<li>
					Save. The offer starts as <strong>Offered</strong> and its history shows
					<strong>Logged as Offered</strong>.
				</li>
			</ol>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Working the list</h2>
			<p class="text-sm text-gray-700">
				The list shows each offer's donor, status, ETA (date range) and description.
				Offers with the soonest ETA are at the top; offers with no ETA are at the bottom.
				Tick <strong>Pending (ETA-sorted) only</strong> to see just the accepted offers
				we're waiting on &mdash; a handy "what's coming?" view. The list shows every open
				offer plus the 150 most recent finished ones. <strong>&larr; Back to Receiving</strong>
				returns to the intake page.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Deciding an offer</h2>
			<p class="text-sm text-gray-700">
				Open an offer and look at <strong>Donor History</strong> first &mdash; it lists that
				donor's earlier donations, requests and offers, so the decision isn't made blind.
				Then, under <strong>Decision</strong> (these buttons need the Donation Offers
				permission), choose one. Each opens a short form; fill it in and press
				<strong>Confirm</strong>. You can also pick a <strong>Contact Method</strong> (Phone,
				Email, In Person, Other) and add <strong>Notes</strong>, which are recorded in the
				history.
			</p>
			<p class="text-sm text-gray-700 font-semibold">On an Offered offer</p>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					<strong>Approve</strong> &mdash; accepts it. Enter the <strong>ETA Window</strong>
					(the start date is required, the end is optional) and any
					<strong>Transit Notes</strong>. The offer becomes Pending Arrival.
				</li>
				<li>
					<strong>Refuse</strong> &mdash; enter the <strong>Reason (required)</strong>.
				</li>
				<li>
					<strong>Divert</strong> &mdash; enter <strong>Diverted to (required)</strong>, the
					organization the donor was sent to.
				</li>
			</ul>
			<p class="text-sm text-gray-700 font-semibold">On a Pending Arrival offer</p>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					<strong>Cancel Offer</strong> &mdash; the donation isn't coming after all. Enter
					the <strong>Reason (required)</strong>.
				</li>
				<li>
					<strong>Match to Arrival</strong> &mdash; see the next section.
				</li>
			</ul>
			<p class="text-sm text-gray-700 font-semibold">On either</p>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					<strong>Log a Call / Update ETA</strong> &mdash; the donor called back to move the
					date or add detail, and nothing is decided. Change the ETA Window or the
					description if needed and write a note (required). The status stays the same and
					the call is added to the history.
				</li>
			</ul>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">When the donation arrives: matching</h2>
			<p class="text-sm text-gray-700">
				Matching links the accepted offer to the actual Receiving record and marks the
				offer <strong>Received</strong>. There are three ways, all with the same result:
			</p>
			<ol class="list-decimal list-inside text-sm text-gray-700 space-y-3">
				<li>
					<strong>At the dock (easiest).</strong> On
					<Link href="/receiving" class="text-blue-600 underline">Receiving</Link>, click
					<strong>Record an Intake</strong>. At the top, under <strong>Does this match a
					phoned-in offer?</strong>, click the card for this donor. The donor, contact
					person and description are filled in for you, and the offer is marked Received
					when you save the intake.
				</li>
				<li>
					<strong>From the offer.</strong> Open the Pending Arrival offer and click the
					link <strong>start a new Receiving intake for this offer</strong>. Receiving opens
					a new intake with the offer already selected.
				</li>
				<li>
					<strong>After the fact.</strong> If the load was already entered at Receiving
					without picking the offer, open the offer, click <strong>Match to Arrival</strong>,
					choose the intake under <strong>Match to arrived donation</strong> (search the
					list of intakes that aren't linked to an offer yet &mdash; the 50 most recent
					are offered), and press <strong>Confirm</strong>.
				</li>
			</ol>
			<p class="text-sm text-gray-700">
				A Receiving record can be linked to only one offer, and only a Pending Arrival offer
				can be matched.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">History</h2>
			<p class="text-sm text-gray-700">
				At the bottom of every offer, <strong>History</strong> lists every step: who did it,
				when, how the donor was contacted, and the notes. Entries read like
				<strong>Offered &rarr; Pending Arrival</strong> for a decision,
				<strong>Update &mdash; Pending Arrival</strong> for a call that changed nothing but the
				details, and <strong>Logged as Offered</strong> for the first entry. Nothing in the
				history can be edited or removed.
			</p>
		</section>
	</HelpPage>
</template>
