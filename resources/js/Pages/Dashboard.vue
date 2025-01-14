<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
        </template>

	<div class="menu-container">
	  <div v-for="page in pages" :key="page.id" class="menu-page">
	    <h2 class="menu-title">{{ page.menu_title }}</h2>
	    <p class="menu-header">{{ page.header_text }}</p>
	    <div class="menu-items">
	      <div 
	        v-for="item in page.menu_items" 
	        :key="item.id" 
	        class="menu-item"
	        @click="navigate(item.link_url)"
	      >
	        <img :src="item.graphic_url" :alt="item.link_text" class="menu-icon" />
	        <span class="menu-text">{{ item.link_text }}</span>
	      </div>
	    </div>
	  </div>
	</div>
    </AuthenticatedLayout>
</template>


<script>
import axios from 'axios';

export default {
  name: "MenuComponent",
  data() {
    return {
      pages: [],
    };
  },
  created() {
    this.fetchMenuData();
  },
  methods: {
    async fetchMenuData() {
      try {
        const response = await axios.get('/json/menu-data');
        this.pages = response.data;
      } catch (error) {
        console.error("Failed to load menu data:", error);
      }
    },
    navigate(url) {
      window.location.href = url;
    },
  },
};
</script>

<style scoped>
.menu-container {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  padding: 20px;
}
.menu-page {
  flex: 1 1 calc(50% - 40px);
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 20px;
  background-color: #f9f9f9;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}
.menu-title {
  font-size: 1.5rem;
  margin-bottom: 10px;
}
.menu-header {
  font-size: 1rem;
  color: #555;
  margin-bottom: 20px;
}
.menu-items {
  display: flex;
  flex-wrap: wrap;
  gap: 15px;
}
.menu-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  cursor: pointer;
  text-align: center;
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 8px;
  background-color: #fff;
  width: 120px;
  transition: transform 0.2s, box-shadow 0.2s;
}
.menu-item:hover {
  transform: translateY(-5px);
  box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
}
.menu-icon {
  width: 64px;
  height: 64px;
  margin-bottom: 10px;
}
.menu-text {
  font-size: 0.9rem;
  font-weight: bold;
  color: #333;
}

@media (max-width: 768px) {
  .menu-page {
    flex: 1 1 100%;
  }
}
</style>

