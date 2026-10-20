<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- RStationFirmware.vue

	R Station software releases (permission: manage-firmware — deliberately narrower than
	manage-devices, since a bad release reaches the whole fleet): where devices look for
	firmware (this server's own catalogue, or another Relief Inventory server), the catalogue
	of uploaded app/OS releases and the channels each is live on, and a chunked, resumable,
	progress-reporting uploader (a firmware image is ~1 GB, far over PHP's and a proxy's body
	limits). Server half: DeviceReleaseController. Panel-style page per the admin-page-style
	skill — not RIForm.
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

const CHANNELS = ['stable', 'testing', 'developer'];

const error = ref('');
const notice = ref('');
const releases = ref([]);

async function fetchAll() {
	const [r, s] = await Promise.all([axios.get('/json/device-releases'), axios.get('/json/firmware-source')]);
	releases.value = r.data.records;
	sourceUrl.value = s.data.source_url || '';
	savedSource.value = s.data.source_url || '';
}

function fail(e, fallback) {
	error.value = e.response?.data?.errors
		? Object.values(e.response.data.errors).flat().join(' ')
		: e.response?.data?.message || fallback;
}

// ---- Where devices look for firmware ----
const sourceUrl = ref('');
const savedSource = ref('');
const savingSource = ref(false);

async function saveSource() {
	savingSource.value = true;
	error.value = '';
	notice.value = '';
	try {
		const { data } = await axios.put('/json/firmware-source', { source_url: sourceUrl.value.trim() || null });
		savedSource.value = sourceUrl.value = data.source_url || '';
		notice.value = data.source_url
			? 'Saved. Devices paired here will look for software at that server from their next check-in.'
			: 'Saved. This server offers its own releases again.';
	} catch (e) {
		fail(e, 'Could not save the firmware source.');
	} finally {
		savingSource.value = false;
	}
}

// ---- Releases ----
async function toggleTag(release, channel) {
	const action = release.channels?.includes(channel) ? 'untag' : 'tag';
	try {
		await axios.post(`/json/device-releases/${release.id}/${action}`, { channel });
		await fetchAll();
	} catch (e) {
		fail(e, 'Could not change that release\'s channels.');
	}
}

async function deleteRelease(release) {
	if (!window.confirm(`Delete ${release.filename}?`)) return;
	try {
		await axios.delete(`/json/device-releases/${release.id}`);
		await fetchAll();
	} catch (e) {
		fail(e, 'Could not delete that release.');
	}
}

function formatSize(bytes) {
	if (bytes > 1073741824) return `${(bytes / 1073741824).toFixed(2)} GB`;
	return bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.ceil(bytes / 1024)} KB`;
}

// ---- Chunked upload ----
// start -> PUT consecutive raw chunks at explicit offsets -> complete. The server is the source
// of truth for how much it has: every response carries `received`, and a 409 (a chunk it
// already has, or one that skipped ahead) tells us where to carry on from, so a dropped
// connection or a double-sent chunk can never corrupt the file. A failed upload keeps its
// server-side progress and can be resumed with the same file.
const upload = reactive({ kind: 'app', version: '', architecture: 'aarch64', release_type: 'full', required_base_version: '', notes: '' });
const uploadFile = ref(null);
const fileInput = ref(null);
const task = reactive({ id: null, chunkSize: 0, received: 0, size: 0, running: false, cancelled: false, failed: false, file: null });
const percent = computed(() => (task.size ? Math.floor((task.received / task.size) * 100) : 0));
const MAX_RETRIES = 6;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function sendChunks() {
	task.running = true;
	task.failed = false;
	let failures = 0;
	try {
		// Re-sync with the server first: this is what makes "Resume" safe.
		task.received = (await axios.get(`/json/device-releases/uploads/${task.id}`)).data.received;
		while (task.received < task.size && !task.cancelled) {
			const blob = task.file.slice(task.received, Math.min(task.received + task.chunkSize, task.size));
			try {
				const { data } = await axios.put(`/json/device-releases/uploads/${task.id}`, blob, {
					params: { offset: task.received },
					headers: { 'Content-Type': 'application/octet-stream' },
					transformRequest: [(body) => body],
				});
				task.received = data.received;
				failures = 0;
			} catch (e) {
				if (e.response?.status === 409) {
					task.received = e.response.data.received; // carry on from where the server really is
					continue;
				}
				if (e.response && e.response.status < 500 && e.response.status !== 429) throw e; // a refusal won't fix itself
				if (++failures > MAX_RETRIES) throw e;
				await sleep(Math.min(1000 * 2 ** failures, 15000)); // flaky network: back off and retry this chunk
			}
		}
		if (task.cancelled) return false;
		await axios.post(`/json/device-releases/uploads/${task.id}/complete`);
		return true;
	} catch (e) {
		task.failed = true;
		fail(e, 'The upload was interrupted. Choose Resume to carry on from where it stopped.');
		return false;
	} finally {
		task.running = false;
	}
}

async function startUpload() {
	if (!uploadFile.value) return;
	error.value = '';
	notice.value = '';
	const file = uploadFile.value;
	const metadata = Object.fromEntries(Object.entries(upload).filter(([, value]) => value !== ''));
	try {
		const { data } = await axios.post('/json/device-releases/uploads', { ...metadata, filename: file.name, size: file.size });
		Object.assign(task, { id: data.upload_id, chunkSize: data.chunk_size, received: 0, size: file.size, cancelled: false, file });
	} catch (e) {
		fail(e, 'Could not start that upload.');
		return;
	}
	await finishTask();
}

async function resumeUpload() {
	error.value = '';
	await finishTask();
}

async function finishTask() {
	if (await sendChunks()) {
		notice.value = `Uploaded ${task.file.name}. It is offered to no one until you tick a channel for it.`;
		Object.assign(task, { id: null, file: null, received: 0, size: 0, failed: false });
		uploadFile.value = null;
		if (fileInput.value) fileInput.value.value = '';
		upload.version = upload.required_base_version = upload.notes = '';
		await fetchAll();
	}
}

async function cancelUpload() {
	task.cancelled = true;
	const id = task.id;
	// Let an in-flight chunk finish, then discard what the server holds.
	while (task.running) await sleep(100);
	try {
		if (id) await axios.delete(`/json/device-releases/uploads/${id}`);
	} catch (e) {
		// Already gone (or expired): nothing to clean up.
	}
	Object.assign(task, { id: null, file: null, received: 0, size: 0, failed: false, cancelled: false });
	notice.value = 'Upload cancelled.';
}

onMounted(fetchAll);
</script>

<template>
	<Head title="R Station Firmware" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">R Station Firmware</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>

			<!-- Where devices look -->
			<section class="bg-white shadow rounded-lg p-6 space-y-3">
				<h2 class="text-lg font-semibold">Where devices look for software</h2>
				<p class="text-sm text-gray-600">
					Devices always check in with the server they are paired to. By default that server offers
					the releases listed below. To use another Relief Inventory server's releases instead (for example a
					central one that maintains firmware for many sites), enter its address: devices paired here are then
					sent to it for updates, using the update channel set for each device on this server.
				</p>
				<div class="flex gap-3 items-end text-sm">
					<label class="block grow">
						<span class="text-gray-700">Firmware source server (leave empty to use this server)</span>
						<input type="url" v-model="sourceUrl" placeholder="https://relief.example.org" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
					</label>
					<PrimaryButton :disabled="savingSource || sourceUrl.trim() === savedSource" @click="saveSource">
						{{ savingSource ? 'Saving…' : 'Save' }}
					</PrimaryButton>
				</div>
				<p v-if="savedSource" class="text-sm rounded bg-amber-50 border border-amber-300 px-3 py-2">
					Devices paired here currently get software from <strong>{{ savedSource }}</strong>. The releases below are not
					offered to them (but remain available to any server pointed at this one).
				</p>
			</section>

			<!-- Releases -->
			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Software Releases</h2>
				<table v-if="releases.length" class="w-full text-sm">
					<thead>
						<tr class="text-left text-gray-600">
							<th class="py-1">Release</th>
							<th>Live on channel</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="release in releases" :key="release.id" class="border-t">
							<td class="py-2">
								<div><strong>{{ release.kind }}</strong> {{ release.version }}
									<span class="text-gray-500">({{ release.release_type }}, {{ release.architecture }}, {{ formatSize(release.size_bytes) }})</span>
								</div>
								<div v-if="release.required_base_version" class="text-xs text-gray-500">applies on top of {{ release.required_base_version }}</div>
								<div class="text-xs text-gray-400">{{ release.filename }}</div>
							</td>
							<td>
								<label v-for="c in CHANNELS" :key="c" class="mr-3 whitespace-nowrap">
									<input type="checkbox" :checked="release.channels?.includes(c)" @change="toggleTag(release, c)" class="rounded border-gray-300" />
									{{ c }}
								</label>
							</td>
							<td class="text-right">
								<button type="button" class="text-red-700 underline" @click="deleteRelease(release)">Delete</button>
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="text-sm text-gray-400">No releases uploaded yet.</p>

				<h3 class="font-medium text-sm pt-2">Upload a release</h3>
				<fieldset :disabled="task.id !== null" class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
					<label class="block">
						<span class="text-gray-700">Kind</span>
						<select v-model="upload.kind" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
							<option value="app">App (.tar.gz)</option>
							<option value="os">OS bundle (.raucb)</option>
						</select>
					</label>
					<label class="block">
						<span class="text-gray-700">Type</span>
						<select v-model="upload.release_type" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
							<option value="full">Full</option>
							<option v-if="upload.kind === 'os'" value="hotfix">Hotfix (small, on top of one base version)</option>
							<option v-if="upload.kind === 'os'" value="disk_image">Disk image (SD card re-flash; never auto-offered)</option>
						</select>
					</label>
					<label class="block">
						<span class="text-gray-700">Version</span>
						<input type="text" v-model="upload.version" placeholder="e.g. 0.4.0_0.1.1" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
					</label>
					<label class="block">
						<span class="text-gray-700">Architecture</span>
						<input type="text" v-model="upload.architecture" placeholder="aarch64" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
					</label>
					<label v-if="upload.release_type === 'hotfix'" class="block sm:col-span-2">
						<span class="text-gray-700">Required base version (exact)</span>
						<input type="text" v-model="upload.required_base_version" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
					</label>
					<label class="block sm:col-span-2">
						<span class="text-gray-700">File</span>
						<input ref="fileInput" type="file" @change="uploadFile = $event.target.files[0]" class="mt-1 block w-full text-sm" />
					</label>
					<label class="block sm:col-span-2">
						<span class="text-gray-700">Notes (optional)</span>
						<input type="text" v-model="upload.notes" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
					</label>
				</fieldset>

				<div v-if="task.id !== null" class="space-y-2">
					<div class="flex justify-between text-sm">
						<span>
							<strong>{{ task.file?.name }}</strong>
							<template v-if="task.running"> — uploading…</template>
							<template v-else-if="task.failed"> — interrupted</template>
						</span>
						<span>{{ percent }}% · {{ formatSize(task.received) }} of {{ formatSize(task.size) }}</span>
					</div>
					<div class="h-3 w-full rounded bg-gray-200 overflow-hidden" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100">
						<div class="h-3 rounded" :class="task.failed ? 'bg-red-500' : 'bg-blue-600'" :style="{ width: percent + '%' }"></div>
					</div>
					<div class="flex gap-3 text-sm">
						<PrimaryButton v-if="task.failed && !task.running" @click="resumeUpload">Resume</PrimaryButton>
						<button type="button" class="text-red-700 underline" @click="cancelUpload">Cancel upload</button>
					</div>
				</div>
				<PrimaryButton v-else :disabled="!uploadFile || !upload.version" @click="startUpload">Upload</PrimaryButton>

				<p class="text-xs text-gray-500">
					Large files are sent in small pieces, so there is no practical size limit and a dropped connection resumes
					where it stopped (keep this page open while it uploads). A newly uploaded release is offered to no one
					until you tick a channel. Ticking a channel replaces whatever release held that slot there (one full
					release per kind and architecture, one hotfix per base version). Devices are only ever offered newer
					versions, never downgrades.
				</p>
			</section>
		</div>
	</AuthenticatedLayout>
</template>
