<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
	breadcrumb: { type: Array },
	feature: { type: String, required: true },
	note: { type: String, default: '' },
});
</script>

<template>
	<Head :title="feature" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="comingsoon_container">
			<h2 class="ri_datatable_head">{{ feature }}</h2>
			<p class="comingsoon_body">
				This page is on the roadmap and not built yet.
				<span v-if="note">{{ note }}</span>
			</p>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.comingsoon_container {
	max-width: 640px;
	margin: 2em auto;
	padding: 16px;
	text-align: center;
}
.comingsoon_body {
	color: #555;
	margin-top: 0.5em;
}
</style>
