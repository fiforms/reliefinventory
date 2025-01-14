<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

</script>

<template>
    <Head title="Order Entry" />

    <AuthenticatedLayout>
        <template #header>
        </template>

	<div class="menu-container">
	  <h1> Future Home of Order Entry</h1>
	</div>
    </AuthenticatedLayout>
</template>


<script>
import axios from 'axios';

export default {
  name: "EntryComponent",
  data() {
    return {
      
    };
  },
  created() {
    // More code here
  },
  methods: {
    async fetchOrders() {
      try {
        const response = await axios.get('/json/orders');
        this.pages = response.data;
      } catch (error) {
        console.error("Failed to load menu data:", error);
      }
    },
  },
};
</script>



