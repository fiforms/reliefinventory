<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- PickPath.vue (/setup/locations/pick-path)
     One walking order through the warehouse. Pickers' fill screen and printed pick sheets visit items in this order
     (locations.PullSequence). Drag rows to reorder, or start from "Auto-order by aisle (snaking)" and tune by hand. -->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';

defineProps({ breadcrumb: { type: Array } });

const locations = ref([]); // in current path order
const loading = ref(true);
const error = ref(null);
const message = ref(null);
const saving = ref(false);
const dirty = ref(false);
const dragIndex = ref(null);
const overIndex = ref(null);

onMounted(async () => {
	try {
		const all = (await axios.get('/json/locations')).data.records;
		locations.value = all.filter((l) => l.status === 'active'); // server already sends them in path order
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not load locations.';
	} finally {
		loading.value = false;
	}
});

const natural = (a, b) => String(a ?? '').localeCompare(String(b ?? ''), undefined, { numeric: true, sensitivity: 'base' });

// ---------- reorder ----------
function move(from, to) {
	if (from === to || to < 0 || to >= locations.value.length) return;
	const list = [...locations.value];
	const [item] = list.splice(from, 1);
	list.splice(to, 0, item);
	locations.value = list;
	dirty.value = true;
	message.value = null;
}
function onDrop(index) {
	if (dragIndex.value !== null) move(dragIndex.value, index);
	dragIndex.value = overIndex.value = null;
}

// Aisle by aisle, alternating direction along the lanes: A1 A2 A3, B3 B2 B1, C1 C2 C3 …
// Locations without an aisle go last, by code.
function autoSnake() {
	const withAisle = locations.value.filter((l) => l.Aisle);
	const without = locations.value.filter((l) => !l.Aisle).sort((a, b) => natural(a.code, b.code));
	const aisles = [...new Set(withAisle.map((l) => l.Aisle))].sort(natural);
	const ordered = [];
	aisles.forEach((aisle, i) => {
		const row = withAisle
			.filter((l) => l.Aisle === aisle)
			.sort((a, b) => natural(a.Lane, b.Lane) || natural(a.Stack, b.Stack) || natural(a.code, b.code));
		ordered.push(...(i % 2 === 0 ? row : row.reverse()));
	});
	locations.value = [...ordered, ...without];
	dirty.value = true;
	message.value = 'Ordered by aisle, snaking. Drag rows to adjust, then Save.';
}

const unplaced = computed(() => locations.value.filter((l) => l.PullSequence === null || l.PullSequence === undefined).length);

async function save() {
	saving.value = true;
	error.value = null;
	try {
		await axios.put('/json/locations/order', { ids: locations.value.map((l) => l.id) });
		dirty.value = false;
		message.value = 'Pick path saved. Pick sheets and the fill screen use it from now on.';
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not save the pick path.';
	} finally {
		saving.value = false;
	}
}
</script>

<template>
	<Head title="Pick Path" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="pp_page">
			<h2>Pick Path</h2>
			<p class="ri_hint">
				The order pickers walk the warehouse. Pick sheets and the fill screen list each item by where it is, in this order.
				Drag a row to move it, or use the arrows.
			</p>

			<div class="pp_actions">
				<button type="button" class="ri_formbutton" :disabled="!locations.length" @click="autoSnake">Auto-order by aisle (snaking)</button>
				<button type="button" class="ri_defaultbutton" :disabled="!dirty || saving" @click="save">Save Pick Path</button>
				<Link href="/setup/locations" class="ri_linkbutton">Back to Locations</Link>
			</div>
			<p v-if="error" class="ri_error">{{ error }}</p>
			<p v-if="message" class="ri_hint">{{ message }}</p>
			<p v-if="unplaced && !dirty" class="ri_hint">{{ unplaced }} location(s) aren't on the path yet; they're listed last. Save to place them.</p>

			<p v-if="loading">Loading…</p>
			<ol v-else class="pp_list">
				<li
					v-for="(l, i) in locations"
					:key="l.id"
					class="pp_row"
					:class="{ pp_over: overIndex === i && dragIndex !== i, pp_drag: dragIndex === i }"
					draggable="true"
					@dragstart="dragIndex = i"
					@dragover.prevent="overIndex = i"
					@dragend="dragIndex = overIndex = null"
					@drop.prevent="onDrop(i)"
				>
					<span class="pp_grip" aria-hidden="true">⋮⋮</span>
					<span class="pp_num">{{ i + 1 }}</span>
					<strong class="pp_code">{{ l.code }}</strong>
					<span class="pp_detail">
						<span v-if="l.Aisle">Aisle {{ l.Aisle }}</span>
						<span v-if="l.Lane">Lane {{ l.Lane }}</span>
						<span v-if="l.Stack">Stack {{ l.Stack }}</span>
					</span>
					<span class="pp_arrows">
						<button type="button" class="ri_linkbutton" :disabled="i === 0" title="Move up" @click="move(i, i - 1)">▲</button>
						<button type="button" class="ri_linkbutton" :disabled="i === locations.length - 1" title="Move down" @click="move(i, i + 1)">▼</button>
					</span>
				</li>
				<li v-if="!locations.length" class="pp_empty">No active locations yet.</li>
			</ol>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.pp_page {
	padding: 1em;
	max-width: 48em;
}
.pp_actions {
	display: flex;
	gap: 0.5em;
	align-items: center;
	flex-wrap: wrap;
	margin: 0.75em 0;
}
.pp_list {
	list-style: none;
	padding: 0;
	margin: 0;
}
.pp_row {
	display: flex;
	align-items: center;
	gap: 0.75em;
	padding: 0.4em 0.6em;
	border: 1px solid #ccc;
	border-top-width: 0;
	background: #fff;
	cursor: grab;
}
.pp_row:first-child {
	border-top-width: 1px;
}
.pp_over {
	border-top: 3px solid #2563eb;
}
.pp_drag {
	opacity: 0.4;
}
.pp_grip {
	color: #999;
	letter-spacing: -2px;
}
.pp_num {
	width: 2.5em;
	text-align: right;
	color: #888;
}
.pp_code {
	min-width: 6em;
}
.pp_detail {
	display: flex;
	gap: 0.75em;
	color: #666;
	font-size: 0.9em;
	flex: 1;
}
.pp_arrows {
	display: flex;
	gap: 0.25em;
}
@media (prefers-color-scheme: dark) {
	.pp_row {
		background: transparent;
		border-color: #444;
	}
}
</style>
