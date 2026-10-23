<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Setup/Training.vue

	Setup > Training Mode (TrainingAdminController, manage-training; the on/off switch needs
	admin-system). While Training Mode is on, approved devices offer a tap-your-name login for the
	trainees listed here, capped to station work (App\Services\TrainingMode). Prepare Training loads
	starter work for every station and the setup packet tells trainers how to stage the floor.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import PrintMenu from '@/Components/PrintMenu.vue';
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
const confirmingPrepare = ref(false);
const preparing = ref(false);
const newTrainee = ref({ first_name: '', last_name: '', team: null });
const showInactive = ref(false);
const event = ref({ event_title: '', event_location: '' });
const savingEvent = ref(false);

const trainees = computed(() => (status.value?.trainees || []).filter((t) => showInactive.value || t.active));

async function load() {
	try {
		status.value = (await axios.get('/json/training/status')).data;
		event.value = { event_title: status.value.event_title || '', event_location: status.value.event_location || '' };
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not load Training Mode settings.';
	}
}

async function saveEvent() {
	error.value = notice.value = null;
	savingEvent.value = true;
	try {
		status.value = (await axios.put('/json/training/event', event.value)).data;
		notice.value = 'Certificate details saved.';
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not save the certificate details.';
	} finally {
		savingEvent.value = false;
	}
}

async function setMode(enabled) {
	error.value = notice.value = null;
	busy.value = true;
	try {
		status.value = (await axios.put('/json/training/mode', { enabled })).data;
		notice.value = enabled ? 'Training Mode is on.' : 'Training Mode is off — approved devices show the normal login again.';
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not change Training Mode.';
	} finally {
		busy.value = false;
	}
}

async function prepare() {
	if (!confirmingPrepare.value) {
		confirmingPrepare.value = true;
		return;
	}
	error.value = notice.value = null;
	confirmingPrepare.value = false;
	preparing.value = true;
	try {
		const { data } = await axios.post('/json/training/prepare');
		notice.value = 'Training data prepared. Print the setup packet and pallet labels below.'
			+ (data.missing_items?.length ? ' Skipped item numbers not in the Master Item List: ' + data.missing_items.join(', ') + '.' : '');
		await load();
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not prepare training data.';
	} finally {
		preparing.value = false;
	}
}

async function addTrainee() {
	error.value = notice.value = null;
	try {
		status.value = (await axios.post('/json/training/trainees', newTrainee.value)).data;
		notice.value = newTrainee.value.first_name + ' ' + newTrainee.value.last_name + ' added.';
		newTrainee.value = { first_name: '', last_name: '', team: newTrainee.value.team };
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not add that trainee.';
	}
}

async function updateTrainee(trainee, changes) {
	error.value = notice.value = null;
	try {
		status.value = (await axios.patch('/json/training/trainees/' + trainee.id, changes)).data;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not update that trainee.';
	}
}

function formatTime(value) {
	return value ? new Date(value).toLocaleString() : '';
}

onMounted(load);
</script>

