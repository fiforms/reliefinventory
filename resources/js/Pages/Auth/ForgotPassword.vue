<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { onMounted } from 'vue';

defineProps({
    status: {
        type: String,
    },
	turnstile_site_key: {
	    type: String,
	    required: true,
	},
});

const form = useForm({
    email: '',
	'cf-turnstile-response': '', // Add Turnstile response field
});

// Function to submit the form
const submit = async () => {
    // Get the Turnstile response token
    const turnstileResponse = document.querySelector("[name='cf-turnstile-response']")?.value;

    if (!turnstileResponse) {
        alert("Please complete the CAPTCHA verification.");
        return;
    }

    // Add Turnstile token to the form
    form['cf-turnstile-response'] = turnstileResponse;

    // Submit the form
    form.post(route('password.email'));
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
        <Head title="Forgot Password" />

        <div class="mb-4 text-sm text-gray-600">
            Forgot your password? No problem. Just let us know your email
            address and we will email you a password reset link that will allow
            you to choose a new one.
        </div>

        <div
            v-if="status"
            class="mb-4 text-sm font-medium text-green-600"
        >
            {{ status }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

			<!-- Cloudflare Turnstile -->
			<div class="" style="margin-top: 1em;">

				<div class="cf-turnstile" :data-sitekey="turnstile_site_key"></div>
			</div>
			
            <div class="mt-4 flex items-center justify-end">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Email Password Reset Link
                </PrimaryButton>
            </div>

					
        </form>
    </GuestLayout>
</template>
