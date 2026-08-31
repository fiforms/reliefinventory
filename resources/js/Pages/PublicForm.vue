<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details

	PublicForm.vue

	The public/staff-facing side of a Form (/forms/{slug}) — no app layout,
	since an unauthenticated prospective partner may land here directly.
	Works identically whether the visitor is logged in (a staffer filling it
	out on someone's behalf) or not; PublicFormController enforces the
	form's access_mode server-side. Turnstile only renders for an
	unauthenticated visitor, same pattern as Register.vue/ForgotPassword.vue.
-->

<script setup>
import { ref, reactive, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
	form: { type: Object, required: true },
	turnstile_enabled: { type: Boolean, default: false },
	turnstile_site_key: { type: String, default: null },
});

const answers = reactive({});
const submitterName = ref('');
const submitterEmail = ref('');
const submitterPhone = ref('');
const submitting = ref(false);
const submitted = ref(false);
const errors = ref({});
const generalError = ref(null);

const choiceTypes = ['single_choice', 'multiple_choice'];

function toggleMultiple(questionId, option) {
	const current = answers[questionId] || [];
	answers[questionId] = current.includes(option)
		? current.filter((o) => o !== option)
		: [...current, option];
}

onMounted(() => {
	if (!props.turnstile_enabled) return;
	const script = document.createElement('script');
	script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
	script.async = true;
	document.head.appendChild(script);
});

async function submit() {
	submitting.value = true;
	errors.value = {};
	generalError.value = null;

	const payload = {
		answers,
		submitter_name: submitterName.value || null,
		submitter_email: submitterEmail.value || null,
		submitter_phone: submitterPhone.value || null,
	};

	if (props.turnstile_enabled) {
		payload['cf-turnstile-response'] = document.querySelector("[name='cf-turnstile-response']")?.value || '';
	}

	try {
		await axios.post(`/forms/${props.form.slug}`, payload);
		submitted.value = true;
	} catch (e) {
		if (e.response?.status === 422) {
			errors.value = e.response.data.errors || {};
			generalError.value = e.response.data.message || null;
		} else {
			generalError.value = 'Something went wrong. Please try again.';
		}
	} finally {
		submitting.value = false;
	}
}
</script>

<template>
	<div class="min-h-screen bg-gray-100 py-8 px-4">
		<div class="max-w-2xl mx-auto bg-white shadow rounded-lg p-6 space-y-6">
			<h1 class="text-2xl font-bold">{{ form.name }}</h1>

			<div v-if="submitted" class="text-green-700 bg-green-50 border border-green-200 rounded p-4">
				Thank you — your submission has been received.
			</div>

			<template v-else>
				<p v-if="form.intro_text" class="text-gray-700 whitespace-pre-line">{{ form.intro_text }}</p>

				<div v-if="generalError" class="text-red-700 bg-red-50 border border-red-200 rounded p-3 text-sm">
					{{ generalError }}
				</div>

				<div class="space-y-2">
					<label class="block text-sm">
						<span class="text-gray-700">Your Name</span>
						<input v-model="submitterName" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
					</label>
					<label class="block text-sm">
						<span class="text-gray-700">Email</span>
						<input v-model="submitterEmail" type="email" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
					</label>
					<label class="block text-sm">
						<span class="text-gray-700">Phone</span>
						<input v-model="submitterPhone" type="tel" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
					</label>
				</div>

				<div v-for="question in form.questions" :key="question.id" class="space-y-1">
					<h2 v-if="question.type === 'section_header'" class="text-lg font-semibold border-b pb-1 pt-2">
						{{ question.label }}
					</h2>
					<template v-else>
						<label class="block text-sm font-medium text-gray-700">
							{{ question.label }}<span v-if="question.required" class="text-red-600"> *</span>
						</label>
						<p v-if="question.help_text" class="text-xs text-gray-500">{{ question.help_text }}</p>

						<textarea
							v-if="question.type === 'long_text'"
							v-model="answers[question.id]"
							rows="3"
							class="block w-full border-gray-300 rounded-md shadow-sm"
						></textarea>

						<input
							v-else-if="question.type === 'number'"
							v-model="answers[question.id]"
							type="number"
							class="block w-full border-gray-300 rounded-md shadow-sm"
						/>

						<input
							v-else-if="question.type === 'date'"
							v-model="answers[question.id]"
							type="date"
							class="block w-full border-gray-300 rounded-md shadow-sm"
						/>

						<div v-else-if="question.type === 'yes_no'" class="flex gap-4">
							<label class="flex items-center gap-1 text-sm">
								<input type="radio" :name="`q${question.id}`" v-model="answers[question.id]" value="Yes" /> Yes
							</label>
							<label class="flex items-center gap-1 text-sm">
								<input type="radio" :name="`q${question.id}`" v-model="answers[question.id]" value="No" /> No
							</label>
						</div>

						<div v-else-if="question.type === 'single_choice'" class="space-y-1">
							<label v-for="option in question.options" :key="option" class="flex items-center gap-1 text-sm">
								<input type="radio" :name="`q${question.id}`" v-model="answers[question.id]" :value="option" /> {{ option }}
							</label>
						</div>

						<div v-else-if="question.type === 'multiple_choice'" class="space-y-1">
							<label v-for="option in question.options" :key="option" class="flex items-center gap-1 text-sm">
								<input
									type="checkbox"
									:checked="(answers[question.id] || []).includes(option)"
									@change="toggleMultiple(question.id, option)"
								/>
								{{ option }}
							</label>
						</div>

						<input
							v-else
							v-model="answers[question.id]"
							type="text"
							class="block w-full border-gray-300 rounded-md shadow-sm"
						/>

						<div v-if="errors[`answers.${question.id}`]" class="text-red-700 text-xs">
							{{ errors[`answers.${question.id}`][0] }}
						</div>
					</template>
				</div>

				<div v-if="turnstile_enabled" class="cf-turnstile" :data-sitekey="turnstile_site_key"></div>

				<button
					@click="submit"
					:disabled="submitting"
					class="w-full bg-indigo-600 text-white rounded-md py-2 font-medium disabled:opacity-50"
				>
					{{ submitting ? 'Submitting...' : 'Submit' }}
				</button>
			</template>
		</div>
	</div>
</template>
