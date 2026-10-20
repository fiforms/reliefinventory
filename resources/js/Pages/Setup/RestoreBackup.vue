<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!--
	RestoreBackup.vue — the "restore from a backup" path of the first-run wizard (FirstRun.vue).

	Upload the backup .zip in resumable chunks (composables/chunkedUpload.js, same protocol as the firmware
	upload), then the server takes over (RestoreService, a detached process) and this page polls its status
	file until it finishes. The status poll tolerates failures on purpose: while the database is being replaced
	the server can briefly answer with errors, and that is progress, not a problem. Server half: RestoreController.
-->

<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useChunkedUpload } from '@/composables/chunkedUpload';
import axios from 'axios';
import { onMounted, onUnmounted, ref } from 'vue';

defineProps({
	codeRequired: { type: Boolean, default: false },
});
defineEmits(['back']);

const BASE = '/install/restore';
const { task, percent, start, resume, cancel } = useChunkedUpload(BASE);

// idle -> uploading -> restoring -> complete | failed
const phase = ref('idle');
const setupCode = ref('');
const file = ref(null);
const error = ref('');
const codeError = ref('');
const status = ref({});
let timer = null;

const human = (bytes) => (bytes > 1073741824 ? `${(bytes / 1073741824).toFixed(2)} GB` : `${(bytes / 1048576).toFixed(1)} MB`);

function pick(event) {
	file.value = event.target.files[0] || null;
	error.value = '';
}

function failWith(e, fallback) {
	codeError.value = e.response?.data?.errors?.setup_code?.[0] || '';
	error.value = codeError.value ? '' : e.response?.data?.message || fallback;
}

async function begin() {
	error.value = '';
	codeError.value = '';
	if (!file.value) return;
	phase.value = 'uploading';
	try {
		await start(file.value, { setup_code: setupCode.value || undefined });
		phase.value = 'restoring';
		poll();
	} catch (e) {
		if (!task.id) phase.value = 'idle'; // never started (wrong code, not a fresh instance, ...)
		failWith(e, 'The upload was interrupted. Choose Resume to carry on from where it stopped.');
	}
}

async function resumeUpload() {
	error.value = '';
	try {
		await resume();
		phase.value = 'restoring';
		poll();
	} catch (e) {
		failWith(e, 'The upload was interrupted again. Choose Resume to try once more.');
	}
}

async function cancelUpload() {
	await cancel();
	phase.value = 'idle';
	error.value = '';
}

function poll() {
	clearInterval(timer);
	const tick = async () => {
		try {
			const { data } = await axios.get(`${BASE}/status`);
			status.value = data;
			if (data.state === 'complete') phase.value = 'complete';
			else if (data.state === 'failed') phase.value = 'failed';
			else if (data.state === 'running') phase.value = 'restoring';
			else return;
			if (phase.value === 'complete' || phase.value === 'failed') clearInterval(timer);
		} catch {
			/* the database is being replaced; keep waiting */
		}
	};
	tick();
	timer = setInterval(tick, 2000);
}

function reset() {
	cancel();
	phase.value = 'idle';
	status.value = {};
	error.value = '';
	file.value = null;
}

