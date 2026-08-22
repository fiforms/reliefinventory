<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
	 
<!-- ItemEntry.vue 
	This is an RIForm Vue File. It's similar to a normal Vue file except that it 
	requires no script or code. Use this as a model for other RIForm Vue Files -->

<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';
import RIForm from '@/Components/RIForm.vue';
import RISubform from '@/Components/RISubform.vue';
import Checkbox from '@/Components/Checkbox.vue';

defineProps({
    breadcrumb: {
        type: Array,
    },
});

// Search box: narrows the list in place by ACS Item# or Item Type name (same
// pattern as People.vue's name/organization search).
const itemSearch = ref('');

function itemMatchesSearch(record) {
	const text = itemSearch.value.trim().toLowerCase();
	if (!text) return true;
	return (
		String(record.display_number ?? '').toLowerCase().includes(text) ||
		String(record.name ?? '').toLowerCase().includes(text)
	);
}

// Column-header sorting: clicking a header sorts by that field, clicking the
// same header again flips direction.
const sortField = ref('category');
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

const sortKeys = {
	category: (r) => r.category?.name ?? '',
	number: (r) => r.display_number ?? '',
	name: (r) => r.name ?? '',
	unit: (r) => r.unit?.abbreviation ?? '',
	active: (r) => (r.active ? 1 : 0),
};

const itemSort = computed(() => (a, b) => {
	const key = sortKeys[sortField.value];
	const av = key(a);
	const bv = key(b);
	const cmp = typeof av === 'number' ? av - bv : String(av).localeCompare(String(bv), undefined, { numeric: true });
	return sortDir.value * cmp;
});


</script>
<template>
  <Head title="Master Item Catalog" />
  <AuthenticatedLayout :breadcrumb="breadcrumb">
	<template #header>
	</template>
	
	<!-- Include the the RIForm Component. This component will attach to the JSON
	     API URL specified in the datasource="" attribute for loading and saving data.
	  -->
	<RIForm
	  title="Master Item List"
	  datasource="/json/itemtypes"
	  newrecordcaption="Add New Item"
	  :filter="itemMatchesSearch"
	  :sort="itemSort">

	  <template #listactions>
		<input
		  type="text"
		  v-model="itemSearch"
		  class="ri_forminput item_search"
		  placeholder="Search by ACS Item# or Item Type..."
		/>
	  </template>

	    <template #thead>
			<th class="ri_sortable" @click="setSort('category')">Category{{ sortArrow('category') }}</th>
			<th class="ri_sortable" @click="setSort('number')">ACS Item#{{ sortArrow('number') }}</th>
			<th class="ri_sortable" @click="setSort('name')">Item Type{{ sortArrow('name') }}</th>
			<th class="ri_sortable" @click="setSort('unit')">Unit{{ sortArrow('unit') }}</th>
		    <th class="ri_sortable" style="text-align:center;" @click="setSort('active')">Active{{ sortArrow('active') }}</th>
		</template>
		<template #tbody="{ record, index }">
			<td> {{ record.category.name }} </td>
			<td> {{ record.display_number }} </td>
			<td> {{ record.name }} </td>
			<td> {{ record.unit.abbreviation }} </td>
			<td style="text-align: center;"> <span v-if="record.active"> &check; </span> </td>
		</template>
		<template #default="{ record, editing, templates }">
		<div class="ri_formtable">
		  <div class="ri_fieldset">
			  <div class="ri_fieldlabel">Category</div>
			  <ComboBox
			  		v-model:keyValue="record.category_id"
			  					v-model:updates="record.category"
			  					optionsource="/json/categories"
			  				  	:enabled="editing"
			    /> 
		  </div>	
		  <div class="ri_fieldset">
		  		<div class="ri_fieldlabel">Family</div>
		  		<TextInput
		  			    v-model="record.family"
		  			    placeholder="e.g. 318"
		  			    autofocus
		  				:enabled="editing"
		  		  />
		  	  </div>
		  <div class="ri_fieldset">
		  		<div class="ri_fieldlabel">Variant (optional)</div>
		  		<TextInput
		  			    v-model="record.variant"
		  			    placeholder="e.g. 90"
		  				:enabled="editing"
		  		  />
		  	  </div>
		  <div class="ri_fieldset">
			<div class="ri_fieldlabel">Item Type Desc</div>
			<TextInput
				    v-model="record.name"
				    required
					:enabled="editing"
			  /> 
		  </div>
		  <div class="ri_fieldset">
		    <div class="ri_fieldlabel">Measured By</div>
		    <ComboBox
		  		v-model:keyValue="record.unit_id"
		  					v-model:updates="record.unit"
		  					optionsource="/json/units"
		  				  	:enabled="editing"
		    /> 
		  </div>	
		  <div class="ri_fieldset">
			  <div class="ri_fieldlabel">Active</div>
			  <Checkbox
			  		v-model="record.active"
			  				  	:enabled="editing"
			    /> 
		  </div>	 
			<RISubform 
					title="Items"  
								v-model:records="record.items"
								:template="templates.items" 
								:enabled="editing">
				<template #thead>
					<th> Plus Code </th>
					<th>Item Description</th>
					<th colspan="3">Package</th>
					<th>Total {{ record.unit.abbreviation }}</th>
					<th>UPC</th>
				</template>
				<template #tbody="{ subrecord, index }">
					<td>{{ subrecord.pluscode }}</td>
					<td>{{ subrecord.description }}</td>
				     <td colspan="3">{{ subrecord.packagetype.singular }} 
						 <span v-if="subrecord.case_qty"> of {{ subrecord.case_qty }} ( {{ subrecord.size * 1.0 }} {{ record.unit.abbreviation }} )</span></td>
					 <td><span v-if="subrecord.case_qty"> {{ subrecord.case_qty * subrecord.size }}</span>
					     <span v-else>{{ subrecord.size * 1.0 }}</span></td>	 
					 <td> {{ subrecord.upc }}</td>
				</template>
				<template #default="{ subrecord, index }">
				  <td>
					<TextInput
						    v-model="subrecord.pluscode"
							placeholder="Plus Code"
							:enabled="true"
					  /> 
				  </td>
				  <td>
	  				<TextInput
	  					    v-model="subrecord.description"
	  						placeholder="Description"
	  						:enabled="true"
	  				  /> 
				   </td>
				  <td>
					<ComboBox 
					    v-model:keyValue="subrecord.packagetypes_id"
						v-model:updates="subrecord.packagetype"
						optionsource="/json/packagetypes"
						display="singular"
						placeholder="Pk Unit"
						:enabled="true" />
				  </td>
				  <td>
				  <TextInput
				   		v-model="subrecord.size" 
						type="number"
						:placeholder="'Size in ' + record.unit.abbreviation"
						:enabled="true"
				        />
				  </td>
				  <td>
				  <TextInput
				   		v-model="subrecord.case_qty" 
					  	type="number"
						placeholder="Case Qty"
					  	:enabled="true"
				   />
				  </td>
				  <td><span v-if="subrecord.case_qty"> {{ subrecord.case_qty * subrecord.size }}</span>
					<span v-else>{{ subrecord.size * 1.0 }}</span></td>
				  <td>
				  <TextInput
				  	    v-model="subrecord.upc"
						placeholder="UPC"
				  		:enabled="true"
				    /> 
				  </td>
				</template>
			</RISubform>  
	  </div>
	  </template>
	</RIForm>
	</AuthenticatedLayout>
</template>

<style scoped>
.item_search {
  max-width: 20rem;
}
</style>






