<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Receiving.vue

	Dock-side donation/equipment/supplies intake. Fast entry that must not
	block on item-level detail (that's Sorting's job): donor, category,
	rough container count, a free-text manifest paragraph, and optional
	shipment weight. Donation-category intakes get pallets created for them
	(one label per container), which then show up on the Sorting page ready
	to work; the donation's own status rolls from Received -> Sorting ->
	Complete automatically as those pallets get sorted and emptied.
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import SearchSelect from '@/Components/SearchSelect.vue';
import TextArea from '@/Components/TextArea.vue';
import axios from 'axios';

const CATEGORIES = [
	{ value: 'donation', label: 'Donation' },
	{ value: 'equipment', label: 'Equipment' },
	{ value: 'supplies', label: 'Supplies' },
	{ value: 'other', label: 'Other' },
];

export default {
	components: { AuthenticatedLayout, Head, SearchSelect, TextArea },
	props: {
		breadcrumb: { type: Array },
	},
	data() {
		return {
			categories: CATEGORIES,

			records: [],
			closeOutCandidates: [],
			listLoading: false,
			listError: null,
			dismissedToday: [],

			form: this.blankForm(),
			formSaving: false,
			formError: null,
			formSuccess: null,

			creatingDonor: false,
			newDonor: { first_name: '', last_name: '', organization: '' },
			donorSaving: false,
			donorError: null,

			palletCounts: {},
			palletSaving: {},
			palletError: {},
		};
	},
	mounted() {
		this.fetchDonations();
	},
	methods: {
		blankForm() {
			return {
				category: 'donation',
				person_id: null,
				container_count: null,
				manifest: '',
				manifest_weight_lbs: null,
				comments: '',
			};
		},
		async fetchDonations() {
			this.listLoading = true;
			this.listError = null;
			try {
				const response = await axios.get('/json/receiving');
				this.records = response.data.records;
				this.closeOutCandidates = response.data.close_out_candidates;
			} catch (error) {
				this.listError = 'Could not load Receiving records.';
			} finally {
				this.listLoading = false;
			}
		},

		// ---------- donor search / quick-add ----------
		donorSelected() {
			this.creatingDonor = false;
		},
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
		async saveNewDonor() {
			this.donorError = null;
			if (!this.newDonor.first_name.trim() || !this.newDonor.last_name.trim()) {
				this.donorError = 'First and last name are required (use the organization name for both if this is a business-only donor).';
				return;
			}
			this.donorSaving = true;
			try {
				const response = await axios.post('/json/people', this.newDonor);
				this.form.person_id = response.data.record.id;
				this.creatingDonor = false;
			} catch (error) {
				this.donorError = error.response?.data?.message || 'Could not save donor.';
			} finally {
				this.donorSaving = false;
			}
		},

		// ---------- intake form ----------
		async submitIntake() {
			this.formError = null;
			this.formSuccess = null;
			this.formSaving = true;
			try {
				const response = await axios.post('/json/receiving', this.form);
				this.formSuccess = 'Recorded successfully.';
				this.form = this.blankForm();
				await this.fetchDonations();

				// Donations usually arrive on containers that need labels
				// right away — offer to create them in the same flow.
				if (response.data.record.category === 'donation' && response.data.record.container_count) {
					this.palletCounts[response.data.record.id] = response.data.record.container_count;
				}
			} catch (error) {
				this.formError = error.response?.data?.message || 'Could not record this intake.';
			} finally {
				this.formSaving = false;
			}
		},

		// ---------- pallets for a donation ----------
		async createPallets(donation) {
			const count = this.palletCounts[donation.id];
			if (!count || count < 1) return;
			this.palletSaving = { ...this.palletSaving, [donation.id]: true };
			this.palletError = { ...this.palletError, [donation.id]: null };
			try {
				await axios.post('/json/receiving/' + donation.id + '/pallets', { count });
				await this.fetchDonations();
			} catch (error) {
				this.palletError = { ...this.palletError, [donation.id]: 'Could not create pallets.' };
			} finally {
				this.palletSaving = { ...this.palletSaving, [donation.id]: false };
			}
		},
		printPalletLabels(donation) {
			(donation.pallets || []).forEach((pallet) => {
				window.open('/report/pallet/' + pallet.id, '_blank');
			});
		},

		// ---------- daily close-out ----------
		async closeOut(donation) {
			this.listError = null;
			try {
				await axios.post('/json/receiving/' + donation.id + '/close-out');
				await this.fetchDonations();
			} catch (error) {
				this.listError = 'Could not close out that donation.';
			}
		},
		confirmStillOpen(donation) {
			// No state change — legitimately still in progress. Just hides
			// it from today's close-out list; it reappears tomorrow if
			// still genuinely open (no "acknowledged" suppression state).
			this.dismissedToday.push(donation.id);
		},
		visibleCloseOutCandidates() {
			return this.closeOutCandidates.filter((d) => !this.dismissedToday.includes(d.id));
		},

		donorName(record) {
			if (!record.person) return '(no donor recorded)';
			return record.person.organization || (record.person.first_name + ' ' + record.person.last_name);
		},
	},
};
</script>

