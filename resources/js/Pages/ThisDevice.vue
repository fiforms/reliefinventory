<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- ThisDevice.vue

	PIN Login on This Device (profile menu; ThisDeviceController). Shows whether the device you're
	using is approved for PIN login, and lets you ask for it — with an optional station name and
	reason. Someone with manage-trusted-devices approves it right here; anyone else's request goes to
	Setup > PIN Login for an approver. The request records this device's IP address, browser and OS.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

defineProps({
	breadcrumb: { type: Array },
});

const status = ref(null);
const error = ref(null);
const notice = ref(null);
const busy = ref(false);
const form = ref({ label: '', reason: '' });

const device = computed(() => status.value?.device);
// A request can be made (or re-made) unless the device is already approved.
const canAsk = computed(() => !device.value || device.value.status !== 'approved');

async function load() {
	try {
		status.value = (await axios.get('/json/this-device')).data;
		form.value.label = status.value.device?.label || '';
		form.value.reason = status.value.device?.status === 'pending' ? (status.value.device.request_reason || '') : '';
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not check this device.';
	}
}

async function submit() {
	error.value = notice.value = null;
	busy.value = true;
	try {
		status.value = (await axios.post('/json/this-device', {
			label: form.value.label || null,
			reason: form.value.reason || null,
		})).data;
		notice.value = status.value.device.status === 'approved'
			? 'This device is approved for PIN login.'
			: 'Request sent. An administrator will approve or deny it.';
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not send the request.';
	} finally {
		busy.value = false;
	}
}

function when(value) {
	return value ? new Date(value).toLocaleString() : '';
}

onMounted(load);
</script>

<template>
	<Head title="PIN Login on This Device" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-2xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">PIN Login on This Device</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>
			<p v-if="!status && !error" class="text-gray-500">Checking this device…</p>

			<template v-if="status">
				<section class="bg-white shadow rounded-lg p-6 space-y-3">
					<h2 class="text-lg font-semibold">This device</h2>
					<div class="text-sm space-y-1">
						<div v-if="!device" class="text-gray-700">Not set up for PIN login.</div>
						<template v-else>
							<div>
								<span class="font-semibold">{{ device.label || 'Unnamed station' }}</span> —
								<span v-if="device.status === 'approved'" class="text-green-700 font-semibold">approved</span>
								<span v-else-if="device.status === 'pending'" class="text-amber-700 font-semibold">waiting for approval</span>
								<span v-else-if="device.status === 'denied'" class="text-red-700 font-semibold">request denied</span>
								<span v-else class="text-gray-600 font-semibold">revoked</span>
							</div>
							<div v-if="device.status === 'pending'" class="text-gray-600">
								Requested {{ when(device.requested_at) }}<template v-if="device.requester"> by {{ device.requester }}</template>.
							</div>
							<div v-if="device.status === 'approved' && device.approver" class="text-gray-600">
								Approved by {{ device.approver }} {{ when(device.approved_at) }}.
							</div>
						</template>
						<div class="text-xs text-gray-500 pt-1">
							{{ [status.detected.browser, status.detected.os].filter(Boolean).join(' on ') || 'Unknown browser' }}
							· IP address <span class="font-mono">{{ status.detected.ip_address }}</span>
						</div>
					</div>

					<p v-if="!status.pin_login_enabled" class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded px-3 py-2">
						PIN login is switched off for the whole system right now. A device can still be approved,
						but PIN login won't be offered until an administrator turns it on.
					</p>
					<p v-if="device?.status === 'approved' && !status.has_pin" class="text-sm text-gray-700">
						To use it yourself, set a PIN on your <Link :href="route('profile.edit')" class="text-blue-600 underline">Profile</Link>.
					</p>
				</section>

				<section v-if="canAsk" class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">
						{{ status.can_approve ? 'Approve this device' : (device?.status === 'pending' ? 'Update your request' : 'Ask for PIN login here') }}
					</h2>
					<p class="text-sm text-gray-700">
						<template v-if="status.can_approve">You can approve devices, so this takes effect straight away.</template>
						<template v-else>An administrator will see your request on Setup &gt; PIN Login and approve or deny it.</template>
						Both fields are optional.
					</p>
					<form class="space-y-4 text-sm" @submit.prevent="submit">
						<label class="block">
							<span class="text-gray-700">Station name</span>
							<input v-model="form.label" maxlength="255" placeholder="e.g. Sorting Station 1"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
						<label class="block">
							<span class="text-gray-700">Reason</span>
							<textarea v-model="form.reason" rows="2" maxlength="1000" placeholder="e.g. Shared tablet at the sorting tables"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></textarea>
						</label>
						<PrimaryButton :disabled="busy">
							{{ busy ? 'Sending…' : (status.can_approve ? 'Approve This Device' : (device?.status === 'pending' ? 'Update Request' : 'Request PIN Login')) }}
						</PrimaryButton>
					</form>
					<p class="text-xs text-gray-500">
						This records the device's IP address, browser and operating system shown above, so the
						administrator can tell which device is asking.
					</p>
				</section>
			</template>
		</div>
	</AuthenticatedLayout>
</template>
