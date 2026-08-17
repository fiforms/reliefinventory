<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
	hasPin: { type: Boolean, default: false },
});

const pinInput = ref(null);
const currentPasswordInput = ref(null);

const form = useForm({
	current_password: '',
	pin: '',
	pin_confirmation: '',
});

const removeForm = useForm({
	current_password: '',
});
const removing = ref(false);

const updatePin = () => {
	form.put(route('pin.update'), {
		preserveScroll: true,
		onSuccess: () => form.reset(),
		onError: () => {
			if (form.errors.pin) {
				form.reset('pin', 'pin_confirmation');
				pinInput.value.focus();
			}
			if (form.errors.current_password) {
				form.reset('current_password');
				currentPasswordInput.value.focus();
			}
		},
	});
};

const removePin = () => {
	if (!removing.value) {
		removing.value = true;
		return;
	}
	removeForm.delete(route('pin.destroy'), {
		preserveScroll: true,
		onSuccess: () => { removeForm.reset(); removing.value = false; },
	});
};
</script>

<template>
	<section>
		<header>
			<h2 class="text-lg font-medium text-gray-900">
				Quick-Unlock PIN
			</h2>

			<p class="mt-1 text-sm text-gray-600">
				A 5-digit PIN for faster re-login on a shared warehouse terminal your administrator has
				approved for PIN unlock. It never works on a device that hasn't been approved, and never
				replaces your real password anywhere else. It can't repeat the same digit more than
				twice in a row, or contain more than 3 sequential digits in a row (like 1234 or 4321).
			</p>
		</header>

		<form @submit.prevent="updatePin" class="mt-6 space-y-6">
			<div>
				<InputLabel for="pin_current_password" value="Current Password" />

				<TextInput
					id="pin_current_password"
					ref="currentPasswordInput"
					v-model="form.current_password"
					type="password"
					class="mt-1 block w-full"
					autocomplete="current-password"
				/>

				<InputError :message="form.errors.current_password" class="mt-2" />
			</div>

			<div>
				<InputLabel for="pin" :value="hasPin ? 'New PIN (5 digits)' : 'PIN (5 digits)'" />

				<TextInput
					id="pin"
					ref="pinInput"
					v-model="form.pin"
					type="password"
					inputmode="numeric"
					maxlength="5"
					class="mt-1 block w-full"
					autocomplete="off"
				/>

				<InputError :message="form.errors.pin" class="mt-2" />
			</div>

			<div>
				<InputLabel for="pin_confirmation" value="Confirm PIN" />

				<TextInput
					id="pin_confirmation"
					v-model="form.pin_confirmation"
					type="password"
					inputmode="numeric"
					maxlength="5"
					class="mt-1 block w-full"
					autocomplete="off"
				/>

				<InputError :message="form.errors.pin_confirmation" class="mt-2" />
			</div>

			<div class="flex items-center gap-4">
				<PrimaryButton :disabled="form.processing">{{ hasPin ? 'Update PIN' : 'Set PIN' }}</PrimaryButton>

				<Transition
					enter-active-class="transition ease-in-out"
					leave-active-class="transition ease-in-out"
					leave-to-class="opacity-0"
				>
					<p v-if="form.recentlySuccessful" class="text-sm text-gray-600">Saved.</p>
				</Transition>
			</div>
		</form>

		<div v-if="hasPin" class="mt-6 pt-6 border-t">
			<InputLabel for="pin_remove_password" value="Remove PIN (requires current password)" />
			<div class="flex items-end gap-3 mt-1">
				<TextInput
					id="pin_remove_password"
					v-model="removeForm.current_password"
					type="password"
					class="block w-full"
					autocomplete="current-password"
				/>
				<button
					type="button"
					@click="removePin"
					class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white whitespace-nowrap"
					:class="removing ? 'bg-red-600 hover:bg-red-500' : 'bg-gray-500 hover:bg-gray-400'"
				>
					{{ removing ? 'Confirm Remove' : 'Remove PIN' }}
				</button>
			</div>
			<InputError :message="removeForm.errors.current_password" class="mt-2" />
		</div>
	</section>
</template>
