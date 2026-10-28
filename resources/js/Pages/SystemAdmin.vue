<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- SystemAdmin.vue

	Updates & Backups (/setup/system) — the one page for everything about keeping this system current and safe.
	Each section is shown only when the person holds its permission AND the platform can do it:

	  admin-system     Software update (systemd: check/install; Docker: version only — you upgrade by pulling a
	                   new image), Offline mode, and Scheduled backups (systemd: the tiered backups, their
	                   schedule and retention, from backup-settings.conf read by the hourly timer — see
	                   scripts/BACKUPS.md; Docker: a read-only summary of what compose.yaml configured).
	  manage-backups   Create a backup zip (database, or database + uploaded files), list / download / delete them.
	  view-app-key     Reveal the application key (password re-entry).

	`runtime` ('systemd' | 'docker') comes from config('system.runtime'). Server halves: SystemController
	(status, update, schedule), BackupController (zips, key).

	During an update the site enters maintenance mode, so status polls start failing with 503 — that's expected
	and shown as "maintenance mode" progress rather than an error; polling continues until the updater reports
	success or failure through its status file.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import axios from 'axios';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';

const props = defineProps({
	breadcrumb: { type: Array },
	runtime: { type: String, default: 'systemd' },
	canAdminSystem: { type: Boolean, default: false },
	canManageBackups: { type: Boolean, default: false },
	canViewAppKey: { type: Boolean, default: false },
});

const docker = computed(() => props.runtime === 'docker');

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
const savingOfflineMode = ref(false);

const settings = reactive({
	frequency: 'daily',
	hourly: false,
	hour: 2,
	dow: 7,
	tz: 'America/Los_Angeles',
	keep_hourly: 48,
	keep_daily: 14,
	keep_monthly: 12,
	keep_yearly: 3,
});

const dayNames = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday' };

let pollTimer = null;

function fail(e, fallback) {
	error.value = e.response?.data?.errors
		? Object.values(e.response.data.errors).flat().join(' ')
		: e.response?.data?.message || fallback;
}

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
				if (props.canManageBackups) loadBackups({ quiet: true });
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

// Systemd only: a scheduled-style backup right now, filed into the Hourly tier.
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

async function toggleOfflineMode() {
	const desired = status.value.offline_mode;
	savingOfflineMode.value = true;
	error.value = '';
	notice.value = '';
	try {
		const response = await axios.put('/json/system/offline-mode', { enabled: desired });
		status.value.offline_mode = response.data.offline_mode;
		notice.value = response.data.offline_mode ? 'Offline mode enabled.' : 'Offline mode disabled.';
	} catch (e) {
		status.value.offline_mode = !desired; // revert the checkbox — save failed
		error.value = e.response?.data?.message || 'Could not save offline mode.';
	} finally {
		savingOfflineMode.value = false;
	}
}

