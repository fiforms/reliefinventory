<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- PrinterManagement.vue

	Every printer on every print server at a glance (permission: manage-devices): live
	status (idle / printing / error / offline / unavailable) and how many jobs are waiting,
	with a Clear Queue action for people who also hold manage-print-queue, and the order
	printers appear in Print menus — set by dragging a printer within its site (or with the
	up/down buttons, for keyboards and touch), saved as sort_order. Printers are set up
	(name, site, paper, on/off) on the R Station Devices page. Server half:
	PrinterAdminController::overview/reorder.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref } from 'vue';

defineProps({
	breadcrumb: {
		type: Array,
	},
	canClearQueue: {
		type: Boolean,
		default: false,
	},
});

const REFRESH_MS = 5000;

const printers = ref([]);
const error = ref('');
const notice = ref('');
const dragging = ref(null); // printer id being dragged
const dragOver = ref(null); // printer id it is hovering over
const confirmingClear = ref(null);
let timer = null;

// Printers grouped by site, in the order the server sent them (site, then sort_order).
const groups = computed(() => {
	const map = new Map();
	for (const printer of printers.value) {
		const key = printer.site_id ?? 'none';
		if (!map.has(key)) map.set(key, { key, site: printer.site || 'No site assigned', printers: [] });
		map.get(key).printers.push(printer);
	}
	return [...map.values()];
});

async function load() {
	if (dragging.value !== null) return; // don't pull the list out from under a drag
	try {
		const { data } = await axios.get('/json/printer-overview');
		printers.value = data.printers;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not load the printers.';
	}
}

function dot(printer) {
	if (printer.status === 'idle' || printer.status === 'printing') return 'bg-green-500';
	if (printer.status === 'error') return 'bg-red-500';
	return 'bg-amber-500';
}

function statusText(printer) {
	if (printer.status === 'unavailable') return printer.status_message || 'unavailable';
	return printer.status_message ? `${printer.status}: ${printer.status_message}` : printer.status;
}

// ---- Ordering ----
async function saveOrder(group, ids) {
	error.value = '';
	notice.value = '';
	try {
		await axios.post('/json/printers/reorder', { ids });
		notice.value = `Saved the order for ${group.site}.`;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not save the order.';
	}
	dragging.value = null;
	dragOver.value = null;
	await load();
}

function moveWithin(group, printer, delta) {
	const ids = group.printers.map((p) => p.id);
	const from = ids.indexOf(printer.id);
	const to = from + delta;
	if (to < 0 || to >= ids.length) return;
	ids.splice(to, 0, ids.splice(from, 1)[0]);
	saveOrder(group, ids);
}

function onDragStart(event, printer) {
	dragging.value = printer.id;
	event.dataTransfer.effectAllowed = 'move';
	event.dataTransfer.setData('text/plain', String(printer.id)); // Firefox needs data to start a drag
}

function onDrop(group, target) {
	const ids = group.printers.map((p) => p.id);
	const from = ids.indexOf(dragging.value);
	const to = ids.indexOf(target.id);
	if (from === -1 || to === -1 || from === to) {
		dragging.value = dragOver.value = null;
		return;
	}
	ids.splice(to, 0, ids.splice(from, 1)[0]);
	saveOrder(group, ids);
}

function onDragEnd() {
	dragging.value = null;
	dragOver.value = null;
}

// ---- Clearing a queue (manage-print-queue) ----
async function clearQueue(printer) {
	if (confirmingClear.value !== printer.id) {
		confirmingClear.value = printer.id;
		return;
	}
	confirmingClear.value = null;
	error.value = '';
	notice.value = '';
	try {
		const { data } = await axios.post('/json/print-jobs/clear', { printer_id: printer.id });
		notice.value = `Cleared ${data.cleared} waiting job${data.cleared === 1 ? '' : 's'} from ${printer.name || printer.queue_name}.`;
		await load();
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not clear that queue.';
	}
}

onMounted(() => {
	load();
	timer = setInterval(load, REFRESH_MS);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
	<Head title="Printers" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Printers</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>

			<p v-if="!printers.length" class="text-sm text-gray-500">
				No printers yet. Printers appear here once a print server has reported them; set them up on the
				<a href="/setup/devices" class="underline">R Station Devices</a> page.
			</p>

			<section v-for="group in groups" :key="group.key" class="bg-white shadow rounded-lg p-6 space-y-3">
				<h2 class="text-lg font-semibold">{{ group.site }}</h2>
				<ul class="space-y-2">
					<li
						v-for="(printer, index) in group.printers"
						:key="printer.id"
						draggable="true"
						class="border rounded p-3 flex items-center gap-3 bg-gray-50"
						:class="{ 'opacity-50': dragging === printer.id, 'ring-2 ring-blue-400': dragOver === printer.id && dragging !== printer.id }"
						@dragstart="onDragStart($event, printer)"
						@dragover.prevent="dragOver = printer.id"
						@drop.prevent="onDrop(group, printer)"
						@dragend="onDragEnd"
					>
						<span class="cursor-grab select-none text-gray-400 text-xl leading-none" title="Drag to reorder" aria-hidden="true">⠿</span>
						<span class="inline-block w-3 h-3 rounded-full shrink-0" :class="dot(printer)" :title="printer.status"></span>
						<div class="grow min-w-0 text-sm">
							<div class="flex flex-wrap items-baseline gap-x-2">
								<strong>{{ printer.name || printer.queue_name }}</strong>
								<span v-if="!printer.active" class="text-xs text-gray-400">off — not in Print menus</span>
								<span v-if="printer.media_label" class="text-xs text-gray-500">{{ printer.media_label }}</span>
							</div>
							<div class="text-xs text-gray-500">
								{{ statusText(printer) }}<template v-if="printer.device_name"> · on {{ printer.device_name }}</template>
							</div>
						</div>
						<div class="text-xs text-right whitespace-nowrap">
							<div :class="printer.waiting_jobs ? 'text-amber-700 font-medium' : 'text-gray-400'">
								{{ printer.waiting_jobs }} waiting
							</div>
							<div v-if="printer.failed_jobs" class="text-red-700">{{ printer.failed_jobs }} failed</div>
						</div>
						<button
							v-if="canClearQueue && (printer.waiting_jobs || printer.failed_jobs)"
							type="button"
							class="text-xs underline whitespace-nowrap"
							:class="confirmingClear === printer.id ? 'text-red-700 font-semibold' : 'text-gray-600'"
							@click="clearQueue(printer)"
						>
							{{ confirmingClear === printer.id ? 'Confirm clear' : 'Clear queue' }}
						</button>
						<span class="flex flex-col">
							<button type="button" class="text-gray-500 hover:text-gray-900 disabled:opacity-25 leading-none" :disabled="index === 0" :aria-label="`Move ${printer.name || printer.queue_name} up`" @click="moveWithin(group, printer, -1)">▲</button>
							<button type="button" class="text-gray-500 hover:text-gray-900 disabled:opacity-25 leading-none" :disabled="index === group.printers.length - 1" :aria-label="`Move ${printer.name || printer.queue_name} down`" @click="moveWithin(group, printer, 1)">▼</button>
						</span>
					</li>
				</ul>
			</section>

			<p v-if="printers.length" class="text-xs text-gray-500">
				Drag a printer (or use the arrows) to change the order it appears in Print menus at its site. Status refreshes every few
				seconds. To rename a printer, change its site or paper type, or switch it on or off, use the
				<a href="/setup/devices" class="underline">R Station Devices</a> page.
			</p>
		</div>
	</AuthenticatedLayout>
</template>
