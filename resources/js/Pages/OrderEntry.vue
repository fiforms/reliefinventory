<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

</script>
<template>
  <Head title="Order Entry" />
  <AuthenticatedLayout>
	<template #header>
	</template>
	  <div>
	    <div v-if="!selectedOrder">
	      <h2>Orders</h2>
	      <table border="1">
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
	
	    <div v-else>
	      <h2>Order Details</h2>
	      <p><strong>Order Date:</strong> {{ selectedOrder.order_date }}</p>
	      <p><strong>Person:</strong> {{ selectedOrder.person.first_name }} {{ selectedOrder.person.last_name }}</p>
	      <p><strong>Status:</strong> {{ getStatusName(selectedOrder.status_id) }}</p>
	      <h3>Items:</h3>
	      <ul>
	        <li v-for="ledger in selectedOrder.item_ledgers" :key="ledger.id">
	          {{ ledger.item.description }} - Quantity: {{ ledger.qty_subtracted }}
	        </li>
	      </ul>
	      <button @click="selectedOrder = null">Back to Orders</button>
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




