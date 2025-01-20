<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';

</script>
<template>
  <Head title="Order Entry" />
  <AuthenticatedLayout>
	<template #header>
	</template>
	  <div class="ri_form_container">
	    <div v-if="!selectedOrder" class="ri_datatable_container">
	      <h2 class="ri_datatable_head">Orders</h2>
	      <table border="1" class="ri_datatable">
	        <thead>
	          <tr>
	            <th>Order Date</th>
	            <th>Person</th>
	            <th>Total Items</th>
	            <th>Status</th>
	          </tr>
	        </thead>
	        <tbody>
	          <tr v-for="order in orders" :key="order.id" @click="selectOrder(order)">
	            <td>{{ order.order_date }}</td>
	            <td>{{ order.person.first_name }} {{ order.person.last_name }}</td>
	            <td>{{ calculateTotalItems(order.item_ledgers) }}</td>
	            <td>{{ getStatusName(order.status_id) }}</td>
	          </tr>
	        </tbody>
	      </table>
	    </div>
	
	    <div v-else class="ri_dataform_container">
	      <h2 class="ri_dataform_head">
			<img :src="editing ? '/img/edit-icon.webp' : '/img/edit-padlock-icon.webp'" style="width: 1.5em; float: right; cursor:pointer;" @click="toggleEdit()" />
			Order Details
		  </h2>
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
	      <button v-if="editing" @click="saveRecord()" class="ri_defaultbutton">Save</button>
		  <button v-if="editing" @click="cancelRecord()" class="ri_formbutton">Cancel Changes</button>
		  <button v-if="!editing" @click="selectedOrder = null" class="ri_defaultbutton">Back to Orders</button>
	    </div>
	  </div>
	</AuthenticatedLayout>
</template>

<script>
import axios from "axios";

export default {
  data() {
    return {
      orders: [],
      selectedOrder: null,
	  editing: false,
      statuses: [],
	  people: [],
    };
  },
  methods: {
    fetchOrders() {
      axios
        .get("/json/orders")
        .then((response) => {
          this.orders = response.data;
        })
        .catch((error) => {
          console.error("Error fetching orders:", error);
        });
    },
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
    calculateTotalItems(itemLedgers) {
      return itemLedgers.reduce((total, ledger) => total + ledger.qty_subtracted, 0);
    },
	getStatusName(statusId) {
	  const status = this.statuses.find((status) => status.id === statusId);
	  return status ? status.name : "Unknown";
	},
    selectOrder(order) {
      this.selectedOrder = order;
    },
	toggleEdit() {
		this.editing = !this.editing;
	},
  },
  created() {
    this.fetchOrders();
	this.fetchStatuses();
	this.fetchPeople();
  },
};
</script>






