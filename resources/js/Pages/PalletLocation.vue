<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- PalletLocation.vue (/inventory-movement)
     One scan-driven "Pallets" screen plus two look-up tabs (Locations, Recent Movements). A scan bar that is always
     focused on the Pallets tab takes a pallet barcode (shows where it is, what is on it, its history, and lets you
     move it / change its status / add items to it), or — once a pallet is showing — a location barcode, which records
     the move straight away. With no pallet showing, the same screen builds a new Warehouse pallet from the sorting
     area. Writes go to POST /json/palletstatus (append-only history) and POST /json/pallet-stock/fill.
     See SCANNING_INTERFACE_NOTES.md for the scanner conventions followed here. -->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';
import axios from 'axios';
import PrintMenu from '@/Components/PrintMenu.vue';
import { playScanError, playScanMatched, scanSoundsEnabled, setScanSoundsEnabled } from '@/scanSounds';

defineProps({
	breadcrumb: { type: Array },
});

const tab = ref('pallet'); // 'pallet' (scan-driven, the default) | 'locations' | 'recent'

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

// ---------- the scan bar and the pallet on screen ----------
const scanInput = ref(null);
const scanText = ref('');
const scanMessage = ref(null); // { text, ok }
const sounds = ref(scanSoundsEnabled());
const current = ref(null); // the looked-up pallet being shown
const history = ref([]);

function toggleSounds() {
	sounds.value = !sounds.value;
	setScanSoundsEnabled(sounds.value);
	if (sounds.value) playScanMatched();
}

async function lookupPallet(tag) {
	const response = await axios.get('/json/palletstatus/lookup', { params: { tag } });
	return response.data.record;
}

function focusScan() {
	nextTick(() => scanInput.value?.focus());
}

// After any click, put focus back on the scan bar — unless they are typing in a field.
function onPageClick() {
	if (tab.value !== 'pallet') return;
	setTimeout(() => {
		const el = document.activeElement;
		if (el === scanInput.value || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el?.tagName)) return;
		scanInput.value?.focus();
	}, 0);
}

async function submitScan() {
	const value = scanText.value.trim();
	scanText.value = '';
	if (value) await handleScan(value);
	focusScan();
}

// A scan (or typed tag): a location code when a pallet is showing, otherwise a pallet.
async function handleScan(value) {
	scanMessage.value = null;
	fillError.value = null;
	fillResult.value = null;
	if (current.value && locationByCode(value)) {
		await quickMove(locationByCode(value));
		return;
	}
	await showPallet(value);
}

async function showPallet(tag) {
	try {
		const pallet = await lookupPallet(tag);
		await applyPallet(pallet);
		playScanMatched();
	} catch (e) {
		scanMessage.value = { ok: false, text: e.response?.data?.message || 'Could not look up that pallet.' };
		playScanError();
	}
}

async function applyPallet(pallet) {
	current.value = pallet;
	move.value = { locationCode: pallet.location?.code || '', status: pallet.status, notes: '' };
	fill.value.locationCode = pallet.location?.code || '';
	fill.value.expires = null;
	history.value = [];
	try {
		history.value = (await axios.get('/json/palletstatus', { params: { pallet_id: pallet.id, limit: 20 } })).data.records;
	} catch (e) {
		// history is secondary; the pallet itself is already showing
	}
}

function clearPallet() {
	current.value = null;
	history.value = [];
	fill.value.locationCode = '';
	fill.value.expires = null;
	scanMessage.value = null;
	focusScan();
}

// ---------- move / change status ----------
const move = ref({ locationCode: '', status: '', notes: '' });
const saving = ref(false);

const chosenLocation = computed(() => locationByCode(move.value.locationCode));
const unknownLocationText = computed(() => move.value.locationCode.trim() !== '' && !chosenLocation.value);
const locationChanged = computed(() => {
	const pallet = current.value;
	if (!pallet) return false;
	return (chosenLocation.value?.id ?? null) !== (pallet.location_id ?? null) && move.value.locationCode.trim() !== '';
});
const saveLabel = computed(() => (locationChanged.value ? 'Record Movement' : 'Record Status'));

async function postStatus(pallet, locationId, status, notes) {
	await axios.post('/json/palletstatus', {
		pallet_id: pallet.id,
		location_id: locationId ?? pallet.location_id ?? null,
		status,
		notes: notes || null,
	});
	loadSummary();
	recent.value = null;
}