<template>
	<Head title="Training Mode" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Training Mode</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>
			<p v-if="!status && !error" class="text-gray-500">Loading Training Mode…</p>

			<template v-if="status">
				<!-- ======================= THE SWITCH ======================= -->
				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">Training Mode is {{ status.enabled ? 'on' : 'off' }}</h2>
					<p class="text-sm text-gray-700">
						While it's on, an approved device shows a <strong>tap-your-name</strong> login for trainees
						— no password. That login can only do station work (Receiving, Sorting, Inventory
						Movement, Order Filling, Shipping, Order Entry, and reports); anything in Setup, user
						changes or inventory adjustments still need a staff member's full login. Everything else
						in the system works exactly as usual, so every practice donation, pallet and order is real
						data in this instance.
					</p>
					<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
						<div class="border rounded p-3">
							<div class="text-gray-500">Approved devices</div>
							<div class="text-lg font-semibold">{{ status.approved_devices }}</div>
						</div>
						<div class="border rounded p-3">
							<div class="text-gray-500">Trainees on the list</div>
							<div class="text-lg font-semibold">{{ status.trainees.filter((t) => t.active).length }}</div>
						</div>
						<div class="border rounded p-3">
							<div class="text-gray-500">On since</div>
							<div class="font-mono text-gray-600 text-xs mt-1">{{ status.enabled ? formatTime(status.enabled_at) : 'off' }}</div>
						</div>
					</div>
					<div v-if="status.can_toggle">
						<PrimaryButton :disabled="busy" @click="setMode(!status.enabled)">
							{{ busy ? 'Saving…' : (status.enabled ? 'Turn Training Mode off' : 'Turn Training Mode on') }}
						</PrimaryButton>
					</div>
					<p v-else class="text-sm text-gray-500">Turning Training Mode on or off needs the System Administration permission.</p>
					<p class="text-xs text-gray-500">
						To approve a tablet, log in on it and choose <strong>PIN Login on This Device</strong> from the
						profile menu (the same approval PIN unlock uses; requests from others wait on
						<Link href="/setup/pin-login" class="underline">PIN Login</Link>). Staff can still log in on a training device with their
						PIN or email from the links under the name tiles.
					</p>
				</section>

				<!-- ======================= SELF SIGN-UP ======================= -->
				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">Sign-up page</h2>
					<p class="text-sm text-gray-700">
						People sign themselves up on their own phones: name, and optionally email, phone, whether
						they'd like project updates, how they'd like to help, and someone they'd recommend. Each
						sign-up goes straight onto the trainee list. The page only accepts sign-ups while Training
						Mode is on, and it never logs anyone in.
					</p>
					<div class="flex flex-wrap items-center gap-6">
						<div class="w-40 h-40 shrink-0 [&>svg]:w-full [&>svg]:h-full" v-html="status.signup.qr_svg"></div>
						<div class="space-y-3 text-sm">
							<div><a :href="status.signup.url" target="_blank" class="font-mono text-blue-600 underline break-all">{{ status.signup.url }}</a></div>
							<div class="grid grid-cols-2 gap-4">
								<div class="border rounded p-3">
									<div class="text-gray-500">Signed up today</div>
									<div class="text-lg font-semibold">{{ status.signup.today }}</div>
								</div>
								<div class="border rounded p-3">
									<div class="text-gray-500">All time</div>
									<div class="text-lg font-semibold">{{ status.signup.total }}</div>
								</div>
							</div>
							<div class="flex flex-wrap gap-2">
								<PrintMenu document-key="training-signup-poster" media="report" variant="form" label="QR Poster"
									download-href="/report/training/signup-poster.pdf" />
								<a href="/report/training/signups.csv" class="ri_formbutton">Follow-up List (CSV)</a>
								<button type="button" class="ri_formbutton" @click="load">Refresh</button>
							</div>
						</div>
					</div>
					<p class="text-xs text-gray-500">
						Point your short link at the address above. The follow-up list has everyone's answers
						(contact details, updates, how they'd help, recommendations).
					</p>
				</section>

				<!-- ======================= PREPARE ======================= -->
				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">Prepare Training</h2>
					<p class="text-sm text-gray-700">
						Loads starter work so every station has something to do from the first minute: donation
						offers waiting to arrive, pallets waiting to sort, stock on sealed pallets and in the sorting
						area, orders waiting to be picked and shipped, a driver for each team, and BOLs waiting for
						review. Then print the setup packet and labels and stage the props to match.
					</p>
					<div v-if="preparing" class="rounded bg-blue-50 border border-blue-300 text-blue-800 px-4 py-3 text-sm">Preparing training data…</div>
					<div class="flex flex-wrap items-center gap-3">
						<button type="button" :disabled="!status.enabled || preparing" @click="prepare"
							class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white disabled:opacity-50"
							:class="confirmingPrepare ? 'bg-red-600 hover:bg-red-500' : 'bg-amber-600 hover:bg-amber-500'">
							{{ confirmingPrepare ? 'Confirm — adds a full set of training records' : 'Prepare Training' }}
						</button>
						<button v-if="confirmingPrepare" type="button" class="text-sm underline" @click="confirmingPrepare = false">Cancel</button>
						<template v-if="status.prepared">
							<PrintMenu document-key="training-setup-packet" media="report" variant="form" label="Setup Packet"
								download-href="/report/training/setup-packet.pdf" />
							<PrintMenu document-key="training-pallet-labels" :media="$page.props.printing.palletLabelMedia" variant="form"
								label="Pallet Labels" download-href="/report/training/pallet-labels.pdf" />
						</template>
					</div>
					<p v-if="!status.enabled" class="text-sm text-gray-500">Turn Training Mode on first.</p>
					<p class="text-xs text-gray-500">
						Last prepared: <span class="font-mono">{{ status.prepared_at ? formatTime(status.prepared_at) : 'never' }}</span>.
						Running it again adds another full set (it never removes anything). The items and amounts
						come from <span class="font-mono">config/training.php</span>.
					</p>
				</section>

				<!-- ======================= TEAM SHEETS & REPORT CARDS ======================= -->
				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">Team sheets and report cards</h2>
					<div class="flex flex-wrap items-center gap-3">
						<PrintMenu document-key="training-all-teams" media="report" variant="form" label="All Team Sheets"
							download-href="/report/training/team-sheets.pdf" />
						<Link href="/reports/training" class="ri_formbutton">Report Cards &amp; Certificates</Link>
					</div>
					<form class="grid grid-cols-1 sm:grid-cols-5 gap-4 text-sm items-end" @submit.prevent="saveEvent">
						<label class="block sm:col-span-2">
							<span class="text-gray-700">Training event title (on certificates)</span>
							<input v-model="event.event_title" maxlength="150" placeholder="Hands-on Warehouse Training"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
						<label class="block sm:col-span-2">
							<span class="text-gray-700">Where the class was held</span>
							<input v-model="event.event_location" maxlength="150" placeholder="e.g. Statesville Warehouse, Statesville, NC"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
						<div><PrimaryButton :disabled="savingEvent">{{ savingEvent ? 'Saving…' : 'Save' }}</PrimaryButton></div>
					</form>
					<p class="text-xs text-gray-500">
						A certificate reads "has completed <em>title</em> held at <em>location</em> on <em>date</em>". Left
						blank, the title is "Hands-on Warehouse Training" and the location is left out.
					</p>
					<p class="text-xs text-gray-500">
						Each team's sheet can also be printed from the <Link href="/help" class="underline">Help</Link> page.
						Print the team sheets after Prepare Training so they include each team's driver login.
					</p>
				</section>

				<!-- ======================= TRAINEES ======================= -->
				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">Trainees</h2>
					<form class="grid grid-cols-1 sm:grid-cols-4 gap-4 text-sm items-end" @submit.prevent="addTrainee">
						<label class="block">
							<span class="text-gray-700">First name</span>
							<input v-model="newTrainee.first_name" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
						<label class="block">
							<span class="text-gray-700">Last name</span>
							<input v-model="newTrainee.last_name" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
						<label class="block">
							<span class="text-gray-700">Team</span>
							<select v-model="newTrainee.team" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
								<option :value="null">Choose at login</option>
								<option v-for="n in status.teams" :key="n" :value="n">Team {{ n }}</option>
							</select>
						</label>
						<div><PrimaryButton>Add Trainee</PrimaryButton></div>
					</form>

					<label class="text-sm flex items-center gap-2">
						<input type="checkbox" v-model="showInactive" /> Show trainees taken off the list
					</label>

					<p v-if="!trainees.length" class="text-sm text-gray-400">None yet — add them here, or with Add New on a training device.</p>
					<table v-else class="ri_datatable w-full text-sm" border="1">
						<thead><tr><th>Name</th><th>Team</th><th>Actions today</th><th></th></tr></thead>
						<tbody>
							<tr v-for="t in trainees" :key="t.id" :class="{ 'text-gray-400': !t.active }">
								<td>{{ t.full_name }}</td>
								<td>
									<select :value="t.team" class="border-gray-300 rounded-md shadow-sm text-sm py-1"
										@change="updateTrainee(t, { team: $event.target.value ? Number($event.target.value) : null })">
										<option value="">—</option>
										<option v-for="n in status.teams" :key="n" :value="n">Team {{ n }}</option>
									</select>
								</td>
								<td>{{ t.actions_today }}</td>
								<td>
									<button type="button" class="text-sm underline" @click="updateTrainee(t, { active: !t.active })">
										{{ t.active ? 'Take off the list' : 'Put back on the list' }}
									</button>
								</td>
							</tr>
						</tbody>
					</table>
					<p class="text-xs text-gray-500">
						Taking a trainee off the list removes their name tile and stops them logging in; their
						activity and report card stay.
					</p>
				</section>
			</template>
		</div>
	</AuthenticatedLayout>
</template>
