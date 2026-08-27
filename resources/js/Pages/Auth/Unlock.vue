<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details

	Shared-terminal PIN unlock screen. Three states, driven entirely by
	server-computed props (never guessed client-side):
	  - Device not yet approved: only "Log in with email" — exactly like
	    hitting this URL on a brand-new/unrecognized browser.
	  - Approved, badge+PIN not required: tap a name tile OR (when
	    badgeLoginEnabled) scan a badge to pick who you are, then enter
	    your PIN.
	  - Approved, badge+PIN required: badge scan is mandatory to identify
	    yourself (no tile-tap shortcut) before the PIN field appears.
	    (require_badge_and_pin can't be on without badgeLoginEnabled — see
	    PinLoginSettingsController.)
	A successful unlock does a real page navigation (not an Inertia visit)
	so the freshly-authenticated session's shared props (auth.user) are
	picked up cleanly.
-->

<script setup>
import { ref, computed, nextTick } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import QrScanner from '@/Components/QrScanner.vue';
import axios from 'axios';

const props = defineProps({
	deviceApproved: { type: Boolean, required: true },
	people: { type: Array, default: () => [] },
	requireBadgeAndPin: { type: Boolean, default: false },
	badgeLoginEnabled: { type: Boolean, default: false },
});

const selectedPerson = ref(null);
const pinDigits = ref(['', '', '', '', '']);
const pinError = ref(null);
const pinSubmitting = ref(false);
const pinInputs = ref([]);

const badgeInput = ref('');
const badgeError = ref(null);
const badgeScanning = ref(false);
const showCameraScan = ref(false);

const pinValue = computed(() => pinDigits.value.join(''));

function selectPerson(person) {
	selectedPerson.value = person;
	pinDigits.value = ['', '', '', '', ''];
	pinError.value = null;
	nextTick(() => pinInputs.value[0]?.focus());
}

function cancelSelection() {
	selectedPerson.value = null;
	pinDigits.value = ['', '', '', '', ''];
	pinError.value = null;
}

function onDigitInput(index, event) {
	const value = event.target.value.replace(/\D/g, '').slice(-1);
	pinDigits.value[index] = value;
	if (value && index < 4) {
		nextTick(() => pinInputs.value[index + 1]?.focus());
	}
	if (pinValue.value.length === 5) {
		submitPin();
	}
}

function onDigitKeydown(index, event) {
	if (event.key === 'Backspace' && !pinDigits.value[index] && index > 0) {
		nextTick(() => pinInputs.value[index - 1]?.focus());
	}
}

async function submitPin() {
	if (pinValue.value.length !== 5 || !selectedPerson.value) return;
	pinSubmitting.value = true;
	pinError.value = null;
	try {
		const response = await axios.post('/unlock/pin', {
			person_id: selectedPerson.value.id,
			pin: pinValue.value,
		});
		window.location.href = response.data.redirect || '/dashboard';
	} catch (error) {
		pinError.value = error.response?.data?.message || 'Could not unlock. Please try again.';
		pinDigits.value = ['', '', '', '', ''];
		nextTick(() => pinInputs.value[0]?.focus());
	} finally {
		pinSubmitting.value = false;
	}
}

async function submitBadge() {
	const code = badgeInput.value.trim();
	if (!code) return;
	badgeScanning.value = true;
	badgeError.value = null;
	try {
		const response = await axios.post('/unlock/badge', { badge_code: code });
		selectPerson(response.data.person);
	} catch (error) {
		badgeError.value = error.response?.data?.message || 'Badge not recognized.';
	} finally {
		badgeInput.value = '';
		badgeScanning.value = false;
	}
}

function onCameraScanned(text) {
	showCameraScan.value = false;
	badgeInput.value = text;
	submitBadge();
}
</script>

<template>
	<Head title="Unlock" />
	<GuestLayout>
		<div class="text-center mb-4">
			<h1 class="text-xl font-bold text-gray-800">Unlock</h1>
		</div>

		<!-- ======================= DEVICE NOT APPROVED ======================= -->
		<div v-if="!deviceApproved" class="text-center space-y-4">
			<p class="text-sm text-gray-600">
				This device isn't set up for quick login yet. Log in with your email and password —
				an administrator can approve this device for PIN login afterward under Settings.
			</p>
			<Link :href="route('login', { email: 1 })"
				class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
				Log in with email
			</Link>
		</div>

		<!-- ======================= APPROVED: PICK A PERSON ======================= -->
		<div v-else-if="!selectedPerson" class="space-y-5">
			<div v-if="(!requireBadgeAndPin || !badgeLoginEnabled) && people.length" class="space-y-2">
				<p class="text-sm text-gray-600 text-center">Select user</p>
				<div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
					<button v-for="person in people" :key="person.id" @click="selectPerson(person)"
						class="border rounded-lg py-3 px-2 text-sm font-medium text-gray-700 hover:bg-gray-50 hover:border-gray-400">
						{{ person.full_name }}
					</button>
				</div>
			</div>

			<div v-if="badgeLoginEnabled" class="space-y-2">
				<p class="text-sm text-gray-600 text-center">
					{{ requireBadgeAndPin ? 'Scan your badge to continue' : 'Or scan your badge' }}
				</p>
				<p v-if="badgeError" class="text-sm text-red-600 text-center">{{ badgeError }}</p>
				<div class="flex gap-2">
					<input
						type="text"
						v-model="badgeInput"
						:disabled="badgeScanning"
						placeholder="Scan badge or type code..."
						class="flex-1 border-gray-300 rounded-md shadow-sm"
						@keydown.enter.prevent="submitBadge"
					/>
					<button @click="showCameraScan = true"
						class="px-3 py-2 border rounded-md text-sm text-gray-600 hover:bg-gray-50">
						Camera
					</button>
				</div>
			</div>

			<div v-if="!requireBadgeAndPin || !badgeLoginEnabled" class="text-center pt-2 border-t">
				<Link :href="route('login', { email: 1 })" class="text-sm text-gray-500 underline">
					Log in with email
				</Link>
			</div>
		</div>

		<!-- ======================= PIN ENTRY ======================= -->
		<div v-else class="space-y-4 text-center">
			<button @click="cancelSelection" class="text-sm text-gray-500">&larr; Back</button>
			<p class="text-sm text-gray-600">{{ selectedPerson.full_name }}</p>
			<p class="text-xs text-gray-500">Enter your PIN</p>

			<div class="flex justify-center gap-2">
				<input
					v-for="(digit, index) in pinDigits"
					:key="index"
					:ref="el => pinInputs[index] = el"
					type="password"
					inputmode="numeric"
					maxlength="1"
					:value="digit"
					:disabled="pinSubmitting"
					class="w-12 h-14 text-center text-xl border-gray-300 rounded-md shadow-sm"
					@input="onDigitInput(index, $event)"
					@keydown="onDigitKeydown(index, $event)"
				/>
			</div>

			<p v-if="pinError" class="text-sm text-red-600">{{ pinError }}</p>
			<p v-if="pinSubmitting" class="text-sm text-gray-500">Checking…</p>
		</div>

		<!-- ======================= CAMERA SCAN MODAL ======================= -->
		<div v-if="showCameraScan" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" @click.self="showCameraScan = false">
			<div class="bg-white rounded-lg p-4">
				<h3 class="font-semibold mb-2">Scan Badge</h3>
				<QrScanner @scanned="onCameraScanned" />
				<button @click="showCameraScan = false" class="mt-2 text-sm text-gray-500 underline">Cancel</button>
			</div>
		</div>
	</GuestLayout>
</template>
