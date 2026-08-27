<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- PinLoginSettings.vue

	Admin panel for the shared-terminal PIN unlock feature. Two
	independently-permissioned sections (see routes/web.php's comment on
	why there's no single OR-gated permission for the page): Settings
	(admin-system — the global on/off/trust-mode config) and Trusted
	Devices (manage-trusted-devices — which specific devices may use PIN
	unlock, deliberately delegable narrower than full admin-system).
-->

<script setup>
import { ref, reactive, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import axios from 'axios';

const props = defineProps({
	breadcrumb: { type: Array },
	canManageSettings: { type: Boolean, default: false },
	canManageDevices: { type: Boolean, default: false },
});

// ---------- settings ----------
const settingsLoading = ref(false);
const settingsError = ref(null);
const settingsSaving = ref(false);
const settingsSavedAt = ref(null);
const form = reactive({
	enabled: false,
	trust_mode: 'session_duration',
	trust_time_of_day: '18:00',
	trust_session_minutes: 480,
	require_badge_and_pin: false,
	badge_login_enabled: false,
});

async function fetchSettings() {
	settingsLoading.value = true;
	settingsError.value = null;
	try {
		const response = await axios.get('/json/pin-login-settings');
		Object.assign(form, response.data.settings);
	} catch (error) {
		settingsError.value = 'Could not load PIN login settings.';
	} finally {
		settingsLoading.value = false;
	}
}

async function saveSettings() {
	settingsError.value = null;
	settingsSaving.value = true;
	try {
		const response = await axios.put('/json/pin-login-settings', form);
		Object.assign(form, response.data.settings);
		settingsSavedAt.value = new Date().toLocaleTimeString();
	} catch (error) {
		settingsError.value = error.response?.data?.message
			|| Object.values(error.response?.data?.errors || {}).flat().join(' ')
			|| 'Could not save settings.';
	} finally {
		settingsSaving.value = false;
	}
}

// ---------- trusted devices ----------
const devicesLoading = ref(false);
const devicesError = ref(null);
const devices = ref([]);
const labelDrafts = reactive({});
const confirmingRevoke = ref(null);

async function fetchDevices() {
	devicesLoading.value = true;
	devicesError.value = null;
	try {
		const response = await axios.get('/json/trusted-devices');
		devices.value = response.data.records;
		devices.value.forEach((d) => { labelDrafts[d.id] = d.label || ''; });
	} catch (error) {
		devicesError.value = 'Could not load trusted devices.';
	} finally {
		devicesLoading.value = false;
	}
}

async function approveDevice(device) {
	devicesError.value = null;
	try {
		const response = await axios.post(`/json/trusted-devices/${device.id}/approve`, {
			label: labelDrafts[device.id] || null,
		});
		Object.assign(device, response.data.record);
	} catch (error) {
		devicesError.value = 'Could not approve device.';
	}
}

async function saveLabel(device) {
	devicesError.value = null;
	try {
		const response = await axios.put(`/json/trusted-devices/${device.id}`, {
			label: labelDrafts[device.id] || null,
		});
		Object.assign(device, response.data.record);
	} catch (error) {
		devicesError.value = 'Could not save label.';
	}
}

async function revokeDevice(device) {
	if (confirmingRevoke.value !== device.id) {
		confirmingRevoke.value = device.id;
		return;
	}
	confirmingRevoke.value = null;
	devicesError.value = null;
	try {
		const response = await axios.post(`/json/trusted-devices/${device.id}/revoke`);
		Object.assign(device, response.data.record);
	} catch (error) {
		devicesError.value = 'Could not revoke device.';
	}
}

function statusBadgeClass(status) {
	return {
		pending: 'bg-amber-100 text-amber-800',
		approved: 'bg-green-100 text-green-800',
		revoked: 'bg-gray-200 text-gray-600',
	}[status] || 'bg-gray-100 text-gray-600';
}

onMounted(() => {
	if (props.canManageSettings) fetchSettings();
	if (props.canManageDevices) fetchDevices();
});
</script>

<template>
	<Head title="PIN Login" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">PIN Login</h1>

			<p v-if="!canManageSettings && !canManageDevices" class="text-gray-500">
				You don't have permission to manage PIN login settings or trusted devices.
			</p>

			<!-- ======================= SETTINGS ======================= -->
			<section v-if="canManageSettings" class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Settings</h2>
				<p class="text-sm text-gray-600">
					A shared-terminal quick unlock for people who've already done a real email+password
					login on this specific device. Off by default; approved devices are managed separately below.
				</p>

				<div v-if="settingsError" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ settingsError }}</div>
				<p v-if="settingsLoading" class="text-gray-500">Loading settings…</p>

				<template v-else>
					<label class="flex items-center gap-2">
						<input type="checkbox" v-model="form.enabled" />
						<span class="text-gray-700 font-medium">Enable PIN login</span>
					</label>

					<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
						<label class="block">
							<span class="text-gray-700">Trust expires</span>
							<select v-model="form.trust_mode" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
								<option value="session_duration">After a fixed duration from login</option>
								<option value="time_of_day">At a fixed time of day (daily reset)</option>
								<option value="indefinite">Never (until explicit logout)</option>
							</select>
						</label>

						<label v-if="form.trust_mode === 'session_duration'" class="block">
							<span class="text-gray-700">Duration (minutes)</span>
							<input type="number" min="1" max="10080" v-model.number="form.trust_session_minutes"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>

						<label v-if="form.trust_mode === 'time_of_day'" class="block">
							<span class="text-gray-700">Time of day</span>
							<input type="time" v-model="form.trust_time_of_day"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
					</div>

					<label class="flex items-center gap-2">
						<input type="checkbox" v-model="form.badge_login_enabled" @change="form.badge_login_enabled || (form.require_badge_and_pin = false)" />
						<span class="text-gray-700 font-medium">Offer badge scanning on the unlock screen</span>
					</label>
					<p class="text-xs text-gray-500">
						Off by default until badges are actually issued — with this off, the unlock screen only
						shows name tiles + PIN, no badge scan step. Turn on once physical badges exist.
					</p>

					<label class="flex items-center gap-2">
						<input type="checkbox" v-model="form.require_badge_and_pin" :disabled="!form.badge_login_enabled" />
						<span class="text-gray-700" :class="{ 'opacity-50': !form.badge_login_enabled }">Require a badge scan in addition to the PIN (two-factor)</span>
					</label>
					<p class="text-xs text-gray-500">
						When on, tapping a name tile is no longer enough — unlocking requires scanning that
						person's badge first, then their PIN. Requires badge scanning to be offered above.
					</p>

					<div class="flex items-center gap-3">
						<PrimaryButton :disabled="settingsSaving" @click="saveSettings">
							{{ settingsSaving ? 'Saving…' : 'Save Settings' }}
						</PrimaryButton>
						<span v-if="settingsSavedAt" class="text-sm text-gray-500">Saved {{ settingsSavedAt }}</span>
					</div>
				</template>
			</section>

			<!-- ======================= TRUSTED DEVICES ======================= -->
			<section v-if="canManageDevices" class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Trusted Devices</h2>
				<p class="text-sm text-gray-600">
					A device must be approved here before PIN login is offered on it at all — even if
					someone has already done a real login there. A pending device just means someone
					visited the unlock screen on it; it grants nothing on its own.
				</p>

				<div v-if="devicesError" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ devicesError }}</div>
				<p v-if="devicesLoading" class="text-gray-500">Loading devices…</p>
				<p v-else-if="!devices.length" class="text-gray-400">No devices have visited the unlock screen yet.</p>

				<div v-else class="space-y-3">
					<div v-for="device in devices" :key="device.id" class="border rounded p-3 space-y-2">
						<div class="flex items-center justify-between flex-wrap gap-2">
							<span class="text-xs font-semibold uppercase tracking-widest px-2 py-1 rounded"
								:class="statusBadgeClass(device.status)">{{ device.status }}</span>
							<span class="text-xs text-gray-500 font-mono">
								{{ device.grants_count }} active login{{ device.grants_count === 1 ? '' : 's' }}
							</span>
						</div>

						<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
							<label class="block">
								<span class="text-gray-700">Label</span>
								<input type="text" v-model="labelDrafts[device.id]" placeholder="e.g. Sorting Station 1"
									class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
							</label>
							<div class="text-xs text-gray-500 self-end">
								<div>First seen: {{ new Date(device.requested_at).toLocaleString() }}</div>
								<div v-if="device.last_seen_at">Last seen: {{ new Date(device.last_seen_at).toLocaleString() }}</div>
								<div v-if="device.approver">Approved by: {{ device.approver.full_name }}</div>
								<div class="truncate" :title="device.user_agent">{{ device.user_agent }}</div>
							</div>
						</div>

						<div class="flex items-center gap-2">
							<button v-if="device.status !== 'approved'" @click="approveDevice(device)"
								class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white bg-green-600 hover:bg-green-500">
								Approve
							</button>
							<button v-else @click="saveLabel(device)"
								class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white bg-gray-600 hover:bg-gray-500">
								Save Label
							</button>
							<button v-if="device.status !== 'revoked'" @click="revokeDevice(device)"
								class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white"
								:class="confirmingRevoke === device.id ? 'bg-red-600 hover:bg-red-500' : 'bg-amber-600 hover:bg-amber-500'">
								{{ confirmingRevoke === device.id ? 'Confirm — signs everyone out of PIN login here' : 'Revoke' }}
							</button>
							<button v-if="confirmingRevoke === device.id" @click="confirmingRevoke = null"
								class="text-sm text-gray-500 underline">Cancel</button>
						</div>
					</div>
				</div>

				<p class="text-xs text-gray-500">
					Revoking a device immediately clears anyone's active PIN-login trust on it — the next
					visit to the unlock screen there shows "log in with email" only, for everyone, until
					re-approved.
				</p>
			</section>
		</div>
	</AuthenticatedLayout>
</template>
