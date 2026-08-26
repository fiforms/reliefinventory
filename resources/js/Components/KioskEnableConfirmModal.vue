<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- KioskEnableConfirmModal.vue

	Shared "Enable Kiosk Mode?" confirmation — used from the Setup menu
	tile (Dashboard.vue, so the confirmation happens right where the tile
	was tapped, before ever navigating anywhere) and from the on-page
	"Enable Kiosk Mode" button on VolunteerKiosk.vue itself. One modal, one
	copy of the warning text, one place to change the styling.

	Does the actual enable-lock POST itself and emits `enabled` on success
	so the caller can navigate — this component doesn't know or care where
	it's being shown from.
-->

<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
	show: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close', 'enabled']);

const enabling = ref(false);
const error = ref(null);

// Only shown/asked when there's more than one active location — a
// single-site install (still the common case) never sees this.
const locations = ref([]);
const locationId = ref(null);

watch(() => props.show, async (show) => {
	if (!show) return;
	try {
		const response = await axios.get('/json/kiosk-locations/active');
		locations.value = response.data.records;
		locationId.value = locations.value.length === 1 ? locations.value[0].id : null;
	} catch (e) {
		locations.value = [];
	}
});

function cancel() {
	if (enabling.value) return;
	error.value = null;
	emit('close');
}

async function confirm() {
	// No Fullscreen API attempt here (or anywhere in the kiosk flow) —
	// tried and dropped. It was requested at this click (the only place
	// with a real user gesture to spend) but Safari broke visibly: entering
	// fullscreen starts a native transition animation, and the navigation
	// below (once the enable-lock POST resolves) fired while that was still
	// mid-flight, interrupting it and leaving the browser stuck on a black
	// frame. iPad kiosks are the realistic deployment target and Safari
	// won't reliably honor this regardless, so it's not worth chasing
	// further — real lockdown, if ever needed, is an OS-level `--kiosk`
	// launch on the device, not something this page can arrange.
	enabling.value = true;
	error.value = null;
	try {
		await axios.post('/json/volunteer-kiosk/enable-lock', { location_id: locationId.value });
		emit('enabled');
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not enable kiosk mode.';
		enabling.value = false;
	}
}
</script>

<template>
	<Modal :show="show" @close="cancel">
		<div class="kecm_body">
			<h2 class="kecm_title">Enable Kiosk Mode?</h2>
			<p class="kecm_text">
				This will log you out and lock this device to sign-in/sign-out only — no login required — until
				someone logs back in.
			</p>
			<label v-if="locations.length > 1" class="ri_formcontrol">
				<span class="text-gray-700">Which location is this kiosk at?</span>
				<select v-model.number="locationId" class="ri_forminput">
					<option :value="null">— Choose —</option>
					<option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option>
				</select>
			</label>
			<p v-if="error" class="kecm_error">{{ error }}</p>
			<div class="ri_formactions">
				<button type="button" class="ri_defaultbutton" :disabled="enabling || (locations.length > 1 && !locationId)" @click="confirm">
					{{ enabling ? 'Enabling…' : 'Enable Kiosk Mode' }}
				</button>
				<button type="button" class="ri_formbutton" :disabled="enabling" @click="cancel">Cancel</button>
			</div>
		</div>
	</Modal>
</template>

<style scoped>
.kecm_body {
	padding: 1.5em;
}
.kecm_title {
	font-size: 1.25rem;
	font-weight: 700;
	color: #111827;
	margin: 0 0 0.6em;
}
.kecm_text {
	color: #4b5563;
	font-size: 0.95rem;
	line-height: 1.5;
	margin: 0 0 1em;
}
.kecm_error {
	color: #b91c1c;
	font-size: 0.85rem;
	margin: 0 0 1em;
}
</style>
