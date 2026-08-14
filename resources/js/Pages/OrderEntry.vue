<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
	 
<!-- OrderEntry.vue  
     This is an RIForm Vue File. It's similar to a normal Vue file except that it 
	 requires no script or code. Use this as a model for other RIForm Vue Files -->

<script setup>
import { ref, nextTick } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';
import RIForm from '@/Components/RIForm.vue';
import RISubform from '@/Components/RISubform.vue';
import TextArea from '@/Components/TextArea.vue';

defineProps({
    breadcrumb: {
        type: Array,
    },
});

// Requested-items quick entry: selecting an item number moves focus to
// quantity, and pressing Enter in quantity starts a new line at item number
// (rapid data entry from a paper order form / catalog).
const requestedItemsSubform = ref(null);
const itemNumberCombo = ref(null);
const qtyInput = ref(null);

function onItemNumberSelected() {
    nextTick(() => qtyInput.value?.focus());
}

function onQtyEnter() {
    requestedItemsSubform.value?.newLine();
    nextTick(() => itemNumberCombo.value?.focus());
}
</script>
<template>
  <Head title="Master Order Entry" />
  <AuthenticatedLayout :breadcrumb="breadcrumb">
	<template #header>
	</template>
	
	<!-- Include the the RIForm Component. This component will attach to the JSON
	     API URL specified in the datasource="" attribute for loading and saving data.
	  -->
	<RIForm 
	  title="Master Order Entry and Fill Form" 
	  datasource="/json/orders"
	  newrecordcaption="New Order">
	    <template #thead>
			<th>Order Date</th>
			<th>Customer</th>
			<th>Total Items</th>
			<th>Status</th>
			<th>Comments</th>
		</template>
		<template #tbody="{ record, index }"> 
			<td>{{ record.order_date }}</td>
			<td v-if="record.person">{{ record.person.full_name }}</td>
			<td v-else>(no customer)</td>
			<td>{{ (record.order_lines || []).reduce((total, line) => total + (Number(line.qty_requested) || 0), 0) }}</td>
			<td>{{ record.status.name }}</td>
			<td>{{ record.comments ? record.comments.slice(0,50) : '' }}</td>
		</template>
		<template #default="{ record, editing, templates }">
		<div class="ri_formtable">
		  <div class="ri_fieldset">
			<div class="ri_fieldlabel">Order Date:</div>
			<TextInput
				    id="orderdate"
				    type="date"
				    v-model="record.order_date"
				    required
				    autofocus
					:enabled="editing"
			  /> 
		  </div>
		  <div class="ri_fieldset">
		    <div class="ri_fieldlabel">Person:</div>
		  	<ComboBox
				  	v-model:keyValue="record.person_id"
					v-model:updates="record.person"
					optionsource="/json/people"
					display="full_name"
					secondaryDisplay="organization"
					:searchFields="['full_name', 'organization']"
					placeholder="Search people..."
				  	:enabled="editing"
		  	/>
		  </div>
		  <div class="ri_fieldset">
		  		<div class="ri_fieldlabel">Name:</div>
		  		{{ record.person?.first_name }} {{ record.person?.last_name }}
		  		</div>
			<div class="ri_fieldset">
		  		<div class="ri_fieldlabel">Organization:</div>
		  		{{ record.person?.organization }}
		  		</div>

		  	<div class="ri_fieldset">
				<div class="ri_fieldlabel">Delivery Address:</div>
				{{ record.person?.address }}
				</div>

			<div class="ri_fieldset">
				<div class="ri_fieldlabel">City/State/Zip:</div>
				{{ record.person?.city }} {{ record.person?.state }} {{ record.person?.zip }}
				</div>

			<div class="ri_fieldset">
				<div class="ri_fieldlabel">County:</div>
				{{ record.person?.county?.county }}
				</div>

			<div class="ri_fieldset">
				<div class="ri_fieldlabel">Phone:</div>
				{{ record.person?.phone }}
				</div>

			<div class="ri_fieldset">
				<div class="ri_fieldlabel">Email:</div>
			  	{{ record.person?.email }}
			 	 </div>
				 
		  <div class="ri_fieldset">
  	  	  		<div class="ri_fieldlabel">Status:</div>
		  		{{ record.status ? record.status.name : '' }}
		  </div>
		  <div class="ri_fieldset">
		  	<div class="ri_fieldlabel">Comments</div>
		  	<TextArea
		  				    v-model="record.comments"
		  					:enabled="editing"
		  			  /> 
		  </div>
			<RISubform
					ref="requestedItemsSubform"
					title="Order Requested Items"
								v-model:records="record.order_lines"
								:template="templates.order_lines"
								:enabled="editing">
				<template #thead>
					<th>ACS Item#</th>
					<th>Quantity</th>
					<th>Item Type</th>
					<th>Unit</th>
					<th>Comments</th>
				</template>
				<template #tbody="{ subrecord, index }">
					<td>{{ subrecord.itemtype ? subrecord.itemtype.display_number : '' }}</td>
					<td>{{ subrecord.qty_requested }}</td>
					<td>{{ subrecord.itemtype ? subrecord.itemtype.name : '' }}</td>
					<td>{{ subrecord.itemtype && subrecord.itemtype.unit ? subrecord.itemtype.unit.name : '' }}</td>
					<td>{{ subrecord.comments }}</td>
				</template>
				<template #default="{ subrecord, index }">
				  <td>
					<ComboBox
						ref="itemNumberCombo"
						v-model:keyValue="subrecord.itemtype_id"
						v-model:updates="subrecord.itemtype"
						optionsource="/json/itemtypes/noitems"
						display="display_number"
						secondaryDisplay="name"
						:searchFields="['display_number', 'name']"
						placeholder="Item #"
						:enabled="true"
						@selected="onItemNumberSelected"
						/>
				  </td>
				  <td class="ri_qty_input">
				  	<TextInput
		  					ref="qtyInput"
		  					type="number"
		  				    v-model="subrecord.qty_requested"
		  					:enabled="true"
		  					@keydown.enter.prevent="onQtyEnter"
				  	  />
				  </td>
				  <td>
					  {{ subrecord.itemtype ? subrecord.itemtype.name : '' }}
				  </td>
				  <td>
					  {{ subrecord.itemtype && subrecord.itemtype.unit ? subrecord.itemtype.unit.name : '' }}
				  </td>
		  		  
				  <td>
				    <TextInput
				  	    v-model="subrecord.comments"
				  		:enabled="true"
				    /> 
				  </td>
				</template>
			</RISubform>
			
			<RISubform
					title="Order Filled Line Items"
					v-model:records="record.item_ledgers"
					:template="templates.item_ledgers"
					:enabled="editing"
					:allowAdd="false">
				<template #thead>
					<th>Description</th>
					<th>Quantity</th>
				</template>
				<template #tbody="{ subrecord, index }">
					<td>{{ subrecord.item.description }}</td>
					<td>{{ subrecord.qty_subtracted }}</td>
				</template>
				<template #default="{ subrecord, index }">
				  <td>
					<ComboBox 
						v-model:keyValue="subrecord.item_id"
						v-model:updates="subrecord.item"
						optionsource="/json/items"
						:enabled="true"
						/>
				  </td>
				  <td>
					<TextInput
					    id="qty"
						type="number"
					    v-model="subrecord.qty_subtracted"
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






