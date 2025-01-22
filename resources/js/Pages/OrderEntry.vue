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
	<RIForm title="Daniel's Test Form" :tabularfields="tabularfields" datasource="/json/orders" v-model:record="selectedOrder" v-model:editing="editing">
		<table class="ri_formtable">
		<tbody>
		    <tr>
			<th>Order Date:</th>
			<td>
			  <TextInput
				    id="orderdate"
				    type="date"
				    v-model="selectedOrder.order_date"
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
		  	v-model="selectedOrder.person_id"
			optionsource="/json/statuses"
		  	:enabled="editing"
		  	/>
		  </td>
		  </tr>
		  <tr>
		  <th>Status:</th>
		  <td> 
		  	<ComboBox 
		  	v-model="selectedOrder.status_id"
			optionsource="/json/people"
		  	:enabled="editing"
		  	/>
		  </td>
		  </tr>
		  <tr>
		  <td colspan="2" class="ri_container_cell">
			<RISubform 
					title="Order Lines" 
					:tabularfields="lineitemfields" 
					v-model:records="selectedOrder.item_ledgers" 
					v-slot="line"
					:enabled="editing">
				<ComboBox 
					v-model="line.record.item_id"
					optionsource="/json/items"
					:enabled="true"
					/>
				<TextInput
				    id="qty"
				    v-model="line.record.qty_subtracted"
					:enabled="true"
				    /> 
			</RISubform>
 	      </td>
		 </tr>
		</tbody>
	  </table>
	</RIForm>
	</AuthenticatedLayout>
</template>

<script>

export default {
  data() {
    return {
      selectedOrder: null,
	  editing: false,
	  tabularfields: [
	    {
			title: 'Order Date',
	  		name: 'order_date',
	    },
	    {
			title: 'Customer',
			calculation: (record) => {
				return record.person.first_name + " " + record.person.last_name;
			},
	    },
		{
			title: 'Total Items',
			calculation: (record) => {
				return record.item_ledgers.reduce((total, ledger) => total + ledger.qty_subtracted, 0);
			},
		},
		{
			title: 'Status',
			calculation: (record) => {
				return record.status.name;
			},
		},
	  ],
	  lineitemfields: [
	      {
			  title: "Description",
			      calculation: (record) => {
				      return record.item.description;
			  },
		  },
		  {
			  title: "Qty",
			  name: "qty_subtracted",
		  }
	  ],
    };
  },
};
</script>






