<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Receiving.vue

	Dock-side donation/equipment/supplies intake, built on RIForm like the
	rest of the CRUD-style admin pages. Donation-category intakes get
	pallets created for them (one label per container) via an action button
	in the detail view once a record has been saved; those pallets then
	show up on the Sorting page ready to work. The donation's own status
	rolls from Received -> Sorting -> Complete automatically as those
	pallets get sorted and emptied. Close-out is likewise an action in the
	detail view, shown only when the record is a close-out candidate
	(is_close_out_candidate, computed server-side).
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import TextArea from '@/Components/TextArea.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import ChipSelect from '@/Components/ChipSelect.vue';
import TileSelect from '@/Components/TileSelect.vue';
import RIForm from '@/Components/RIForm.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

defineProps({
	breadcrumb: {
		type: Array,
	},
});

const categoryOptions = [
	{ id: 'donation', name: 'Donation' },
	{ id: 'equipment', name: 'Equipment' },
	{ id: 'supplies', name: 'Supplies' },
	{ id: 'other', name: 'Other' },
];

const yesNoOptions = [
	{ value: true, label: 'Yes' },
	{ value: false, label: 'No' },
];

// Pallets is a single, exclusive choice; Other reveals a multi-select
// checklist below it since a mixed load can be boxes AND bags AND totes
// AND/OR loose all at once.
const arrivalModeOptions = [
	{ value: 'pallet', label: 'Pallets' },
	{ value: 'other', label: 'Other' },
];

const otherContainerTypeOptions = [
	{ value: 'box', label: 'Boxes' },
	{ value: 'bag', label: 'Bags' },
	{ value: 'tote', label: 'Totes' },
	{ value: 'loose', label: 'Loose' },
];

// Icon paths point at public/img/ files in the same style as the
// Dashboard menu tile graphics (navy rounded-square badge, white
// line-art glyph) — drop matching .webp files there with these exact
// names. TileSelect falls back to plain text/emoji for any icon value
// that isn't a path, so this degrades gracefully if a file is missing.
const arrivalMethodOptions = [
	{ value: 'semi', label: 'Semi', icon: '/img/truck-semi-icon.webp' },
	{ value: 'box_truck', label: 'Box Truck', icon: '/img/truck-box-icon.webp' },
	{ value: 'personal_vehicle', label: 'Personal Vehicle', icon: '/img/truck-personal-icon.webp' },
	{ value: 'trailer', label: 'Trailer', icon: '/img/truck-trailer-icon.webp' },
	{ value: 'delivery_truck', label: 'Delivery Truck', icon: '/img/truck-delivery-icon.webp' },
	{ value: 'other', label: 'Other', icon: '/img/truck-other-icon.webp' },
];

const containerTypeOptions = [
	{ value: 'pallet', label: 'Pallet' },
	{ value: 'gaylord', label: 'Gaylord' },
	{ value: 'box', label: 'Box' },
	{ value: 'bag', label: 'Bag' },
	{ value: 'tote', label: 'Tote' },
];
</script>

