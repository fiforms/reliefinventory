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
import PrintMenu from '@/Components/PrintMenu.vue';

defineProps({
	breadcrumb: { type: Array },
});

const tab = ref('locations'); // 'locations' | 'record' | 'fill' | 'search' | 'recent'

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

// One flat list of table rows: each real location, then the "no location"/"archived" buckets when non-empty.
const locationRows = computed(() => {
	const rows = (summary.value?.locations || []).map((l) => ({ key: l.id, label: l.code, total: l.total, by_status: l.by_status || {}, zero: !l.total }));
	const s = summary.value;
	if (s?.no_location?.total) rows.push({ key: 'none', label: 'No location recorded', total: s.no_location.total, by_status: s.no_location.by_status || {}, special: true });
	if (s?.other_locations?.total) rows.push({ key: 'other', label: 'Archived / other locations', total: s.other_locations.total, by_status: s.other_locations.by_status || {}, special: true });
	return rows;
});

const locationCodes = computed(() => (summary.value?.locations || []).map((l) => l.code));

function locationByCode(code) {
	const wanted = (code || '').trim().toLowerCase();
	return (summary.value?.locations || []).find((l) => l.code.toLowerCase() === wanted) || null;
}

// ---------- location drill-down ----------
const openLocation = ref(null); // { key, label, pallets|null, error }

