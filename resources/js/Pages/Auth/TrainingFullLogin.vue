<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details

	Shown by CheckPermission when a Training Mode (tap-your-name) login opens a page outside the
	station work it's capped to (App\Services\TrainingMode::TRAINING_KEYS). Offers the full
	logins, which end the training session first (POST /training-login/exit).
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
	path: { type: String, default: '' },
});
</script>

<template>
	<Head title="Full Login Needed" />
	<AuthenticatedLayout>
		<template #header></template>

		<div class="max-w-xl mx-auto p-6 mt-6 bg-white shadow rounded-lg space-y-4">
			<h1 class="text-xl font-bold">This needs a full login</h1>
			<p class="text-gray-700">
				You're logged in with a training login, which can only do station work (receiving,
				sorting, inventory, picking, shipping and order entry). <span v-if="path"><code>{{ path }}</code></span>
				needs a staff member's full login.
			</p>
			<p class="text-sm text-gray-600">Logging in as staff ends this training login on this device.</p>
			<div class="flex flex-wrap gap-3">
				<Link :href="route('training-login.exit')" method="post" as="button" :data="{ to: 'pin' }"
					class="px-4 py-2 bg-gray-800 text-white rounded-md text-sm font-semibold hover:bg-gray-700">
					Staff login with PIN
				</Link>
				<Link :href="route('training-login.exit')" method="post" as="button" :data="{ to: 'email' }"
					class="px-4 py-2 bg-gray-800 text-white rounded-md text-sm font-semibold hover:bg-gray-700">
					Staff login with email
				</Link>
				<Link href="/dashboard" class="px-4 py-2 border rounded-md text-sm">Back to the menu</Link>
			</div>
		</div>
	</AuthenticatedLayout>
</template>
