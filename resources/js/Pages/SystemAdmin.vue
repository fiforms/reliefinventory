<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- SystemAdmin.vue

	Warehouse Backups (/setup/system) — backups and keys for the current warehouse. (Software updates, offline mode and
	whole-instance backups are on Instance Admin, /setup/instance, InstanceAdmin.vue.) Each section is shown only when
	the person holds its permission:

	  manage-backups   Create a backup zip (database, or database + uploaded files), list / download / delete them, and this
	                   warehouse's snapshot schedule: hourly / daily / weekly / monthly tiers and how many of each to keep
	                   (BackupController::schedule / updateSchedule, BackupSchedule). The same page and schedule serve a Docker
	                   install (the scheduler container asks every hour) and a bare-metal one (its systemd timer runs
	                   scripts/update.sh --scheduled, which asks the same command).
	  admin-system     Rolling the warehouse back to a listed backup (password re-entry; progress shows here, polled from
	                   /w/<id>/restore-status).
	  view-app-key     Reveal the warehouse key (password re-entry).

	Server half: BackupController (zips, schedule, rollback, key).
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { currentWarehouseId } from '@/bootstrap';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
	breadcrumb: { type: Array },
	canAdminSystem: { type: Boolean, default: false },
	canManageBackups: { type: Boolean, default: false },
	canViewAppKey: { type: Boolean, default: false },
});

const error = ref('');
const notice = ref('');

function fail(e, fallback) {
	error.value = e.response?.data?.errors
		? Object.values(e.response.data.errors).flat().join(' ')
		: e.response?.data?.message || fallback;
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
		schedule.value = data.schedule;
		// The form is filled once (and again after a save); the 4-second poll must not overwrite what is being typed.
		if (!form.value) form.value = scheduleForm(data.schedule);
	} catch (e) {
		if (!quiet) fail(e, 'Could not load the backup list.');
	}
}

// ---- Snapshot schedule (manage-backups; Docker, where the scheduler container asks every hour) ----
const schedule = ref(null);
const form = ref(null);
const savingSchedule = ref(false);
const scheduleSaved = ref(false);
const tierNames = { hourly: 'Hourly', daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly' };
const weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

const scheduleForm = (s) => ({
	hourly_enabled: !!s.hourly_enabled, hourly_keep: s.hourly_keep,
	daily_enabled: !!s.daily_enabled, daily_hour: s.daily_hour, daily_keep: s.daily_keep, daily_with_files: !!s.daily_with_files,
	weekly_enabled: !!s.weekly_enabled, weekly_dow: s.weekly_dow, weekly_keep: s.weekly_keep, weekly_with_files: !!s.weekly_with_files,
	monthly_enabled: !!s.monthly_enabled, monthly_keep: s.monthly_keep, monthly_with_files: !!s.monthly_with_files,
});

// An upper bound: every kept snapshot as big as the latest backup. One zip often serves several tiers, so real use is usually lower.
const estimatedBytes = computed(() => {
	if (!form.value || !schedule.value?.last_size_bytes) return null;
	const kept = Object.keys(tierNames).reduce((n, t) => n + (form.value[`${t}_enabled`] ? Number(form.value[`${t}_keep`]) || 0 : 0), 0);
	return kept * schedule.value.last_size_bytes;
});

async function saveSchedule() {
	error.value = '';
	notice.value = '';
	scheduleSaved.value = false;
	savingSchedule.value = true;
	try {
		const { data } = await axios.put('/json/backups/schedule', form.value);
		schedule.value = data;
		form.value = scheduleForm(data);
		scheduleSaved.value = true;
	} catch (e) {
		fail(e, 'Could not save the schedule.');
	} finally {
		savingSchedule.value = false;
	}
}

const tiersOf = (r) => (r.tiers ? r.tiers.split(',') : []);

// ---- Roll back to a listed backup (admin-system; the restore engine, so it asks for the password) ----
const rollbackTarget = ref(null);
const rollbackPassword = ref('');
const rollingBack = ref(false);

function askRollback(record) {
	error.value = '';
	rollbackPassword.value = '';
	rollbackTarget.value = rollbackTarget.value?.id === record.id ? null : record;
}

// The rollback replaces the database, and the sessions with it, so progress comes from the status page that needs no login
// (RestoreController::status), the same one the restore page polls. Failures while the database is swapped are expected: keep asking.
const rollback = ref(null);   // null, or the last status the server gave
let rollbackTimer = null;

async function confirmRollback() {
	error.value = '';
	rollingBack.value = true;
	try {
		await axios.post(`/json/backups/${rollbackTarget.value.id}/restore`, { password: rollbackPassword.value });
		rollbackTarget.value = null;
		rollback.value = { state: 'running', phase: 'Starting', percent: 1 };
		clearInterval(backupTimer);   // the list can't be read while the database is replaced
		pollRollback();
	} catch (e) {
		fail(e, 'Could not start the rollback.');
		rollingBack.value = false;
	} finally {
		rollbackPassword.value = '';
	}
}

function pollRollback() {
	const tick = async () => {
		try {
			const { data } = await axios.get(`/w/${currentWarehouseId() ?? ''}/restore-status`);
			if (data.state === 'running' || data.state === 'complete' || data.state === 'failed') rollback.value = data;
			if (data.state === 'complete' || data.state === 'failed') {
				clearInterval(rollbackTimer);
				rollingBack.value = false;
			}
		} catch {
			/* the database is being replaced; keep waiting */
		}
	};
	clearInterval(rollbackTimer);
	tick();
	rollbackTimer = setInterval(tick, 2000);
}

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
	if (props.canManageBackups) {
		await loadBackups();
		// Poll while a backup runs (and keep polling so a second admin's backup shows up).
		// Quiet: an update elsewhere puts the site in maintenance mode, and its 503s aren't worth showing.
		backupTimer = setInterval(() => {
			loadBackups({ quiet: true });
		}, 4000);
	}
});
onUnmounted(() => {
	clearInterval(backupTimer);
	clearInterval(rollbackTimer);
	hideKey();
});
</script>