async function toggleLocation(key, label) {
	if (openLocation.value?.key === key) {
		openLocation.value = null;
		return;
	}
	openLocation.value = { key, label, pallets: null, error: null };
	try {
		const pallets = (await axios.get(`/json/palletstatus/location/${key}`)).data.records;
		if (openLocation.value?.key === key) openLocation.value.pallets = pallets;
	} catch (e) {
		if (openLocation.value?.key === key) openLocation.value.error = e.response?.data?.message || 'Could not load pallets.';
	}
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


// ---------- fill a warehouse pallet (leaving the sorting area) ----------
const staging = ref(null); // what's still in the sorting area, i.e. on hand but not on a pallet
// expires: null = use the suggestion (earliest date among the chosen items and what's already on the pallet);
// '' = the driver cleared it (no date); a date = the driver's own entry.
const fill = ref({ tag: '', pallet: null, lines: [], locationCode: '', notes: '', expires: null });
const fillError = ref(null);
const fillResult = ref(null);
const filling = ref(false);

// item_id -> its item type, so SKUs of one ACS item type can be shown together. Best effort: without
// it (no permission, request failed) every SKU just shows as its own row.
const itemTypes = ref({});

async function loadItemTypes() {
	if (Object.keys(itemTypes.value).length) return;
	try {
		const records = (await axios.get('/json/items')).data.records;
		itemTypes.value = Object.fromEntries(
			records.filter((i) => i.itemtype).map((i) => [i.id, { id: i.itemtype.id, number: i.itemtype.display_number, name: i.itemtype.name }]),
		);
	} catch (e) {
		// leave ungrouped
	}
}

async function loadStaging() {
	try {
		const [records] = await Promise.all([axios.get('/json/pallet-stock/staging').then((r) => r.data.records), loadItemTypes()]);
		staging.value = records;
	} catch (e) {
		fillError.value = e.response?.data?.message || 'Could not load the sorting-area list.';
	}
}

const stagingLeft = computed(() =>
	(staging.value || [])
		.map((row) => ({ ...row, left: row.qty - fill.value.lines.filter((l) => l.item_id === row.item_id).reduce((n, l) => n + (Number(l.qty) || 0), 0) }))
		.filter((row) => row.left > 0),
);

// Sorting-area rows grouped by item type (one group per ACS number); a type with a single SKU stays a plain row.
const stagingGroups = computed(() => {
	const groups = new Map();
	for (const row of stagingLeft.value) {
		const type = itemTypes.value[row.item_id];
		const key = type ? `t${type.id}` : `i${row.item_id}`;
		if (!groups.has(key)) groups.set(key, { key, type, rows: [], left: 0 });
		const g = groups.get(key);
		g.rows.push(row);
		g.left += row.left;
	}
	for (const g of groups.values()) {
		g.expires = g.rows.map((r) => r.expires_on).filter(Boolean).sort()[0] || '';
	}
	return [...groups.values()];
});

const expandedGroups = ref(new Set());

function toggleGroup(key) {
	const next = new Set(expandedGroups.value);
	if (!next.delete(key)) next.add(key);
	expandedGroups.value = next;
}

function addGroup(group) {
	group.rows.forEach(addFillLine);
}

function addFillLine(row) {
	const existing = fill.value.lines.find((l) => l.item_id === row.item_id);
	if (existing) existing.qty = row.qty;
	else fill.value.lines.push({ item_id: row.item_id, name: row.name, qty: row.left ?? row.qty, max: row.qty, expires_on: row.expires_on || null });
}

async function pickFillPallet() {
	fillError.value = null;
	fill.value.pallet = null;
	if (!fill.value.tag.trim()) return;
	try {
		const pallet = await lookupPallet(fill.value.tag);
		if (pallet.kind !== 'W') {
			fillError.value = `${pallet.tag} is a ${pallet.kind_label} pallet — use a Warehouse (W) pallet, or leave blank for a new one.`;
			return;
		}
		fill.value.pallet = pallet;
		if (pallet.location) fill.value.locationCode = pallet.location.code;
	} catch (e) {
		fillError.value = e.response?.data?.message || 'Could not look up that pallet.';
	}
}

const fillLocation = computed(() => locationByCode(fill.value.locationCode));
const suggestedExpiry = computed(() => {
	const dates = fill.value.lines.map((l) => l.expires_on).concat(fill.value.pallet?.expires_on || null).filter(Boolean);
	return dates.length ? dates.reduce((a, b) => (b < a ? b : a)) : '';
});
const fillExpiry = computed(() => (fill.value.expires === null ? suggestedExpiry.value : fill.value.expires));
const fillTotal = computed(() => fill.value.lines.reduce((n, l) => n + (Number(l.qty) || 0), 0));

async function submitFill() {
	fillError.value = null;
	fillResult.value = null;
	if (!fill.value.lines.length) {
		fillError.value = 'Add at least one item.';
		return;
	}
	if (fill.value.locationCode.trim() && !fillLocation.value) {
		fillError.value = `"${fill.value.locationCode}" is not a known active location.`;
		return;
	}
	filling.value = true;
	try {
		const response = await axios.post('/json/pallet-stock/fill', {
			pallet_id: fill.value.pallet?.id ?? null,
			location_id: fillLocation.value?.id ?? null,
			notes: fill.value.notes || null,
			expires_on: fillExpiry.value || null,
			lines: fill.value.lines.map((l) => ({ item_id: l.item_id, qty: Number(l.qty) })),
		});
		fillResult.value = { ...response.data.record, isNew: !fill.value.pallet };
		fill.value = { tag: '', pallet: null, lines: [], locationCode: '', notes: '', expires: null };
		loadStaging();
		loadSummary();
	} catch (e) {
		const errors = e.response?.data?.errors;
		fillError.value = (errors && Object.values(errors).flat().join(' ')) || e.response?.data?.message || 'Could not save.';
	} finally {
		filling.value = false;
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

function openSearchFor(tag) {
	search.value.tag = tag;
	tab.value = 'search';
	runSearch();
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
	if (name === 'fill') loadStaging();
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
				<button type="button" :class="['ri_formbutton', { pm_active: tab === 'fill' }]" @click="show('fill')">Fill Pallet</button>
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
							<template v-for="r in locationRows" :key="r.key">
								<tr :class="{ pm_zero: r.zero, pm_nolocation: r.special, pm_clickable: r.total }" @click="r.total && toggleLocation(r.key, r.label)">
									<td>{{ openLocation?.key === r.key ? '▾' : '▸' }} {{ r.label }}</td>
									<td>{{ r.total }}</td>
									<td v-for="s in statusColumns" :key="s">{{ r.by_status[s] || '' }}</td>
								</tr>
								<tr v-if="openLocation?.key === r.key" class="pm_drillrow">
									<td :colspan="2 + statusColumns.length">
										<p v-if="openLocation.error" class="ri_error">{{ openLocation.error }}</p>
										<p v-else-if="!openLocation.pallets">Loading…</p>
										<table v-else class="ri_datatable" border="1">
											<thead>
												<tr><th>Pallet</th><th>Type</th><th>Status</th><th>Last moved</th></tr>
											</thead>
											<tbody>
												<tr v-for="p in openLocation.pallets" :key="p.id">
													<td class="pm_mono"><a href="#" @click.prevent="openSearchFor(p.tag)">{{ p.tag }}</a></td>
													<td>{{ p.kind_label }}</td>
													<td>{{ p.status }}</td>
													<td>{{ formatDate(p.last_moved_at) }}</td>
												</tr>
												<tr v-if="!openLocation.pallets.length"><td colspan="4">No live pallets here.</td></tr>
											</tbody>
										</table>
									</td>
								</tr>
							</template>
							<tr v-if="!summary.locations.length">
								<td :colspan="2 + statusColumns.length">No active locations are set up yet.</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p class="ri_hint">Click a location to list its pallets. Click a pallet number to see its history and contents. Counts are live pallets only — emptied, shipped, collected, released, condemned and missing pallets aren't included.</p>
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
					<p v-if="form.pallet.contents && form.pallet.contents.length" class="pm_current">
						On this pallet:
						<span v-for="(c, i) in form.pallet.contents" :key="c.item_id">{{ c.qty }} × {{ c.name }}<span v-if="i < form.pallet.contents.length - 1">, </span></span>.
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

			<!-- Fill pallet: stock leaving the sorting area goes onto a W pallet and is placed in one step -->
			<div v-else-if="tab === 'fill'">
				<p class="ri_hint">Build a Warehouse pallet from what's still in the sorting area, then seal it and say where it's going.</p>
				<p v-if="fillError" class="ri_error">{{ fillError }}</p>
				<div v-if="fillResult" class="pm_current">
					<strong>{{ fillResult.tag }}</strong> sealed<span v-if="fillResult.location"> at <strong>{{ fillResult.location.code }}</strong></span>:
					<span v-for="(c, i) in fillResult.contents" :key="c.item_id">{{ c.qty }} × {{ c.name }}<span v-if="i < fillResult.contents.length - 1">, </span></span>.
					<PrintMenu v-if="fillResult.isNew" document-key="pallet-label" media="label_large" variant="form" label="Print Label"
						:params="{ id: fillResult.id }" :download-href="'/report/pallet/' + fillResult.id" />
				</div>

				<h3 class="pm_sub">This pallet</h3>
				<div class="pm_fillrow">
					<label class="pm_fillfield">
						<span class="ri_fieldlabel">Pallet:</span>
						<input v-model="fill.tag" class="ri_forminput" placeholder="Scan a W pallet, or blank for new" @keyup.enter="pickFillPallet" @change="pickFillPallet" />
					</label>
					<label class="pm_fillfield">
						<span class="ri_fieldlabel">Location:</span>
						<input v-model="fill.locationCode" class="ri_forminput" list="pm_locations" placeholder="Where it's going" />
						<datalist id="pm_locations">
							<option v-for="code in locationCodes" :key="code" :value="code" />
						</datalist>
					</label>
					<label class="pm_fillfield">
						<span class="ri_fieldlabel">Expires:</span>
						<input type="date" class="ri_forminput" :value="fillExpiry" title="Earliest expiration date of anything on this pallet"
							@input="fill.expires = $event.target.value" />
						<button v-if="fill.expires !== null" type="button" class="ri_linkbutton" title="Go back to the earliest date of the items" @click="fill.expires = null">Auto</button>
					</label>
					<label class="pm_fillfield">
						<span class="ri_fieldlabel">Notes:</span>
						<input v-model="fill.notes" class="ri_forminput" />
					</label>
				</div>
				<p v-if="fill.pallet" class="pm_current">
					{{ fill.pallet.tag }} is {{ fill.pallet.status }}<span v-if="fill.pallet.location"> at {{ fill.pallet.location.code }}</span>; already holds
					<span v-if="fill.pallet.contents && fill.pallet.contents.length">
						<span v-for="(c, i) in fill.pallet.contents" :key="c.item_id">{{ c.qty }} × {{ c.name }}<span v-if="i < fill.pallet.contents.length - 1">, </span></span>.
					</span>
					<span v-else>nothing recorded.</span>
				</p>
				<p v-else-if="!fill.tag" class="ri_hint">A new pallet will be created.</p>

				<div class="pm_tablewrap" v-if="fill.lines.length">
					<table class="ri_datatable" border="1">
						<thead><tr><th>Item</th><th>Quantity</th><th></th></tr></thead>
						<tbody>
							<tr v-for="(l, i) in fill.lines" :key="l.item_id">
								<td>{{ l.name }}</td>
								<td><input v-model.number="l.qty" type="number" min="1" :max="l.max" class="ri_forminput pm_qty" /></td>
								<td><button type="button" class="ri_linkbutton" @click="fill.lines.splice(i, 1)">Remove</button></td>
							</tr>
						</tbody>
					</table>
				</div>
				<p v-else class="ri_hint">Tap items below to put them on this pallet.</p>

				<div class="ri_formactions">
					<button type="button" class="ri_defaultbutton" :disabled="filling || !fill.lines.length" @click="submitFill">
						Seal &amp; Place ({{ fillTotal }})
					</button>
				</div>

				<h3 class="pm_sub">In the sorting area</h3>
				<p v-if="!staging">Loading…</p>
				<p v-else-if="!stagingLeft.length" class="ri_hint">Nothing waiting — everything sorted is already on a pallet.</p>
				<div v-else class="pm_tablewrap">
					<table class="ri_datatable" border="1">
						<thead><tr><th>Item</th><th>Waiting</th><th>Expires</th><th></th></tr></thead>
						<tbody>
							<template v-for="g in stagingGroups" :key="g.key">
								<tr v-if="g.rows.length === 1">
									<td>{{ g.type?.number ? 'ACS #' + g.type.number + ' ' : '' }}{{ g.rows[0].name }}</td>
									<td>{{ g.left }}</td>
									<td>{{ g.expires }}</td>
									<td><button type="button" class="ri_formbutton" @click="addFillLine(g.rows[0])">Put all {{ g.left }} on pallet</button></td>
								</tr>
								<template v-else>
									<tr class="pm_group pm_clickable" @click="toggleGroup(g.key)">
										<td>{{ expandedGroups.has(g.key) ? '▾' : '▸' }} ACS #{{ g.type.number }} ({{ g.type.name }}) — {{ g.rows.length }} items</td>
										<td>{{ g.left }}</td>
										<td>{{ g.expires }}</td>
										<td><button type="button" class="ri_formbutton" @click.stop="addGroup(g)">Put all {{ g.left }} on pallet</button></td>
									</tr>
									<tr v-for="row in expandedGroups.has(g.key) ? g.rows : []" :key="row.item_id" class="pm_sku">
										<td>{{ row.name }}</td>
										<td>{{ row.left }}</td>
										<td>{{ row.expires_on || '' }}</td>
										<td><button type="button" class="ri_formbutton" @click="addFillLine(row)">Put all {{ row.left }} on pallet</button></td>
									</tr>
								</template>
							</template>
						</tbody>
					</table>
				</div>
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
					<p v-if="search.result.expires_on" class="pm_current">Earliest expiration: <strong>{{ search.result.expires_on }}</strong></p>
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

					<h3 class="pm_sub">Pallet Contents</h3>
					<template v-if="search.result.contents">
						<p v-if="!search.result.contents.length" class="ri_hint">Nothing is recorded as being on this pallet.</p>
						<div v-else class="pm_tablewrap">
							<table class="ri_datatable" border="1">
								<thead><tr><th>Item</th><th>Quantity</th></tr></thead>
								<tbody>
									<tr v-for="c in search.result.contents" :key="c.item_id"><td>{{ c.name }}</td><td>{{ c.qty }}</td></tr>
								</tbody>
							</table>
						</div>
					</template>
					<template v-else-if="search.result.sorted_from">
						<p class="ri_hint">Receiving pallets aren't tracked item by item; this is what sorting recorded coming off it.</p>
						<p v-if="!search.result.sorted_from.length" class="ri_hint">Nothing sorted from this pallet yet.</p>
						<div v-else class="pm_tablewrap">
							<table class="ri_datatable" border="1">
								<thead><tr><th>Item</th><th>Disposition</th><th>Quantity</th></tr></thead>
								<tbody>
									<tr v-for="(c, i) in search.result.sorted_from" :key="i"><td>{{ c.name }}</td><td>{{ c.disposition }}</td><td>{{ c.qty }}</td></tr>
								</tbody>
							</table>
						</div>
					</template>
					<p v-else class="ri_hint">Contents aren't tracked for this kind of pallet.</p>
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
.pm_clickable {
	cursor: pointer;
}
.pm_drillrow > td {
	padding: 0.5em 0.75em 0.75em 2em;
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
.pm_sub {
	margin: 1em 0 0.25em;
	font-weight: bold;
}
.pm_fillrow {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5em 1em;
	margin: 0.5em 0;
}
.pm_fillfield {
	display: flex;
	align-items: center;
	gap: 0.4em;
}
.pm_group {
	font-weight: bold;
}
.pm_sku > td:first-child {
	padding-left: 2em;
}
.pm_qty {
	width: 6em;
}
.pm_mono {
	font-family: monospace;
}
</style>
