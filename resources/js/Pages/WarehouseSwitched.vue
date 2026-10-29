<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details

	Where GuardWarehouseTab sends a tab that tried to act on a warehouse the browser no longer uses (another tab
	switched warehouses). Nothing was changed; the visitor continues in the warehouse the browser is on now.
-->

<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const warehouse = usePage().props.warehouse;
</script>

<template>
	<Head title="Warehouse Changed" />
	<GuestLayout>
		<div class="space-y-4">
			<h1 class="text-lg font-bold">This browser switched warehouses</h1>
			<p class="text-gray-700">
				The page you were using belonged to a different warehouse than the one this browser is using now,
				probably because another tab switched warehouses. Nothing was changed.
			</p>
			<p class="text-gray-700">
				This browser is now using <strong>{{ warehouse?.name }}</strong>. Warehouses on the same address share
				one sign-in at a time, so use one warehouse per browser (or a private window for the other one).
			</p>
			<Link href="/dashboard" class="inline-block px-4 py-2 bg-gray-800 text-white rounded">
				Continue in {{ warehouse?.name }}
			</Link>
		</div>
	</GuestLayout>
</template>
