<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
	 
<!-- ItemEntry.vue 
	This is an RIForm Vue File. It's similar to a normal Vue file except that it 
	requires no script or code. Use this as a model for other RIForm Vue Files -->

<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import HelpLink from '@/Components/HelpLink.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
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

// Moving an item (one SKU row) to another ACS # - Administrators only. Everything in
// the database links to the item itself, so its stock and history move with it.
const canMove = computed(() => !!usePage().props.catalog?.canMoveItems);
const move = ref({ open: false, item: null, impact: null, itemtype_id: null, busy: false, error: '' });

async function openMove(item) {
	move.value = { open: true, item, impact: null, itemtype_id: null, busy: false, error: '' };
	try {
		move.value.impact = (await axios.get(`/json/items/${item.id}/move-impact`)).data;
	} catch (e) {
		move.value.error = e.response?.data?.message || 'Could not load what this item is linked to.';
	}
}

async function confirmMove() {
	const m = move.value;
	if (!m.itemtype_id) { m.error = 'Choose the ACS # to move this item to.'; return; }
	m.busy = true;
	m.error = '';
	try {
		await axios.post(`/json/items/${m.item.id}/move`, { itemtype_id: m.itemtype_id });
		window.location.reload(); // the open Item Type record is stale now
	} catch (e) {
		m.error = e.response?.data?.message || e.response?.data?.errors?.itemtype_id?.[0] || 'The move failed.';
		m.busy = false;
	}
}

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
	    <HelpLink guide="items">Master Item List Guide</HelpLink>
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
		    <div class="ri_fieldlabel">Default Ordering Unit</div>
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
					<th v-if="canMove"></th>
				</template>
				<template #tbody="{ subrecord, index }">
					<td>{{ subrecord.pluscode }}</td>
					<td>{{ subrecord.description }}</td>
				     <td colspan="3">{{ subrecord.packagetype.singular }} 
						 <span v-if="subrecord.case_qty"> of {{ subrecord.case_qty }} ( {{ subrecord.size * 1.0 }} {{ record.unit.abbreviation }} )</span></td>
					 <td><span v-if="subrecord.case_qty"> {{ subrecord.case_qty * subrecord.size }}</span>
					     <span v-else>{{ subrecord.size * 1.0 }}</span></td>	 
					 <td> {{ subrecord.upc }}</td>
					 <td v-if="canMove"><button v-if="subrecord.id" type="button" class="ri_formbutton" @click="openMove(subrecord)">Move to ACS #&hellip;</button></td>
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
				  <td v-if="canMove"><button v-if="subrecord.id" type="button" class="ri_formbutton" @click="openMove(subrecord)">Move to ACS #&hellip;</button></td>
				</template>
			</RISubform>  
	  </div>
	  </template>
	</RIForm>

	<Modal :show="move.open" max-width="lg" @close="move.open = false">
		<div class="item_move">
			<h3>Move item to a different ACS #</h3>
			<p v-if="move.item"><strong>{{ move.item.description || '(no description)' }}</strong><span v-if="move.impact"> &mdash; now under {{ move.impact.item.acs }} {{ move.impact.item.itemtype }}</span></p>
			<label>New ACS # / item type:</label>
			<SearchSelect
				v-model="move.itemtype_id"
				optionsource="/json/itemtypes/noitems"
				display="name"
				:searchfields="['name', 'display_number']"
				placeholder="Search item types..."
			/>
			<div v-if="move.impact" class="item_move_warn">
				<strong>This changes history, not just the catalog.</strong>
				Everything recorded against this item moves to the new ACS #: its
				{{ move.impact.ledger_rows }} sorting/fill ledger row(s) (so stock on hand and the inventory and flow reports change),
				{{ move.impact.pallet_assignments }} pallet stock entr{{ move.impact.pallet_assignments === 1 ? 'y' : 'ies' }}
				and any carton barcodes pointing at it.
				<span v-if="move.impact.order_fills">
					{{ move.impact.order_fills }} of its ledger rows are fills for order lines; those lines still request the old item type,
					so past orders will show goods of a different type than was requested. Orders themselves are not changed.
				</span>
				Already-printed inventory labels keep the old number. This cannot be undone automatically &mdash; you would move it back by hand.
			</div>
			<p v-if="move.error" class="item_move_error">{{ move.error }}</p>
			<div class="item_move_actions">
				<button type="button" class="ri_defaultbutton" :disabled="move.busy || !move.impact" @click="confirmMove">{{ move.busy ? 'Moving...' : 'Move item' }}</button>
				<button type="button" class="ri_formbutton" @click="move.open = false">Cancel</button>
			</div>
		</div>
	</Modal>
	</AuthenticatedLayout>
</template>

<style scoped>
.item_move { padding: 1.25rem; display: grid; gap: 0.75rem; }
.item_move_warn { background: #fef3c7; border: 1px solid #d97706; border-radius: 6px; padding: 0.75rem; font-size: 0.9rem; }
.item_move_error { color: #b00020; }
.item_move_actions { display: flex; gap: 0.5rem; }
.item_search {
  max-width: 20rem;
}
</style>






