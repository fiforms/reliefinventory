<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- EmailSettings.vue

	Outgoing email settings (permission: admin-system): which mail service to
	use, the From address/name, the Resend API key or SMTP server, and who is
	emailed about new feedback reports — plus a test-email button. A blank
	field falls back to the server's .env value, shown as the placeholder.
	Secrets are write-only: the server only says whether one is saved.
	Panel-style page per the admin-page-style skill.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import axios from 'axios';
import { computed, onMounted, reactive, ref } from 'vue';

defineProps({
	breadcrumb: {
		type: Array,
	},
});

const MAILER_LABELS = { resend: 'Resend', smtp: 'SMTP server', log: 'Log only (no email is sent)' };

const error = ref('');
const notice = ref('');
const loaded = ref(false);
const saving = ref(false);
const savedAt = ref('');
const testing = ref(false);
const testTo = ref('');

const status = ref(null); // last server response: settings, defaults, mailers
const form = reactive({});
const clean = ref('');

function fill(data) {
	status.value = data;
	const s = data.settings;
	Object.assign(form, {
		mailer: s.mailer ?? '',
		from_address: s.from_address ?? '',
		from_name: s.from_name ?? '',
		resend_key: '',
		clear_resend_key: false,
		smtp_host: s.smtp_host ?? '',
		smtp_port: s.smtp_port ?? '',
		smtp_username: s.smtp_username ?? '',
		smtp_password: '',
		clear_smtp_password: false,
		feedback_notify_emails: s.feedback_notify_emails ?? '',
	});
	clean.value = JSON.stringify(form);
}

const dirty = computed(() => loaded.value && JSON.stringify(form) !== clean.value);
const defaults = computed(() => status.value?.defaults ?? {});
const activeMailer = computed(() => form.mailer || defaults.value.mailer);

function placeholder(key) {
	const value = defaults.value[key];
	return value ? `Server default: ${value}` : 'Not set';
}

// Where the key/password in use comes from, for the status line under each secret field.
function secretSource(key) {
	const s = status.value?.settings ?? {};
	if (form['clear_' + key]) return defaults.value[key + '_set'] ? 'Will use the one in the server .env' : 'Will be removed';
	if (form[key]) return 'A new one will be saved';
	if (s[key + '_set']) return 'Saved here';
	if (defaults.value[key + '_set']) return 'Using the one in the server .env';
	return 'None set';
}

async function load() {
	try {
		const response = await axios.get('/json/mail-settings');
		fill(response.data);
		testTo.value = response.data.test_to ?? '';
		loaded.value = true;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not load the email settings.';
	}
}

async function save() {
	error.value = '';
	notice.value = '';
	saving.value = true;
	try {
		const payload = { ...form, smtp_port: form.smtp_port === '' ? null : Number(form.smtp_port) };
		const response = await axios.put('/json/mail-settings', payload);
		fill(response.data);
		savedAt.value = 'Saved ' + new Date().toLocaleTimeString();
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not save the email settings.';
	} finally {
		saving.value = false;
	}
}

async function sendTest() {
	error.value = '';
	notice.value = '';
	testing.value = true;
	try {
		const response = await axios.post('/json/mail-settings/test', { to: testTo.value });
		notice.value = response.data.message;
	} catch (e) {
		error.value = e.response?.data?.message || 'The test email could not be sent.';
	} finally {
		testing.value = false;
	}
}

onMounted(load);
</script>

