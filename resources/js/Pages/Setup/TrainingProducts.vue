<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Setup/TrainingProducts.vue

	Setup > Training Warehouse > Scan Training Products (TrainingProductController, manage-training): the trainer scans
	the real goods that start out "already in the warehouse" — Stations 3–5 (sorting area, table-spot trays, loads to
	ship) — one unit per scan. Receiving's goods are the trainer's own and are not scanned. A barcode the catalog already knows just counts; an
	unknown one opens the shared Add New Item box (NewItemModal) and counts once saved. The list is not stock —
	Prepare Training spreads it over the stations (App\Services\TrainingPreparer) and the progress line shows how
	many items that needs. The scan bar keeps focus (see SCANNING_INTERFACE_NOTES.md).
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import NewItemModal from '@/Components/NewItemModal.vue';
import ScanButton from '@/Components/ScanButton.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { playScanCarton, playScanError, playScanMatched, playScanUnknown, scanSoundsEnabled, setScanSoundsEnabled } from '@/scanSounds';

defineProps({
	breadcrumb: { type: Array },
});

const STATION_NAMES = { 1: 'Receiving', 2: 'Sorting', 3: 'Inventory', 4: 'Picking', 5: 'Shipping', 6: 'Delivery / Ordering' };

const data = ref(null);
const error = ref(null);
const scanText = ref('');
const scanInput = ref(null);
const scanMessage = ref(null);
const busy = ref(false);
const sounds = ref(scanSoundsEnabled());
const lastId = ref(null);
const modalInit = ref(null);
const manual = ref({ item_id: null, qty: 1 });
const manualSelect = ref(null);
const confirmingClear = ref(false);

const plan = computed(() => data.value?.plan);
const percent = computed(() => (plan.value ? Math.min(100, Math.round((data.value.units / plan.value.target) * 100)) : 0));

async function load() {
	try {
		data.value = (await axios.get('/json/training/products')).data;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not load the product list.';
	}
}

function focusScan() {
	nextTick(() => scanInput.value?.focus());
}

// After any click, put focus back on the scan bar — unless they are typing in a field or the Add New Item box is open.
function onPageClick() {
	setTimeout(() => {
		if (modalInit.value) return;
		const el = document.activeElement;
		if (el === scanInput.value || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el?.tagName)) return;
		scanInput.value?.focus();
	}, 0);
}

function counted(name, added, total) {
	scanMessage.value = { ok: true, text: `Counted ${name}${added > 1 ? ' (+' + added + ')' : ''} — ${total} scanned.` };
}

async function submitScan() {
	const code = scanText.value.trim();
	scanText.value = '';
	if (!code || busy.value) return;
	error.value = null;
	busy.value = true;
	try {
		const response = (await axios.post('/json/training/products/scan', { code })).data;
		const scan = response.scan;
		if (scan.status === 'matched') {
			data.value = response;
			lastId.value = response.product_id;
			const record = response.records.find((r) => r.id === response.product_id);
			counted(record?.name || 'item', scan.qty || 1, record?.qty);
			scan.kind === 'carton' ? playScanCarton() : playScanMatched();
		} else if (scan.kind === 'carton') {
			playScanUnknown();
			scanMessage.value = { ok: false, text: 'That is a case barcode this system doesn\'t know yet. Scan one of the single items inside instead, and count the rest with Add by hand below.' };
		} else {
			playScanUnknown();
			scanMessage.value = { ok: false, text: 'New barcode — add the item, then it is counted.' };
			modalInit.value = { scan: { code: scan.code, online: scan.online, suggestion: scan.suggestion } };
		}
	} catch (e) {
		playScanError();
		scanMessage.value = { ok: false, text: e.response?.data?.message || 'Could not look up that barcode.' };
	} finally {
		busy.value = false;
		if (!modalInit.value) focusScan();
	}
}

