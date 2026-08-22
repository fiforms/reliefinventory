<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';
import RIForm from '@/Components/RIForm.vue';

defineProps({
    breadcrumb: {
        type: Array,
    },
});

// Field accessors, shared by search (checks every field) and per-column sort
// (checks the one field a header was clicked for).
const fieldGetters = {
	code: (r) => r.code ?? '',
	route: (r) => r.Route ?? '',
	block: (r) => r.Block ?? '',
	aisle: (r) => r.Aisle ?? '',
	lane: (r) => r.Lane ?? '',
	stack: (r) => r.Stack ?? '',
	use: (r) => r.use?.use ?? '',
	status: (r) => r.status ?? '',
};

// Search box: narrows the list in place by matching any column.
const locationSearch = ref('');

function locationMatchesSearch(record) {
	const text = locationSearch.value.trim().toLowerCase();
	if (!text) return true;
	return Object.values(fieldGetters).some((get) => String(get(record)).toLowerCase().includes(text));
}

// Column-header sorting: clicking a header sorts by that field, clicking the
// same header again flips direction.
const sortField = ref('code');
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
	if (sortField.value !== field) return '';
	return sortDir.value === 1 ? ' ▲' : ' ▼';
}

const locationSort = computed(() => (a, b) => {
	const get = fieldGetters[sortField.value];
	return sortDir.value * String(get(a)).localeCompare(String(get(b)), undefined, { numeric: true });
});
</script>

<template>
  <Head title="Location Management" />
  <AuthenticatedLayout :breadcrumb="breadcrumb">
    <RIForm
		title="Manage Locations"
		datasource="/json/locations"
		newrecordcaption="Add Location"
		:filter="locationMatchesSearch"
		:sort="locationSort">

      <template #listactions>
        <input
          type="text"
          v-model="locationSearch"
          class="ri_forminput location_search"
          placeholder="Search all fields..."
        />
      </template>

      <template #thead>
        <th class="ri_sortable" @click="setSort('code')">Code{{ sortArrow('code') }}</th>
        <th class="ri_sortable" @click="setSort('route')">Route{{ sortArrow('route') }}</th>
        <th class="ri_sortable" @click="setSort('block')">Block{{ sortArrow('block') }}</th>
        <th class="ri_sortable" @click="setSort('aisle')">Aisle{{ sortArrow('aisle') }}</th>
        <th class="ri_sortable" @click="setSort('lane')">Lane{{ sortArrow('lane') }}</th>
        <th class="ri_sortable" @click="setSort('stack')">Stack{{ sortArrow('stack') }}</th>
        <th class="ri_sortable" @click="setSort('use')">Use{{ sortArrow('use') }}</th>
        <th class="ri_sortable" @click="setSort('status')">Status{{ sortArrow('status') }}</th>
      </template>

      <template #tbody="{ record, index }">
        <td>{{ record.code }}</td>
        <td>{{ record.Route }}</td>
        <td>{{ record.Block }}</td>
        <td>{{ record.Aisle }}</td>
        <td>{{ record.Lane }}</td>
        <td>{{ record.Stack }}</td>
        <td>{{ record.use ? record.use.use : '' }}</td>
        <td>{{ record.status }}</td>
      </template>

      <template #default="{ record, editing }">
        <div class="ri_formtable">
          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Code: *</div>
            <TextInput v-model="record.code" :enabled="editing" required />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Route:</div>
            <TextInput v-model="record.Route" :enabled="editing" />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Block:</div>
            <TextInput v-model="record.Block" :enabled="editing" />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Aisle:</div>
            <TextInput v-model="record.Aisle" :enabled="editing" />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Lane:</div>
            <TextInput v-model="record.Lane" :enabled="editing" />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Stack:</div>
            <TextInput v-model="record.Stack" :enabled="editing" />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Use: *</div>
            <ComboBox
              v-model:keyValue="record.use_id"
              v-model:updates="record.use"
              optionsource="/json/uses"
              :enabled="editing"
			  display="use"
            />
          </div>
		  
		  <div class="ri_fieldset">
		     <div class="ri_fieldlabel">Pull Sequence</div>
		     <TextInput v-model="record.PullSequence" type="number" :enabled="editing" />
		   </div>


          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Status: *</div>
            <ComboBox 
              v-model="record.status"
              :options="[{ id: 'active', name: 'Active' }, { id: 'archived', name: 'Archived' }]"
              :enabled="editing"
            />
          </div>
        </div>
      </template>
    </RIForm>
  </AuthenticatedLayout>
</template>

<style scoped>
.location_search {
  max-width: 20rem;
}
</style>