<template>
	<Head title="Receiving" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<RIForm
			ref="riform"
			title="Receiving"
			datasource="/json/receiving"
			newrecordcaption="Record an Intake"
			:filter="receivingFilter"
			@select="onOpenRecord"
			@new="onOpenRecord"
		>
			<template #titleactions>
				<Link href="/receiving/offers" class="ri_defaultbutton ri_floating">Donation Offers</Link>
			</template>

			<template #listactions>
				<input
					type="text"
					v-model="donorSearch"
					class="ri_forminput recv_search"
					placeholder="Search by donor name or organization..."
				/>
				<label class="recv_checkbox">
					<input type="checkbox" v-model="flaggedOnly" />
					Flagged for donor ID only
				</label>
				<a href="/help/receiving" target="_blank" class="recv_helplink">Help: Receiving Guide</a>
			</template>

			<template #thead>
				<th>Donor</th>
				<th>Category</th>
				<th>Status</th>
				<th>Container Est.</th>
				<th>Pallets</th>
			</template>

			<template #tbody="{ record, index }">
				<td>
					{{ donorName(record) }}
					<span v-if="record.donor_identification_pending" class="recv_badge recv_badge_id">donor ID pending</span>
				</td>
				<td>{{ record.category === 'other' && record.category_other ? record.category_other : record.category }}</td>
				<td>
					{{ record.status ? record.status.name : '' }}
					<span v-if="record.is_close_out_candidate" class="recv_badge">ready to close out</span>
				</td>
				<td>{{ record.container_count }}</td>
				<td>{{ (record.pallets || []).length }}</td>
			</template>

			<template #default="{ record, editing, templates }">
				<template v-if="wizardStep === 'details'">
					<div class="ri_formtable">
						<div class="ri_fieldset" v-if="!record.id">
							<div class="ri_fieldlabel">Match to a phoned-in offer?</div>
							<SearchSelect
								ref="offerSelect"
								v-model="matchedOfferId"
								optionsource="/json/donation-offers?status=pending"
								display="label"
								:searchfields="['label']"
								placeholder="Search pending donation offers..."
								:enabled="editing"
								@selected="(o) => onOfferSelected(record, o)"
							/>
						</div>

						<div class="ri_fieldset">
							<div class="ri_fieldlabel">Date:</div>
							<div class="ri_formcontrol">
								<input
								type="date"
								v-model="record.order_date"
								class="ri_forminput"
								:disabled="!editing"
								@change="$event.target.blur()"
							/>
							</div>
						</div>

						<div class="ri_fieldset">
							<div class="ri_fieldlabel">Category:</div>
							<ChipSelect
								v-model="record.category"
								:options="categoryOptions.map((o) => ({ value: o.id, label: o.name }))"
								:enabled="editing"
							/>
						</div>
						<div class="ri_fieldset" v-if="record.category === 'other'">
							<div class="ri_fieldlabel">Describe:</div>
							<TextInput v-model="record.category_other" placeholder="What is this?" :enabled="editing" />
						</div>
						<div class="ri_fieldset">
							<div class="ri_fieldlabel">Truck Size:</div>
							<TileSelect v-model="record.arrival_method" :options="arrivalMethodOptions" :enabled="editing" />
						</div>

						<div class="ri_fieldset" v-if="record.arrival_method === 'other'">
							<div class="ri_fieldlabel">Describe:</div>
							<TextInput v-model="record.arrival_method_other" placeholder="How did it arrive?" :enabled="editing" />
						</div>

						<div class="ri_fieldset">
							<div class="ri_fieldlabel">Driver:</div>
							<SearchSelect
								ref="driverSelect"
								v-model="record.driver_id"
								optionsource="/json/drivers"
								display="name"
								secondary="carrier"
								:searchfields="['name', 'phone', 'carrier']"
								placeholder="Search drivers..."
								:allowcreate="true"
								:enabled="editing"
								@selected="(d) => onDriverSelected(record, d)"
								@create="(name) => startNewDriver(record, name)"
							/>
						</div>
						<Modal :show="creatingDriver" @close="creatingDriver = false" max-width="sm">
							<div class="p-6 space-y-4">
								<h2 class="text-lg font-semibold">New Driver</h2>
								<div>
									<InputLabel value="Name" />
									<TextInput v-model="newDriver.name" class="mt-1 block w-full" autofocus />
								</div>
								<div>
									<InputLabel value="Phone" />
									<TextInput v-model="newDriver.phone" class="mt-1 block w-full" />
								</div>
								<div>
									<InputLabel value="Carrier (if a trucking company, not a personal vehicle)" />
									<TextInput v-model="newDriver.carrier" class="mt-1 block w-full" />
								</div>
								<p v-if="driverError" class="text-sm text-red-700">{{ driverError }}</p>
								<div class="flex justify-end gap-3">
									<SecondaryButton :disabled="driverSaving" @click="creatingDriver = false">Cancel</SecondaryButton>
									<PrimaryButton :disabled="driverSaving" @click="saveNewDriver(record)">Save Driver</PrimaryButton>
								</div>
							</div>
						</Modal>
						<button
							v-if="selectedDriver"
							type="button"
							@click="useDriverAsDonor(record)"
							:disabled="donorSaving"
							class="ri_linkbutton"
						>
							This driver is also the donor
						</button>

						<div class="ri_fieldset">
							<div class="ri_fieldlabel">Carrier:</div>
							<TextInput v-model="record.carrier" placeholder="Trucking company, or UPS/FedEx/Amazon, etc." :enabled="editing" />
						</div>

						<div class="ri_fieldset">
							<div class="ri_fieldlabel">How did this arrive?</div>
							<ChipSelect
								:modelValue="arrivalMode"
								:options="arrivalModeOptions"
								:enabled="editing"
								@update:modelValue="(m) => setArrivalMode(record, m)"
							/>
						</div>

						<div class="ri_fieldset" v-if="arrivalMode === 'other'">
							<div class="ri_fieldlabel">Which kind(s)?</div>
							<ChipSelect
								v-model="record.container_types"
								:options="otherContainerTypeOptions"
								:multiple="true"
								:enabled="editing"
							/>
						</div>

						<div class="ri_fieldset" v-if="arrivalMode === 'pallet'">
							<div class="ri_fieldlabel">Number of Pallets:</div>
							<TextInput v-model="record.container_type_counts.pallet" type="number" :enabled="editing" />
						</div>
						<div
							class="ri_fieldset"
							v-for="type in otherContainerTypeOptions.filter((o) => o.value !== 'loose' && (record.container_types || []).includes(o.value))"
							:key="type.value"
						>
							<div class="ri_fieldlabel">Number of {{ type.label }}:</div>
							<TextInput v-model="record.container_type_counts[type.value]" type="number" :enabled="editing" />
						</div>

						<div class="ri_fieldset">
							<div class="ri_fieldlabel">Donor / Source:</div>
							<SearchSelect
								ref="donorSelect"
								v-model="record.person_id"
								optionsource="/json/people"
								display="full_name"
								:searchfields="['organization', 'first_name', 'last_name']"
								placeholder="Search donors..."
								:allowcreate="true"
								:enabled="editing"
								@selected="(p) => { creatingDonor = false; record.person = p; record.contact_person_id = null; }"
								@create="startNewDonor"
							/>
						</div>
						<Modal :show="creatingDonor" @close="creatingDonor = false" max-width="sm">
							<div class="p-6 space-y-4">
								<h2 class="text-lg font-semibold">New Donor</h2>
								<div>
									<InputLabel value="First Name" />
									<TextInput v-model="newDonor.first_name" class="mt-1 block w-full" autofocus />
								</div>
								<div>
									<InputLabel value="Last Name" />
									<TextInput v-model="newDonor.last_name" class="mt-1 block w-full" />
								</div>
								<div>
									<InputLabel value="Organization" />
									<TextInput v-model="newDonor.organization" class="mt-1 block w-full" />
								</div>
								<p v-if="donorError" class="text-sm text-red-700">{{ donorError }}</p>
								<div class="flex justify-end gap-3">
									<SecondaryButton :disabled="donorSaving" @click="creatingDonor = false">Cancel</SecondaryButton>
									<PrimaryButton :disabled="donorSaving" @click="saveNewDonor(record)">Save Donor</PrimaryButton>
								</div>
							</div>
						</Modal>

						<template v-if="record.person_id && record.person?.is_organization">
							<div class="ri_fieldset">
								<div class="ri_fieldlabel">Contact Person for this shipment:</div>
								<SearchSelect
									ref="contactSelect"
									v-model="record.contact_person_id"
									:optionsource="`/json/people?parent_person_id=${record.person_id}`"
									display="full_name"
									:searchfields="['first_name', 'last_name']"
									placeholder="Search contacts at this org..."
									:allowcreate="true"
									:enabled="editing"
									@create="startNewContact"
								/>
							</div>
							<Modal :show="creatingContact" @close="creatingContact = false" max-width="sm">
								<div class="p-6 space-y-4">
									<h2 class="text-lg font-semibold">New Contact</h2>
									<div>
										<InputLabel value="First Name" />
										<TextInput v-model="newContact.first_name" class="mt-1 block w-full" autofocus />
									</div>
									<div>
										<InputLabel value="Last Name" />
										<TextInput v-model="newContact.last_name" class="mt-1 block w-full" />
									</div>
									<div>
										<InputLabel value="Phone" />
										<TextInput v-model="newContact.phone" class="mt-1 block w-full" />
									</div>
									<p v-if="contactError" class="text-sm text-red-700">{{ contactError }}</p>
									<div class="flex justify-end gap-3">
										<SecondaryButton :disabled="contactSaving" @click="creatingContact = false">Cancel</SecondaryButton>
										<PrimaryButton :disabled="contactSaving" @click="saveNewContact(record)">Save Contact</PrimaryButton>
									</div>
								</div>
							</Modal>
						</template>

						<div class="ri_fieldset">
							<div class="ri_fieldlabel"></div>
							<div class="ri_formcontrol">
								<label class="recv_checkbox">
									<input type="checkbox" v-model="record.donor_identification_pending" :disabled="!editing" />
									Donor Unknown
								</label>
							</div>
						</div>

						<div class="ri_fieldset">
							<div class="ri_fieldlabel">Record address where this donation was picked up:</div>
							<TextInput v-model="record.source_address" placeholder="Street address" :enabled="editing" />
						</div>
						<div class="ri_fieldset">
							<div class="ri_fieldlabel">City / State / Zip:</div>
							<div class="ri_formcontrol recv_addressline">
								<TextInput v-model="record.source_city" placeholder="City" :enabled="editing" />
								<TextInput v-model="record.source_state" placeholder="State" :enabled="editing" />
								<TextInput v-model="record.source_zip" placeholder="Zip" :enabled="editing" />
							</div>
						</div>

						<div class="ri_fieldset">
							<div class="ri_fieldlabel">Summary of Items:</div>
							<TextArea v-model="record.manifest" :enabled="editing" />
						</div>

						<div class="ri_fieldset">
							<div class="ri_fieldlabel">Quick-Sort Candidate?</div>
							<ChipSelect v-model="record.quick_sort_candidate" :options="yesNoOptions" :enabled="editing" />
						</div>

						<div class="ri_fieldset">
							<div class="ri_fieldlabel">Notes:</div>
							<TextArea v-model="record.comments" :enabled="editing" />
						</div>
					</div>

					<template v-if="record.id && record.is_close_out_candidate">
						<h3 class="recv_subhead">Daily Close-Out</h3>
						<button @click="closeOut(record)" class="ri_defaultbutton">Close Out</button>
						<p v-if="closeOutError" class="ri_error">{{ closeOutError }}</p>
					</template>
				</template>

				<template v-else-if="wizardStep === 'photo'">
					<h3 class="recv_subhead">Photo of the Shipment</h3>
					<div class="recv_photobuttons">
						<input
							ref="cameraInput"
							type="file"
							accept="image/*"
							capture="environment"
							class="recv_hidden_input"
							@change="onPhotoSelected($event, record)"
						/>
						<input
							ref="fileInput"
							type="file"
							accept="image/*"
							class="recv_hidden_input"
							@change="onPhotoSelected($event, record)"
						/>
						<button type="button" class="ri_formbutton" @click="$refs.cameraInput.click()">📷 Take Photo</button>
						<button type="button" class="ri_formbutton" @click="$refs.fileInput.click()">Choose File</button>
					</div>
					<p v-if="record.photo_path" class="ri_hint">
						<a :href="`/json/receiving/${record.id}/photo`" target="_blank">View current photo</a>
					</p>
					<p v-if="photoError" class="ri_error">{{ photoError }}</p>
				</template>

				<template v-else>
					<h3 class="recv_subhead">Print Labels</h3>
					<p class="recv_hint">
						{{ record.container_count ? `${(record.pallets || []).length} of ${record.container_count} labeled.` : `${(record.pallets || []).length} labeled so far.` }}
					</p>
					<div class="recv_palletline">
						<input
							v-model.number="palletCount"
							type="number"
							min="1"
							placeholder="Qty"
							class="recv_pallet_count"
						/>
						<ChipSelect v-model="palletContainerType" :options="containerTypeOptions" />
						<button @click="createPallets(record)" :disabled="palletSaving" class="ri_formbutton">
							Add Label(s)
						</button>
					</div>
					<p v-if="palletError" class="ri_error">{{ palletError }}</p>

					<template v-if="(record.pallets || []).length">
						<div class="recv_tablewrap">
							<table class="ri_datatable" border="1">
								<thead>
									<tr><th>Tag</th><th>Type</th><th>Status</th><th></th></tr>
								</thead>
								<tbody>
									<tr v-for="pallet in record.pallets" :key="pallet.id">
										<td class="recv_mono">{{ pallet.tag }}</td>
										<td>{{ pallet.container_type }}</td>
										<td>{{ pallet.status }}</td>
										<td><a :href="'/report/pallet/' + pallet.id" target="_blank">Label</a></td>
									</tr>
								</tbody>
							</table>
						</div>
						<button @click="printAllLabels(record)" class="ri_formbutton">
							Print All Labels ({{ (record.pallets || []).length }})
						</button>
					</template>
					<p v-else>No labels created for this intake yet.</p>
				</template>
			</template>

			<template #actions="{ editing, record, confirmingDelete, save, cancel, delete: doDelete, keepRecord }">
				<div class="ri_formactions" v-if="!editing">
					<button @click="cancel()" class="ri_defaultbutton">Back to List</button>
				</div>
				<template v-else-if="wizardStep === 'details'">
					<div class="ri_formactions recv_actionsrow">
						<div class="recv_cancelgroup">
							<button
								@click="handleCancelClick(cancel)"
								class="ri_formbutton"
								:class="{ recv_cancel_confirming: confirmingCancel }"
							>
								{{ confirmingCancel ? 'Confirm Cancel — changes will be lost' : 'Cancel' }}
							</button>
							<button v-if="confirmingCancel" @click="confirmingCancel = false" class="ri_linkbutton">
								Keep Editing
							</button>
						</div>
						<button @click="goToPhoto(record, save)" class="ri_defaultbutton">Add Photo</button>
					</div>
					<div class="ri_formactions recv_dangerrow" v-if="record.id">
						<a
							href="#"
							@click.prevent="doDelete()"
							class="ri_deletebutton"
							:class="{ ri_confirming: confirmingDelete }"
						>
							{{ confirmingDelete ? 'Confirm Delete Intake — cannot be undone' : 'Delete Intake' }}
						</a>
						<button v-if="confirmingDelete" @click="keepRecord()" class="ri_linkbutton">Keep Record</button>
					</div>
				</template>
				<div class="ri_formactions recv_actionsrow" v-else-if="wizardStep === 'photo'">
					<button @click="wizardStep = 'details'" class="ri_formbutton">Back to Details</button>
					<button @click="finishPhotoStep(record, cancel)" class="ri_defaultbutton">
						{{ record.photo_path ? 'Continue' : 'Skip' }}
					</button>
				</div>
				<div class="ri_formactions recv_actionsrow" v-else>
					<button @click="wizardStep = 'details'" class="ri_formbutton">Back to Details</button>
					<button @click="finishWizard(cancel)" class="ri_defaultbutton">Done</button>
				</div>
			</template>
		</RIForm>
	</AuthenticatedLayout>
