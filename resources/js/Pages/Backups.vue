<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Backups.vue

	Full backup files (permission: manage-backups): start a backup zip (database, optionally all
	uploads), watch it run, download or delete finished ones. Plus the "Application key" panel
	(permission: view-app-key, only rendered for people who hold it): reveals APP_KEY after the
	person re-enters their password. Server half: BackupController / BackupService. Panel-style
	page per the admin-page-style skill. The bare-metal tiered backups on /setup/system are separate.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
	breadcrumb: { type: Array },
	canViewAppKey: { type: Boolean, default: false },
});

const error = ref('');
const notice = ref('');
const records = ref(null);
const keepDays = ref(14);
const includeUploads = ref(false);
const starting = ref(false);
const confirmingDelete = ref(null);
let timer = null;

const active = computed(() => (records.value || []).find((r) => r.status === 'queued' || r.status === 'running'));

function fail(e, fallback) {
	error.value = e.response?.data?.errors
		? Object.values(e.response.data.errors).flat().join(' ')
		: e.response?.data?.message || fallback;
}

async function load() {
	try {
		const { data } = await axios.get('/json/backups');
		records.value = data.records;
		keepDays.value = data.keep_days;
	} catch (e) {
		fail(e, 'Could not load the backup list.');
	}
}

async function startBackup() {
	error.value = '';
	notice.value = '';
	starting.value = true;
	try {
		await axios.post('/json/backups', { include_uploads: includeUploads.value });
		notice.value = 'Backup started. You can leave this page; it keeps running on the server.';
		await load();
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
		await load();
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

// ---- Application key ----
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
	await load();
	// Poll while a backup runs (and keep a slow poll otherwise so a second admin's backup shows up).
	timer = setInterval(load, 4000);
});
onUnmounted(() => {
	clearInterval(timer);
	hideKey();
});
</script>

<template>
	<Head title="Backup Files" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Backup Files</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Create a backup</h2>
				<p class="text-sm text-gray-700">
					A backup is one .zip file holding the database and, if you choose, every uploaded file (photos, screenshots, imports).
					It can be used to set up a new, empty copy of this system. Only one backup runs at a time, and a large one can take
					a long time.
				</p>
				<label class="flex items-center gap-2 text-sm">
					<input type="checkbox" v-model="includeUploads" class="rounded border-gray-300" :disabled="!!active" />
					<span>Include all uploaded files (pictures, documents) — larger and slower</span>
				</label>
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
					Backups are deleted automatically {{ keepDays }} days after they are made, or earlier if you delete them here.
					A backup <strong>never</strong> contains the application key — see the panel below.
				</p>
			</section>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
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
									— {{ formatSize(r.size_bytes) }}, {{ r.include_uploads ? 'database + uploads' : 'database only' }}
								</span>
								<span v-else-if="r.status === 'failed'" class="text-red-700">— failed: {{ r.error }}</span>
								<span v-else class="text-blue-700">— {{ r.progress || r.status }}</span>
							</div>
							<div v-if="r.expires_at" class="text-xs text-gray-500">Deleted automatically {{ when(r.expires_at) }}</div>
						</div>
						<div class="flex items-center gap-3">
							<a v-if="r.status === 'complete'" :href="`/json/backups/${r.id}/download`"
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
				</p>
			</section>

			<section v-if="canViewAppKey" class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Application key</h2>
				<div class="rounded bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 text-sm space-y-2">
					<p>
						The application key encrypts a few secret values stored in the database (for example the shared secret for
						centralized feedback) and signs login cookies. <strong>Backups deliberately do not contain it</strong>, so a stolen
						backup file can't decrypt anything.
					</p>
					<p>
						If you restore a backup onto a new system without this key, nothing is lost except those encrypted values — the
						restore blanks them and you re-enter them — and everyone has to log in again. If you want a perfect restore, write the
						key down now and keep it somewhere safe and separate from your backups (a password manager or a sealed envelope).
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