function formatStamp(stamp) {
	// 20260814-112703 -> 2026-08-14 11:27, already in the configured backup
	// timezone (settings.tz below) — update.sh stamps backups with TZ="$BACKUP_TZ",
	// not server-local time, specifically so this matches what's configured on
	// the Scheduled Backups section.
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

// ---- Backup zips (manage-backups) ----
const records = ref(null);
const keepDays = ref(14);
const scope = ref('database');
const starting = ref(false);
const confirmingDelete = ref(null);
let backupTimer = null;

const active = computed(() => (records.value || []).find((r) => r.status === 'queued' || r.status === 'running'));
const scopeNames = { database: 'database only', full: 'database + files', storage: 'files only' };

// quiet: the background poll. It keeps running during an update, when every request gets the
// maintenance-mode 503 (or a 502 while PHP restarts) — that's expected, not something to show.
async function loadBackups({ quiet = false } = {}) {
	try {
		const { data } = await axios.get('/json/backups');
		records.value = data.records;
		keepDays.value = data.keep_days;
	} catch (e) {
		if (!quiet) fail(e, 'Could not load the backup list.');
	}
}

async function startBackup() {
	error.value = '';
	notice.value = '';
	starting.value = true;
	try {
		await axios.post('/json/backups', { scope: scope.value });
		notice.value = 'Backup started. You can leave this page; it keeps running on the server.';
		await loadBackups();
	} catch (e) {
		fail(e, 'Could not start the backup.');
	} finally {
		starting.value = false;
	}
}

async function deleteBackup(record) {
	error.value = '';
	notice.value = '';
	if (confirmingDelete.value !== record.id) {
		confirmingDelete.value = record.id;
		return;
	}
	confirmingDelete.value = null;
	try {
		await axios.delete(`/json/backups/${record.id}`);
		await loadBackups();
	} catch (e) {
		fail(e, 'Could not delete that backup.');
	}
}

function formatSize(bytes) {
	if (bytes == null) return '';
	if (bytes > 1073741824) return `${(bytes / 1073741824).toFixed(2)} GB`;
	return bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.ceil(bytes / 1024)} KB`;
}

const when = (iso) => (iso ? new Date(iso).toLocaleString() : '');

// ---- Application key (view-app-key) ----
const keyPassword = ref('');
const revealedKey = ref('');
const revealing = ref(false);
const copied = ref(false);

async function revealKey() {
	error.value = '';
	notice.value = '';
	revealing.value = true;
	try {
		const { data } = await axios.post('/json/backups/app-key', { password: keyPassword.value });
		revealedKey.value = data.key;
	} catch (e) {
		fail(e, 'Could not show the key.');
	} finally {
		keyPassword.value = '';
		revealing.value = false;
	}
}

function hideKey() {
	revealedKey.value = '';
	copied.value = false;
}

async function copyKey() {
	try {
		await navigator.clipboard.writeText(revealedKey.value);
		copied.value = true;
	} catch {
		copied.value = false;
	}
}

onMounted(async () => {
	if (props.canAdminSystem) {
		fetchStatus().catch(() => { error.value = 'Could not load system status.'; });
	}
	if (props.canManageBackups) {
		await loadBackups();
		// Poll while a backup runs (and keep polling so a second admin's backup shows up).
		// Paused while this page's update runs (the site is in maintenance mode); quiet covers an
		// update this page doesn't know about — started elsewhere, or viewed without admin-system.
		backupTimer = setInterval(() => {
			if (!updating.value) loadBackups({ quiet: true });
		}, 4000);
	}
});
onUnmounted(() => {
	stopPolling();
	clearInterval(backupTimer);
	hideKey();
});
</script>

<template>
	<Head title="Updates & Backups" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Updates & Backups</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>

			<div v-if="canAdminSystem && !status" class="text-gray-500">Loading system status…</div>

			<!-- Software update -->
			<section v-if="canAdminSystem && status" class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Software {{ docker ? 'Version' : 'Update' }}</h2>
				<div class="text-sm text-gray-700">
					<p>
						Current version:
						<span v-if="status.version.release" class="font-semibold">{{ status.version.release }}</span>
						<span v-else class="text-gray-500">unversioned</span>
						(commit <span class="font-mono">{{ status.version.current || 'unknown' }}</span>)
						<span v-if="status.version.current_date"> — {{ new Date(status.version.current_date).toLocaleString() }}</span>
					</p>
					<p v-if="status.version.current_subject" class="text-gray-500">“{{ status.version.current_subject }}”</p>
				</div>

				<!-- Docker / R Station: nothing to install from here -->
				<p v-if="docker" class="text-xs text-gray-500">
					This system runs from a container image. To upgrade, pull the new image and restart it (see the
					Docker guide); the database is backed up automatically first when the upgrade has changes to apply.
				</p>

				<template v-else>
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
				</template>
			</section>

			<!-- Offline Mode -->
			<section v-if="canAdminSystem && status" class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Offline Mode</h2>
				<p class="text-sm text-gray-600">
					Turn this on when this warehouse has no reliable internet connection. It automatically
					disables Cloudflare Turnstile (bot protection on login/registration/password reset) and
					geocod.io address lookups (county/city/state auto-fill on Order Entry and People) — neither
					is required for normal operation, they just fail slowly without a real network otherwise.
					Nothing else changes — address, order, and inventory entry all work exactly the same either way.
				</p>
				<label class="flex items-center gap-3">
					<input type="checkbox" v-model="status.offline_mode" :disabled="savingOfflineMode"
						@change="toggleOfflineMode" class="h-5 w-5 rounded border-gray-300" />
					<span class="text-sm font-medium text-gray-700">
						{{ status.offline_mode ? 'Offline mode is ON' : 'Offline mode is off' }}
					</span>
				</label>
			</section>

			<!-- The key is not in any backup: say so, loudly, wherever backups are made or listed -->
			<section v-if="canAdminSystem || canManageBackups" class="rounded-lg border-2 border-red-400 bg-red-50 p-5 space-y-2 text-sm text-red-900">
				<h2 class="text-base font-bold">Write down this warehouse's key — backups do not contain it</h2>
				<p>
					For your protection no backup ever contains the warehouse key. <strong>You NEED a copy of it, kept in a safe place
					separate from your backups</strong> (a password manager or a sealed envelope): it is required to restore the encrypted
					parts of a backup — for example the shared secret for centralized feedback. Without the key those values can't be
					recovered and have to be re-entered, and everyone has to sign in again; with it, a restore is complete.
				</p>
				<p v-if="canViewAppKey">
					<a href="#application-key" class="font-semibold underline">Show the warehouse key (at the bottom of this page)</a>
					and record it now — and again if it is ever changed.
				</p>
				<p v-else>An administrator who can view the warehouse key needs to record it. Ask for it to be done if you are not sure it has been.</p>
			</section>

			<!-- Create a backup -->
			<section v-if="canManageBackups" class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Create a backup</h2>
				<p class="text-sm text-gray-700">
					A backup is one .zip file. It can be used to set up a new, empty copy of this system. Only one backup
					runs at a time, and a large one can take a long time.
				</p>
				<fieldset class="space-y-2 text-sm" :disabled="!!active">
					<label class="flex items-start gap-2">
						<input type="radio" value="database" v-model="scope" class="mt-1 border-gray-300" />
						<span>
							<span class="text-gray-800">Database only</span>
							<span class="block text-xs text-gray-500">Everything entered in the system. Small and fast.</span>
						</span>
					</label>
					<label class="flex items-start gap-2">
						<input type="radio" value="full" v-model="scope" class="mt-1 border-gray-300" />
						<span>
							<span class="text-gray-800">Database + uploaded files</span>
							<span class="block text-xs text-gray-500">Also every uploaded file (receiving photos, screenshots, imports) — larger and slower.</span>
						</span>
					</label>
				</fieldset>
				<div class="flex items-center gap-4">
					<PrimaryButton @click="startBackup" :disabled="starting || !!active">
						{{ starting ? 'Starting…' : active ? 'A backup is running…' : 'Back Up Now' }}
					</PrimaryButton>
				</div>
				<div v-if="active" class="rounded bg-blue-50 border border-blue-300 text-blue-800 px-4 py-3 text-sm">
					{{ active.status === 'queued' ? 'Starting…' : active.progress || 'Working…' }}
					<span class="text-xs text-blue-700">(started {{ when(active.started_at || active.created_at) }})</span>
				</div>
				<p class="text-xs text-gray-500">
					Backups are deleted automatically {{ keepDays }} days after they are made, or earlier if you delete them below.
					A backup <strong>never</strong> contains the warehouse key — see the panel at the bottom of this page.
				</p>
			</section>

			<!-- Backup zips -->
			<section v-if="canManageBackups" class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Backups</h2>
				<p v-if="records === null" class="text-gray-500">Loading…</p>
				<p v-else-if="!records.length" class="text-gray-400">none yet</p>
				<div v-else class="space-y-3">
					<div v-for="r in records" :key="r.id" class="border rounded p-3 text-sm flex flex-wrap items-center justify-between gap-3">
						<div class="space-y-1">
							<div class="font-mono text-xs text-gray-600">{{ r.filename || `backup #${r.id}` }}</div>
							<div>
								{{ when(r.finished_at || r.created_at) }}
								<span v-if="r.status === 'complete'" class="text-gray-600">
									— {{ formatSize(r.size_bytes) }}, {{ scopeNames[r.scope] || r.scope }}
								</span>
								<span v-else-if="r.status === 'failed'" class="text-red-700">— failed: {{ r.error }}</span>
								<span v-else class="text-blue-700">— {{ r.progress || r.status }}</span>
							</div>
							<div v-if="r.expires_at" class="text-xs text-gray-500">Deleted automatically {{ when(r.expires_at) }}</div>
						</div>
						<div class="flex items-center gap-3">
							<a v-if="r.status === 'complete'" :href="$withWarehouse(`/json/backups/${r.id}/download`)"
								class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white bg-gray-800 hover:bg-gray-700">Download</a>
							<button v-if="r.status !== 'queued' && r.status !== 'running'" @click="deleteBackup(r)"
								class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white"
								:class="confirmingDelete === r.id ? 'bg-red-600 hover:bg-red-500' : 'bg-amber-600 hover:bg-amber-500'">
								{{ confirmingDelete === r.id ? 'Confirm — delete this backup' : 'Delete' }}
							</button>
							<button v-if="confirmingDelete === r.id" @click="confirmingDelete = null" class="text-xs underline text-gray-600">Cancel</button>
						</div>
					</div>
				</div>
				<p class="text-xs text-gray-500">
					Treat a downloaded backup like the data itself: it contains every person's information. Store it somewhere private.
					To restore one into <strong>this warehouse</strong>, an administrator can use
					<a href="/setup/restore" class="underline">Restore this warehouse from a backup</a>; on a new installation, choose “Restore from a backup”
					on its setup screen. To repair a damaged
					database on this system, an administrator at the server runs <span class="font-mono">php artisan backup:restore</span>
					with the latest good database backup and, if you want, a separate backup of the files.
				</p>
			</section>

			<!-- Scheduled backups: Docker / R Station — compose.yaml owns the schedule -->
			<section v-if="canAdminSystem && status && docker && status.container_backups" class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Scheduled Backups</h2>
				<ul class="text-sm text-gray-700 space-y-1 list-disc pl-5">
					<li>
						Daily backup:
						<strong>{{ status.container_backups.enabled ? `on, after ${String(status.container_backups.hour).padStart(2, '0')}:00 server time` : 'off' }}</strong>
						— {{ scopeNames[status.container_backups.scope] || status.container_backups.scope }}.
					</li>
					<li>
						Before an upgrade that changes the database:
						<strong>{{ status.container_backups.premigrate ? 'a database backup is made automatically' : 'no automatic backup (turned off)' }}</strong>.
					</li>
					<li>Each is kept for <strong>{{ status.container_backups.keep_days }} days</strong>, then deleted. They appear in the Backups list above.</li>
				</ul>
				<p class="text-xs text-gray-500">
					These are set in the compose file (<span class="font-mono">RI_BACKUP_*</span>), not here. Retention is a single
					number of days: the daily / monthly / yearly tiers that a standard server install keeps are not available in
					this container install yet, so copy a backup off this machine before the days run out if you need to keep it longer.
				</p>
			</section>

			<!-- Scheduled backups: systemd — tiers, run now, schedule -->
			<section v-if="canAdminSystem && status && !docker && status.backups" class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Scheduled Backups</h2>
				<p class="text-xs text-gray-500">Times below are in the configured backup timezone ({{ settings.tz }}), not your browser's local time.</p>
				<p class="text-xs text-gray-500">
					Hourly holds every scheduled backup (hourly, daily/weekly, or run from here) for short-term
					rollback; only the day's scheduled backup is promoted into Daily, and only that promotion can
					reach Monthly/Yearly — so hourly backups or clicking "Run a Scheduled Backup Now" repeatedly can never crowd
					out that history. Every backup is a full copy of the database, not incremental, and is the same
					.zip format as the backups above, and can be downloaded from the lists here (if you may manage backups).
					<strong>Uploaded files are not part of the scheduled backups</strong> — make a
					“Database + uploaded files” backup above when they need protecting too.
				</p>
				<div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
					<div v-for="tier in ['hourly', 'daily', 'monthly', 'yearly']" :key="tier" class="border rounded p-3">
						<h3 class="font-medium capitalize">{{ tier }} <span class="text-gray-400 font-normal">({{ status.backups.tiers[tier].count }})</span></h3>
						<ul class="text-xs font-mono text-gray-600 mt-1 space-y-0.5 max-h-48 overflow-y-auto">
							<li v-for="entry in status.backups.tiers[tier].entries" :key="entry.name">
								{{ formatStamp(entry.name) }}<span v-if="entry.size" class="text-gray-400"> · {{ formatBytes(entry.size) }}</span>
								<a v-if="canManageBackups && entry.downloadable" :href="$withWarehouse(`/json/backups/tier/${tier}/${entry.name}/download`)"
									class="font-sans text-blue-700 underline ml-1" title="Download this backup (.zip)">download</a>
							</li>
							<li v-if="status.backups.tiers[tier].count === 0" class="text-gray-400 font-sans">none yet</li>
						</ul>
					</div>
				</div>
				<p class="text-sm text-gray-600">
					Last scheduled backup: {{ formatMarkerDate(status.backups.last_scheduled) || 'never' }}
					· Disk free: {{ formatBytes(status.backups.disk_free_bytes) }}
				</p>
				<PrimaryButton :disabled="backingUp" @click="backupNow">
					{{ backingUp ? 'Backing up…' : 'Run a Scheduled Backup Now' }}
				</PrimaryButton>

				<h3 class="text-base font-semibold pt-2">Schedule</h3>
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
					<label class="flex items-start gap-2 sm:col-span-2">
						<input type="checkbox" v-model="settings.hourly" class="mt-1 rounded border-gray-300" />
						<span>
							<span class="text-gray-700">Also back up every hour</span>
							<span class="block text-xs text-gray-500">
								In addition to the {{ settings.frequency }} backup above. Hourly backups are kept in the
								Hourly tier only (see "Hourly backups to keep" — 48 is two days of hourly backups).
							</span>
						</span>
					</label>
					<label class="block">
						<span class="text-gray-700">Hourly backups to keep</span>
						<input type="number" min="1" max="500" v-model.number="settings.keep_hourly" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
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
					Changes take effect at the next hourly check — no server restart needed. "Hourly backups to
					keep" counts every raw backup — hourly, scheduled, or run from here; Daily/Monthly/Yearly are space-free
					copies of the day's/month's/year's first scheduled backup.
				</p>
			</section>

			<!-- Application key -->
			<section v-if="canViewAppKey" id="application-key" class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Warehouse key</h2>
				<div class="rounded bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 text-sm space-y-2">
					<p>
						This is this warehouse's own key. It encrypts a few secret values stored in its database (for example the email
						password and the federation key that paired sites and devices know it by). <strong>Backups deliberately do not contain
						it</strong>, so a stolen backup file can't decrypt anything.
					</p>
					<p>
						<strong>Write this key down now and keep it somewhere safe, separate from your backups</strong> (a password manager or a
						sealed envelope). It is needed to restore the encrypted parts of a backup: restoring onto a new system without it loses
						those encrypted values (the restore blanks them and you must re-enter them). To restore everything onto a new system, paste this key
						into “Key from the old system” when you choose the backup there (the setup screen's restore step); an instance
						administrator restoring a whole instance can instead keep it with the backup (<span class="font-mono">instance:backup --with-keys</span>).
					</p>
				</div>

				<div v-if="!revealedKey" class="space-y-2">
					<label class="block text-sm max-w-sm">
						<span class="text-gray-700">Confirm your password to show the key</span>
						<input type="password" v-model="keyPassword" autocomplete="current-password" @keyup.enter="revealKey"
							class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
					</label>
					<PrimaryButton @click="revealKey" :disabled="revealing || !keyPassword">{{ revealing ? 'Checking…' : 'Show Key' }}</PrimaryButton>
				</div>
				<div v-else class="space-y-2">
					<div class="font-mono text-sm break-all border rounded p-3 bg-gray-50 select-all">{{ revealedKey }}</div>
					<div class="flex items-center gap-4">
						<PrimaryButton @click="copyKey">{{ copied ? 'Copied' : 'Copy' }}</PrimaryButton>
						<button @click="hideKey" class="text-sm underline text-gray-600">Hide</button>
					</div>
				</div>
				<p class="text-xs text-gray-500">
					Showing the key is logged. It is hidden again when you leave this page. Anyone holding both this key and a backup file
					can read everything in it.
				</p>
			</section>
		</div>
	</AuthenticatedLayout>
</template>
