<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Units.vue

	Admin UI for the "Default Ordering Unit" list
	(units table, /json/units — UnitController already existed with no
	page bound to it). Modeled on CategoryEntry.vue, the simplest existing
	RIForm CRUD page.
-->

<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ChipSelect from '@/Components/ChipSelect.vue';
import RIForm from '@/Components/RIForm.vue';

defineProps({
	breadcrumb: {
		type: Array,
	},
});

const typeOptions = [
	{ value: 'weight', label: 'Weight' },
	{ value: 'volume', label: 'Volume' },
	{ value: 'each', label: 'Each' },
	{ value: 'other', label: 'Other' },
];

const typeLabels = Object.fromEntries(typeOptions.map((o) => [o.value, o.label]));

// Search box: narrows the list in place by name or abbreviation.
const unitSearch = ref('');

function unitMatchesSearch(record) {
	const text = unitSearch.value.trim().toLowerCase();
	if (!text) return true;
	return (
		String(record.name ?? '').toLowerCase().includes(text) ||
		String(record.abbreviation ?? '').toLowerCase().includes(text)
	);
}

// Column-header sorting: clicking a header sorts by that field, clicking the
// same header again flips direction.
const sortField = ref('name');
const sortDir = ref(1);

function setSort(field) {
	if (sortField.value === field) {
		sortDir.value = -sortDir.value;
	} else {
		sortField.value = field;
		sortDir.value = 1;
	}
}

function sortArrow(field) {
	return sortField.value === field ? (sortDir.value === 1 ? ' ▲' : ' ▼') : '';
}

const sortKeys = {
	name: (r) => r.name ?? '',
	abbreviation: (r) => r.abbreviation ?? '',
	type: (r) => r.type ?? '',
};

const unitSort = computed(() => (a, b) => {
	const key = sortKeys[sortField.value];
	return sortDir.value * String(key(a)).localeCompare(String(key(b)), undefined, { numeric: true });
});
</script>

<template>
	<Head title="Default Ordering Units" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<RIForm
			title="Default Ordering Units"
			datasource="/json/units"
			newrecordcaption="Add Unit"
			:filter="unitMatchesSearch"
			:sort="unitSort"
		>
			<template #listactions>
				<input
					type="text"
					v-model="unitSearch"
					class="ri_forminput unit_search"
					placeholder="Search by name or abbreviation..."
				/>
			</template>

			<template #thead>
				<th class="ri_sortable" @click="setSort('name')">Name{{ sortArrow('name') }}</th>
				<th class="ri_sortable" @click="setSort('abbreviation')">Abbreviation{{ sortArrow('abbreviation') }}</th>
				<th class="ri_sortable" @click="setSort('type')">Type{{ sortArrow('type') }}</th>
			</template>
			<template #tbody="{ record }">
				<td>{{ record.name }}</td>
				<td>{{ record.abbreviation }}</td>
				<td>{{ typeLabels[record.type] || record.type }}</td>
			</template>
			<template #default="{ record, editing }">
				<div class="ri_formtable">
					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Name:</div>
						<TextInput v-model="record.name" required autofocus :enabled="editing" />
					</div>
					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Abbreviation:</div>
						<TextInput v-model="record.abbreviation" required :enabled="editing" />
					</div>
					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Type:</div>
						<ChipSelect v-model="record.type" :options="typeOptions" :enabled="editing" />
					</div>
				</div>
			</template>
		</RIForm>
	</AuthenticatedLayout>
</template>

<style scoped>
.unit_search {
	max-width: 20rem;
}
</style>
