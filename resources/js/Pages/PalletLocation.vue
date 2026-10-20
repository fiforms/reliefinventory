<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- PalletLocation.vue (/inventory-movement)
     Where the pallets are: a per-location summary, a scan-driven "record movement/status"
     form that first looks up where the database believes the pallet is, a pallet search,
     and the recent movement log. Writes go to POST /json/palletstatus (append-only history). -->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';
import axios from 'axios';

defineProps({
	breadcrumb: { type: Array },
});

const tab = ref('locations'); // 'locations' | 'record' | 'search' | 'recent'

// ---------- locations summary ----------
const summary = ref(null);
const summaryError = ref(null);

async function loadSummary() {
	summaryError.value = null;
	try {
		summary.value = (await axios.get('/json/palletstatus/summary')).data;
	} catch (e) {
		summaryError.value = e.response?.data?.message || 'Could not load locations.';
	}
}

const statusColumns = computed(() => {
	const seen = new Set();
	const add = (b) => Object.keys(b?.by_status || {}).forEach((s) => seen.add(s));
	(summary.value?.locations || []).forEach(add);
	add(summary.value?.no_location);
	add(summary.value?.other_locations);
	return [...seen].sort();
});

function breakdown(bucket) {
	return Object.entries(bucket.by_status || {})
		.map(([status, n]) => `${n} ${status}`)
		.join(', ');
}

const locationCodes = computed(() => (summary.value?.locations || []).map((l) => l.code));

function locationByCode(code) {
	const wanted = (code || '').trim().toLowerCase();
	return (summary.value?.locations || []).find((l) => l.code.toLowerCase() === wanted) || null;
}

// ---------- pallet lookup (shared by Record + Search) ----------
async function lookupPallet(tag) {
	const response = await axios.get('/json/palletstatus/lookup', { params: { tag } });
	return response.data.record;
}

// ---------- record movement / status ----------
const form = ref({ tag: '', pallet: null, locationCode: '', status: '', notes: '' });
const formError = ref(null);
const formMessage = ref(null);
const saving = ref(false);
const palletInput = ref(null);

async function scanPallet() {
	formError.value = null;
	formMessage.value = null;
	form.value.pallet = null;
	if (!form.value.tag.trim()) return;
	try {
		const pallet = await lookupPallet(form.value.tag);
		form.value.pallet = pallet;
		form.value.locationCode = pallet.location?.code || '';
		form.value.status = pallet.status;
	} catch (e) {
		formError.value = e.response?.data?.message || 'Could not look up that pallet.';
	}
}

const chosenLocation = computed(() => locationByCode(form.value.locationCode));
const unknownLocationText = computed(() => form.value.locationCode.trim() !== '' && !chosenLocation.value);
const locationChanged = computed(() => {
	const pallet = form.value.pallet;
	if (!pallet) return false;
	return (chosenLocation.value?.id ?? null) !== (pallet.location_id ?? null) && form.value.locationCode.trim() !== '';
});
const saveLabel = computed(() => (locationChanged.value ? 'Record Movement' : 'Record Status'));

async function recordMovement() {
	const pallet = form.value.pallet;
	if (!pallet) return;
	if (unknownLocationText.value) {
		formError.value = `"${form.value.locationCode}" is not a known active location.`;
		return;
	}
	saving.value = true;
	formError.value = null;
	try {
		await axios.post('/json/palletstatus', {
			pallet_id: pallet.id,
			location_id: chosenLocation.value?.id ?? pallet.location_id ?? null,
			status: form.value.status,
			notes: form.value.notes || null,
		});
		formMessage.value = `${pallet.tag}: ${locationChanged.value ? 'moved to ' + form.value.locationCode : 'status recorded as ' + form.value.status}.`;
		form.value = { tag: '', pallet: null, locationCode: '', status: '', notes: '' };
		loadSummary();
		recent.value = null;
		await nextTick();
		palletInput.value?.focus();
	} catch (e) {
		const errors = e.response?.data?.errors;
		formError.value = (errors && Object.values(errors).flat().join(' ')) || e.response?.data?.message || 'Could not save.';
	} finally {
		saving.value = false;
	}
}

// ---------- pallet search ----------
const search = ref({ tag: '', result: null, history: [], error: null });