function errorText(e, fallback) {
	const errors = e.response?.data?.errors;
	return (errors && Object.values(errors).flat().join(' ')) || e.response?.data?.message || fallback;
}

async function recordMovement() {
	const pallet = current.value;
	if (!pallet) return;
	if (unknownLocationText.value) {
		scanMessage.value = { ok: false, text: `"${move.value.locationCode}" is not a known active location.` };
		return;
	}
	saving.value = true;
	scanMessage.value = null;
	try {
		await postStatus(pallet, chosenLocation.value?.id, move.value.status, move.value.notes);
		scanMessage.value = { ok: true, text: `${pallet.tag}: ${locationChanged.value ? 'moved to ' + move.value.locationCode : 'status recorded as ' + move.value.status}.` };
		await applyPallet(await lookupPallet(pallet.tag));
	} catch (e) {
		scanMessage.value = { ok: false, text: errorText(e, 'Could not save.') };
		playScanError();
	} finally {
		saving.value = false;
		focusScan();
	}
}

// Scanning a location while a pallet is showing records the move immediately, keeping its status.
async function quickMove(location) {
	const pallet = current.value;
	if (location.id === pallet.location_id) {
		scanMessage.value = { ok: true, text: `${pallet.tag} is already at ${location.code}.` };
		playScanMatched();
		return;
	}
	try {
		await postStatus(pallet, location.id, pallet.status, null);
		playScanMatched();
		scanMessage.value = { ok: true, text: `${pallet.tag}: moved to ${location.code}.` };
		await applyPallet(await lookupPallet(pallet.tag));
	} catch (e) {
		scanMessage.value = { ok: false, text: errorText(e, 'Could not save the move.') };
		playScanError();
	}
}

// ---------- fill a warehouse pallet (leaving the sorting area) ----------
const staging = ref(null); // what's still in the sorting area, i.e. on hand but not on a pallet
// expires: null = use the suggestion (earliest date among the chosen items and what's already on the pallet);
// '' = the driver cleared it (no date); a date = the driver's own entry.
const fill = ref({ lines: [], locationCode: '', notes: '', expires: null });
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

// The pallet the lines will go onto: the one on screen when it is a Warehouse pallet still taking stock, else a new one.
const fillTarget = computed(() => {
	const p = current.value;
	return p && p.kind === 'W' && ['sealed', 'open'].includes(p.status) ? p : null;
});

const fillLocation = computed(() => locationByCode(fill.value.locationCode));
const suggestedExpiry = computed(() => {
	const dates = fill.value.lines.map((l) => l.expires_on).concat(fillTarget.value?.expires_on || null).filter(Boolean);
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
			pallet_id: fillTarget.value?.id ?? null,
			location_id: fillLocation.value?.id ?? null,
			notes: fill.value.notes || null,
			expires_on: fillExpiry.value || null,
			lines: fill.value.lines.map((l) => ({ item_id: l.item_id, qty: Number(l.qty) })),
		});
		const wasNew = !fillTarget.value;
		const existingTag = current.value?.tag;
		fillResult.value = { ...response.data.record, isNew: wasNew };
		fill.value = { lines: [], locationCode: '', notes: '', expires: null };
		if (wasNew) clearPallet();
		else await applyPallet(await lookupPallet(existingTag));
		loadStaging();
		loadSummary();
	} catch (e) {
		const errors = e.response?.data?.errors;
		fillError.value = (errors && Object.values(errors).flat().join(' ')) || e.response?.data?.message || 'Could not save.';
		playScanError();
	} finally {
		filling.value = false;
	}
}

// A quantity box can catch a stray barcode; 8+ digits there is a scan, not a quantity.
function setLineQty(line, event) {
	const raw = event.target.value.trim();
	event.target.value = line.qty;
	if (/^\d{8,}$/.test(raw)) {
		handleScan(raw).then(focusScan);
		return;
	}
	const n = parseInt(raw, 10);
	if (n >= 1) line.qty = Math.min(n, line.max);
	event.target.value = line.qty;
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
	if (name === 'pallet') {
		loadStaging();
		focusScan();
	}
}

// From the Locations drill-down: show that pallet on the Pallets tab.
function openSearchFor(tag) {
	show('pallet');
	handleScan(tag).then(focusScan);
}

