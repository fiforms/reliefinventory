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
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import TextArea from '@/Components/TextArea.vue';
import ComboBox from '@/Components/ComboBox.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import RIForm from '@/Components/RIForm.vue';

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
</script>

<template>
	<Head title="Receiving" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<RIForm
			ref="riform"
			title="Receiving"
			datasource="/json/receiving"
			newrecordcaption="Record an Intake"
		>
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
				<td>{{ record.category }}</td>
				<td>
					{{ record.status ? record.status.name : '' }}
					<span v-if="record.is_close_out_candidate" class="recv_badge">ready to close out</span>
				</td>
				<td>{{ record.container_count }}</td>
				<td>{{ (record.pallets || []).length }}</td>
			</template>

			<template #default="{ record, editing, templates }">
				<div class="ri_formtable">
					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Category:</div>
						<ComboBox
							v-model:keyValue="record.category"
							:options="categoryOptions"
							:enabled="editing"
						/>
					</div>

					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Donor / Source:</div>
						<SearchSelect
							ref="donorSelect"
							v-model="record.person_id"
							optionsource="/json/people"
							display="organization"
							:searchfields="['organization', 'first_name', 'last_name']"
							placeholder="Search donors..."
							:allowcreate="true"
							:enabled="editing"
							@selected="creatingDonor = false"
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
							<div class="ri_fieldlabel">Organization:</div>
							<input v-model="newDonor.organization" class="ri_forminput" />
						</div>
						<p class="ri_hint">Provide a name (first + last) and/or an organization &mdash; at least one is required.</p>
						<button type="button" @click="saveNewDonor(record)" :disabled="donorSaving" class="ri_formbutton">
							Save Donor
						</button>
					</template>

					<div class="ri_fieldset">
						<label class="recv_checkbox">
							<input type="checkbox" v-model="record.donor_identification_pending" :disabled="!editing" />
							Donor unidentified &mdash; flag for follow-up
						</label>
						<p class="ri_hint">
							Use this when the donation arrived with little or no source information (no organization,
							no contact) so it can be found later once the donor is known.
						</p>
					</div>

					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Rough Container Count:</div>
						<TextInput v-model="record.container_count" type="number" :enabled="editing" />
					</div>

					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Manifest (what's on this load):</div>
						<TextArea v-model="record.manifest" :enabled="editing" />
					</div>

					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Manifest Weight (lbs, optional):</div>
						<TextInput v-model="record.manifest_weight_lbs" type="number" :enabled="editing" />
					</div>

					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Comments:</div>
						<TextArea v-model="record.comments" :enabled="editing" />
					</div>
				</div>

				<template v-if="record.id && record.category === 'donation'">
					<h3 class="recv_subhead">Pallets</h3>
					<p class="recv_hint">
						Describe each pallet on the load as it's tagged (optional but encouraged) —
						e.g. quantity 4, "Mixed pallet". If a whole pallet is one product (a pallet of
						ketchup), tag it with the item: single-item pallets can be expedited at sorting
						(count the cases and put away) instead of sorted line by line.
					</p>
					<div class="recv_palletline">
						<input
							v-model.number="palletCount"
							type="number"
							min="1"
							placeholder="Qty"
							class="recv_pallet_count"
						/>
						<input
							v-model="palletDescription"
							class="ri_forminput recv_pallet_desc"
							placeholder="What's on these pallets? (e.g. Mixed pallet)"
						/>
						<div class="recv_pallet_item">
							<SearchSelect
								ref="palletItemSelect"
								v-model="palletItemId"
								optionsource="/json/items"
								display="name"
								:searchfields="['name', 'upc', 'description']"
								placeholder="All one item? Tag it (optional)..."
							/>
						</div>
						<button @click="createPallets(record)" :disabled="palletSaving" class="ri_formbutton">
							Add Pallet(s)
						</button>
					</div>
					<p v-if="palletError" class="ri_error">{{ palletError }}</p>

					<template v-if="(record.pallets || []).length">
						<div class="recv_tablewrap">
							<table class="ri_datatable" border="1">
								<thead>
									<tr><th>Tag</th><th>Contents</th><th>Status</th><th></th></tr>
								</thead>
								<tbody>
									<tr v-for="pallet in record.pallets" :key="pallet.id">
										<td class="recv_mono">{{ pallet.tag }}</td>
										<td>{{ palletContents(pallet) }}</td>
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
					<p v-else>No pallets created for this intake yet.</p>
				</template>

				<template v-if="record.id && record.is_close_out_candidate">
					<h3 class="recv_subhead">Daily Close-Out</h3>
					<p class="recv_hint">
						This donation is down to one pallet still in sorting — probably finished, just never marked empty.
					</p>
					<button @click="closeOut(record)" class="ri_defaultbutton">Close Out</button>
					<p v-if="closeOutError" class="ri_error">{{ closeOutError }}</p>
				</template>
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
			creatingDonor: false,
			newDonor: { first_name: '', last_name: '', organization: '' },
			donorSaving: false,
			donorError: null,

			palletCount: null,
			palletDescription: '',
			palletItemId: null,
			palletSaving: false,
			palletError: null,

			closeOutError: null,
		};
	},
	methods: {
		donorName(record) {
			if (!record.person) return '(no donor recorded)';
			return record.person.organization || (record.person.first_name + ' ' + record.person.last_name);
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

		// ---------- pallets for a donation ----------
		palletContents(pallet) {
			if (pallet.content_item) {
				return pallet.content_item.name || pallet.content_item.description;
			}
			return pallet.content_description || '(not described)';
		},
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
					content_description: this.palletDescription.trim() || null,
					content_item_id: this.palletItemId,
				});
				record.pallets = [...(record.pallets || []), ...response.data.records];
				this.palletCount = null;
				this.palletDescription = '';
				this.palletItemId = null;
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
.recv_pallet_desc {
	flex: 1;
	min-width: 14em;
}
.recv_pallet_item {
	flex: 1;
	min-width: 16em;
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
</style>
