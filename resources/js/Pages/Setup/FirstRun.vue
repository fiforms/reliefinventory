<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!--
	FirstRun.vue — the first-run setup wizard at /install (SetupController).

	Only reachable while the instance has no administrator and the setup latch is
	unset (FirstRunService::needsSetup); RedirectToSetup sends every other page
	here until it's done. Two client-side steps, one POST at the end — unlike the
	long-running scan/entry screens this is a single short write, so there is no
	per-step autosave: nothing exists server-side until "Finish setup".
-->

<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import RestoreBackup from './RestoreBackup.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
	codeRequired: { type: Boolean, default: false },
});

// 'new' (the two-step wizard) or 'restore' (upload a backup instead)
const mode = ref('new');
const step = ref(1);
const form = useForm({
	first_name: '',
	last_name: '',
	email: '',
	password: '',
	password_confirmation: '',
	setup_code: '',
	// 'empty' | 'example' | 'simulation'
	starting_data: 'empty',
});

// Client-side check only to keep people from advancing with an obviously
// incomplete step; the server validates everything again on submit.
const localErrors = ref({});
const accountFields = ['first_name', 'last_name', 'email', 'password', 'password_confirmation', 'setup_code'];

function validateAccount() {
	const e = {};
	if (!form.first_name.trim()) e.first_name = 'Please enter a first name.';
	if (!form.last_name.trim()) e.last_name = 'Please enter a last name.';
	if (!/^\S+@\S+\.\S+$/.test(form.email)) e.email = 'Please enter a valid email address.';
	if (form.password.length < 8) e.password = 'Use at least 8 characters.';
	if (form.password !== form.password_confirmation) e.password_confirmation = 'The passwords do not match.';
	if (props.codeRequired && !form.setup_code.trim()) e.setup_code = 'Enter the setup code from your server configuration.';
	localErrors.value = e;
	return Object.keys(e).length === 0;
}

function next() {
	form.clearErrors();
	if (validateAccount()) step.value = 2;
}

const err = (field) => localErrors.value[field] || form.errors[field];

const submit = () => {
	form.post(route('setup.store'), {
		onError: (errors) => {
			// Server-side complaints about step 1 fields send you back to fix them.
			if (accountFields.some((f) => errors[f])) step.value = 1;
		},
		onFinish: () => form.reset('password', 'password_confirmation'),
	});
};

const stepTitle = computed(() => (step.value === 1 ? 'Create the administrator account' : 'Starting data'));
</script>