<template>
	<Head title="Receiving" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="recv_container">
			<h2 class="recv_head">Record an Intake</h2>
			<p v-if="formSuccess" class="ri_success">{{ formSuccess }}</p>
			<p v-if="formError" class="ri_error">{{ formError }}</p>

			<form @submit.prevent="submitIntake" class="recv_form">
				<div class="ri_fieldset">
					<div class="ri_fieldlabel">Category:</div>
					<select v-model="form.category" class="ri_forminput">
						<option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
					</select>
				</div>

				<div class="ri_fieldset">
					<div class="ri_fieldlabel">Donor / Source:</div>
					<SearchSelect
						v-model="form.person_id"
						optionsource="/json/people"
						display="organization"
						:searchfields="['organization', 'first_name', 'last_name']"
						placeholder="Search donors..."
						:allowcreate="true"
						@selected="donorSelected"
						@create="startNewDonor"
					/>
				</div>
				<template v-if="creatingDonor">
					<p v-if="donorError" class="ri_error">{{ donorError }}</p>
					<div class="ri_fieldset">
						<div class="ri_fieldlabel">New Donor First Name:</div>
						<input v-model="newDonor.first_name" class="ri_forminput" />
					</div>
					<div class="ri_fieldset">
						<div class="ri_fieldlabel">New Donor Last Name:</div>
						<input v-model="newDonor.last_name" class="ri_forminput" />
					</div>
					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Organization (optional):</div>
						<input v-model="newDonor.organization" class="ri_forminput" />
					</div>
					<button type="button" @click="saveNewDonor" :disabled="donorSaving" class="ri_formbutton">
						Save Donor
					</button>
				</template>

				<div class="ri_fieldset">
					<div class="ri_fieldlabel">Rough Container Count:</div>
					<input v-model.number="form.container_count" type="number" min="0" class="ri_forminput" />
				</div>

				<div class="ri_fieldset">
					<div class="ri_fieldlabel">Manifest (what's on this load):</div>
					<TextArea v-model="form.manifest" :enabled="true" />
				</div>

				<div class="ri_fieldset">
					<div class="ri_fieldlabel">Manifest Weight (lbs, optional):</div>
					<input v-model.number="form.manifest_weight_lbs" type="number" min="0" step="0.01" class="ri_forminput" />
				</div>

				<div class="ri_fieldset">
					<div class="ri_fieldlabel">Comments:</div>
					<TextArea v-model="form.comments" :enabled="true" />
				</div>

				<button type="submit" :disabled="formSaving" class="ri_defaultbutton">Record Intake</button>
			</form>

			<h2 class="recv_head">Open Donations</h2>
			<p v-if="listError" class="ri_error">{{ listError }}</p>
			<p v-if="listLoading">Loading...</p>
			<table v-else border="1" class="ri_datatable">
				<thead>
					<tr>
						<th>Donor</th>
						<th>Status</th>
						<th>Container Est.</th>
						<th>Manifest</th>
						<th>Pallets</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="record in records" :key="record.id">
						<td>{{ donorName(record) }}</td>
						<td>{{ record.status ? record.status.name : '' }}</td>
						<td>{{ record.container_count }}</td>
						<td>{{ record.manifest }}</td>
						<td>
							<span>{{ (record.pallets || []).length }} pallet(s)</span>
							<div v-if="record.category === 'donation'">
								<input
									v-model.number="palletCounts[record.id]"
									type="number"
									min="1"
									placeholder="# to create"
									class="recv_pallet_count"
								/>
								<button @click="createPallets(record)" :disabled="palletSaving[record.id]" class="ri_formbutton">
									Create Pallets
								</button>
								<button v-if="(record.pallets || []).length" @click="printPalletLabels(record)" class="ri_formbutton">
									Print Labels
								</button>
								<p v-if="palletError[record.id]" class="ri_error">{{ palletError[record.id] }}</p>
							</div>
						</td>
					</tr>
					<tr v-if="!records.length">
						<td colspan="5">No open donations.</td>
					</tr>
				</tbody>
			</table>

			<template v-if="visibleCloseOutCandidates().length">
				<h2 class="recv_head">Daily Close-Out Review</h2>
				<p class="recv_hint">
					These donations are down to one pallet still in sorting — probably finished, just never marked empty.
				</p>
				<table border="1" class="ri_datatable">
					<thead>
						<tr>
							<th>Donor</th>
							<th>Last Pallet</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="record in visibleCloseOutCandidates()" :key="record.id">
							<td>{{ donorName(record) }}</td>
							<td>{{ record.pallets.find(p => p.status !== 'empty')?.tag }}</td>
							<td>
								<button @click="confirmStillOpen(record)" class="ri_formbutton">Still Open</button>
								<button @click="closeOut(record)" class="ri_defaultbutton">Close Out</button>
							</td>
						</tr>
					</tbody>
				</table>
			</template>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.recv_container {
	max-width: 900px;
	margin: 0 auto;
	padding: 1em;
}
.recv_head {
	margin-top: 1.5em;
}
.recv_form .ri_fieldset {
	margin-bottom: 0.75em;
}
.recv_hint {
	color: #666;
	font-size: 0.9em;
}
.recv_pallet_count {
	width: 5em;
	margin-right: 0.5em;
}
</style>
