<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- SystemAdmin.vue

	System Administration panel (permission: admin-system): shows the running
	software version, checks GitHub for updates, and triggers the self-update;
	shows the tiered backup inventory and triggers an on-demand backup; edits
	the backup schedule (storage/app/backup-settings.conf, read hourly by the
	scheduled-backup timer — see scripts/BACKUPS.md).

	During an update the site enters maintenance mode, so status polls start
	failing with 503 — that's expected and shown as "maintenance mode" progress
	rather than an error; polling continues until the updater reports success
	or failure through its status file.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import axios from 'axios';
import { onMounted, onUnmounted, reactive, ref } from 'vue';

defineProps({
	breadcrumb: {
		type: Array,
	},
});

const status = ref(null);
const error = ref('');
const notice = ref('');
const checking = ref(false);
const updating = ref(false);
const confirmingUpdate = ref(false);
const inMaintenance = ref(false);
const backingUp = ref(false);
const savingSettings = ref(false);
const settingsSavedAt = ref(null);

const settings = reactive({
	frequency: 'daily',
	hour: 2,
	dow: 7,
	tz: 'America/Los_Angeles',
	keep_daily: 14,
	keep_monthly: 12,
	keep_yearly: 3,
});

const kioskSettings = reactive({ welcome_message: '' });
const savingKiosk = ref(false);
const kioskSavedAt = ref(null);

const dayNames = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday' };

let pollTimer = null;

async function fetchStatus(applySettings = true) {
	const response = await axios.get('/json/system/status');
	status.value = response.data;
	if (applySettings && response.data.backup_settings) {
		Object.assign(settings, response.data.backup_settings);
	}
	// An update left running (or just finished) when the page loaded
	if (response.data.update_status?.state === 'running' && !updating.value) {
		updating.value = true;
		startPolling();
	} else if (response.data.update_status?.state === 'stalled') {
		error.value = `Update stalled: ${response.data.update_status.message}`;
	}
	return response.data;
}

async function checkForUpdates() {
	checking.value = true;
	error.value = '';
	notice.value = '';
	try {
		const response = await axios.post('/json/system/check-updates');
		status.value.version = response.data.version;
		notice.value = response.data.version.behind === 0
			? 'You are running the latest version.'
			: `Update available: ${response.data.version.behind} new commit(s).`;
	} catch (e) {
		error.value = e.response?.data?.message || 'Update check failed.';
	} finally {
		checking.value = false;
	}
}

async function startUpdate() {
	if (!confirmingUpdate.value) {
		confirmingUpdate.value = true;
		return;
	}
	confirmingUpdate.value = false;
	updating.value = true;
	error.value = '';
	notice.value = '';
	try {
		await axios.post('/json/system/update');
		startPolling();
	} catch (e) {
		updating.value = false;
		error.value = e.response?.data?.message || 'Could not start the update.';
	}
}

function startPolling() {
	stopPolling();
	pollTimer = setInterval(async () => {
		try {
			const data = await fetchStatus(false);
			inMaintenance.value = false;
			const state = data.update_status?.state;
			if (state === 'success' || state === 'failed' || state === 'stalled') {
				stopPolling();
				updating.value = false;
				if (state === 'success') {
					notice.value = 'Update finished successfully.';
				} else if (state === 'stalled') {
					error.value = `Update stalled: ${data.update_status.message}`;
				} else {
					error.value = `Update failed: ${data.update_status.message} The site may be in maintenance mode — check the server.`;
				}
			}
		} catch (e) {
			// 503 = maintenance mode = update in progress; keep polling
			inMaintenance.value = true;
		}
	}, 4000);
}

function stopPolling() {
	if (pollTimer) {
		clearInterval(pollTimer);
		pollTimer = null;
	}
}

async function backupNow() {
	backingUp.value = true;
	error.value = '';
	notice.value = '';
	try {
		await axios.post('/json/system/backup');
		notice.value = 'Backup started — the list below will refresh shortly.';
		setTimeout(() => fetchStatus(false).catch(() => {}), 8000);
		setTimeout(() => {
			fetchStatus(false).catch(() => {});
			backingUp.value = false;
		}, 25000);
	} catch (e) {
		backingUp.value = false;
		error.value = e.response?.data?.message || 'Could not start the backup.';
	}
}