<template>
	<Head title="Warehouse Backups" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Warehouse Backups</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>

			<!-- A rollback in progress (everyone is signed out while the data is swapped) -->
			<section v-if="rollback" class="rounded-lg border-2 p-5 space-y-2 text-sm"
				:class="rollback.state === 'failed' ? 'border-red-400 bg-red-50 text-red-900' : rollback.state === 'complete' ? 'border-green-500 bg-green-50 text-green-900' : 'border-blue-400 bg-blue-50 text-blue-900'">
				<h2 class="text-base font-bold">
					{{ rollback.state === 'failed' ? 'The rollback did not finish' : rollback.state === 'complete' ? 'Rolled back' : 'Rolling back this warehouse…' }}
				</h2>
				<p v-if="rollback.state === 'running'">
					{{ rollback.phase || 'Working…' }} <span v-if="rollback.percent">({{ rollback.percent }}%)</span> —
					keep this page open. Everyone is signed out while the data is replaced.
				</p>
				<p v-else-if="rollback.state === 'failed'">{{ rollback.error }}</p>
				<p v-else>
					The data is back to {{ rollback.backup_created_at ? when(rollback.backup_created_at) : 'that backup' }}.
					<a href="/login" class="underline font-semibold">Sign in again</a>. A copy of the data as it was just before is kept in this warehouse's
					backup folder (named “before-rollback”) for {{ keepDays }} days.
				</p>
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
					Backups you make here are deleted automatically {{ keepDays }} days after they are made, or earlier if you delete them below.
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
							<div v-if="tiersOf(r).length" class="flex gap-1">
								<span v-for="t in tiersOf(r)" :key="t" class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ tierNames[t] || t }}</span>
							</div>
							<div>
								{{ when(r.finished_at || r.created_at) }}
								<span v-if="r.status === 'complete'" class="text-gray-600">
									— {{ formatSize(r.size_bytes) }}, {{ scopeNames[r.scope] || r.scope }}
								</span>
								<span v-else-if="r.status === 'failed'" class="text-red-700">— failed: {{ r.error }}</span>
								<span v-else class="text-blue-700">— {{ r.progress || r.status }}</span>
							</div>
							<div v-if="r.expires_at" class="text-xs text-gray-500">Deleted automatically {{ when(r.expires_at) }}</div>
							<div v-else-if="tiersOf(r).length" class="text-xs text-gray-500">Kept by the snapshot schedule below, until newer ones replace it</div>
						</div>
						<div class="flex items-center gap-3">
							<a v-if="r.status === 'complete'" :href="$withWarehouse(`/json/backups/${r.id}/download`)"
								class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white bg-gray-800 hover:bg-gray-700">Download</a>
							<button v-if="canAdminSystem && r.status === 'complete' && r.scope !== 'storage' && !rollback" @click="askRollback(r)"
								class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white bg-blue-700 hover:bg-blue-600">
								{{ rollbackTarget?.id === r.id ? 'Cancel' : 'Roll back to this' }}
								</button>
							<button v-if="r.status !== 'queued' && r.status !== 'running'" @click="deleteBackup(r)"
								class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white"
								:class="confirmingDelete === r.id ? 'bg-red-600 hover:bg-red-500' : 'bg-amber-600 hover:bg-amber-500'">
								{{ confirmingDelete === r.id ? 'Confirm — delete this backup' : 'Delete' }}
							</button>
							<button v-if="confirmingDelete === r.id" @click="confirmingDelete = null" class="text-xs underline text-gray-600">Cancel</button>
						</div>
						<!-- Roll back: says what is lost, then asks for the password -->
						<div v-if="rollbackTarget?.id === r.id" class="basis-full rounded border border-blue-300 bg-blue-50 p-3 space-y-2 text-sm text-blue-900">
							<p>
								<strong>Everything entered after {{ when(r.finished_at || r.created_at) }} will be lost</strong> — receiving, sorting, orders,
								people, changes of every kind{{ r.scope === 'full' ? ', and the uploaded files are put back as they were then' : '; uploaded files are left as they are' }}.
								Everyone is signed out while it happens. First, a copy of the data as it is now is saved in this warehouse's backup folder,
								so a rollback made by mistake can be undone by an administrator at the server.
							</p>
							<form @submit.prevent="confirmRollback" class="flex flex-wrap items-center gap-3">
								<input type="password" v-model="rollbackPassword" autocomplete="current-password" placeholder="Your password"
									class="rounded-md border-gray-300 text-sm" />
								<button type="submit" :disabled="rollingBack || !rollbackPassword"
									class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white bg-red-600 hover:bg-red-500 disabled:opacity-50">
									{{ rollingBack ? 'Starting…' : 'Roll back now' }}
								</button>
							</form>
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

			<!-- Snapshot schedule: the scheduler (Docker) or the systemd timer (bare metal) asks this warehouse every hour -->
			<section v-if="canManageBackups && form" class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Snapshot Schedule</h2>
				<p class="text-sm text-gray-700">
					Snapshots are ordinary backups made automatically, so this warehouse can be rolled back to how it was an hour, a day or a week ago
					(“Roll back to this” in the list above). One backup can serve several tiers at once, and the newest few of each tier are kept.
					<strong>They sit on this same machine</strong>, so they protect against mistakes, not against losing the machine: copy backups elsewhere too
					(the RI Launcher can do that for you).
				</p>
				<p v-if="!schedule.scheduler_enabled" class="rounded bg-amber-100 border border-amber-400 text-amber-900 px-3 py-2 text-sm">
					Scheduled backups are turned off for this whole installation (<span class="font-mono">RI_BACKUP_ENABLED=false</span> in its settings), so nothing below runs.
				</p>
				<form @submit.prevent="saveSchedule" class="space-y-3 text-sm">
					<div class="border rounded p-3 flex flex-wrap items-center gap-x-6 gap-y-2">
						<label class="flex items-center gap-2 w-28 font-medium"><input type="checkbox" v-model="form.hourly_enabled" class="rounded border-gray-300" /> Hourly</label>
						<label class="flex items-center gap-2">Keep the newest <input type="number" min="1" max="168" v-model.number="form.hourly_keep" class="w-20 rounded-md border-gray-300 text-sm" /></label>
						<span class="text-xs text-gray-500">Database only.</span>
					</div>
					<div class="border rounded p-3 flex flex-wrap items-center gap-x-6 gap-y-2">
						<label class="flex items-center gap-2 w-28 font-medium"><input type="checkbox" v-model="form.daily_enabled" class="rounded border-gray-300" /> Daily</label>
						<label class="flex items-center gap-2">After <select v-model.number="form.daily_hour" class="rounded-md border-gray-300 text-sm"><option v-for="h in 24" :key="h" :value="h - 1">{{ String(h - 1).padStart(2, '0') }}:00</option></select> ({{ schedule.timezone }})</label>
						<label class="flex items-center gap-2">Keep the newest <input type="number" min="1" max="90" v-model.number="form.daily_keep" class="w-20 rounded-md border-gray-300 text-sm" /></label>
						<label class="flex items-center gap-2"><input type="checkbox" v-model="form.daily_with_files" class="rounded border-gray-300" /> include uploaded files</label>
					</div>
					<div class="border rounded p-3 flex flex-wrap items-center gap-x-6 gap-y-2">
						<label class="flex items-center gap-2 w-28 font-medium"><input type="checkbox" v-model="form.weekly_enabled" class="rounded border-gray-300" /> Weekly</label>
						<label class="flex items-center gap-2">On <select v-model.number="form.weekly_dow" class="rounded-md border-gray-300 text-sm"><option v-for="(d, i) in weekdays" :key="d" :value="i">{{ d }}</option></select></label>
						<label class="flex items-center gap-2">Keep the newest <input type="number" min="1" max="52" v-model.number="form.weekly_keep" class="w-20 rounded-md border-gray-300 text-sm" /></label>
						<label class="flex items-center gap-2"><input type="checkbox" v-model="form.weekly_with_files" class="rounded border-gray-300" /> include uploaded files</label>
					</div>
					<div class="border rounded p-3 flex flex-wrap items-center gap-x-6 gap-y-2">
						<label class="flex items-center gap-2 w-28 font-medium"><input type="checkbox" v-model="form.monthly_enabled" class="rounded border-gray-300" /> Monthly</label>
						<span class="text-gray-600">First check of the month, after the daily hour.</span>
						<label class="flex items-center gap-2">Keep the newest <input type="number" min="1" max="36" v-model.number="form.monthly_keep" class="w-20 rounded-md border-gray-300 text-sm" /></label>
						<label class="flex items-center gap-2"><input type="checkbox" v-model="form.monthly_with_files" class="rounded border-gray-300" /> include uploaded files</label>
					</div>
					<div class="flex flex-wrap items-center gap-4">
						<PrimaryButton type="submit" :disabled="savingSchedule">{{ savingSchedule ? 'Saving…' : 'Save Schedule' }}</PrimaryButton>
						<span v-if="scheduleSaved" class="text-green-700">Saved.</span>
						<span v-if="schedule.last_run_at" class="text-xs text-gray-500">
							Last scheduled backup: {{ when(schedule.last_run_at) }} — {{ schedule.last_status === 'complete' ? 'made' : 'FAILED' }}
						</span>
					</div>
				</form>
				<ul class="text-xs text-gray-500 space-y-1 list-disc pl-5">
					<li v-if="estimatedBytes">
						Disk use is at most about <strong>{{ formatSize(estimatedBytes) }}</strong> if every kept snapshot is as large as the latest backup
						({{ formatSize(schedule.last_size_bytes) }}); usually less, because one backup often serves several tiers. Backups with files are larger.
					</li>
					<li>Turning a tier off stops new ones; the ones already made stay until newer ones of that tier replace them or you delete them.</li>
					<li>
						Before an upgrade that changes the database a whole-installation backup is made automatically (set in the compose file). Backups you make by hand
						above are deleted after {{ keepDays }} days.
					</li>
				</ul>
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
