<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- RStationDevices.vue

	Fleet admin for R Station Raspberry Pi devices and PC/Mac print servers
	(permission: manage-devices): generate a pairing code, configure each
	device (name, site, what it does, update channel), set up the printers a
	print server reports (name, site, paper type, on/off), revoke it, and
	upload/tag the app and OS releases offered to devices on their next
	heartbeat. Server half
	of rstation/platform/docs/DEVICE_CONTRACT.md. Panel-style page per the
	admin-page-style skill — not RIForm.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import axios from 'axios';
import { onMounted, reactive, ref } from 'vue';

defineProps({
	breadcrumb: {
		type: Array,
	},
});

const error = ref('');
const notice = ref('');

const devices = ref([]);
const functions = ref([]);
const channels = ref([]);
const media = ref([]);
const locations = ref([]);
const releases = ref([]);
const savingId = ref(null);

async function fetchAll() {
	const [d, l, r] = await Promise.all([
		axios.get('/json/managed-devices'),
		axios.get('/json/kiosk-locations'),
		axios.get('/json/device-releases'),
	]);
	devices.value = d.data.records;
	functions.value = d.data.functions;
	channels.value = d.data.channels;
	media.value = d.data.media;
	locations.value = l.data.records.filter((loc) => loc.active);
	releases.value = r.data.records;
	if (!pairLocationId.value && locations.value.length) pairLocationId.value = locations.value[0].id;
}

function fail(e, fallback) {
	error.value = e.response?.data?.message || fallback;
}

// ---- Pairing ----
const pairLocationId = ref(null);
const pairingCode = ref(null);

async function generateCode() {
	error.value = '';
	try {
		const response = await axios.post('/json/device-pairing-codes', { kiosk_location_id: pairLocationId.value });
		pairingCode.value = response.data.record;
	} catch (e) {
		fail(e, 'Could not generate a pairing code.');
	}
}

// ---- Devices ----
async function saveDevice(device) {
	savingId.value = device.id;
	error.value = '';
	notice.value = '';
	try {
		await axios.put(`/json/managed-devices/${device.id}`, device);
		notice.value = `Saved ${device.name}. It picks up the change on its next heartbeat (within 5 minutes).`;
	} catch (e) {
		fail(e, 'Could not save that device.');
	} finally {
		savingId.value = null;
	}
}

// ---- Printers (queues a print server reported) ----
const savingPrinterId = ref(null);
const confirmingPrinterRemove = ref(null);

function printerDot(printer) {
	if (printer.status === 'idle' || printer.status === 'printing') return 'bg-green-500';
	if (printer.status === 'error') return 'bg-red-500';
	return 'bg-amber-500';
}

function printerState(printer) {
	if (printer.status === 'unavailable') return printer.status_message || 'unavailable';
	return printer.status_message ? `${printer.status}: ${printer.status_message}` : printer.status;
}

async function savePrinter(printer) {
	savingPrinterId.value = printer.id;
	error.value = '';
	notice.value = '';
	try {
		const { data } = await axios.put(`/json/printers/${printer.id}`, printer);
		Object.assign(printer, data.record);
		notice.value = `Saved ${printer.name}.`;
	} catch (e) {
		fail(e, 'Could not save that printer.');
	} finally {
		savingPrinterId.value = null;
	}
}

async function removePrinter(printer) {
	if (confirmingPrinterRemove.value !== printer.id) {
		confirmingPrinterRemove.value = printer.id;
		return;
	}
	confirmingPrinterRemove.value = null;
	error.value = '';
	notice.value = '';
	try {
		const { data } = await axios.delete(`/json/printers/${printer.id}`);
		notice.value = data.message;
		await fetchAll();
	} catch (e) {
		fail(e, 'Could not remove that printer.');
	}
}

async function revokeDevice(device) {
	if (!window.confirm(`Revoke ${device.name}? It will wipe its pairing and return to setup at its next heartbeat.`)) return;
	try {
		await axios.post(`/json/managed-devices/${device.id}/revoke`);
		await fetchAll();
	} catch (e) {
		fail(e, 'Could not revoke that device.');
	}
}

async function removeDevice(device) {
	if (!window.confirm(`Remove ${device.name} from the list?`)) return;
	try {
		await axios.delete(`/json/managed-devices/${device.id}`);
		await fetchAll();
	} catch (e) {
		fail(e, 'Could not remove that device.');
	}
}

