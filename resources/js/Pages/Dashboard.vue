<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
<template>
   <Head title="Dashboard" />
   <AuthenticatedLayout :breadcrumb="breadcrumb">
     <template #header></template>

     <div v-if="currentPage" class="menu-container">
       <div class="menu-page">
         <h2 class="menu-title">{{ currentPage.menu_title }}</h2>
         <p class="menu-header">{{ currentPage.header_text }}</p>
         <div class="menu-items">
           <div
             v-for="item in currentPage.menu_items"
             :key="item.id"
             class="menu-item"
             @click="navigate(item.link_url,false)"
			 @contextmenu="navigate(item.link_url,true)"
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
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

import axios from 'axios';

export default {
  name: "MenuComponent",
  components: {
    AuthenticatedLayout,
    Head
  },
  data() {
    return {
      pages: [],
	  currentPage: null,
	  route: "",
	  breadcrumb: [],
    };
  },
  created() {
    this.fetchMenuData();
	this.route = window.location.hash;
	window.addEventListener(
	  "hashchange",
	  () => {
		    this.route = location.hash;
			this.navigate(this.route, false);
	   },
	  false,
	);
  },
  methods: {
    async fetchMenuData() {
      try {
        const response = await axios.get('/json/menu-data');
        this.pages = response.data;
		if(this.route) {
			this.navigate(this.route, false)
		}
		else {
			this.currentPage = this.pages.length ? this.pages[0] : null;
		}
      } catch (error) {
        console.error("Failed to load menu data:", error);
      }
    },
    navigate(url,newWindow) {
	  if (url.startsWith('#')) {
		if(newWindow) return false;
	    const targetPage = this.pages.find(page => page.hashtag === url.substring(1));
	    if (targetPage) {
		  this.currentPage = targetPage;
		  this.route = url;	
		  window.location.hash = url;
		  if(targetPage.id == 1) {
			  this.breadcrumb = [];
		  }
		  else {
			  this.breadcrumb = [{href: '/dashboard#' + targetPage.hashtag, title: targetPage.menu_title}];
		  }
 	    }
	  } else {
	    if(newWindow) {
			window.open(url,'_blank');
		}
		else {
			window.location.href = url;
		}
	  }
	},
	navigateToPage(hashtag) {
	  const targetPage = this.pages.find(page => page.hashtag === hashtag);
	  if (targetPage) {
	    this.currentPage = targetPage;
	  }
	},
	},
	watch: {
	    '$route.query.page'(newPage) {
	      if (newPage) {
	        this.navigateToPage(newPage);
	      }
	  }
  },
};
</script>

<style scoped>
.menu-container {
  display: flex;
  justify-content: center; /* Centers the .menu-page container */
  padding: 20px;
}

.menu-page {
  max-width: 1024px; /* Locks maximum width */
  width: 100%; /* Ensures it scales responsively */
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 20px;
  background-color: #f9f9f9;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
  text-align: center; /* Centers the text elements inside */
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
  justify-content: center; /* Ensures icons are evenly spaced and centered */
  gap: 20px; /* Increases spacing between items */
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

