<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    // Several warehouses on this address (MULTI_WAREHOUSE.md §3): the listed ones to pick from, or null when there is no choice to make.
    chooser: {
        type: Object,
        default: null,
    },
});

const choosing = ref(false);
const codeForm = useForm({ code: '' });
const chooseByCode = () => codeForm.post(route('warehouse.choose'));

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <div v-if="$page.props.warehouse.multiple" class="mb-4 rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
            Signing in to <strong>{{ $page.props.warehouse.name }}</strong>
            <template v-if="chooser">
                &middot;
                <button type="button" class="underline text-blue-700" @click="choosing = !choosing">Not the right warehouse?</button>
            </template>
        </div>

        <div v-if="chooser && choosing" class="mb-4 rounded border border-gray-200 px-3 py-3 text-sm">
            <ul v-if="chooser.options.length" class="space-y-1">
                <li v-for="option in chooser.options" :key="option.id">
                    <a :href="`/login?w=${option.id}`" class="underline text-blue-700">{{ option.name }}</a>
                </li>
            </ul>
            <form class="mt-2 flex items-start gap-2" @submit.prevent="chooseByCode">
                <div class="flex-1">
                    <TextInput type="text" class="block w-full font-mono uppercase" v-model="codeForm.code" placeholder="Or enter a warehouse code" autocomplete="off" />
                    <InputError class="mt-1" :message="codeForm.errors.code" />
                </div>
                <PrimaryButton type="submit" :disabled="codeForm.processing || !codeForm.code">Go</PrimaryButton>
            </form>
        </div>

        <div v-if="status" class="mb-4 text-sm font-medium text-green-600">
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

            <div class="mt-4">
                <InputLabel for="password" value="Password" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4 block">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-gray-600"
                        >Remember me</span
                    >
                </label>
            </div>

            <div class="mt-4 flex items-center justify-end">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Forgot your password?
                </Link>

                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Log in
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
