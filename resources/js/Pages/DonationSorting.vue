<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
	 
<!-- OrderEntry.vue  
     This is an RIForm Vue File. It's similar to a normal Vue file except that it 
	 requires no script or code. Use this as a model for other RIForm Vue Files -->

<script setup>
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
</script>
<template>
  <Head title="Donation Sorting" />
  <AuthenticatedLayout :breadcrumb="breadcrumb">
	<template #header>
	</template>
	
	<!-- Include the the RIForm Component. This component will attach to the JSON
	     API URL specified in the datasource="" attribute for loading and saving data.
	  -->
	<RIForm 
	  title="Donation Sorting" 
	  datasource="/json/donations"
	  newrecordcaption="Start Sorting">
	    <template #thead>
			<th>Transaction Date</th>
			<th>User</th>
			<th>Total Items</th>
		</template>
		<template #tbody="{ record, index }"> 
			<td>{{ record.order_date }}</td>
			<td>{{ record.user.name }}</td>
			<td>{{ record.item_ledgers.reduce((total, ledger) => total + ledger.qty_subtracted, 0) }}</td>
		</template>
		<template #default="{ record, editing, templates }">
		<div class="ri_formtable">
		  <div class="ri_fieldset">
			<div class="ri_fieldlabel">Transaction Date:</div>
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
		  	<div class="ri_fieldlabel">Comments</div>
		  	<TextArea
		  				    v-model="record.comments"
		  					:enabled="editing"
		  			  /> 
		  </div>
			
			<RISubform 
					title="Donations Sorted"  
					v-model:records="record.item_ledgers"
					:template="templates.item_ledgers" 
					:enabled="editing">
				<template #thead>
					<th>UPC</th>
					<th>Description</th>
					<th>Quantity</th>
				</template>
				<template #tbody="{ subrecord, index }">
					<td>{{ subrecord.item.upc }}</td>
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
						display="upc"
						placeholder="UPC"
						/>
					</td>
					<td>
					<ComboBox 
						v-model:keyValue="subrecord.item_id"
						v-model:updates="subrecord.item"
						optionsource="/json/items"
						:enabled="true"
						display="name"
						placeholder="Product"
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
			<div class="ri_fieldset">
				<button class="ri_defaultbutton" @click="newPallet()">Create New Pallet Label</button>
			</div>
	  </div>
	  </template>
	</RIForm>
	</AuthenticatedLayout>
</template>


<script>

export default {
	
	methods: {
		newPallet() {
		    window.location.href = "/pallet-management";
		},
    }
}
</script>