async function addItem(itemId, qty) {
	const response = (await axios.post('/json/training/products', { item_id: itemId, qty })).data;
	data.value = response;
	lastId.value = response.product_id;
	const record = response.records.find((r) => r.id === response.product_id);
	counted(record?.name || 'item', qty, record?.qty);
}

async function onItemSaved(item) {
	modalInit.value = null;
	try {
		await addItem(item.id, 1);
		playScanMatched();
	} catch (e) {
		error.value = e.response?.data?.message || 'The item was added, but could not be counted. Scan it again.';
	}
	focusScan();
}

function onModalClose() {
	modalInit.value = null;
	scanMessage.value = null;
	focusScan();
}

async function addManual() {
	if (!manual.value.item_id) return;
	error.value = null;
	try {
		await addItem(manual.value.item_id, Math.max(1, parseInt(manual.value.qty, 10) || 1));
		manual.value = { item_id: null, qty: 1 };
		manualSelect.value?.clearSelection?.();
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not add that item.';
	}
	focusScan();
}

async function setQty(record, value) {
	const qty = Math.max(0, parseInt(value, 10) || 0);
	if (qty === record.qty) return;
	error.value = null;
	try {
		data.value = (await axios.patch('/json/training/products/' + record.id, { qty })).data;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not change that count.';
	}
}

async function remove(record) {
	error.value = null;
	try {
		data.value = (await axios.delete('/json/training/products/' + record.id)).data;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not remove that product.';
	}
	focusScan();
}

async function clearAll() {
	if (!confirmingClear.value) {
		confirmingClear.value = true;
		return;
	}
	confirmingClear.value = false;
	try {
		data.value = (await axios.delete('/json/training/products')).data;
		scanMessage.value = null;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not clear the list.';
	}
	focusScan();
}

function toggleSounds() {
	sounds.value = !sounds.value;
	setScanSoundsEnabled(sounds.value);
	if (sounds.value) playScanMatched();
	focusScan();
}

onMounted(async () => {
	await load();
	focusScan();
	document.addEventListener('click', onPageClick);
});
onBeforeUnmount(() => document.removeEventListener('click', onPageClick));
</script>