async function saveSettings() {
	savingSettings.value = true;
	error.value = '';
	try {
		const response = await axios.put('/json/system/backup-settings', settings);
		Object.assign(settings, response.data.backup_settings);
		settingsSavedAt.value = new Date();
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not save backup settings.';
	} finally {
		savingSettings.value = false;
	}
}

async function fetchKioskSettings() {
	const response = await axios.get('/json/kiosk-settings');
	Object.assign(kioskSettings, response.data.settings);
}

async function saveKioskSettings() {
	savingKiosk.value = true;
	error.value = '';
	try {
		const response = await axios.put('/json/kiosk-settings', kioskSettings);
		Object.assign(kioskSettings, response.data.settings);
		kioskSavedAt.value = new Date();
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not save the kiosk welcome message.';
	} finally {
		savingKiosk.value = false;
	}
}

function formatStamp(stamp) {
	// 20260814-112703 -> 2026-08-14 11:27 (server time)
	const m = stamp?.match(/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})/);
	return m ? `${m[1]}-${m[2]}-${m[3]} ${m[4]}:${m[5]}` : stamp;
}

function formatMarkerDate(marker) {
	const m = marker?.match(/^(\d{4})(\d{2})(\d{2})$/);
	return m ? `${m[1]}-${m[2]}-${m[3]}` : marker;
}

function formatBytes(bytes) {
	if (bytes == null) return 'unknown';
	const units = ['B', 'KB', 'MB', 'GB', 'TB'];
	let i = 0;
	while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
	return `${bytes.toFixed(1)} ${units[i]}`;
}

onMounted(() => fetchStatus().catch(() => { error.value = 'Could not load system status.'; }));
onMounted(() => fetchKioskSettings().catch(() => { error.value = 'Could not load kiosk settings.'; }));
onUnmounted(stopPolling);
</script>