<template>
	<GuestLayout>
		<Head title="Set up Relief Inventory" />

		<h1 class="text-lg font-semibold text-gray-900">Welcome to Relief Inventory</h1>
		<template v-if="mode === 'restore'">
			<p class="mt-1 text-sm text-gray-600">Restore from a backup</p>
			<RestoreBackup :code-required="codeRequired" @back="mode = 'new'" />
		</template>

		<template v-else>
		<p class="mt-1 text-sm text-gray-600">
			This is a brand-new installation. Two quick steps and you're in.
			Moving from another system?
			<button type="button" class="underline text-blue-700" @click="mode = 'restore'">Restore from a backup</button>
		</p>

		<p class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">
			Step {{ step }} of 2 &middot; {{ stepTitle }}
		</p>

		<form @submit.prevent="submit">
			<!-- Step 1: administrator -->
			<div v-show="step === 1" class="mt-3">
				<p class="text-sm text-gray-600">
					This person manages everything else — other users, permissions and settings are all
					added from inside the app afterwards.
				</p>

				<div class="mt-4 grid grid-cols-2 gap-3">
					<div>
						<InputLabel for="first_name" value="First name" />
						<TextInput id="first_name" type="text" class="mt-1 block w-full" v-model="form.first_name" autofocus autocomplete="given-name" />
						<InputError class="mt-2" :message="err('first_name')" />
					</div>
					<div>
						<InputLabel for="last_name" value="Last name" />
						<TextInput id="last_name" type="text" class="mt-1 block w-full" v-model="form.last_name" autocomplete="family-name" />
						<InputError class="mt-2" :message="err('last_name')" />
					</div>
				</div>

				<div class="mt-4">
					<InputLabel for="email" value="Email (used to sign in)" />
					<TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" autocomplete="username" />
					<InputError class="mt-2" :message="err('email')" />
				</div>

				<div class="mt-4">
					<InputLabel for="password" value="Password" />
					<TextInput id="password" type="password" class="mt-1 block w-full" v-model="form.password" autocomplete="new-password" />
					<InputError class="mt-2" :message="err('password')" />
				</div>

				<div class="mt-4">
					<InputLabel for="password_confirmation" value="Confirm password" />
					<TextInput id="password_confirmation" type="password" class="mt-1 block w-full" v-model="form.password_confirmation" autocomplete="new-password" />
					<InputError class="mt-2" :message="err('password_confirmation')" />
				</div>

				<div v-if="codeRequired" class="mt-4">
					<InputLabel for="setup_code" value="Setup code" />
					<TextInput id="setup_code" type="password" class="mt-1 block w-full" v-model="form.setup_code" autocomplete="off" />
					<p class="mt-1 text-xs text-gray-500">This server needs a setup code. On an R Station it is shown on the device under Settings &gt; Local server; with Docker it is RI_SETUP_CODE in your compose file.</p>
					<InputError class="mt-2" :message="err('setup_code')" />
				</div>

				<div class="mt-6 flex justify-end">
					<PrimaryButton type="button" @click="next">Next</PrimaryButton>
				</div>
			</div>

			<!-- Step 2: starting data -->
			<div v-show="step === 2" class="mt-3">
				<p class="text-sm text-gray-600">
					The standard categories, units and item catalog are always set up for you. Choose whether
					to start with a clean slate or with sample data.
				</p>

				<label class="mt-4 flex cursor-pointer items-start gap-3 rounded border p-3" :class="form.starting_data === 'empty' ? 'border-blue-500 bg-blue-50' : 'border-gray-200'">
					<input type="radio" class="mt-1" value="empty" v-model="form.starting_data" />
					<span>
						<span class="block text-sm font-medium text-gray-900">Start empty <span class="text-xs font-normal text-blue-700">(recommended for real use)</span></span>
						<span class="block text-xs text-gray-600">Nothing but the standard catalog. You enter your own donors, partners and stock.</span>
					</span>
				</label>

				<label class="mt-3 flex cursor-pointer items-start gap-3 rounded border p-3" :class="form.starting_data === 'example' ? 'border-blue-500 bg-blue-50' : 'border-gray-200'">
					<input type="radio" class="mt-1" value="example" v-model="form.starting_data" />
					<span>
						<span class="block text-sm font-medium text-gray-900">Load example data</span>
						<span class="block text-xs text-gray-600">
							Fictional donors and partner organizations, donations at every stage, stock on hand and
							orders in every status — good for trying things out or training.
						</span>
					</span>
				</label>

				<label class="mt-3 flex cursor-pointer items-start gap-3 rounded border p-3" :class="form.starting_data === 'simulation' ? 'border-blue-500 bg-blue-50' : 'border-gray-200'">
					<input type="radio" class="mt-1" value="simulation" v-model="form.starting_data" />
					<span>
						<span class="block text-sm font-medium text-gray-900">Simulate a year of activity <span class="text-xs font-normal text-gray-600">(for testing)</span></span>
						<span class="block text-xs text-gray-600">
							Generates about a year of fictional donations, sorting, partner requests, shipments and stock
							movement &mdash; roughly 1,000 requests and 10,000+ inventory movements. Meant for testing screens,
							reports and performance with a realistic amount of data. Takes a minute or two.
						</span>
					</span>
				</label>

				<p v-if="form.starting_data !== 'empty'" class="mt-3 rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900">
					Example and simulated data can't be removed from inside the app. If you later want a clean instance,
					you'll need to start over with a fresh database. Don't use this for a real operation.
				</p>

				<InputError class="mt-2" :message="form.errors.starting_data" />

				<div class="mt-6 flex items-center justify-between">
					<SecondaryButton type="button" @click="step = 1" :disabled="form.processing">Back</SecondaryButton>
					<PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
						{{ form.processing ? (form.starting_data === 'example' ? 'Setting up (loading examples…)' : 'Setting up…') : 'Finish setup' }}
					</PrimaryButton>
				</div>
			</div>
		</form>
		</template>
	</GuestLayout>
</template>
