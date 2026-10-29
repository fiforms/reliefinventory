<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- CartonCodes.vue

	Carton Barcodes (/setup/carton-codes, manage-items) — review and remove the carton barcode -> item
	mappings sorters confirmed on the Sorting page ("New Carton Barcode"). A mapping is only ever
	created there; removing one here just means the next scan of that carton asks again. Panel-style
	page (see admin-page-style); data from GET /json/carton-codes, delete via DELETE /json/carton-codes/{id}.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

defineProps({ breadcrumb: { type: Array } });

const records = ref(null);
const total = ref(0);
const search = ref('');
const error = ref(null);
const notice = ref(null);
const confirmingId = ref(null);
const busyId = ref(null);

function displayCode(code) {
	return code.startsWith('00') ? code.slice(2) : code.startsWith('0') ? code.slice(1) : code;
}

function contents(record) {
	if (record.inner_packs && record.items_per_pack) {
		return record.inner_packs + ' × ' + record.items_per_pack + ' = ' + record.qty_per_carton;
	}
	return String(record.qty_per_carton);
}

function dateOf(value) {
	const date = new Date(value);
	return isNaN(date) ? '' : date.toLocaleDateString();
}

const filtered = computed(() => {
	const text = search.value.trim().toLowerCase();
	if (!text) return records.value || [];
	return (records.value || []).filter((record) =>
		[record.code, record.item?.name, record.item?.upc, record.entered_by_name]
			.some((field) => field && String(field).toLowerCase().includes(text)));
});

async function load() {
	try {
		const response = await axios.get('/json/carton-codes');
		records.value = response.data.records;
		total.value = response.data.total;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not load the carton barcodes.';
	}
}

async function remove(record) {
	error.value = null;
	notice.value = null;
	if (confirmingId.value !== record.id) {
		confirmingId.value = record.id; // first click arms it; the second removes
		return;
	}
	busyId.value = record.id;
	try {
		await axios.delete('/json/carton-codes/' + record.id);
		records.value = records.value.filter((entry) => entry.id !== record.id);
		total.value -= 1;
		notice.value = 'Removed carton ' + displayCode(record.code) + '. Its next scan will ask what is inside.';
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not remove that carton barcode.';
	} finally {
		busyId.value = null;
		confirmingId.value = null;
	}
}

onMounted(load);
</script>

<template>
	<Head title="Carton Barcodes" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Carton Barcodes</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Confirmed cartons</h2>

				<p v-if="records === null && !error" class="text-gray-500">Loading carton barcodes&hellip;</p>

				<template v-else-if="records">
					<label class="block text-sm">
						<span class="text-gray-700">Search</span>
						<input v-model="search" type="text" placeholder="Barcode, item or who added it"
							class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
					</label>

					<p v-if="!records.length" class="text-gray-400">None yet &mdash; they are added from the Sorting page the first time a carton is scanned.</p>
					<p v-else-if="!filtered.length" class="text-gray-400">No carton matches that search.</p>

					<div v-else class="overflow-x-auto">
						<table class="w-full text-sm text-left">
							<thead class="text-gray-600 border-b">
								<tr>
									<th class="py-2 pr-3">Carton barcode</th>
									<th class="py-2 pr-3">Contains</th>
									<th class="py-2 pr-3">Items per carton</th>
									<th class="py-2 pr-3">Added</th>
									<th class="py-2"></th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="record in filtered" :key="record.id" class="border-b align-top">
									<td class="py-2 pr-3 font-mono">{{ displayCode(record.code) }}</td>
									<td class="py-2 pr-3">
										{{ record.item?.name }}
										<div v-if="record.item?.upc" class="text-xs text-gray-500 font-mono">{{ record.item.upc }}</div>
									</td>
									<td class="py-2 pr-3">{{ contents(record) }}</td>
									<td class="py-2 pr-3 text-gray-600">
										{{ dateOf(record.created_at) }}
										<div v-if="record.entered_by_name" class="text-xs text-gray-500">{{ record.entered_by_name }}</div>
									</td>
									<td class="py-2 text-right whitespace-nowrap">
										<template v-if="confirmingId === record.id">
											<button type="button" :disabled="busyId === record.id" @click="remove(record)"
												class="inline-flex items-center px-3 py-1.5 rounded-md font-semibold text-xs uppercase tracking-widest text-white bg-red-600 hover:bg-red-500">
												{{ busyId === record.id ? 'Removing…' : 'Confirm remove' }}
											</button>
											<button type="button" class="ml-2 text-xs underline text-gray-600" @click="confirmingId = null">Cancel</button>
										</template>
										<button v-else type="button" @click="remove(record)"
											class="inline-flex items-center px-3 py-1.5 rounded-md font-semibold text-xs uppercase tracking-widest text-white bg-amber-600 hover:bg-amber-500">
											Remove
										</button>
									</td>
								</tr>
							</tbody>
						</table>
					</div>

					<p v-if="total > records.length" class="text-xs text-gray-500">Showing the newest {{ records.length }} of {{ total }}.</p>
				</template>

				<p class="text-xs text-gray-500">
					Removing a carton barcode doesn't touch any item or anything already sorted. The next time that carton is
					scanned, the Sorting page asks again what is inside and how many, and saves the new answer. To change an
					entry, remove it and scan the carton again.
				</p>
			</section>
		</div>
	</AuthenticatedLayout>
</template>
