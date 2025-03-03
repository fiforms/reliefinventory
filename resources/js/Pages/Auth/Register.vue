<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { onMounted } from 'vue';

defineProps({
	turnstile_site_key: {
	    type: String,
	    required: true,
	},
});

const form = useForm({
	first_name: '',
	last_name: '',
    email: '',
    password: '',
    password_confirmation: '',
	'cf-turnstile-response': '', // Add Turnstile response field
});

const submit = () => {
	// Get the Turnstile response token
	const turnstileResponse = document.querySelector("[name='cf-turnstile-response']")?.value;

	if (!turnstileResponse) {
	    alert("Please complete the CAPTCHA verification.");
	    return;
	}

	// Add Turnstile token to the form
	form['cf-turnstile-response'] = turnstileResponse;

	// Submit the form
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

// Load Cloudflare Turnstile script on mount
onMounted(() => {
    const script = document.createElement("script");
    script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js";
    script.async = true;
    script.defer = true;
    document.head.appendChild(script);
});
</script>

<template>
    <GuestLayout>
        <Head title="Register" />

        <form @submit.prevent="submit">
			<div>
			    <InputLabel for="first_name" value="First Name" />

			    <TextInput
			        id="first_name"
			        type="text"
			        class="mt-1 block w-full"
			        v-model="form.first_name"
			        required
			        autofocus
			        autocomplete="first_name"
			    />

			    <InputError class="mt-2" :message="form.errors.first_name" />
			</div>
			<div>
			    <InputLabel for="last_name" value="Last Name" />

			    <TextInput
			        id="last_name"
			        type="text"
			        class="mt-1 block w-full"
			        v-model="form.last_name"
			        required
			        autofocus
			        autocomplete="last_name"
			    />

			    <InputError class="mt-2" :message="form.errors.last_name" />
			</div>

            <div class="mt-4">
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="Password" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4">
                <InputLabel
                    for="password_confirmation"
                    value="Confirm Password"
                />

                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />

                <InputError
                    class="mt-2"
                    :message="form.errors.password_confirmation"
                />
            </div>

			<!-- Cloudflare Turnstile -->
			<div class="" style="margin-top: 1em;">

				<div class="cf-turnstile" :data-sitekey="turnstile_site_key"></div>
			</div>
			
            <div class="mt-4 flex items-center justify-end">
                <Link
                    :href="route('login')"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Already registered?
                </Link>

                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Register
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
