<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!--
	Activate.vue — what a new warehouse shows while it waits for its setup code (/activate,
	WarehouseSetupController, MULTI_WAREHOUSE.md §7). Two steps: the code the instance
	administrator handed over, then the person who will run the warehouse (a new login, or an
	existing one proved with its password). Like FirstRun.vue it is one short write at the end.
-->

<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import RestoreBackup from '@/Pages/Setup/RestoreBackup.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
	warehouseName: { type: String, default: '' },
	claimed: { type: Boolean, default: false },
	locked: { type: Boolean, default: false },
});

// 'fresh' (set the warehouse up empty) or 'restore' (load a backup into it)
const mode = ref('fresh');
const codeForm = useForm({ code: '' });
const form = useForm({
	login: 'new',
	first_name: '',
	last_name: '',
	email: '',
	password: '',
	password_confirmation: '',
	example_data: false,
});

// The person who will run the warehouse, sent along when a restore is launched (the restore replaces the database, which holds the
// session, so the server settles who they are first).
const identity = computed(() => ({
	login: form.login,
	first_name: form.first_name,
	last_name: form.last_name,
	email: form.email,
	password: form.password,
	password_confirmation: form.password_confirmation,
}));
const identityReady = computed(() =>
	form.first_name.trim() && form.last_name.trim() && /^\S+@\S+\.\S+$/.test(form.email) && form.password
	&& (form.login === 'existing' || form.password === form.password_confirmation),
);

const submitCode = () => codeForm.post(route('activate.code'), { onFinish: () => codeForm.reset() });
const submit = () => form.post(route('activate.setup'), { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
	<GuestLayout>
		<Head :title="`Set up ${warehouseName}`" />

		<h1 class="text-lg font-semibold text-gray-900">{{ warehouseName }}</h1>

		<form v-if="!claimed" @submit.prevent="submitCode">
			<p class="mt-1 text-sm text-gray-600">
				This warehouse is ready to be set up. Enter the setup code you were given.
			</p>
			<div class="mt-4">
				<InputLabel for="code" value="Setup code" />
				<TextInput id="code" type="text" class="mt-1 block w-full font-mono uppercase" v-model="codeForm.code" autofocus autocomplete="off" placeholder="ABCDE-FGHJK" />
				<InputError class="mt-2" :message="codeForm.errors.code" />
				<p v-if="locked && !codeForm.errors.code" class="mt-2 text-xs text-amber-800">Too many wrong tries; this screen is paused for a few minutes.</p>
			</div>
			<div class="mt-6 flex justify-end">
				<PrimaryButton :class="{ 'opacity-25': codeForm.processing }" :disabled="codeForm.processing">Continue</PrimaryButton>
			</div>
		</form>

		<form v-else @submit.prevent="mode === 'fresh' ? submit() : null">
			<p class="mt-1 text-sm text-gray-600">
				Code accepted. Who will run this warehouse? They become its Administrator and add everyone else from inside the app.
			</p>

			<div class="mt-3 flex gap-4 text-sm">
				<label class="flex items-center gap-2"><input type="radio" value="fresh" v-model="mode" /> Start empty</label>
				<label class="flex items-center gap-2"><input type="radio" value="restore" v-model="mode" /> Restore a backup into it</label>
			</div>

			<div class="mt-4 flex gap-4 text-sm">
				<label class="flex items-center gap-2"><input type="radio" value="new" v-model="form.login" /> I need a new login</label>
				<label class="flex items-center gap-2"><input type="radio" value="existing" v-model="form.login" /> I already have a login</label>
			</div>

			<div class="mt-4 grid grid-cols-2 gap-3">
				<div>
					<InputLabel for="first_name" value="First name" />
					<TextInput id="first_name" type="text" class="mt-1 block w-full" v-model="form.first_name" autofocus autocomplete="given-name" />
					<InputError class="mt-2" :message="form.errors.first_name" />
				</div>
				<div>
					<InputLabel for="last_name" value="Last name" />
					<TextInput id="last_name" type="text" class="mt-1 block w-full" v-model="form.last_name" autocomplete="family-name" />
					<InputError class="mt-2" :message="form.errors.last_name" />
				</div>
			</div>

			<div class="mt-4">
				<InputLabel for="email" value="Email (used to sign in)" />
				<TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" autocomplete="username" />
				<InputError class="mt-2" :message="form.errors.email" />
			</div>

			<div class="mt-4">
				<InputLabel for="password" :value="form.login === 'existing' ? 'Your password' : 'Password'" />
				<TextInput id="password" type="password" class="mt-1 block w-full" v-model="form.password" :autocomplete="form.login === 'existing' ? 'current-password' : 'new-password'" />
				<InputError class="mt-2" :message="form.errors.password" />
			</div>

			<div v-if="form.login === 'new'" class="mt-4">
				<InputLabel for="password_confirmation" value="Confirm password" />
				<TextInput id="password_confirmation" type="password" class="mt-1 block w-full" v-model="form.password_confirmation" autocomplete="new-password" />
			</div>

			<label v-if="mode === 'fresh'" class="mt-4 flex items-start gap-2 text-sm text-gray-700">
				<input type="checkbox" class="mt-1" v-model="form.example_data" />
				<span>Load example data (fictional donors, partners, stock and requests, for trying things out; it can't be removed later)</span>
			</label>

			<InputError class="mt-2" :message="form.errors.code" />

			<div v-if="mode === 'fresh'" class="mt-6 flex justify-end">
				<PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
					{{ form.processing ? 'Setting up…' : 'Finish setup' }}
				</PrimaryButton>
			</div>

			<div v-else class="mt-6">
				<p v-if="!identityReady" class="text-sm text-amber-800">Fill in who will run this warehouse above, then choose the backup.</p>
				<RestoreBackup v-else base="/activate/restore" :identity="identity" @back="mode = 'fresh'" />
			</div>
		</form>
	</GuestLayout>
</template>