<template>
	<Head title="Scan Training Products" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<div class="flex flex-wrap items-center justify-between gap-2">
				<h1 class="text-2xl font-bold">Scan Training Products</h1>
				<Link href="/setup/training" class="ri_formbutton">Back to Training Warehouse</Link>
			</div>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<p v-if="!data && !error" class="text-gray-500">Loading…</p>

			<template v-if="data">
				<!-- ======================= PROGRESS ======================= -->
				<section class="bg-white shadow rounded-lg p-6 space-y-3">
					<p class="text-sm text-gray-700">
						Scan the goods that start out already in the warehouse — the sorting area table, the trays on the
						table spots and the loads waiting to ship (Stations 3–5) — one at a time; each scan counts one.
						Scan a variety: at least <strong>{{ plan.min_products }} different products</strong>. Goods for
						Receiving are not scanned here: put them out yourself, and the teams receive and sort them. Prepare
						Training decides which scanned items go where, and the setup packet tells you where to put each
						one. Items scanned here are not stock until Prepare Training.
					</p>
					<div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
						<div><span class="text-3xl font-bold">{{ data.units }}</span> <span class="text-gray-600">of {{ plan.target }} items</span></div>
						<div><span class="text-3xl font-bold">{{ data.distinct }}</span> <span class="text-gray-600">of {{ plan.min_products }} different products</span></div>
					</div>
					<div class="h-3 bg-gray-200 rounded">
						<div class="h-3 rounded" :class="data.units >= plan.target ? 'bg-green-600' : 'bg-blue-600'" :style="{ width: percent + '%' }"></div>
					</div>
					<table class="ri_datatable w-full text-sm" border="1">
						<thead><tr><th>Station</th><th>Items needed</th></tr></thead>
						<tbody>
							<tr v-for="(n, station) in plan.stations" :key="station">
								<td>{{ station }} {{ STATION_NAMES[station] }}</td>
								<td>{{ n }}</td>
							</tr>
						</tbody>
					</table>
					<p class="text-xs text-gray-500">
						Prepare Training needs at least {{ plan.target }} items. Scanning more is fine: the extra goes on
						the Station 4 trays, so there is more to pick.
					</p>
				</section>

				<!-- ======================= SCAN ======================= -->
				<section class="bg-white shadow rounded-lg p-6 space-y-3">
					<div class="flex flex-wrap items-center gap-2">
						<input ref="scanInput" v-model="scanText" type="text" autocomplete="off" inputmode="numeric"
							class="ri_forminput flex-1 min-w-[14rem] text-lg" placeholder="Scan an item's barcode"
							@keydown.enter.prevent="submitScan" />
						<button type="button" class="ri_defaultbutton" :disabled="busy" @click="submitScan">Count</button>
						<ScanButton :target="() => scanInput" />
						<button type="button" class="ri_formbutton" @click="toggleSounds"
							:title="sounds ? 'Scan sounds on. Click to mute.' : 'Scan sounds off. Click to turn on.'">{{ sounds ? '🔊' : '🔇' }}</button>
					</div>
					<p v-if="scanMessage" :class="scanMessage.ok ? 'text-green-700 font-semibold' : 'text-amber-700 font-semibold'">{{ scanMessage.text }}</p>

					<details class="text-sm">
						<summary class="cursor-pointer text-gray-700">Add by hand (no barcode, or several of the same at once)</summary>
						<div class="flex flex-wrap items-center gap-2 mt-2">
							<div class="flex-1 min-w-[16rem]">
								<SearchSelect ref="manualSelect" v-model="manual.item_id" optionsource="/json/items" display="name"
									:searchfields="['name', 'upc']" placeholder="Search items…" />
							</div>
							<input v-model="manual.qty" type="number" min="1" class="ri_forminput w-20" />
							<button type="button" class="ri_defaultbutton" :disabled="!manual.item_id" @click="addManual">Add</button>
						</div>
					</details>
				</section>

				<!-- ======================= LIST ======================= -->
				<section class="bg-white shadow rounded-lg p-6 space-y-3">
					<div class="flex flex-wrap items-center justify-between gap-2">
						<h2 class="text-lg font-semibold">Scanned products</h2>
						<div v-if="data.records.length" class="flex items-center gap-2">
							<button type="button" class="ri_formbutton" :class="{ 'text-red-700': confirmingClear }" @click="clearAll">
								{{ confirmingClear ? 'Confirm — clear the whole list' : 'Clear list' }}
							</button>
							<button v-if="confirmingClear" type="button" class="text-sm underline" @click="confirmingClear = false">Cancel</button>
						</div>
					</div>
					<p v-if="!data.records.length" class="text-sm text-gray-400">Nothing scanned yet.</p>
					<table v-else class="ri_datatable w-full text-sm" border="1">
						<thead><tr><th>Product</th><th>ACS item type</th><th>UPC</th><th>Count</th><th></th></tr></thead>
						<tbody>
							<tr v-for="r in data.records" :key="r.id" :class="{ 'bg-green-50': r.id === lastId }">
								<td>{{ r.name }}</td>
								<td>{{ r.number }} {{ r.type }}</td>
								<td class="font-mono text-xs">{{ r.upc }}</td>
								<td><input type="number" min="0" :value="r.qty" class="ri_forminput w-20" @change="setQty(r, $event.target.value)" /></td>
								<td><button type="button" class="text-sm underline" @click="remove(r)">Remove</button></td>
							</tr>
						</tbody>
					</table>
					<p class="text-xs text-gray-500">
						Removing a product here only takes it off this list; the item stays in the Master Item List.
					</p>
				</section>
			</template>
		</div>

		<NewItemModal v-if="modalInit" :initial="modalInit" :allow-carton="false" @saved="onItemSaved" @close="onModalClose" />
	</AuthenticatedLayout>
</template>
