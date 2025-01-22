<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';
import RIForm from '@/Components/RIForm.vue';
import RISubform from '@/Components/RISubform.vue';

</script>
<template>
  <Head title="Order Entry" />
  <AuthenticatedLayout>
	<template #header>
	</template>
	<RIForm 
	  title="Daniel's Test Form" 
	  datasource="/json/orders">
	    <template #thead>
			<th>Order Date</th>
			<th>Customer</th>
			<th>Total Items</th>
			<th>Status</th>
		</template>
		<template #tbody="{ record, index }"> 
			<td>{{ record.order_date }}</td>
			<td v-if="record.person">{{ record.person.first_name }} {{ record.person.last_name }}</td>
			<td v-else> () </td>
			<td>{{ record.item_ledgers.reduce((total, ledger) => total + ledger.qty_subtracted, 0) }}</td>
			<td>{{ record.status.name }}</td>
		</template>
		<template #default="{ record, editing }">
		<table class="ri_formtable">
		<tbody>
		    <tr>
			<th>Order Date:</th>
			<td>
			  <TextInput
				    id="orderdate"
				    type="date"
				    v-model="record.order_date"
				    required
				    autofocus
					:enabled="editing"
			  /> 
			</td>
		  </tr>
		  <tr>
		  <th>Person:</th>
		  <td> 
		  	<ComboBox 
		  	v-model="record.person_id"
			optionsource="/json/people"
		  	:enabled="editing"
		  	/>
		  </td>
		  </tr>
		  <tr>
		  <th>Status:</th>
		  <td> 
		  	<ComboBox 
		  	v-model="record.status_id"
			optionsource="/json/statuses"
		  	:enabled="editing"
		  	/>
		  </td>
		  </tr>
		  <tr>
		  <td colspan="2" class="ri_container_cell">
			<RISubform 
					title="Order Lines"  
					v-model:records="record.item_ledgers" 
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
						v-model="record.item_id"
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
 	      </td>
		 </tr>
		</tbody>
	  </table>
	  </template>
	</RIForm>
	</AuthenticatedLayout>
</template>

<script>

export default {
  data() {
    return {
    };
  },
};
</script>