<template>
	<Head title="Updates & Backups" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Updates & Backups</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>

			<!-- Volunteer kiosk -->
			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Volunteer Kiosk</h2>
				<label class="block text-sm">
					<span class="text-gray-700">Welcome banner</span>
					<input
						type="text"
						maxlength="255"
						v-model="kioskSettings.welcome_message"
						placeholder="Welcome!"
						class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
					/>
				</label>
				<div class="flex gap-3 items-center">
					<PrimaryButton :disabled="savingKiosk" @click="saveKioskSettings">
						{{ savingKiosk ? 'Saving…' : 'Save' }}
					</PrimaryButton>
					<span v-if="kioskSavedAt" class="text-sm text-green-700">Saved {{ kioskSavedAt.toLocaleTimeString() }}</span>
				</div>
				<p class="text-xs text-gray-500">
					Shown as the headline on the volunteer sign-in kiosk (/volunteers/kiosk), above the
					"Facility Sign-In/Sign-Out" tagline — e.g. a location name like "Welcome to the
					Statesville Warehouse". Leave blank for a generic "Welcome!".
				</p>
			</section>

			<div v-if="!status" class="text-gray-500">Loading system status…</div>

			<template v-if="status">
				<!-- Software update -->
				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">Software Update</h2>
					<div class="text-sm text-gray-700">
						<p>
							Current version:
							<span class="font-mono">{{ status.version.current || 'unknown' }}</span>
							<span v-if="status.version.current_date"> — {{ new Date(status.version.current_date).toLocaleString() }}</span>
						</p>
						<p v-if="status.version.current_subject" class="text-gray-500">“{{ status.version.current_subject }}”</p>
					</div>

					<div v-if="status.version.behind > 0" class="text-sm">
						<p class="font-medium text-amber-700">{{ status.version.behind }} update(s) available:</p>
						<ul class="font-mono text-xs text-gray-600 mt-1 space-y-0.5">
							<li v-for="commit in status.version.pending_commits" :key="commit">{{ commit }}</li>
						</ul>
					</div>

					<div v-if="updating" class="rounded bg-blue-50 border border-blue-300 text-blue-800 px-4 py-3 text-sm">
						<p class="font-medium">Update in progress…</p>
						<p v-if="inMaintenance">The site is in maintenance mode while the update runs. This page will recover automatically.</p>
						<p v-else-if="status.update_status?.message">{{ status.update_status.message }}</p>
					</div>

					<div class="flex gap-3 items-center" v-if="!updating">
						<PrimaryButton :disabled="checking" @click="checkForUpdates">
							{{ checking ? 'Checking…' : 'Check for Updates' }}
						</PrimaryButton>
						<button
							v-if="status.version.behind > 0 || confirmingUpdate"
							class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white"
							:class="confirmingUpdate ? 'bg-red-600 hover:bg-red-500' : 'bg-amber-600 hover:bg-amber-500'"
							@click="startUpdate"
						>
							{{ confirmingUpdate ? 'Confirm — site will briefly go offline' : 'Install Update' }}
						</button>
						<button v-if="confirmingUpdate" class="text-sm text-gray-500 underline" @click="confirmingUpdate = false">Cancel</button>
					</div>
					<p class="text-xs text-gray-500">
						Installing an update backs up first, puts the site into maintenance mode, applies the update,
						and verifies health before coming back online.
					</p>
				</section>

				<!-- Backups -->
				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">Backups</h2>
					<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
						<div v-for="tier in ['daily', 'monthly', 'yearly']" :key="tier" class="border rounded p-3">
							<h3 class="font-medium capitalize">{{ tier }} <span class="text-gray-400 font-normal">({{ status.backups.tiers[tier].count }})</span></h3>
							<ul class="text-xs font-mono text-gray-600 mt-1 space-y-0.5">
								<li v-for="entry in status.backups.tiers[tier].entries" :key="entry">{{ formatStamp(entry) }}</li>
								<li v-if="status.backups.tiers[tier].count === 0" class="text-gray-400 font-sans">none yet</li>
							</ul>
						</div>
					</div>
					<p class="text-sm text-gray-600">
						Last scheduled backup: {{ formatMarkerDate(status.backups.last_scheduled) || 'never' }}
						· Disk free: {{ formatBytes(status.backups.disk_free_bytes) }}
					</p>
					<PrimaryButton :disabled="backingUp" @click="backupNow">
						{{ backingUp ? 'Backing up…' : 'Back Up Now' }}
					</PrimaryButton>
				</section>

				<!-- Backup schedule -->
				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">Backup Schedule</h2>
					<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
						<label class="block">
							<span class="text-gray-700">Frequency</span>
							<select v-model="settings.frequency" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
								<option value="daily">Daily</option>
								<option value="weekly">Weekly</option>
							</select>
						</label>
						<label class="block" v-if="settings.frequency === 'weekly'">
							<span class="text-gray-700">Day of week</span>
							<select v-model.number="settings.dow" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
								<option v-for="(name, num) in dayNames" :key="num" :value="Number(num)">{{ name }}</option>
							</select>
						</label>
						<label class="block">
							<span class="text-gray-700">Time of day</span>
							<select v-model.number="settings.hour" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
								<option v-for="h in 24" :key="h - 1" :value="h - 1">{{ String(h - 1).padStart(2, '0') }}:00</option>
							</select>
						</label>
						<label class="block">
							<span class="text-gray-700">Timezone</span>
							<select v-model="settings.tz" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
								<option v-for="tz in status.timezones" :key="tz" :value="tz">{{ tz }}</option>
							</select>
						</label>
						<label class="block">
							<span class="text-gray-700">Daily backups to keep</span>
							<input type="number" min="1" max="365" v-model.number="settings.keep_daily" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
						<label class="block">
							<span class="text-gray-700">Monthly backups to keep</span>
							<input type="number" min="0" max="120" v-model.number="settings.keep_monthly" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
						<label class="block">
							<span class="text-gray-700">Yearly backups to keep</span>
							<input type="number" min="0" max="50" v-model.number="settings.keep_yearly" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
					</div>
					<div class="flex gap-3 items-center">
						<PrimaryButton :disabled="savingSettings" @click="saveSettings">
							{{ savingSettings ? 'Saving…' : 'Save Schedule' }}
						</PrimaryButton>
						<span v-if="settingsSavedAt" class="text-sm text-green-700">Saved {{ settingsSavedAt.toLocaleTimeString() }}</span>
					</div>
					<p class="text-xs text-gray-500">
						Changes take effect at the next hourly check — no server restart needed. Monthly and yearly
						backups are space-free copies of the first backup of the period.
					</p>
				</section>
			</template>
		</div>
	</AuthenticatedLayout>
</template>
