<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
<!-- OrderEntry.vue -->

<!-- This can be used as a prototype for additional vue components -->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';
import RIForm from '@/Components/RIForm.vue';
import RISubform from '@/Components/RISubform.vue';
import TextArea from '@/Components/TextArea.vue';

</script>
<template>
  <Head title="Master Order Entry" />
  <AuthenticatedLayout>
	<template #header>
	</template>
	
	<!-- Include the the RIForm Component. This component will attach to the JSON
	     API URL specified in the datasource="" attribute for loading and saving data.
	  -->
	<RIForm 
	  title="Master Order Entry and Fill Form" 
	  datasource="/json/orders">
	    <template #thead>
			<th>Order Date</th>
			<th>Customer</th>
			<th>Total Items</th>
			<th>Status</th>
			<th>Comments</th>
		</template>
		<template #tbody="{ record, index }"> 
			<td>{{ record.order_date }}</td>
			<td v-if="record.person">{{ record.person.first_name }} {{ record.person.last_name }}</td>
			<td v-else> ("Enter Name") </td>
			<td>{{ record.item_ledgers.reduce((total, ledger) => total + ledger.qty_subtracted, 0) }}</td>
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
				  	:enabled="editing"
		  	/>
		  </div>
		  <div class="ri_fieldset">
			<div class="ri_fieldlabel">Contact Info:</div>
			<p>{{ record.person.address }} <br />
			{{ record.person.city }}, {{ record.person.state }} {{ record.person.zip }}</p>
		  </div>
		  <div class="ri_fieldset">
  	  	  	<div class="ri_fieldlabel">Status:</div>
		  
		  	<ComboBox 
				  	v-model:keyValue="record.status_id"
					v-model:updates="record.status"
					optionsource="/json/statuses"
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
					title="Order Requested Items"  
								v-model:records="record.order_lines"
								:template="templates.order_lines" 
								:enabled="editing">
				<template #thead>
					<th>Item Type</th>
					<th colspan="2">Quantity</th>
					<th>Comments</th>
				</template>
				<template #tbody="{ record, index }">
					<td>{{ record.itemtype.name }}</td>
					<td colspan="2">{{ record.qty_requested }}
					    {{ record.qty_requested > 1 ? record.packagetype.plural : record.packagetype.singular }}</td>
					<td>{{ record.comments }}</td>
				</template>
				<template #default="{ record, index }">
				  <td>
					<ComboBox 
						v-model:keyValue="record.itemtype_id"
						v-model:updates="record.itemtype"
						optionsource="/json/itemtypes"
						:enabled="true"
						/>
				  </td>
				  <td>
					<TextInput
							type="number"
						    v-model="record.qty_requested"
							:enabled="true"
					  /> 
				  </td>
				  <td>
					  <ComboBox 
					  	v-model:keyValue="record.packagetype_id"
					  	v-model:updates="record.packagetype"
					  	optionsource="/json/packagetypes"
						:display="record.qty_requested > 1 ? 'plural' : 'singular'"
					  	:enabled="true"
					  	/>
				  </td>
				  <td>
				    <TextInput
				  	    v-model="record.comments"
				  		:enabled="true"
				    /> 
				  </td>
				</template>
			</RISubform>
			
			<RISubform 
					title="Order Filled Line Items"  
					v-model:records="record.item_ledgers"
					:template="templates.item_ledgers" 
					:enabled="editing">
				<template #thead>
					<th>Description</th>
					<th>Quantity</th>
				</template>
				<template #tbody="{ record, index }">
					<td>{{ record.item.description }}</td>
					<td>{{ record.qty_subtracted }}</td>
				</template>
				<template #default="{ record, index }">
				  <td>
					<ComboBox 
						v-model:keyValue="record.item_id"
						v-model:updates="record.item"
						optionsource="/json/items"
						:enabled="true"
						/>
				  </td>
				  <td>
					<TextInput
					    id="qty"
						type="number"
					    v-model="record.qty_subtracted"
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