</template>

<script>
import axios from 'axios';
import { invalidateOptions } from '@/Components/SearchSelect.vue';

export default {
	data() {
		return {
			wizardStep: 'details', // 'details' | 'photo' | 'labels' — see @select/@new on <RIForm> and goToPhoto()/finishPhotoStep()/finishWizard() below

			creatingDonor: false,
			newDonor: { first_name: '', last_name: '', organization: '' },
			donorSaving: false,
			donorError: null,

			selectedDriver: null,
			creatingDriver: false,
			newDriver: { name: '', phone: '', carrier: '' },
			driverSaving: false,
			driverError: null,

			creatingContact: false,
			newContact: { first_name: '', last_name: '', phone: '' },
			contactSaving: false,
			contactError: null,

			photoError: null,

			confirmingCancel: false,
			arrivalMode: null, // 'pallet' | 'other' | null — derived from record.container_types on open, see onOpenRecord()

			palletCount: null,
			palletContainerType: 'pallet',
			palletSaving: false,
			palletError: null,

			closeOutError: null,

			donorSearch: '',
			flaggedOnly: false,

			// Matching a phoned-in DonationOffer at intake — see
			// onOfferSelected()/onOpenRecord() and store()'s donation_offer_id.
			matchedOfferId: null,
		};
	},
	computed: {
		receivingFilter() {
			const query = this.donorSearch.trim().toLowerCase();
			const flaggedOnly = this.flaggedOnly;
			const donorName = this.donorName;
			return (record) => {
				if (flaggedOnly && !record.donor_identification_pending) return false;
				if (query && !donorName(record).toLowerCase().includes(query)) return false;
				return true;
			};
		},
	},
	methods: {
		donorName(record) {
			return record.person?.full_name || '(no donor recorded)';
		},

		// ---------- wizard navigation ----------
		onOpenRecord(record) {
			this.wizardStep = 'details';
			this.creatingDonor = false;
			this.creatingDriver = false;
			this.creatingContact = false;
			this.donorError = null;
			this.driverError = null;
			this.contactError = null;
			this.photoError = null;
			this.confirmingCancel = false;
			this.selectedDriver = record?.driver || null;
			if (record) record.container_type_counts = record.container_type_counts || {};
			const types = record?.container_types || [];
			this.arrivalMode = types.includes('pallet') ? 'pallet' : types.length ? 'other' : null;
			this.matchedOfferId = record?.donation_offer_id || null;
		},
		// Selecting a pending offer pre-fills the donor/contact/manifest fields
		// it already has and stashes the offer id for store() to pick up —
		// matching at intake instead of requiring a separate trip to the
		// offers worklist afterward.
		onOfferSelected(record, offer) {
			if (!offer) return;
			record.donation_offer_id = offer.id;
			record.person_id = offer.person_id;
			record.person = offer.person;
			record.contact_person_id = offer.contact_person_id;
			if (offer.description) {
				record.comments = record.comments ? `${record.comments}\n\n${offer.description}` : offer.description;
			}
		},
		// Pallets is exclusive; switching to it replaces any box/bag/tote/loose
		// selection, and switching to Other clears a prior "pallet" choice
		// while keeping whatever box/bag/tote/loose was already picked.
		setArrivalMode(record, mode) {
			this.arrivalMode = mode;
			record.container_types = mode === 'pallet' ? ['pallet'] : (record.container_types || []).filter((t) => t !== 'pallet');
		},
		// container_count is the derived total the rest of the page reads
		// (list column, Describe Pallets progress) — computed from whichever
		// per-type counts are currently relevant, not entered directly.
		computeContainerCount(record) {
			const counts = record.container_type_counts || {};
			const types = this.arrivalMode === 'pallet' ? ['pallet'] : (record.container_types || []).filter((t) => t !== 'loose');
			return types.reduce((sum, t) => sum + (Number(counts[t]) || 0), 0);
		},
		// Two-step inline confirm (no window.confirm), matching the Delete
		// pattern — the first click just arms it.
		handleCancelClick(cancel) {
			if (!this.confirmingCancel) {
				this.confirmingCancel = true;
				return;
			}
			this.confirmingCancel = false;
			cancel();
		},
		remainingContainers(record) {
			const target = Number(record.container_count) || 0;
			const described = (record.pallets || []).length;
			return Math.max(target - described, 0);
		},
		async goToPhoto(record, save) {
			// Every category gets a chance to attach a photo; only a donation
			// goes on afterward to auto-generate pallet/container labels.
			record.container_count = this.computeContainerCount(record);
			const ok = await save(true);
			if (!ok) return;
			this.wizardStep = 'photo';
		},
		async finishPhotoStep(record, cancel) {
			if (record.category === 'donation') {
				this.wizardStep = 'labels';
				await this.autoCreateLabels(record);
			} else {
				this.finishWizard(cancel);
			}
		},
		finishWizard(cancel) {
			cancel();
			this.$refs.riform?.fetchRecords();
		},
		// Quantities were already declared on the Details screen (Number of
		// Pallets/Boxes/Bags/Totes) — this creates exactly enough labels to
		// cover them in one shot instead of re-entering the same numbers by
		// hand. Idempotent: re-entering this step only tops up whatever's
		// still short, it never double-creates.
		async autoCreateLabels(record) {
			this.palletError = null;
			this.palletSaving = true;
			try {
				const declared = record.container_type_counts || {};
				const types = this.arrivalMode === 'pallet' ? ['pallet'] : (record.container_types || []).filter((t) => t !== 'loose');
				for (const type of types) {
					const wanted = Number(declared[type]) || 0;
					const already = (record.pallets || []).filter((p) => p.container_type === type).length;
					const remaining = wanted - already;
					if (remaining > 0) {
						const response = await axios.post('/json/receiving/' + record.id + '/pallets', {
							count: remaining,
							container_type: type,
						});
						record.pallets = [...(record.pallets || []), ...response.data.records];
					}
				}
				this.palletCount = this.remainingContainers(record) || null;
				this.$refs.riform?.fetchRecords();
			} catch (error) {
				this.palletError = error.response?.data?.message || 'Could not create labels.';
			} finally {
				this.palletSaving = false;
			}
		},

		// ---------- donor quick-add ----------
		startNewDonor(name) {
			this.creatingDonor = true;
			this.donorError = null;
			const parts = (name || '').trim().split(/\s+/);
			this.newDonor = {
				first_name: parts[0] || '',
				last_name: parts.slice(1).join(' '),
				organization: parts.length <= 1 ? (name || '') : '',
			};
		},
		async saveNewDonor(record) {
			this.donorError = null;
			const hasName = this.newDonor.first_name.trim() && this.newDonor.last_name.trim();
			const hasOrg = (this.newDonor.organization || '').trim();
			if (!hasName && !hasOrg) {
				// A donation often arrives with less than a full contact — an
				// organization alone (no known contact person) is fine.
				this.donorError = 'Enter a name (first and last) or an organization.';
				return;
			}
			this.donorSaving = true;
			try {
				const response = await axios.post('/json/people', this.newDonor);
				invalidateOptions('/json/people');
				await this.$refs.donorSelect?.refresh(response.data.record.id);
				record.person_id = response.data.record.id;
				record.person = response.data.record;
				this.creatingDonor = false;
			} catch (error) {
				this.donorError = error.response?.data?.message || 'Could not save donor.';
			} finally {
				this.donorSaving = false;
			}
		},

		// ---------- driver quick-add / lookup ----------
		// A driver's carrier auto-fills the shipment's Carrier field (and
		// vice versa when quick-adding a driver) so it's never typed twice.
		onDriverSelected(record, driver) {
			this.selectedDriver = driver;
			if (driver?.carrier) record.carrier = driver.carrier;
		},
		startNewDriver(record, name) {
			this.creatingDriver = true;
			this.driverError = null;
			this.newDriver = { name: name || '', phone: '', carrier: record.carrier || '' };
		},
		async saveNewDriver(record) {
			this.driverError = null;
			if (!this.newDriver.name.trim()) {
				this.driverError = 'Enter the driver\'s name.';
				return;
			}
			this.driverSaving = true;
			try {
				const response = await axios.post('/json/drivers', this.newDriver);
				invalidateOptions('/json/drivers');
				await this.$refs.driverSelect?.refresh(response.data.record.id);
				record.driver_id = response.data.record.id;
				this.selectedDriver = response.data.record;
				if (response.data.record.carrier) record.carrier = response.data.record.carrier;
				this.creatingDriver = false;
			} catch (error) {
				this.driverError = error.response?.data?.message || 'Could not save driver.';
			} finally {
				this.driverSaving = false;
			}
		},
		// Some people bring their own donations — link the driver's Person
		// record (creating one, quick-add style, if this driver has never
		// been linked before) and select them as the donor.
		async useDriverAsDonor(record) {
			if (!this.selectedDriver) return;
			this.donorError = null;
			this.donorSaving = true;
			try {
				let personId = this.selectedDriver.person_id;
				if (!personId) {
					const parts = this.selectedDriver.name.trim().split(/\s+/);
					const response = await axios.post('/json/people', {
						first_name: parts[0] || '',
						last_name: parts.slice(1).join(' '),
						phone: this.selectedDriver.phone || '',
					});
					personId = response.data.record.id;
					invalidateOptions('/json/people');
					await axios.put('/json/drivers/' + this.selectedDriver.id, { ...this.selectedDriver, person_id: personId });
					this.selectedDriver.person_id = personId;
					invalidateOptions('/json/drivers');
				}
				await this.$refs.donorSelect?.refresh(personId);
				record.person_id = personId;
			} catch (error) {
				this.donorError = error.response?.data?.message || 'Could not link driver as donor.';
			} finally {
				this.donorSaving = false;
			}
		},

		// ---------- shipment contact quick-add ----------
		startNewContact(name) {
			this.creatingContact = true;
			this.contactError = null;
			const parts = (name || '').trim().split(/\s+/);
			this.newContact = { first_name: parts[0] || '', last_name: parts.slice(1).join(' '), phone: '' };
		},
		async saveNewContact(record) {
			this.contactError = null;
			if (!this.newContact.first_name.trim() && !this.newContact.last_name.trim()) {
				this.contactError = 'Enter the contact\'s name.';
				return;
			}
			this.contactSaving = true;
			try {
				const response = await axios.post('/json/people', { ...this.newContact, parent_person_id: record.person_id });
				invalidateOptions(`/json/people?parent_person_id=${record.person_id}`);
				await this.$refs.contactSelect?.refresh(response.data.record.id);
				record.contact_person_id = response.data.record.id;
				this.creatingContact = false;
			} catch (error) {
				this.contactError = error.response?.data?.message || 'Could not save contact.';
			} finally {
				this.contactSaving = false;
			}
		},

		// ---------- photo of the shipment ----------
		async onPhotoSelected(event, record) {
			const file = event.target.files?.[0];
			// Reset so picking the same file again (or retaking a photo) still
			// fires 'change' next time.
			event.target.value = '';
			if (!file) return;
			this.photoError = null;
			const formData = new FormData();
			formData.append('photo', file);
			try {
				const response = await axios.post('/json/receiving/' + record.id + '/photo', formData);
				record.photo_path = response.data.record.photo_path;
			} catch (error) {
				this.photoError = error.response?.data?.message || 'Could not upload photo.';
			}
		},

		// ---------- pallets for a donation ----------
		async createPallets(record) {
			if (!this.palletCount || this.palletCount < 1) {
				this.palletError = 'Enter how many pallets to add.';
				return;
			}
			this.palletSaving = true;
			this.palletError = null;
			try {
				const response = await axios.post('/json/receiving/' + record.id + '/pallets', {
					count: this.palletCount,
					container_type: this.palletContainerType,
				});
				record.pallets = [...(record.pallets || []), ...response.data.records];
				const remaining = this.remainingContainers(record);
				this.palletCount = remaining || null;
				this.$refs.riform?.fetchRecords();
			} catch (error) {
				this.palletError = error.response?.data?.message || 'Could not create pallets.';
			} finally {
				this.palletSaving = false;
			}
		},
		printAllLabels(record) {
			// One PDF with every label, instead of one browser tab per pallet
			// (popup blockers allow only the first of a burst of window.opens).
			window.open('/report/pallets/donation/' + record.id, '_blank');
		},

		// ---------- daily close-out ----------
		async closeOut(record) {
			this.closeOutError = null;
			try {
				const response = await axios.post('/json/receiving/' + record.id + '/close-out');
				Object.assign(record, response.data.record);
				this.$refs.riform?.fetchRecords();
			} catch (error) {
				this.closeOutError = error.response?.data?.message || 'Could not close out that donation.';
			}
		},

		// ---------- matching a phoned-in offer, hand-off from DonationOffers.vue ----------
		waitForTemplates() {
			return new Promise((resolve) => {
				const check = () => {
					if (this.$refs.riform?.templates?._default) resolve();
					else setTimeout(check, 50);
				};
				check();
			});
		},
	},
	async mounted() {
		const offerId = new URLSearchParams(window.location.search).get('match_offer_id');
		if (!offerId) return;
		try {
			const response = await axios.get('/json/donation-offers?status=pending');
			const offer = (response.data.records || []).find((o) => String(o.id) === String(offerId));
			if (!offer) return;
			await this.waitForTemplates();
			this.$refs.riform.newRecord();
			this.onOfferSelected(this.$refs.riform.record, offer);
			this.matchedOfferId = offer.id;
		} catch (error) {
			// Offer no longer available/pending — just leave a fresh blank form.
		}
	},
};
</script>

