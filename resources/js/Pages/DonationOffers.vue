<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- DonationOffers.vue

	Pre-arrival donation offers: someone calls to offer a donation before
	anything ships. Lives inside Receiving (URL nested under it, linked from
	Receiving.vue's header — no top-level nav item) but is its own page/
	permission-gated RIForm rather than tabs bolted onto Receiving.vue, since
	an offer's record shape/lifecycle (offered -> accepted/refused/diverted,
	then pending -> cancelled/received) is genuinely different from a
	Transaction.

	Recording/editing an offer only needs manage-receiving (anyone answering
	the phone can log a call); the decision buttons (Approve/Refuse/Divert/
	Cancel/Match) call endpoints gated on manage-donation-offers and are
	simply hidden by the server 403 for anyone without it, same as any other
	permission-gated action elsewhere in the app.

	The full status_log history (who/when/how/notes for every transition —
	the "full conversation history" requirement) is always visible, same
	always-visible-history pattern as FeedbackReports.vue.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import TextArea from '@/Components/TextArea.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
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

const contactMethodOptions = [
	{ value: 'phone', label: 'Phone' },
	{ value: 'email', label: 'Email' },
	{ value: 'in_person', label: 'In Person' },
	{ value: 'other', label: 'Other' },
];

const statusLabels = {
	offered: 'Offered',
	pending: 'Pending Arrival',
	refused: 'Refused',
	diverted: 'Diverted',
	cancelled: 'Cancelled',
	received: 'Received',
};

const statusClasses = {
	offered: 'don_badge_offered',
	pending: 'don_badge_pending',
	refused: 'don_badge_terminal',
	diverted: 'don_badge_terminal',
	cancelled: 'don_badge_terminal',
	received: 'don_badge_received',
};
</script>