function formatDate(value) {
	return value ? new Date(value).toLocaleString() : '';
}

onMounted(() => {
	loadSummary();
	loadStaging();
	focusScan();
});
</script>

<template>
	<Head title="Inventory Movement" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<div class="pm_page">
			<h2 class="pm_title">Inventory Movement</h2>

			<div class="pm_tabs">
				<button type="button" :class="['ri_formbutton', { pm_active: tab === 'pallet' }]" @click="show('pallet')">Pallets</button>
				<button type="button" :class="['ri_formbutton', { pm_active: tab === 'locations' }]" @click="show('locations')">Locations</button>
				<button type="button" :class="['ri_formbutton', { pm_active: tab === 'recent' }]" @click="show('recent')">Recent Movements</button>
			</div>

			<datalist id="pm_locations">
				<option v-for="code in locationCodes" :key="code" :value="code" />
			</datalist>

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

			<!-- Pallets: one scan-driven screen -->
			<div v-else-if="tab === 'pallet'" @click="onPageClick">
				<div class="pm_scanbar">
					<input
						ref="scanInput"
						v-model="scanText"
						type="text"
						autocomplete="off"
						class="ri_forminput pm_scaninput"
						:placeholder="current ? 'Scan another pallet — or a location to move ' + current.tag + ' there' : 'Scan a pallet barcode'"
						@keydown.enter.prevent="submitScan"
					/>
					<button type="button" class="ri_defaultbutton" @click="submitScan">Find</button>
					<button type="button" class="pm_sound" @click="toggleSounds"
						:title="sounds ? 'Scan sounds on. Click to mute.' : 'Scan sounds off. Click to turn on.'">{{ sounds ? '🔊' : '🔇' }}</button>
				</div>
				<p v-if="scanMessage" :class="scanMessage.ok ? 'pm_ok' : 'ri_error'">{{ scanMessage.text }}</p>
				<p v-if="fillError" class="ri_error">{{ fillError }}</p>
				<div v-if="fillResult" class="pm_current">
					<strong>{{ fillResult.tag }}</strong> sealed<span v-if="fillResult.location"> at <strong>{{ fillResult.location.code }}</strong></span>:
					<span v-for="(c, i) in fillResult.contents" :key="c.item_id">{{ c.qty }} × {{ c.name }}<span v-if="i < fillResult.contents.length - 1">, </span></span>.
					<PrintMenu v-if="fillResult.isNew" document-key="pallet-label" media="label_large" variant="form" label="Print Label"
						:params="{ id: fillResult.id }" :download-href="'/report/pallet/' + fillResult.id" />
				</div>

				<!-- the pallet on screen -->
				<div v-if="current" class="pm_card">
					<p class="pm_current">
						<strong class="pm_mono">{{ current.tag }}</strong> ({{ current.kind_label }}) —
						<strong>{{ current.location ? 'at ' + current.location.code : 'no location recorded' }}</strong>,
						status <strong>{{ current.status }}</strong><span v-if="current.last_moved_at">, last updated {{ formatDate(current.last_moved_at) }}</span><span v-if="current.expires_on">, earliest expiration <strong>{{ current.expires_on }}</strong></span>.
						<button type="button" class="ri_linkbutton" @click="clearPallet">Clear / new pallet</button>
					</p>

					<div class="pm_fillrow">
						<label class="pm_fillfield">
							<span class="ri_fieldlabel">Location:</span>
							<input v-model="move.locationCode" class="ri_forminput" list="pm_locations" placeholder="Unknown — scan or type a location" />
						</label>
						<label class="pm_fillfield">
							<span class="ri_fieldlabel">Status:</span>
							<select v-model="move.status" class="ri_forminput">
								<option v-for="st in current.statuses" :key="st.id" :value="st.id">{{ st.name }}</option>
							</select>
						</label>
						<label class="pm_fillfield">
							<span class="ri_fieldlabel">Notes:</span>
							<input v-model="move.notes" class="ri_forminput" />
						</label>
						<button type="button" class="ri_defaultbutton" :disabled="saving" @click="recordMovement">{{ saveLabel }}</button>
					</div>

					<h3 class="pm_sub">Pallet Contents</h3>
					<template v-if="current.contents">
						<p v-if="!current.contents.length" class="ri_hint">Nothing is recorded as being on this pallet.</p>
						<div v-else class="pm_tablewrap">
							<table class="ri_datatable" border="1">
								<thead><tr><th>Item</th><th>Quantity</th></tr></thead>
								<tbody>
									<tr v-for="c in current.contents" :key="c.item_id"><td>{{ c.name }}</td><td>{{ c.qty }}</td></tr>
								</tbody>
							</table>
						</div>
					</template>
					<template v-else-if="current.sorted_from">
						<p class="ri_hint">Receiving pallets aren't tracked item by item; this is what sorting recorded coming off it.</p>
						<p v-if="!current.sorted_from.length" class="ri_hint">Nothing sorted from this pallet yet.</p>
						<div v-else class="pm_tablewrap">
							<table class="ri_datatable" border="1">
								<thead><tr><th>Item</th><th>Disposition</th><th>Quantity</th></tr></thead>
								<tbody>
									<tr v-for="(c, i) in current.sorted_from" :key="i"><td>{{ c.name }}</td><td>{{ c.disposition }}</td><td>{{ c.qty }}</td></tr>
								</tbody>
							</table>
						</div>
					</template>
					<p v-else class="ri_hint">Contents aren't tracked for this kind of pallet.</p>
				</div>
				<p v-else class="ri_hint">Scan a pallet to see where it is, what is on it and move it — or build a new Warehouse pallet below.</p>

				<!-- put stock from the sorting area onto this (or a new) Warehouse pallet -->
				<div v-if="!current || fillTarget">
					<h3 class="pm_sub">{{ fillTarget ? 'Add items to ' + fillTarget.tag : 'Build a new Warehouse pallet' }}</h3>
					<p v-if="!fillTarget" class="ri_hint">Tap items in the sorting area below, set where it is going, then Seal &amp; Place. Scan an existing Warehouse pallet instead to add to that one.</p>

					<div class="pm_tablewrap" v-if="fill.lines.length">
						<table class="ri_datatable" border="1">
							<thead><tr><th>Item</th><th>Quantity</th><th></th></tr></thead>
							<tbody>
								<tr v-for="(l, i) in fill.lines" :key="l.item_id">
									<td>{{ l.name }}</td>
									<td><input type="text" inputmode="numeric" autocomplete="off" :value="l.qty" class="ri_forminput pm_qty" @focus="$event.target.select()" @change="setLineQty(l, $event)" /></td>
									<td><button type="button" class="ri_linkbutton" @click="fill.lines.splice(i, 1)">Remove</button></td>
								</tr>
							</tbody>
						</table>
					</div>
					<p v-else class="ri_hint">Tap items below to put them on this pallet.</p>

					<div class="pm_fillrow">
						<label class="pm_fillfield">
							<span class="ri_fieldlabel">Location:</span>
							<input v-model="fill.locationCode" class="ri_forminput" list="pm_locations" placeholder="Where it's going" />
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
				<p v-else-if="fill.lines.length" class="ri_hint">{{ fill.lines.length }} item line(s) are staged for a Warehouse pallet — use Clear / new pallet to go back to them.</p>

				<div v-if="current" class="pm_history">
					<h3 class="pm_sub">Movement History</h3>
					<div class="pm_tablewrap">
						<table class="ri_datatable" border="1">
							<thead><tr><th>Date</th><th>Location</th><th>Status</th><th>Notes</th></tr></thead>
							<tbody>
								<tr v-for="h in history" :key="h.id">
									<td>{{ formatDate(h.created_at) }}</td>
									<td>{{ h.location ? h.location.code : 'Unknown' }}</td>
									<td>{{ h.status }}</td>
									<td>{{ h.notes }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
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
.pm_scanbar {
	display: flex;
	gap: 0.5em;
	align-items: center;
	margin-bottom: 0.5em;
}
.pm_scaninput {
	flex: 1;
	max-width: 32em;
	font-size: 1.2em;
}
.pm_sound {
	background: none;
	border: 0;
	font-size: 1.4em;
	cursor: pointer;
}
.pm_ok {
	color: #1b6e2d;
	font-weight: bold;
}
.pm_card {
	border: 1px solid #bbb;
	border-radius: 6px;
	padding: 0.5em 0.9em;
	margin: 0.5em 0;
}
.pm_qty {
	width: 6em;
}
.pm_mono {
	font-family: monospace;
}
</style>