onMounted(async () => {
	// Page reloaded mid-restore (or the restore finished while away): pick up where the server is.
	try {
		const { data } = await axios.get(`${BASE}/status`);
		if (data.state === 'running') {
			phase.value = 'restoring';
			poll();
		}
	} catch {
		/* nothing to resume */
	}
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
	<div>
		<!-- choose a file -->
		<div v-if="phase === 'idle'" class="mt-3 space-y-4">
			<p class="text-sm text-gray-600">
				Choose a backup file (.zip) made by <strong>Backup Files</strong> in Relief Inventory. The whole system — data
				and, if the backup included them, uploaded photos and documents — is restored onto this new installation.
			</p>

			<div v-if="codeRequired">
				<InputLabel for="restore_code" value="Setup code" />
				<TextInput id="restore_code" type="password" class="mt-1 block w-full" v-model="setupCode" autocomplete="off" />
				<InputError class="mt-2" :message="codeError" />
			</div>

			<div>
				<InputLabel for="restore_file" value="Backup file" />
				<input id="restore_file" type="file" accept=".zip,application/zip" class="mt-1 block w-full text-sm" @change="pick" />
				<p v-if="file" class="mt-1 text-xs text-gray-500">{{ file.name }} — {{ human(file.size) }}</p>
			</div>

			<div class="rounded border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 space-y-1">
				<p>This installation must be new and empty — a backup can only be restored onto one that has never been set up.</p>
				<p>
					The backup does not contain the application key. Everything works without it, but a few encrypted settings (for
					example the centralized-feedback shared secret) can't be carried over; they are cleared and listed for you
					afterwards so you can re-enter them, and everyone signs in again.
				</p>
				<p>The software here must be the same version as the one that made the backup, or newer — never older.</p>
			</div>

			<div v-if="error" class="rounded border border-red-300 bg-red-50 p-2 text-sm text-red-800">{{ error }}</div>

			<div class="flex items-center justify-between">
				<SecondaryButton type="button" @click="$emit('back')">Back</SecondaryButton>
				<PrimaryButton type="button" :disabled="!file" @click="begin">Upload and restore</PrimaryButton>
			</div>
		</div>

		<!-- uploading -->
		<div v-else-if="phase === 'uploading'" class="mt-3 space-y-3">
			<p class="text-sm text-gray-700">Uploading {{ task.file?.name }}…</p>
			<div class="h-3 w-full overflow-hidden rounded bg-gray-200"><div class="h-3 bg-blue-600" :style="{ width: percent + '%' }"></div></div>
			<p class="text-xs text-gray-500">{{ human(task.received) }} of {{ human(task.size) }} ({{ percent }}%). Keep this page open.</p>
			<div v-if="error" class="rounded border border-red-300 bg-red-50 p-2 text-sm text-red-800">{{ error }}</div>
			<div class="flex items-center gap-3">
				<PrimaryButton v-if="task.failed && !task.running" type="button" @click="resumeUpload">Resume</PrimaryButton>
				<SecondaryButton type="button" @click="cancelUpload">Cancel</SecondaryButton>
			</div>
		</div>

		<!-- the server is restoring -->
		<div v-else-if="phase === 'restoring'" class="mt-3 space-y-3">
			<p class="text-sm text-gray-700">Restoring… this can take a while for a large backup. You can leave this page open or come back to it.</p>
			<div class="h-3 w-full overflow-hidden rounded bg-gray-200"><div class="h-3 bg-blue-600" :style="{ width: (status.percent || 1) + '%' }"></div></div>
			<p class="text-xs text-gray-500">{{ status.phase || 'Starting' }}</p>
		</div>

		<!-- done -->
		<div v-else-if="phase === 'complete'" class="mt-3 space-y-3">
			<div class="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-900">
				The backup was restored<span v-if="status.backup_created_at"> (made {{ new Date(status.backup_created_at).toLocaleString() }})</span>.
			</div>
			<div v-if="status.blanked?.length" class="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
				<p class="font-medium">These settings were encrypted with the old system's key and could not be carried over. Re-enter them after signing in:</p>
				<ul class="mt-1 list-disc pl-5"><li v-for="label in status.blanked" :key="label">{{ label }}</li></ul>
			</div>
			<div v-if="!status.includes_uploads" class="text-xs text-gray-600">This backup did not include uploaded photos and documents.</div>
			<div v-if="status.admin_missing" class="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
				The restored data has no administrator account. Continue to create one.
				<a href="/install" class="block mt-2 underline">Continue to setup</a>
			</div>
			<a v-else href="/login" class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">Sign in</a>
		</div>

		<!-- failed -->
		<div v-else-if="phase === 'failed'" class="mt-3 space-y-3">
			<div class="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
				<p class="font-medium">The restore did not finish.</p>
				<p class="mt-1">{{ status.error }}</p>
			</div>
			<PrimaryButton type="button" @click="reset">Try again</PrimaryButton>
		</div>
	</div>
</template>
