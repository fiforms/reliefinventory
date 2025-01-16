<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

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
	      <h2 class="ri_dataform_head">Order Details</h2>
		  <table class="ri_formtable">
			<tbody>
		      <tr><th>Order Date:</th><td> {{ selectedOrder.order_date }}</td></tr>
		      <tr><th>Person:</th><td> {{ selectedOrder.person.first_name }} {{ selectedOrder.person.last_name }}</td></tr>
		      <tr><th>Status:</th><td> {{ getStatusName(selectedOrder.status_id) }}</td></tr>
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
	      <button @click="selectedOrder = null" class="ri_formbutton">Back to Orders</button>
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
      statuses: {
        1: "Shopping Cart",
        2: "New Order",
        3: "Picking In Progress",
        4: "Completed",
      },
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
    calculateTotalItems(itemLedgers) {
      return itemLedgers.reduce((total, ledger) => total + ledger.qty_subtracted, 0);
    },
    getStatusName(statusId) {
      return this.statuses[statusId] || "Unknown";
    },
    selectOrder(order) {
      this.selectedOrder = order;
    },
  },
  created() {
    this.fetchOrders();
  },
};
</script>

<style scoped>
table {
  width: 100%;
  border-collapse: collapse;
}
th, td {
  padding: 10px;
  text-align: left;
}
th {
  background-color: #f4f4f4;
}
tr:hover {
  cursor: pointer;
  background-color: #f0f0f0;
}
</style>




