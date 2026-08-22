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

// Search box: narrows the list in place by Category Name.
const categorySearch = ref('');

function categoryMatchesSearch(record) {
	const text = categorySearch.value.trim().toLowerCase();
	if (!text) return true;
	return String(record.name ?? '').toLowerCase().includes(text);
}

// Column-header sorting: clicking a header sorts by that field, clicking the
// same header again flips direction.
const sortDir = ref(1);

function setSort() {
	sortDir.value = -sortDir.value;
}

function sortArrow() {
	return sortDir.value === 1 ? ' ▲' : ' ▼';
}

const categorySort = computed(() => (a, b) => sortDir.value * String(a.name ?? '').localeCompare(String(b.name ?? ''), undefined, { numeric: true }));
</script>

<template>
  <Head title="Item Category Entry" />
  <AuthenticatedLayout :breadcrumb="breadcrumb">
    <template #header>
    </template>

    <RIForm
      title="Item Category Management"
      datasource="/json/categories"
	  newrecordcaption="Add Category"
	  :filter="categoryMatchesSearch"
	  :sort="categorySort">
      <template #listactions>
        <input
          type="text"
          v-model="categorySearch"
          class="ri_forminput category_search"
          placeholder="Search by Category Name..."
        />
      </template>
      <template #thead>
        <th class="ri_sortable" @click="setSort()">Category Name{{ sortArrow() }}</th>
      </template>
      <template #tbody="{ record, index }">
        <td>{{ record.name }}</td>
      </template>
      <template #default="{ record, editing, templates }">
        <div class="ri_formtable">
          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Category Name:</div>
            <TextInput
              v-model="record.name"
              required
              autofocus
              :enabled="editing"
            /> 
          </div>
        </div>
      </template>
    </RIForm>
  </AuthenticatedLayout>
</template>

<style scoped>
.category_search {
  max-width: 20rem;
}
</style>
