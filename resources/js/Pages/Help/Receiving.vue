<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Help/Receiving.vue

	Static how-to guide for the Receiving page (resources/js/Pages/Receiving.vue).
	Reached from the "Help: ..." link on that page —
	there is no Help menu any more. Hand-kept in step with the wizard there
	(Details -> Photo -> Print Labels) and with
	resources/views/reports/help/receiving.blade.php.
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
	<HelpPage guide="receiving" title="Receiving" :breadcrumb="breadcrumb">
		<template #intro>
			Log what comes off the truck — a donation, purchased equipment, supplies, or
			anything else arriving at the warehouse — before it's sorted into inventory.
		</template>

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
				(or unknown for now), what kind of load it is and roughly how many pallets,
				boxes, bags or totes there are, and — for donations — the labels that get
				attached to the containers and sent on to
				<Link href="/help/sorting" class="text-blue-600 underline">Donation Sorting</Link>.
				It is <em>not</em> the item-by-item count, and you do not list what is on each
				pallet here — opening the containers and counting is Sorting's job.
			</p>
			<p class="text-sm text-gray-700">
				The screen walks you through three steps:
				<strong>Details</strong>, then <strong>Photo</strong>, then
				<strong>Print Labels</strong>. Your work is saved when you press
				<strong>Continue</strong> on the Details step.
			</p>
			<p class="text-sm text-gray-700">
				Was this donation phoned in ahead of time? See
				<Link href="/help/donation-offers" class="text-blue-600 underline">Donation Offers</Link>.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Step 1 &mdash; Details</h2>
			<ol class="list-decimal list-inside text-sm text-gray-700 space-y-3">
				<li>
					Open <Link href="/receiving" class="text-blue-600 underline">Receiving</Link>
					and click <strong>Record an Intake</strong> to start a record for the arrival in
					front of you. (The list shows open donations, plus the most recent equipment,
					supplies and other intakes. Use the search box to find a donor, or tick
					<strong>Flagged for donor ID only</strong> to see just the ones still needing
					a donor identified.)
				</li>
				<li>
					If the donor phoned ahead, the top of the form shows
					<strong>Does this match a phoned-in offer?</strong> with a card for each
					offer still waiting to arrive (donor, what was offered, and ETA). Click the
					card that matches. It fills in the donor, contact person and the offer's
					description (added to Notes) for you, and marks that offer as received when
					you save. Click the card again to un-select it. If no offers are waiting, you
					will see a note saying so &mdash; just carry on.
				</li>
				<li>
					Check the <strong>Date</strong>, then pick a <strong>Category</strong> &mdash;
					Donation, Equipment, Supplies, or Other (for Other, type what it is under
					<strong>Describe</strong>). <strong>Equipment</strong> and
					<strong>Supplies</strong> are both for items the warehouse itself will use, not
					donations that will be distributed to partners or recipients. Equipment is durable
					stuff the warehouse keeps and reuses (shelving, pallet jacks, tools, computers);
					Supplies are consumables the warehouse uses up (packing tape, gloves, cleaning
					supplies, printer paper). Only Donations get labels and go on to sorting; the
					others are logged for the record.
				</li>
				<li>
					Tap the <strong>Truck Size</strong> picture that matches the vehicle (Semi, Box
					Truck, Personal Vehicle, Trailer, Delivery Truck, or Other &mdash; for Other,
					describe it).
				</li>
				<li>
					<strong>Driver:</strong> search for the driver by name, phone or carrier. If
					they aren't listed, choose the create option to add a new driver (name, phone,
					and carrier if it's a trucking company). A driver's carrier fills in the
					<strong>Carrier</strong> box for you; you can also type the Carrier directly
					(a trucking company, UPS, FedEx, Amazon, and so on). Drivers are just a simple
					list for our records &mdash; they are not staff or donors.
				</li>
				<li>
					Answer <strong>How did this arrive?</strong>
					<ul class="list-disc list-inside ml-6 mt-2 space-y-1">
						<li>
							<strong>Pallets</strong> &mdash; the load is on pallets. Enter
							<strong>Number of Pallets</strong>. Pallets can't be combined with the
							other kinds.
						</li>
						<li>
							<strong>Other</strong> &mdash; tick <strong>Which kind(s)?</strong>: Boxes,
							Bags, Totes and/or Loose. A mixed load can be several at once. Boxes,
							Bags and Totes each get a <strong>Number of ...</strong> box to fill in.
							Loose has no count.
						</li>
					</ul>
					These numbers decide how many labels are created later, so count carefully.
				</li>
				<li>
					<strong>Donor / Source:</strong> search by name or organization. If they're not
					in the system yet, use the create option &mdash; a first name, last name, or
					organization is enough. If the driver brought the donation themselves, pick
					the driver and press <strong>This driver is also the donor</strong>.
				</li>
				<li>
					If the donor is an organization, a <strong>Contact Person for this
					shipment</strong> box appears. Search the organization's contacts, or add a new
					one (name and phone) &mdash; this is who to call about <em>this</em> load.
				</li>
				<li>
					If you genuinely don't know who dropped this off, tick
					<strong>Donor Unknown</strong> instead of guessing. This only flags the record
					for follow-up &mdash; it shows a <strong>donor ID pending</strong> tag in the
					list and stays visible there (even after the donation is marked Complete) until
					someone fixes the source. It doesn't hold up sorting.
				</li>
				<li>
					Fill in the pickup location: <strong>Record address where this donation was
					picked up</strong>, plus <strong>City / State / Zip</strong>, with whatever the
					driver can tell you &mdash; every time, whether or not you know the donor.
				</li>
				<li>
					Write a short <strong>Summary of Items</strong> (what the paperwork or a quick
					look says is on board) and any <strong>Notes</strong>. Neither blocks sorting
					&mdash; they're context for whoever works this donation next.
				</li>
				<li>
					Answer <strong>Quick-Sort Candidate?</strong> (Yes or No) &mdash; a rough,
					visible-only judgment call: is this load mostly one item (a pallet of the same
					donated item, for example), making it eligible for Sorting's express lane? It's
					not a promise of exact contents.
				</li>
				<li>
					Press <strong>Continue</strong>. This saves the record and moves to the photo
					step. <strong>Cancel</strong> asks you to confirm before throwing away your
					changes. On a saved record without labels, a red <strong>Delete Intake</strong>
					link (also asks you to confirm) removes it &mdash; once labels exist it can't be
					deleted.
				</li>
			</ol>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Step 2 &mdash; Photo</h2>
			<p class="text-sm text-gray-700">
				Take one reference photo of the shipment as it arrived. On a phone or tablet,
				<strong>Take Photo</strong> opens the camera; on a computer with a webcam it opens
				a preview. You can also use <strong>Choose File</strong> to pick a picture already
				on the device. Retaking replaces the earlier photo. The photo is optional: the
				button reads <strong>Skip</strong> for non-donations or <strong>Proceed to
				Labels</strong> for a donation if there is no photo yet, and <strong>Continue</strong>
				once one is attached. <strong>Back to Details</strong> returns to the form.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Step 3 &mdash; Print Labels (donations only)</h2>
			<ol class="list-decimal list-inside text-sm text-gray-700 space-y-3">
				<li>
					When you arrive at this step, the labels are created for you from the counts
					you entered on the Details step &mdash; one for each pallet, box, bag or tote.
					(Loose items don't get a label.) Nothing is created twice: if you come back to
					this step, only the labels still missing are added. The line at the top shows
					progress, such as <strong>3 of 4 labeled</strong>.
				</li>
				<li>
					Need an extra one? Under that, enter a <strong>Qty</strong>, choose the type
					(Pallet, Gaylord, Box, Bag or Tote) and click <strong>Add Label(s)</strong>.
				</li>
				<li>
					Already stuck pre-printed labels on the containers (see
					<strong>Pre-print Labels</strong> below)? Type or scan the label numbers, such
					as R00000042, into the box and click <strong>Link Label(s)</strong>. Any number
					that can't be linked is listed with the reason (not valid, no such label, or
					already used on another donation).
				</li>
				<li>
					The table lists each label's <strong>Tag</strong>, <strong>Type</strong> and
					<strong>Status</strong>. Use <strong>Label</strong> in its row to print one, or
					<strong>Print All Labels</strong> for the whole donation. Each option offers a
					PDF download or a printer at your site. Attach each printed label to its
					pallet, gaylord, box, bag or tote before it leaves the dock.
				</li>
				<li>Click <strong>Done</strong>. The donation is now ready for
					<Link href="/help/sorting" class="text-blue-600 underline">sorting</Link>.
				</li>
			</ol>
			<p class="text-sm text-gray-700">
				Labels are about what the container <em>is</em>, not what's in it &mdash; there is
				no place to describe the contents of each pallet here. Sorting records that when
				the container is opened.
			</p>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-4">
			<h2 class="text-lg font-semibold">Other things you can do on this page</h2>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-2">
				<li>
					<strong>Pre-print Labels</strong> (button at the top): print a batch of blank R
					labels (1 to 300, of a container type you pick) before any donation is entered.
					Put them on containers as goods arrive, then link the numbers at the Print Labels
					step.
				</li>
				<li>
					<strong>Donation Offers</strong> (button at the top): the list of donations
					phoned in ahead of time &mdash; see the
					<Link href="/help/donation-offers" class="text-blue-600 underline">Donation Offers guide</Link>.
				</li>
				<li>
					<strong>Re-print Labels</strong> (Details step of a donation that already has
					labels): reprints every label. It asks you to confirm so you don't end up with
					duplicate labels on the floor.
				</li>
				<li>
					<strong>Close Out</strong>: shown on a record that's down to its last
					non-empty container, which is already being sorted. It marks that last one
					empty so the donation can finish &mdash; use it for the forgotten pallet at the
					end of the day.
				</li>
				<li>
					Changing the <strong>Category</strong> is only possible before labels have been
					created.
				</li>
			</ul>
		</section>

		<section class="bg-white shadow rounded-lg p-6 space-y-3">
			<h2 class="text-lg font-semibold">What the pallet number means</h2>
			<p class="text-sm text-gray-700">
				Every printed label starts with a letter, then an 8-digit number — for example
				<strong>R00000042</strong>. The number just counts up (it's never reused, even if
				that pallet later empties out), and the letter says what kind of record it is and
				where in the warehouse that unit lives, not what's on it:
			</p>
			<ul class="list-disc list-inside text-sm text-gray-700 space-y-1">
				<li><strong>R</strong> — Receiving (a fresh label from this page, not yet sorted)</li>
				<li><strong>W</strong> — Warehouse (a pallet of sorted goods built for the shelves)</li>
				<li><strong>S</strong> — Shipping (staged and building toward an outgoing order)</li>
				<li><strong>H</strong> — Hold (set aside for a specific order being filled)</li>
				<li><strong>Q</strong> — Quarantine (flagged for review before it can move again)</li>
			</ul>
			<p class="text-sm text-gray-700">
				A pallet record is one load's trip, not the physical pallet underneath it — the
				letter is fixed for that trip and never changes. The tag Receiving prints (an
				<strong>R</strong> label) is what Sorting scans to pull up this same donation; once
				sorted stock moves onward — into the warehouse, out to Shipping, on Hold for an
				order, or into Quarantine — that's tracked as a new pallet record with its own tag
				and letter, not a relabel of the original.
			</p>
		</section>
	</HelpPage>
</template>