<template>
	<Head title="Email Settings" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Email Settings</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>

			<p v-if="!loaded && !error" class="text-gray-500">Loading email settings…</p>

			<template v-if="loaded">
				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">Outgoing Email</h2>
					<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
						<label class="block">
							<span class="text-gray-700">Send email using</span>
							<select v-model="form.mailer" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
								<option value="">Server default ({{ MAILER_LABELS[defaults.mailer] ?? defaults.mailer }})</option>
								<option v-for="m in status.mailers" :key="m" :value="m">{{ MAILER_LABELS[m] ?? m }}</option>
							</select>
						</label>
						<div class="hidden sm:block"></div>
						<label class="block">
							<span class="text-gray-700">From address</span>
							<input v-model="form.from_address" type="email" :placeholder="placeholder('from_address')"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
						<label class="block">
							<span class="text-gray-700">From name</span>
							<input v-model="form.from_name" type="text" :placeholder="placeholder('from_name')"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>

						<label v-if="activeMailer === 'resend'" class="block sm:col-span-2">
							<span class="text-gray-700">Resend API key</span>
							<input v-model="form.resend_key" type="password" autocomplete="new-password"
								placeholder="Leave blank to keep the current key" :disabled="form.clear_resend_key"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm font-mono" />
							<span class="mt-1 flex flex-wrap items-center gap-x-4 text-xs text-gray-600">
								<span>{{ secretSource('resend_key') }}</span>
								<label v-if="status.settings.resend_key_set" class="inline-flex items-center gap-1">
									<input v-model="form.clear_resend_key" type="checkbox" class="rounded border-gray-300" />
									Remove the saved key
								</label>
							</span>
						</label>

						<template v-if="activeMailer === 'smtp'">
							<label class="block">
								<span class="text-gray-700">SMTP server</span>
								<input v-model="form.smtp_host" type="text" :placeholder="placeholder('smtp_host')"
									class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
							</label>
							<label class="block">
								<span class="text-gray-700">Port</span>
								<input v-model="form.smtp_port" type="number" min="1" max="65535" :placeholder="placeholder('smtp_port')"
									class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
							</label>
							<label class="block">
								<span class="text-gray-700">Username</span>
								<input v-model="form.smtp_username" type="text" autocomplete="off" :placeholder="placeholder('smtp_username')"
									class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
							</label>
							<label class="block">
								<span class="text-gray-700">Password</span>
								<input v-model="form.smtp_password" type="password" autocomplete="new-password"
									placeholder="Leave blank to keep the current password" :disabled="form.clear_smtp_password"
									class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
								<span class="mt-1 flex flex-wrap items-center gap-x-4 text-xs text-gray-600">
									<span>{{ secretSource('smtp_password') }}</span>
									<label v-if="status.settings.smtp_password_set" class="inline-flex items-center gap-1">
										<input v-model="form.clear_smtp_password" type="checkbox" class="rounded border-gray-300" />
										Remove the saved password
									</label>
								</span>
							</label>
						</template>
					</div>
					<p class="text-xs text-gray-500">
						A blank field uses the server's <span class="font-mono">.env</span> value, shown in grey.
						<template v-if="activeMailer === 'resend'">
							The From address must be on a domain verified in the Resend account the key belongs to.
						</template>
						<template v-if="activeMailer === 'smtp'">
							Port 465 connects over SSL; any other port switches to TLS when the server offers it.
						</template>
						Keys and passwords are stored encrypted and are never shown again after saving; a backup
						restored onto another server loses them, so they would need to be entered again.
					</p>
				</section>

				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">Feedback Report Recipients</h2>
					<label class="block text-sm">
						<span class="text-gray-700">Email these addresses when someone submits a bug report or idea</span>
						<input v-model="form.feedback_notify_emails" type="text" :placeholder="placeholder('feedback_notify_emails')"
							class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
					</label>
					<p class="text-xs text-gray-500">Separate addresses with commas. With none, reports are still saved, just not emailed.</p>
				</section>

				<div class="flex items-center gap-4">
					<PrimaryButton :disabled="saving || !dirty" @click="save">{{ saving ? 'Saving…' : 'Save' }}</PrimaryButton>
					<span v-if="savedAt && !dirty" class="text-sm text-gray-600">{{ savedAt }}</span>
					<span v-if="dirty" class="text-sm text-amber-700">Unsaved changes</span>
				</div>

				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">Send a Test Email</h2>
					<div class="flex flex-wrap items-end gap-4 text-sm">
						<label class="block grow">
							<span class="text-gray-700">Send to</span>
							<input v-model="testTo" type="email" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
						<PrimaryButton :disabled="testing || dirty || !testTo" @click="sendTest">
							{{ testing ? 'Sending…' : 'Send Test' }}
						</PrimaryButton>
					</div>
					<p class="text-xs text-gray-500">
						Uses the saved settings{{ dirty ? ' — save your changes first' : '' }}. If the mail service refuses
						the message, its own error is shown above.
					</p>
				</section>
			</template>
		</div>
	</AuthenticatedLayout>
</template>