async function runSearch() {
	search.value.error = null;
	search.value.result = null;
	search.value.history = [];
	if (!search.value.tag.trim()) return;
	try {
		const pallet = await lookupPallet(search.value.tag);
		search.value.result = pallet;
		search.value.history = (await axios.get('/json/palletstatus', { params: { pallet_id: pallet.id, limit: 20 } })).data.records;
	} catch (e) {
		search.value.error = e.response?.data?.message || 'Could not look up that pallet.';
	}
}

function openRecordFor(pallet) {
	form.value.tag = pallet.tag;
	tab.value = 'record';
	scanPallet();
}

// ---------- recent movements ----------
const recent = ref(null);

async function loadRecent() {
	if (recent.value) return;
	recent.value = (await axios.get('/json/palletstatus', { params: { limit: 100 } })).data.records;
}

function show(name) {
	tab.value = name;
	if (name === 'recent') loadRecent();
	if (name === 'locations') loadSummary();
}

function formatDate(value) {
	return value ? new Date(value).toLocaleString() : '';
}

onMounted(loadSummary);
</script>

<template>
	<Head title="Inventory Movement" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<div class="pm_page">
			<h2 class="pm_title">Inventory Movement</h2>

			<div class="pm_tabs">
				<button type="button" :class="['ri_formbutton', { pm_active: tab === 'locations' }]" @click="show('locations')">Locations</button>
				<button type="button" :class="['ri_formbutton', { pm_active: tab === 'record' }]" @click="show('record')">Record Movement</button>
				<button type="button" :class="['ri_formbutton', { pm_active: tab === 'search' }]" @click="show('search')">Pallet Search</button>
				<button type="button" :class="['ri_formbutton', { pm_active: tab === 'recent' }]" @click="show('recent')">Recent Movements</button>
			</div>

			<!-- Locations -->
			<div v-if="tab === 'locations'">
				<p v-if="summaryError" class="ri_error">{{ summaryError }}</p>
				<p v-else-if="!summary">Loading…</p>
				<div v-else class="pm_tablewrap">
					<table class="ri_datatable" border="1">
						<thead>
							<tr>
								<th>Location</th>
								<th>Pallets</th>
								<th v-for="s in statusColumns" :key="s" class="pm_status_head">{{ s }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="l in summary.locations" :key="l.id" :class="{ pm_zero: !l.total }">
								<td>{{ l.code }}</td>
								<td>{{ l.total }}</td>
								<td v-for="s in statusColumns" :key="s">{{ l.by_status[s] || '' }}</td>
							</tr>
							<tr v-if="summary.no_location.total" class="pm_nolocation">
								<td>No location recorded</td>
								<td>{{ summary.no_location.total }}</td>
								<td v-for="s in statusColumns" :key="s">{{ summary.no_location.by_status[s] || '' }}</td>
							</tr>
							<tr v-if="summary.other_locations.total" class="pm_nolocation">
								<td>Archived / other locations</td>
								<td>{{ summary.other_locations.total }}</td>
								<td v-for="s in statusColumns" :key="s">{{ summary.other_locations.by_status[s] || '' }}</td>
							</tr>
							<tr v-if="!summary.locations.length">
								<td :colspan="2 + statusColumns.length">No active locations are set up yet.</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p class="ri_hint">Counts are live pallets only — shipped, collected, released, condemned and missing pallets aren't included.</p>
			</div>

			<!-- Record movement -->
			<div v-else-if="tab === 'record'" class="ri_formtable">
				<div class="ri_fieldset">
					<div class="ri_fieldlabel">Pallet:</div>
					<input
						ref="palletInput"
						v-model="form.tag"
						class="ri_forminput"
						placeholder="Scan or enter pallet ID"
						autofocus
						@keyup.enter="scanPallet"
						@change="scanPallet"
					/>
				</div>
				<p v-if="formError" class="ri_error">{{ formError }}</p>
				<p v-if="formMessage" class="ri_hint">{{ formMessage }}</p>

				<template v-if="form.pallet">
					<p class="pm_current">
						<strong>{{ form.pallet.tag }}</strong> ({{ form.pallet.kind_label }}) — the database has it
						<strong>{{ form.pallet.location ? 'at ' + form.pallet.location.code : 'with no location recorded' }}</strong>,
						status <strong>{{ form.pallet.status }}</strong>.
					</p>

					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Location:</div>
						<input
							v-model="form.locationCode"
							class="ri_forminput"
							list="pm_locations"
							placeholder="Unknown — scan or type a location"
						/>
						<datalist id="pm_locations">
							<option v-for="code in locationCodes" :key="code" :value="code" />
						</datalist>
					</div>

					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Status:</div>
						<select v-model="form.status" class="ri_forminput">
							<option v-for="s in form.pallet.statuses" :key="s.id" :value="s.id">{{ s.name }}</option>
						</select>
					</div>

					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Notes:</div>
						<textarea v-model="form.notes" class="ri_forminput" rows="2"></textarea>
					</div>

					<div class="ri_formactions">
						<button type="button" class="ri_defaultbutton" :disabled="saving" @click="recordMovement">{{ saveLabel }}</button>
						<button type="button" class="ri_formbutton" @click="form = { tag: '', pallet: null, locationCode: '', status: '', notes: '' }">Cancel</button>
					</div>
				</template>
			</div>

			<!-- Pallet search -->
			<div v-else-if="tab === 'search'">
				<div class="ri_fieldset">
					<div class="ri_fieldlabel">Pallet:</div>
					<input v-model="search.tag" class="ri_forminput" placeholder="Scan or enter pallet ID" @keyup.enter="runSearch" />
					<button type="button" class="ri_formbutton" @click="runSearch">Find</button>
				</div>
				<p v-if="search.error" class="ri_error">{{ search.error }}</p>
				<template v-if="search.result">
					<p class="pm_current">
						<strong>{{ search.result.tag }}</strong> ({{ search.result.kind_label }}) is
						<strong>{{ search.result.location ? 'at ' + search.result.location.code : 'not at any recorded location' }}</strong>,
						status <strong>{{ search.result.status }}</strong><span v-if="search.result.last_moved_at">, last updated {{ formatDate(search.result.last_moved_at) }}</span>.
					</p>
					<button type="button" class="ri_formbutton" @click="openRecordFor(search.result)">Record Movement / Status</button>
					<div class="pm_tablewrap">
						<table class="ri_datatable" border="1">
							<thead><tr><th>Date</th><th>Location</th><th>Status</th><th>Notes</th></tr></thead>
							<tbody>
								<tr v-for="h in search.history" :key="h.id">
									<td>{{ formatDate(h.created_at) }}</td>
									<td>{{ h.location ? h.location.code : 'Unknown' }}</td>
									<td>{{ h.status }}</td>
									<td>{{ h.notes }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>
			</div>

			<!-- Recent movements -->
			<div v-else-if="tab === 'recent'">
				<p v-if="!recent">Loading…</p>
				<div v-else class="pm_tablewrap">
					<table class="ri_datatable" border="1">
						<thead><tr><th>Date</th><th>Pallet</th><th>Location</th><th>Status</th><th>Notes</th></tr></thead>
						<tbody>
							<tr v-for="r in recent" :key="r.id">
								<td>{{ formatDate(r.created_at) }}</td>
								<td class="pm_mono">{{ r.pallet ? r.pallet.tag : r.pallet_id }}</td>
								<td>{{ r.location ? r.location.code : 'Unknown' }}</td>
								<td>{{ r.status }}</td>
								<td>{{ r.notes }}</td>
							</tr>
							<tr v-if="!recent.length"><td colspan="5">No movements recorded yet.</td></tr>
						</tbody>
					</table>
				</div>
				<p class="ri_hint">Showing the 100 most recent entries.</p>
			</div>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.pm_page {
	padding: 1em;
}
.pm_title {
	margin-bottom: 0.5em;
}
.pm_tabs {
	display: flex;
	gap: 0.5em;
	flex-wrap: wrap;
	margin-bottom: 1em;
}
.pm_active {
	font-weight: bold;
	outline: 2px solid currentColor;
}
.pm_tablewrap {
	overflow-x: auto;
	margin: 0.5em 0;
}
.pm_status_head {
	text-transform: capitalize;
}
.pm_zero {
	opacity: 0.55;
}
.pm_nolocation {
	font-style: italic;
}
.pm_current {
	margin: 0.75em 0;
}
.pm_mono {
	font-family: monospace;
}
</style>