function lastSeen(device) {
	return device.last_seen_at ? new Date(device.last_seen_at).toLocaleString() : 'never';
}

// ---- Releases ----
const upload = reactive({ kind: 'app', version: '', architecture: 'aarch64', release_type: 'full', required_base_version: '', notes: '' });
const uploadFile = ref(null);
const uploading = ref(false);

async function uploadRelease() {
	if (!uploadFile.value) return;
	uploading.value = true;
	error.value = '';
	const form = new FormData();
	Object.entries(upload).forEach(([key, value]) => value !== '' && form.append(key, value));
	form.append('file', uploadFile.value);
	try {
		await axios.post('/json/device-releases', form);
		upload.version = '';
		upload.required_base_version = '';
		upload.notes = '';
		await fetchAll();
	} catch (e) {
		fail(e, 'Could not upload that release.');
	} finally {
		uploading.value = false;
	}
}

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
	return bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.ceil(bytes / 1024)} KB`;
}

onMounted(fetchAll);
</script>

<template>
	<Head title="R Station Devices" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">R Station Devices</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>

			<!-- Pairing -->
			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Pair a New Device or Print Server</h2>
				<div class="flex gap-3 items-end text-sm">
					<label class="block">
						<span class="text-gray-700">Site</span>
						<select v-model.number="pairLocationId" class="mt-1 block border-gray-300 rounded-md shadow-sm">
							<option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option>
						</select>
					</label>
					<PrimaryButton :disabled="!pairLocationId" @click="generateCode">Generate Code</PrimaryButton>
				</div>
				<div v-if="pairingCode" class="rounded bg-blue-50 border border-blue-300 px-4 py-3">
					<span class="text-3xl font-mono tracking-widest">{{ pairingCode.code }}</span>
					<span class="ml-3 text-sm text-gray-600">Enter this on the device's (or print server's) setup screen. Single use; expires {{ new Date(pairingCode.expires_at).toLocaleTimeString() }}.</span>
				</div>
			</section>

			<!-- Devices -->
			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Devices</h2>
				<p v-if="!devices.length" class="text-sm text-gray-400">No devices paired yet.</p>
				<div v-for="device in devices" :key="device.id" class="border rounded p-3 space-y-3">
					<div class="flex items-center gap-3 text-sm">
						<span class="inline-block w-3 h-3 rounded-full" :class="device.revoked ? 'bg-red-500' : device.online ? 'bg-green-500' : 'bg-gray-400'"></span>
						<strong>{{ device.name }}</strong>
						<span class="text-gray-500">
							{{ device.revoked ? 'revoked' : device.online ? 'online' : 'offline' }} · last seen {{ lastSeen(device) }}
						</span>
					</div>
					<div class="text-xs text-gray-500">
						<template v-if="device.platform">{{ device.platform }} · </template>app {{ device.app_version || '?' }} · OS {{ device.os_version || '?' }} · {{ device.architecture || '?' }}
						<template v-if="device.hostname"> · {{ device.hostname }}.local</template>
						<template v-if="device.cpu_temp_c != null"> · {{ device.cpu_temp_c }}°C</template>
					</div>
					<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
						<label class="block">
							<span class="text-gray-700">Name</span>
							<input type="text" maxlength="255" v-model="device.name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
						<label class="block">
							<span class="text-gray-700">Site</span>
							<select v-model="device.kiosk_location_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
								<option :value="null">— none —</option>
								<option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option>
							</select>
						</label>
						<label class="block">
							<span class="text-gray-700">Function</span>
							<select v-model="device.kiosk_function" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
								<option :value="null">— not configured —</option>
								<option v-for="f in functions" :key="f.key" :value="f.key">{{ f.label }}</option>
							</select>
						</label>
						<label class="block">
							<span class="text-gray-700">Update channel</span>
							<select v-model="device.update_channel" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
								<option v-for="c in channels" :key="c" :value="c">{{ c }}</option>
							</select>
						</label>
						<label class="flex items-center gap-2 sm:col-span-2">
							<input type="checkbox" v-model="device.os_auto_update_enabled" class="rounded border-gray-300" />
							<span class="text-gray-700">Allow OS updates to apply automatically (overnight window)</span>
						</label>
					</div>
					<div class="flex gap-3 items-center">
						<PrimaryButton :disabled="savingId === device.id" @click="saveDevice(device)">
							{{ savingId === device.id ? 'Saving…' : 'Save' }}
						</PrimaryButton>
						<button v-if="!device.revoked" type="button" class="text-sm text-red-700 underline" @click="revokeDevice(device)">Revoke</button>
						<button type="button" class="text-sm text-gray-600 underline" @click="removeDevice(device)">Remove</button>
					</div>

					<!-- Printers this print server reports -->
					<div v-if="device.kiosk_function === 'print_station' || device.printers?.length" class="border-t pt-3 space-y-3">
						<h3 class="font-medium text-sm">Printers</h3>
						<p v-if="!device.printers?.length" class="text-sm text-gray-400">
							No printers reported yet. Once the print server is running it lists the printers it can see here at its next check-in.
						</p>
						<div v-for="printer in device.printers" :key="printer.id" class="border rounded p-3 space-y-3 bg-gray-50">
							<div class="flex items-center gap-2 text-sm">
								<span class="inline-block w-3 h-3 rounded-full" :class="printerDot(printer)"></span>
								<span class="font-mono text-xs text-gray-600">{{ printer.queue_name }}</span>
								<span class="text-gray-500">{{ printerState(printer) }}</span>
								<span v-if="!printer.active" class="text-xs text-gray-400">· off</span>
							</div>
							<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
								<label class="block">
									<span class="text-gray-700">Name</span>
									<input type="text" maxlength="255" v-model="printer.name" placeholder="e.g. Shipping Floor" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
								</label>
								<label class="block">
									<span class="text-gray-700">Site</span>
									<select v-model="printer.kiosk_location_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
										<option :value="null">— none —</option>
										<option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option>
									</select>
								</label>
								<label class="block">
									<span class="text-gray-700">Paper type</span>
									<select v-model="printer.media" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
										<option :value="null">— not set —</option>
										<option v-for="m in media" :key="m.key" :value="m.key">{{ m.label }}</option>
									</select>
								</label>
								<label class="block">
									<span class="text-gray-700">Menu order</span>
									<input type="number" min="0" max="9999" v-model.number="printer.sort_order" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
								</label>
								<label class="flex items-center gap-2 sm:col-span-2">
									<input type="checkbox" v-model="printer.active" class="rounded border-gray-300" />
									<span class="text-gray-700">Offer this printer in Print menus</span>
								</label>
							</div>
							<div class="flex gap-3 items-center">
								<PrimaryButton :disabled="savingPrinterId === printer.id" @click="savePrinter(printer)">
									{{ savingPrinterId === printer.id ? 'Saving…' : 'Save Printer' }}
								</PrimaryButton>
								<button
									type="button"
									class="text-sm underline"
									:class="confirmingPrinterRemove === printer.id ? 'text-red-700 font-semibold' : 'text-gray-600'"
									@click="removePrinter(printer)"
								>
									{{ confirmingPrinterRemove === printer.id ? 'Confirm — it returns if the server still reports it' : 'Remove' }}
								</button>
								<button v-if="confirmingPrinterRemove === printer.id" type="button" class="text-sm text-gray-600 underline" @click="confirmingPrinterRemove = null">Cancel</button>
							</div>
						</div>
						<p class="text-xs text-gray-500">
							A printer is only offered in Print menus once it has a site and a paper type and is switched on.
							Changing a paper type needs the printer's queue to be empty.
						</p>
					</div>
				</div>
				<p class="text-xs text-gray-500">
					Devices check in every 5 minutes; changes made here reach a device on its next check-in.
					Revoking sends it back to setup. A device unseen for 15+ minutes shows as offline.
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
								<label v-for="c in channels" :key="c" class="mr-3 whitespace-nowrap">
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
				<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
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
						<input type="file" @change="uploadFile = $event.target.files[0]" class="mt-1 block w-full text-sm" />
					</label>
					<label class="block sm:col-span-2">
						<span class="text-gray-700">Notes (optional)</span>
						<input type="text" v-model="upload.notes" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
					</label>
				</div>
				<PrimaryButton :disabled="uploading || !uploadFile || !upload.version" @click="uploadRelease">
					{{ uploading ? 'Uploading…' : 'Upload' }}
				</PrimaryButton>
				<p class="text-xs text-gray-500">
					A newly uploaded release is offered to no one until you tick a channel. Ticking a channel
					replaces whatever release held that slot there (one full release per kind and architecture,
					one hotfix per base version). Devices are only ever offered newer versions, never downgrades.
					Very large uploads may need PHP's upload_max_filesize / post_max_size raised.
				</p>
			</section>
		</div>
	</AuthenticatedLayout>
</template>