<style scoped>
.recv_subhead {
	margin-top: 1.5em;
}
.recv_hint {
	color: #666;
	font-size: 0.9em;
}
.recv_pallet_count {
	width: 5em;
}
.recv_palletline {
	display: flex;
	align-items: center;
	gap: 0.5em;
	flex-wrap: wrap;
	margin: 0.5em 0;
}
.recv_addressline {
	display: flex;
	gap: 0.5em;
	flex-wrap: wrap;
	margin: 0 0 0.75em;
}
.recv_addressline > * {
	flex: 1;
	min-width: 6em;
}
.recv_photobuttons {
	display: flex;
	gap: 0.5em;
	flex-wrap: wrap;
	margin: 0.5em 0;
}
.recv_hidden_input {
	display: none;
}
.recv_tablewrap {
	overflow-x: auto;
	margin: 0.5em 0;
}
.recv_mono {
	font-family: monospace;
}
.recv_badge {
	display: inline-block;
	margin-left: 0.5em;
	padding: 0.1em 0.5em;
	font-size: 0.8em;
	background: #fef3c7;
	color: #92400e;
	border-radius: 3px;
}
.recv_badge_id {
	background: #fee2e2;
	color: #991b1b;
}
.recv_checkbox {
	display: flex;
	align-items: center;
	gap: 0.4em;
	font-weight: normal;
}
.recv_search {
	min-width: 220px;
}
.recv_helplink {
	color: #007bff;
	text-decoration: underline;
	font-size: 0.9em;
	align-self: center;
}

/* Cancel (left) and Next (right), pushed to opposite ends and given real
   room between them so a mis-tap on a tablet doesn't hit the wrong one.
   Delete lives in its own row below, further separating the two "lose your
   work" actions from each other. */
.recv_actionsrow {
	justify-content: space-between;
}
.recv_cancelgroup {
	display: flex;
	align-items: center;
	gap: 0.75rem;
}
.recv_cancel_confirming {
	background-color: #b45309;
}
.recv_dangerrow {
	justify-content: flex-end;
}
</style>
