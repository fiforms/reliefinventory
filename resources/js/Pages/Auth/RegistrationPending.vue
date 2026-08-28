<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
	requested_track: {
		type: String,
		default: null,
	},
});

const trackMessages = {
	volunteer: 'Your volunteer sign-up is pending review. A staff member will follow up once your account is approved.',
	donor: 'Your donation offer is pending review. A staff member will follow up once your account is approved.',
	partner: 'Your request to receive supplies is pending review. A staff member will follow up once your account is approved.',
};

const message = computed(() => trackMessages[props.requested_track] || 'Your account is pending review. A staff member will follow up once it\'s approved.');
</script>

<template>
	<GuestLayout>
		<Head title="Registration Received" />

		<div class="mb-4 text-lg font-medium text-gray-900">
			Thanks for registering!
		</div>

		<div class="mb-4 text-sm text-gray-600">
			Your email address has been verified. {{ message }}
		</div>

		<div class="mt-4 flex items-center justify-between">
			<Link
				:href="route('logout')"
				method="post"
				as="button"
				class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
				>Log Out</Link
			>
		</div>
	</GuestLayout>
</template>
