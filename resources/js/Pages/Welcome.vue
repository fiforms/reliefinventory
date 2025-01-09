<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    hostedBy: {
        type: String,
    },
    hostedLink: {
        type: String,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
});

function handleImageError() {
    document.getElementById('screenshot-container')?.classList.add('!hidden');
    document.getElementById('docs-card')?.classList.add('!row-span-1');
    document.getElementById('docs-card-content')?.classList.add('!flex-row');
    document.getElementById('background')?.classList.add('!hidden');
}
</script>

<template>
  <div class="min-h-screen bg-gray-50 flex flex-col">
    <!-- Header -->
    <header class="bg-white shadow-md">
      <div class="container mx-auto px-4 py-4 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-blue-600">Relief Inventory</h1>
        <nav class="space-x-4">
          <a href="/login" class="text-gray-700 hover:text-blue-600">Login</a>
	  <a :href="hostedLink" class="text-gray-700 hover:text-blue-600" target="blank">{{ hostedBy }} -&gt;</a>
        </nav>
      </div>
    </header>

    <!-- Hero Section -->
    <section class="flex-1 bg-blue-100">
      <div class="container mx-auto px-4 py-16 text-center md:text-left md:flex md:items-center">
        <div class="md:w-1/2">
          <h2 class="text-4xl md:text-5xl font-bold text-gray-800 mb-6">
            Welcome to Relief Inventory
          </h2>
          <p class="text-gray-600 text-lg mb-8">
            Manage and track donations efficiently to help communities in need.
          </p>
          <div class="space-x-4">
	    <a href="/login">
	      <button class="bg-blue-600 text-white px-6 py-3 rounded-lg shadow hover:bg-blue-700">
                Login
              </button>
	    </a>
	    <link v-if="canRegister" href="/register">
              <button class="bg-gray-300 text-gray-800 px-6 py-3 rounded-lg shadow hover:bg-gray-400">
                Register
              </button>
	    </link>
          </div>
        </div>
        <div class="mt-8 md:mt-0 md:w-1/2 flex justify-center">
          <img src="/img/warehouse.webp" alt="Welcome Illustration" class="w-3/4 md:w-full max-w-md">
        </div>
      </div>
    </section>

    <!-- Footer -->
    <footer class="bg-white border-t">
      <div class="container mx-auto px-4 py-6 text-center text-gray-600">
	      &copy;  {{ new Date().getFullYear() }} Inventory Relief Project, 
	      <a href="https://www.gnu.org/licenses/gpl-3.0.en.html">Licensed under the GNU General Public License v. 3</a>
	      Hosted by <a :href="hostedLink" target="_blank">{{ hostedBy }}</a>
      </div>
    </footer>
  </div>
</template>

<script>
export default {
  name: "Welcome",
};
</script>

<style>
/* Add custom styles if necessary */
</style>

