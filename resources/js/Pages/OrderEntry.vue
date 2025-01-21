<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';
import RIForm from '@/Components/RIForm.vue';

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
		  	:options="people" 
		  	:enabled="editing"
		  	/>
		  </td>
		  </tr>
		  <tr>
		  <th>Status:</th>
		  <td> 
		  	<ComboBox 
		  	v-model="selectedOrder.status_id"
		  	:options="statuses" 
		  	:enabled="editing"
		  	/>
		  </td>
		  </tr>
		  <tr>
		  <td colspan="2" class="ri_container_cell">
		      <h3>Items:</h3>
		        <table class="ri_subtable">
		  		<tbody>
		            <tr v-for="ledger in selectedOrder.item_ledgers" :key="ledger.id" class="ri_subtable_row">
		              <td>{{ ledger.item.description }}</td><td>Quantity: {{ ledger.qty_subtracted }}</td>
		       	      </tr >
		     	    </tbody>
		  	  </table>
		     </td>
		     </tr>
		</tbody>
	  </table>
	</RIForm>
	</AuthenticatedLayout>
</template>

<script>
import axios from "axios";

export default {
  data() {
    return {
      selectedOrder: null,
	  editing: false,
      statuses: [],
	  people: [],
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
    };
  },
  methods: {
	fetchStatuses() {
	  axios
		.get("/json/statuses")
		.then((response) => {
		  this.statuses = response.data;
		})
		.catch((error) => {
		  console.error("Error fetching statuses:", error);
		});
	},
	fetchPeople() {
	  axios
		.get("/json/people")
		.then((response) => {
		  this.people = response.data;
		})
		.catch((error) => {
		  console.error("Error fetching statuses:", error);
		});
	},
  },
  created() {
	this.fetchStatuses();
	this.fetchPeople();
  },
};
</script>