<template>
	<Head title="Donation Offers" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<RIForm
			ref="riform"
			title="Donation Offers"
			datasource="/json/donation-offers"
			newrecordcaption="Log a Donation Offer"
			:filter="offerFilter"
			:sort="offerSort"
			@select="onOpenRecord"
			@new="onOpenRecord"
		>
			<template #listactions>
				<label class="don_checkbox">
					<input type="checkbox" v-model="pendingOnly" />
					Pending (ETA-sorted) only
				</label>
				<Link href="/receiving" class="don_backlink">&larr; Back to Receiving</Link>
			</template>

			<template #thead>
				<th>Donor</th>
				<th>Status</th>
				<th>ETA (Date Range)</th>
				<th>Description</th>
			</template>

			<template #tbody="{ record }">
				<td>{{ donorName(record) }}</td>
				<td>
					<span class="don_badge" :class="statusClasses[record.status]">{{ statusLabels[record.status] }}</span>
					<span v-if="record.is_overdue" class="don_badge don_badge_overdue">overdue</span>
				</td>
				<td>{{ formatEtaRange(record.eta_start, record.eta_end) }}</td>
				<td>{{ record.description }}</td>
			</template>

			<template #default="{ record, editing }">
				<div class="ri_formtable">
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
							:enabled="editing && isEditableStatus(record)"
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
							<div class="ri_fieldlabel">Contact Person:</div>
							<SearchSelect
								ref="contactSelect"
								v-model="record.contact_person_id"
								:optionsource="`/json/people?parent_person_id=${record.person_id}`"
								display="full_name"
								:searchfields="['first_name', 'last_name']"
								placeholder="Search contacts at this org..."
								:allowcreate="true"
								:enabled="editing && isEditableStatus(record)"
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
						<div class="ri_fieldlabel">What's being offered:</div>
						<TextArea v-model="record.description" :enabled="editing && isEditableStatus(record)" />
					</div>

					<div class="ri_fieldset" v-if="record.status === 'offered'">
						<div class="ri_fieldlabel">Rough ETA Window (if known):</div>
						<div class="ri_formcontrol don_etarange">
							<input
								type="date"
								:value="toDateInput(record.eta_start)"
								@input="record.eta_start = $event.target.value"
								class="ri_forminput"
								:disabled="!editing"
							/>
							<span>to</span>
							<input
								type="date"
								:value="toDateInput(record.eta_end)"
								@input="record.eta_end = $event.target.value"
								class="ri_forminput"
								:disabled="!editing"
							/>
						</div>
					</div>

					<template v-if="record.status === 'pending'">
						<div class="ri_fieldset">
							<div class="ri_fieldlabel">ETA Window:</div>
							<div class="ri_formcontrol don_etarange">
								<input
									type="date"
									:value="toDateInput(record.eta_start)"
									@input="record.eta_start = $event.target.value"
									class="ri_forminput"
									:disabled="!editing"
								/>
								<span>to</span>
								<input
									type="date"
									:value="toDateInput(record.eta_end)"
									@input="record.eta_end = $event.target.value"
									class="ri_forminput"
									:disabled="!editing"
								/>
							</div>
						</div>
						<div class="ri_fieldset">
							<div class="ri_fieldlabel">Transit Notes:</div>
							<TextArea v-model="record.transit_notes" :enabled="false" />
						</div>
					</template>

					<div class="ri_fieldset" v-if="record.status === 'refused'">
						<div class="ri_fieldlabel">Refused Because:</div>
						<TextArea v-model="record.refused_reason" :enabled="false" />
					</div>
					<div class="ri_fieldset" v-if="record.status === 'diverted'">
						<div class="ri_fieldlabel">Diverted To:</div>
						<TextInput v-model="record.diverted_to" :enabled="false" />
					</div>
					<div class="ri_fieldset" v-if="record.status === 'cancelled'">
						<div class="ri_fieldlabel">Cancelled Because:</div>
						<TextArea v-model="record.cancelled_reason" :enabled="false" />
					</div>
				</div>

				<!-- Donor history: past donations and past offers, so the decision isn't made blind -->
				<template v-if="record.person">
					<h3 class="don_subhead">Donor History</h3>
					<div v-if="!pastDonations(record).length && !pastOffers(record).length" class="ri_hint">
						No prior donations or offers from this donor on file.
					</div>
					<ul v-if="pastDonations(record).length" class="don_historylist">
						<li v-for="d in pastDonations(record)" :key="'d' + d.id">
							Donation #{{ d.id }} — {{ d.order_date }} — {{ d.status?.name || 'Unknown status' }}
						</li>
					</ul>
					<ul v-if="pastOffers(record).length" class="don_historylist">
						<li v-for="o in pastOffers(record)" :key="'o' + o.id">
							Offer #{{ o.id }} — {{ statusLabels[o.status] }}
						</li>
					</ul>
				</template>

				<!-- Full audit trail: every transition, who/when/how/notes -->
				<h3 class="don_subhead">History</h3>
				<div class="don_historylog">
					<div
						v-for="log in record.status_logs || []"
						:key="log.id"
						class="don_logentry"
					>
						<span class="font-semibold">
							{{ log.from_status ? `${statusLabels[log.from_status]} → ${statusLabels[log.to_status]}` : `Logged as ${statusLabels[log.to_status]}` }}
						</span>
						by {{ log.changed_by?.full_name || 'Unknown' }}
						<span class="don_logmeta">— {{ formatDateTime(log.created_at) }}<template v-if="log.contact_method"> · {{ contactMethodLabel(log.contact_method) }}</template></span>
						<div v-if="log.notes" class="don_lognotes">{{ log.notes }}</div>
					</div>
				</div>
			</template>

			<template #actions="{ editing, record, save, cancel }">
				<div class="ri_formactions" v-if="!editing">
					<button @click="cancel()" class="ri_defaultbutton">Back to List</button>
				</div>
				<template v-else>
					<div class="ri_formactions">
						<button @click="save()" class="ri_defaultbutton">Save</button>
						<button @click="cancel()" class="ri_formbutton">Cancel</button>
					</div>

					<template v-if="record.status === 'offered'">
						<h3 class="don_subhead">Decision</h3>
						<div class="don_decisionrow">
							<button @click="startDecision(record, 'approve')" class="ri_defaultbutton">Approve</button>
							<button @click="startDecision(record, 'refuse')" class="ri_formbutton">Refuse</button>
							<button @click="startDecision(record, 'divert')" class="ri_formbutton">Divert</button>
						</div>
					</template>

					<template v-if="record.status === 'pending'">
						<h3 class="don_subhead">Decision</h3>
						<div class="don_decisionrow">
							<button @click="startDecision(record, 'cancel')" class="ri_formbutton">Cancel Offer</button>
							<button @click="startDecision(record, 'match')" class="ri_defaultbutton">Match to Arrival</button>
						</div>
						<p class="ri_hint">
							Or
							<Link :href="`/receiving?match_offer_id=${record.id}`">start a new Receiving intake for this offer</Link>.
						</p>
					</template>

					<!-- Inline confirm panel — shown after a decision button is clicked -->
					<div v-if="decision" class="don_decisionpanel">
						<h4 class="don_subhead">
							{{ { approve: 'Approve Offer', refuse: 'Refuse Offer', divert: 'Divert Offer', cancel: 'Cancel Offer', match: 'Match to Arrival' }[decision.action] }}
						</h4>

						<div class="ri_fieldset" v-if="decision.action === 'approve'">
							<div class="ri_fieldlabel">ETA Window (start required):</div>
							<div class="ri_formcontrol don_etarange">
								<input type="date" v-model="decision.eta_start" class="ri_forminput" />
								<span>to</span>
								<input type="date" v-model="decision.eta_end" class="ri_forminput" />
							</div>
						</div>
						<div class="ri_fieldset" v-if="decision.action === 'approve'">
							<div class="ri_fieldlabel">Transit Notes:</div>
							<TextArea v-model="decision.transit_notes" />
						</div>

						<div class="ri_fieldset" v-if="decision.action === 'refuse'">
							<div class="ri_fieldlabel">Reason (required):</div>
							<TextArea v-model="decision.refused_reason" />
						</div>

						<div class="ri_fieldset" v-if="decision.action === 'divert'">
							<div class="ri_fieldlabel">Diverted to (required):</div>
							<TextInput v-model="decision.diverted_to" />
						</div>

						<div class="ri_fieldset" v-if="decision.action === 'cancel'">
							<div class="ri_fieldlabel">Reason (required):</div>
							<TextArea v-model="decision.cancelled_reason" />
						</div>

						<div class="ri_fieldset" v-if="decision.action === 'match'">
							<div class="ri_fieldlabel">Match to arrived donation:</div>
							<SearchSelect
								v-model="decision.donation_id"
								optionsource="/json/donation-offers/unmatched-donations"
								display="label"
								:searchfields="['label']"
								placeholder="Search unmatched intakes..."
							/>
						</div>

						<div class="ri_fieldset">
							<div class="ri_fieldlabel">Contact Method:</div>
							<select v-model="decision.contact_method" class="ri_forminput">
								<option :value="null">—</option>
								<option v-for="o in contactMethodOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
							</select>
						</div>
						<div class="ri_fieldset">
							<div class="ri_fieldlabel">Notes:</div>
							<TextArea v-model="decision.notes" />
						</div>

						<p v-if="decisionError" class="ri_error">{{ decisionError }}</p>
						<div class="ri_formactions">
							<button @click="confirmDecision(record)" :disabled="decisionSaving" class="ri_defaultbutton">Confirm</button>
							<button @click="decision = null" class="ri_formbutton">Cancel</button>
						</div>
					</div>
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
			pendingOnly: true,

			creatingDonor: false,
			newDonor: { first_name: '', last_name: '', organization: '' },
			donorSaving: false,
			donorError: null,

			creatingContact: false,
			newContact: { first_name: '', last_name: '', phone: '' },
			contactSaving: false,
			contactError: null,

			decision: null, // { action, ...fields }
			decisionSaving: false,
			decisionError: null,
		};
	},
	computed: {
		offerFilter() {
			const pendingOnly = this.pendingOnly;
			return (record) => (pendingOnly ? record.status === 'pending' : true);
		},
		offerSort() {
			return (a, b) => {
				if (!a.eta_start && !b.eta_start) return 0;
				if (!a.eta_start) return 1;
				if (!b.eta_start) return -1;
				return new Date(a.eta_start) - new Date(b.eta_start);
			};
		},
	},
	methods: {
		donorName(record) {
			return record.person?.full_name || '(no donor recorded)';
		},
		formatDateTime(value) {
			return value ? new Date(value).toLocaleString() : '';
		},
		// Parses a "YYYY-MM-DD" string as a local calendar date, not UTC —
		// `new Date("YYYY-MM-DD")` is spec'd to parse as UTC midnight, which
		// can render as the wrong day in a timezone west of UTC.
		formatLocalDate(value) {
			if (!value) return '';
			const [y, m, d] = value.split('-').map(Number);
			return new Date(y, m - 1, d).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
		},
		formatEtaRange(start, end) {
			if (!start) return '';
			const startLabel = this.formatLocalDate(start);
			if (!end || end === start) return startLabel;
			return `${startLabel} – ${this.formatLocalDate(end)}`;
		},
		contactMethodLabel(value) {
			return { phone: 'Phone', email: 'Email', in_person: 'In Person', other: 'Other' }[value] || value;
		},
		isEditableStatus(record) {
			// !record.id covers a brand-new record before its first save,
			// regardless of whatever status the template happens to carry.
			return !record.id || record.status === 'offered' || record.status === 'pending';
		},
		pastDonations(record) {
			return (record.person?.order_donations || []).filter((d) => d.id !== record.donation_id);
		},
		pastOffers(record) {
			return (record.person?.donation_offers || []).filter((o) => o.id !== record.id);
		},

		onOpenRecord() {
			this.creatingDonor = false;
			this.creatingContact = false;
			this.donorError = null;
			this.contactError = null;
			this.decision = null;
			this.decisionError = null;
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

		// ---------- decisions: approve/refuse/divert/cancel/match ----------
		// eta_start/eta_end arrive from the server as a plain "YYYY-MM-DD"
		// string (deliberately uncast on the model — see DonationOffer.php),
		// which is exactly what <input type="date"> expects.
		toDateInput(value) {
			return value || '';
		},
		startDecision(record, action) {
			this.decisionError = null;
			this.decision = {
				action,
				eta_start: this.toDateInput(record.eta_start),
				eta_end: this.toDateInput(record.eta_end),
				transit_notes: '',
				refused_reason: '',
				diverted_to: '',
				cancelled_reason: '',
				donation_id: null,
				contact_method: null,
				notes: '',
			};
		},
		async confirmDecision(record) {
			this.decisionError = null;
			const { action } = this.decision;

			if (action === 'approve' && !this.decision.eta_start) {
				this.decisionError = 'Enter an ETA.';
				return;
			}
			if (action === 'refuse' && !this.decision.refused_reason.trim()) {
				this.decisionError = 'Enter a reason.';
				return;
			}
			if (action === 'divert' && !this.decision.diverted_to.trim()) {
				this.decisionError = 'Enter where this was diverted to.';
				return;
			}
			if (action === 'cancel' && !this.decision.cancelled_reason.trim()) {
				this.decisionError = 'Enter a reason.';
				return;
			}
			if (action === 'match' && !this.decision.donation_id) {
				this.decisionError = 'Select the arrived intake to match.';
				return;
			}

			this.decisionSaving = true;
			try {
				const response = await axios.post(`/json/donation-offers/${record.id}/${action}`, this.decision);
				Object.assign(record, response.data.record);
				this.decision = null;
				this.$refs.riform?.fetchRecords();
			} catch (error) {
				this.decisionError = error.response?.data?.message || 'Could not save.';
			} finally {
				this.decisionSaving = false;
			}
		},
	},
};
</script>

