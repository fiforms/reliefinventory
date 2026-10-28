<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Join.vue

	Trainee self sign-up (TraineeSignupController), opened on people's own phones from a QR code
	during the project overview. Public, and only in a training warehouse. Name is all that's
	required; everything else is optional follow-up. Never logs anyone in. Turnstile renders
	automatically from its class when enabled, the same way PublicForm.vue does.
-->

<script setup>
import { Head } from '@inertiajs/vue3';
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
	open: { type: Boolean, default: false },
	turnstile_enabled: { type: Boolean, default: false },
	turnstile_site_key: { type: String, default: null },
	turnstile_unavailable: { type: Boolean, default: false },
});

const blank = () => ({
	first_name: '', last_name: '', email: '', phone: '',
	wants_updates: false, help_volunteer: false, help_software: false, help_organization: false, help_other: false,
	recommend: '', comments: '',
});
const form = reactive(blank());
const errors = ref({});
const message = ref(null);
const submitting = ref(false);
const done = ref(null);

const helpOptions = [
	['help_volunteer', 'Volunteer at a warehouse'],
	['help_software', 'Help with the software or IT'],
	['help_organization', 'Bring this to my organization'],
	['help_other', 'Some other way'],
];

onMounted(() => {
	if (!props.turnstile_enabled) return;
	const script = document.createElement('script');
	script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
	script.async = true;
	document.head.appendChild(script);
});

async function submit() {
	errors.value = {};
	message.value = null;
	submitting.value = true;
	const payload = { ...form };
	for (const key of ['email', 'phone', 'recommend', 'comments']) {
		if (!payload[key]) payload[key] = null;
	}
	if (props.turnstile_enabled) {
		payload['cf-turnstile-response'] = document.querySelector("[name='cf-turnstile-response']")?.value || '';
	}
	try {
		const { data } = await axios.post('/join', payload);
		done.value = data.first_name;
	} catch (e) {
		errors.value = e.response?.data?.errors || {};
		message.value = e.response?.status === 422
			? 'Please check the highlighted fields.'
			: (e.response?.data?.message || 'Something went wrong — please try again.');
		if (props.turnstile_enabled && window.turnstile) window.turnstile.reset();
	} finally {
		submitting.value = false;
	}
}

function again() {
	Object.assign(form, blank());
	done.value = null;
	if (props.turnstile_enabled && window.turnstile) window.turnstile.reset();
}
</script>

<template>
	<Head title="Sign Up for Training" />
	<div class="min-h-screen bg-gray-100 py-6 px-4">
		<div class="max-w-md mx-auto bg-white shadow rounded-lg p-5 space-y-5">
			<div class="text-center">
				<h1 class="text-2xl font-bold text-gray-800">Sign up for training</h1>
			</div>

			<p v-if="!open" class="text-center text-gray-600">Sign-up is closed right now.</p>

			<!-- ======================= THANK YOU ======================= -->
			<div v-else-if="done" class="text-center space-y-4">
				<p class="text-lg text-gray-800">Thank you, {{ done }}! You're signed up.</p>
				<button type="button" class="text-sm text-gray-500 underline" @click="again">Sign up someone else on this phone</button>
			</div>

			<!-- ======================= FORM ======================= -->
			<form v-else class="space-y-5" @submit.prevent="submit">
				<div class="grid grid-cols-2 gap-3">
					<label class="block">
						<span class="text-sm text-gray-700">First name</span>
						<input v-model="form.first_name" required autocomplete="given-name" autocapitalize="words"
							class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base" />
						<span v-if="errors.first_name" class="text-xs text-red-600">{{ errors.first_name[0] }}</span>
					</label>
					<label class="block">
						<span class="text-sm text-gray-700">Last name</span>
						<input v-model="form.last_name" required autocomplete="family-name" autocapitalize="words"
							class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base" />
						<span v-if="errors.last_name" class="text-xs text-red-600">{{ errors.last_name[0] }}</span>
					</label>
				</div>

				<div class="space-y-3">
					<p class="text-xs text-gray-500 uppercase font-semibold">Optional</p>
					<label class="block">
						<span class="text-sm text-gray-700">Email</span>
						<input v-model="form.email" type="email" autocomplete="email" inputmode="email"
							class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base" />
						<span v-if="errors.email" class="text-xs text-red-600">{{ errors.email[0] }}</span>
					</label>
					<label class="block">
						<span class="text-sm text-gray-700">Phone</span>
						<input v-model="form.phone" type="tel" autocomplete="tel" inputmode="tel"
							class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base" />
					</label>
					<label class="flex items-start gap-3 py-1">
						<input v-model="form.wants_updates" type="checkbox" class="mt-1 h-5 w-5 rounded border-gray-300" />
						<span class="text-sm text-gray-800">Email me updates about the project</span>
					</label>
				</div>

				<div class="space-y-2">
					<p class="text-sm font-medium text-gray-800">Would you like to get involved?</p>
					<label v-for="[key, label] in helpOptions" :key="key" class="flex items-start gap-3 py-1">
						<input v-model="form[key]" type="checkbox" class="mt-1 h-5 w-5 rounded border-gray-300" />
						<span class="text-sm text-gray-800">{{ label }}</span>
					</label>
				</div>

				<label class="block">
					<span class="text-sm font-medium text-gray-800">Know someone who could help?</span>
					<span class="block text-xs text-gray-500">Their name, and how to reach them if you know.</span>
					<input v-model="form.recommend" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base" />
				</label>

				<label class="block">
					<span class="text-sm text-gray-700">Anything else?</span>
					<textarea v-model="form.comments" rows="2" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base"></textarea>
				</label>

				<div v-if="turnstile_enabled" class="cf-turnstile" :data-sitekey="turnstile_site_key"></div>
				<p v-if="turnstile_unavailable" class="text-sm text-amber-900 bg-amber-50 border border-amber-300 rounded p-3">
					Sign-up can't accept entries right now because its spam protection isn't set up on this server.
				</p>
				<p v-if="message" class="text-sm text-red-600">{{ message }}</p>

				<button type="submit" :disabled="submitting || turnstile_unavailable"
					class="w-full py-3 bg-blue-600 text-white rounded-md font-semibold text-lg hover:bg-blue-700 disabled:opacity-50">
					{{ submitting ? 'Signing up…' : 'Sign Up' }}
				</button>
				<p class="text-xs text-gray-500 text-center">We'll only use your details to contact you about the training and the project.</p>
			</form>
		</div>
	</div>
</template>
