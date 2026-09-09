<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- PackageTypes.vue

	Feedback #43 ("package types is blocking adding a new item"): the
	packagetypes JSON API (PackageTypeController) already existed, but no
	page was ever bound to it — the item catalog's "Package" picker on
	each item (ItemEntry.vue's items subform) could only choose among
	whatever package types already existed, with no way to add a new one.
	Modeled on Units.vue/CategoryEntry.vue, the simplest existing RIForm
	CRUD pages.
-->

<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import RIForm from '@/Components/RIForm.vue';

defineProps({
	breadcrumb: {
		type: Array,
	},
});

// Search box: narrows the list in place by singular or plural name.
const packageTypeSearch = ref('');

function packageTypeMatchesSearch(record) {
	const text = packageTypeSearch.value.trim().toLowerCase();
	if (!text) return true;
	return (
		String(record.singular ?? '').toLowerCase().includes(text) ||
		String(record.plural ?? '').toLowerCase().includes(text)
	);
}

// Column-header sorting: clicking a header sorts by that field, clicking the
// same header again flips direction.
const sortField = ref('singular');
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

const packageTypeSort = computed(() => (a, b) =>
	sortDir.value * String(a[sortField.value] ?? '').localeCompare(String(b[sortField.value] ?? ''), undefined, { numeric: true })
);
</script>

<template>
	<Head title="Package Types" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<RIForm
			title="Package Types"
			datasource="/json/packagetypes"
			newrecordcaption="Add Package Type"
			:filter="packageTypeMatchesSearch"
			:sort="packageTypeSort"
		>
			<template #listactions>
				<input
					type="text"
					v-model="packageTypeSearch"
					class="ri_forminput packagetype_search"
					placeholder="Search by name..."
				/>
			</template>

			<template #thead>
				<th class="ri_sortable" @click="setSort('singular')">Singular{{ sortArrow('singular') }}</th>
				<th class="ri_sortable" @click="setSort('plural')">Plural{{ sortArrow('plural') }}</th>
			</template>
			<template #tbody="{ record }">
				<td>{{ record.singular }}</td>
				<td>{{ record.plural }}</td>
			</template>
			<template #default="{ record, editing }">
				<div class="ri_formtable">
					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Singular:</div>
						<TextInput v-model="record.singular" placeholder="e.g. Case" required autofocus :enabled="editing" />
					</div>
					<div class="ri_fieldset">
						<div class="ri_fieldlabel">Plural:</div>
						<TextInput v-model="record.plural" placeholder="e.g. Cases" required :enabled="editing" />
					</div>
				</div>
			</template>
		</RIForm>
	</AuthenticatedLayout>
</template>

<style scoped>
.packagetype_search {
	max-width: 20rem;
}
</style>