<style scoped>
.don_subhead {
	margin-top: 1.5em;
}
.don_checkbox {
	display: flex;
	align-items: center;
	gap: 0.4em;
	font-weight: normal;
}
.don_backlink {
	color: #007bff;
	text-decoration: underline;
	font-size: 0.9em;
	align-self: center;
}
.don_badge {
	display: inline-block;
	margin-right: 0.4em;
	padding: 0.1em 0.5em;
	font-size: 0.8em;
	border-radius: 3px;
	background: #f3f4f6;
	color: #374151;
}
.don_badge_offered {
	background: #dbeafe;
	color: #1e40af;
}
.don_badge_pending {
	background: #fef3c7;
	color: #92400e;
}
.don_badge_received {
	background: #dcfce7;
	color: #166534;
}
.don_badge_terminal {
	background: #f3f4f6;
	color: #6b7280;
}
.don_badge_overdue {
	background: #fee2e2;
	color: #991b1b;
}
.don_historylist {
	margin: 0.25em 0 0.75em 1.25em;
	font-size: 0.9em;
	color: #444;
}
.don_historylog {
	margin: 0.5em 0;
}
.don_etarange {
	display: flex;
	align-items: center;
	gap: 0.5em;
}
.don_etarange .ri_forminput {
	width: auto;
}
.don_logentry {
	border: 1px solid #e5e7eb;
	border-radius: 4px;
	background: #fafafa;
	padding: 0.5em 0.75em;
	margin-bottom: 0.4em;
	font-size: 0.85em;
}
.don_logmeta {
	color: #888;
}
.don_lognotes {
	margin-top: 0.25em;
	color: #333;
}
.don_decisionrow {
	display: flex;
	gap: 0.5em;
	flex-wrap: wrap;
	margin: 0.5em 0;
}
.don_decisionpanel {
	border: 1px solid #e5e7eb;
	border-radius: 4px;
	padding: 0.75em;
	margin-top: 0.75em;
	background: #fafafa;
}
</style>
