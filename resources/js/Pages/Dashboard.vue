<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import MenuComponent from '@/Components/MenuComponent.vue';
import OrderEntry from '@/Components/OrderEntry.vue';
import DonationEntry from '@/Components/DonationEntry.vue';

</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
        </template>

	<MenuComponent @navigate="handleNavigation" />

        <main>
          <component :is="currentComponent" />
        </main>
    </AuthenticatedLayout>
</template>

<script>

export default {
  name: 'Dashboard',
  components: {
    MenuComponent,
    OrderEntry,
    DonationEntry,
  },
  data() {
    return {
      currentComponent: null,
    };
  },
  methods: {
    handleNavigation(linkUrl) {
      // Map URLs to component names
      const routeMap = {
        '/order-entry': 'OrderEntry',
        '/donation-entry': 'DonationEntry',
        '/setup/statuses': 'EditStatuses',
        '/setup/items': 'EditItems',
        '/setup/users': 'EditUsers',
      };

      const componentName = routeMap[linkUrl];
      if (componentName && this.$options.components[componentName]) {
        this.currentComponent = componentName;
      } else {
        console.error(`No component found for URL: ${linkUrl}`);
      }
    },
  },
};

</script>
