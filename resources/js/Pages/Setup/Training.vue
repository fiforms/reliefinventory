<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Setup/Training.vue

	Setup > Training Warehouse (TrainingAdminController, manage-training, a key only a training warehouse has). Approved devices offer a tap-your-name login for the
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
const event = ref({ event_title: '', event_location: '', trainer1_name: '', trainer2_name: '' });
const uploadingSlot = ref(null);
const confirmingReset = ref(false);
const resetText = ref('');
const resetting = ref(false);
const savingEvent = ref(false);

const trainees = computed(() => (status.value?.trainees || []).filter((t) => showInactive.value || t.active));

async function load() {
	try {
		status.value = (await axios.get('/json/training/status')).data;
		event.value = {
			event_title: status.value.event_title || '',
			event_location: status.value.event_location || '',
			trainer1_name: status.value.trainers[0].name || '',
			trainer2_name: status.value.trainers[1].name || '',
		};
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not load the training warehouse settings.';
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

async function startReset() {
	error.value = notice.value = null;
	resetting.value = true;
	try {
		await axios.post('/json/training/reset', { confirm: resetText.value });
		confirmingReset.value = false;
		watchReset();
	} catch (e) {
		resetting.value = false;
		error.value = e.response?.data?.message || 'Could not start the reset.';
	}
}

// The reset runs in the background: this warehouse answers 503 while it is rebuilt and, rebuilt, has none of today's
// sessions (401/419), so keep asking until the answer says how it ended.
function watchReset() {
	const timer = setInterval(async () => {
		try {
			const data = (await axios.get('/json/training/status')).data;
			if (data.reset_status?.state === 'running') return;
			clearInterval(timer);
			resetting.value = false;
			status.value = data;
			if (data.reset_status?.state === 'failed') error.value = 'The reset failed: ' + data.reset_status.error;
			else notice.value = 'The reset is done.';
		} catch (e) {
			const code = e.response?.status;
			if (code === 401 || code === 419) {
				clearInterval(timer);
				window.location.href = '/login';
			} // 503 (being rebuilt) or a dropped connection: still going
		}
	}, 3000);
}

function archiveTime(stamp) {
	const [d, t] = stamp.split('_');
	return new Date(d + 'T' + t.slice(0, 2) + ':' + t.slice(2, 4) + ':' + t.slice(4, 6)).toLocaleString();
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
		notice.value = 'Training data prepared. Print the setup packet and labels below.'
			+ (data.warnings?.length ? ' ' + data.warnings.join(' ') : '');
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

async function uploadSignature(slot, input) {
	const file = input.files[0];
	if (!file) return;
	error.value = notice.value = null;
	uploadingSlot.value = slot;
	try {
		const form = new FormData();
		form.append('signature', file);
		status.value = (await axios.post('/json/training/signatures/' + slot, form)).data;
		notice.value = 'Signature uploaded.';
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not upload that signature.';
	} finally {
		uploadingSlot.value = null;
		input.value = '';
	}
}

async function removeSignature(slot) {
	error.value = notice.value = null;
	try {
		status.value = (await axios.delete('/json/training/signatures/' + slot)).data;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not remove that signature.';
	}
}

function formatTime(value) {
	return value ? new Date(value).toLocaleString() : '';
}

onMounted(async () => {
	await load();
	if (status.value?.reset_status?.state === 'running') {
		resetting.value = true;
		watchReset();
	}
});
</script>

<template>
	<Head title="Training Warehouse" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Training Warehouse</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>
			<p v-if="!status && !error" class="text-gray-500">Loading…</p>

			<template v-if="status">
				<!-- ======================= THIS WAREHOUSE ======================= -->
				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">This is a training warehouse</h2>
					<p class="text-sm text-gray-700">
						Everything entered here is practice data and never mixes with a real warehouse. An approved device shows a
						<strong>tap-your-name</strong> login for trainees &mdash; no password &mdash; and a trainee can do what the Trainee
						role grants (station work and reports; nothing in Setup). To close this warehouse after the class, ask the person who runs the
						instance to turn it off (<span class="font-mono">php artisan warehouse:training disable</span>); its data is kept until it is reset.
					</p>
					<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
						<div class="border rounded p-3">
							<div class="text-gray-500">Approved devices</div>
							<div class="text-lg font-semibold">{{ status.approved_devices }}</div>
						</div>
						<div class="border rounded p-3">
							<div class="text-gray-500">Trainees on the list</div>
							<div class="text-lg font-semibold">{{ status.trainees.filter((t) => t.active).length }}</div>
						</div>
					</div>
					<div v-if="resetting" class="rounded bg-blue-50 border border-blue-300 text-blue-800 px-4 py-3 text-sm">
						Resetting this warehouse… It is unavailable for a minute or two, and everyone is logged out when it finishes.
						This page goes to the login screen by itself.
					</div>
					<div v-else class="space-y-3">
						<button v-if="!confirmingReset" type="button"
							class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white bg-red-600 hover:bg-red-500"
							@click="confirmingReset = true; resetText = ''">Reset this warehouse…</button>
						<div v-if="confirmingReset" class="border border-red-300 bg-red-50 rounded p-4 space-y-2 text-sm">
							<p class="font-semibold text-red-800">This erases everything entered in this warehouse and starts it again from its seed.</p>
							<p>
								Practice donations, orders, pallets and stock, trainees and sign-ups, report cards, items added to the Master Item
								List, uploaded photos and approved devices are erased. <strong>Kept:</strong> the people who log in here (with their
								roles), the certificate details and signatures, and the class archives. Nothing outside this warehouse is touched.
							</p>
							<p>
								First, a <strong>class archive</strong> is saved: the sign-up follow-up list, report cards (PDF and CSV) and
								certificates. Download it below afterwards.
							</p>
							<label class="block">Type <strong>RESET</strong> to confirm:
								<input v-model="resetText" class="ml-2 border-gray-300 rounded-md shadow-sm text-sm py-1 w-32" autocomplete="off" />
							</label>
							<div class="flex gap-3">
								<button type="button" :disabled="resetText.trim() !== 'RESET'" @click="startReset"
									class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white bg-red-600 hover:bg-red-500 disabled:opacity-50">
									Reset now</button>
								<button type="button" class="text-sm underline" @click="confirmingReset = false">Cancel</button>
							</div>
						</div>
					</div>
					<div v-if="status.reset_status?.state === 'failed' && !resetting" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3 text-sm">
						The last reset failed: {{ status.reset_status.error }}
					</div>
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
								<a :href="$withWarehouse('/report/training/signups.csv')" class="ri_formbutton">Follow-up List (CSV)</a>
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
						Receiving and Sorting use your own goods, not scanned: put out boxes to receive, and the teams
						sort what they bring in. Prepare Training adds offers for those deliveries to match. The goods
						that start out already in the warehouse — the sorting area table, sealed trays at the table spots
						with orders to pick, and picked orders to ship (Stations 3–5) — are the ones you scan first, so the
						system knows exactly what is there. Each team also gets a driver. After its first station every
						team works on the goods it handled one station earlier. The setup packet says which scanned goods
						go at which spot or in which load, with a contents slip for each and a sign for each station.
					</p>
					<div class="flex flex-wrap items-center gap-4 border rounded p-3 text-sm">
						<div><span class="text-lg font-semibold">{{ status.products.units }}</span> of {{ status.products.plan.target }} items scanned</div>
						<div><span class="text-lg font-semibold">{{ status.products.distinct }}</span> of {{ status.products.plan.min_products }} different products</div>
						<Link href="/setup/training/products" class="ri_defaultbutton">Scan Training Products</Link>
					</div>
					<div v-if="preparing" class="rounded bg-blue-50 border border-blue-300 text-blue-800 px-4 py-3 text-sm">Preparing training data…</div>
					<div class="flex flex-wrap items-center gap-3">
						<button type="button" :disabled="preparing || status.products.units < status.products.plan.target" @click="prepare"
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
						<PrintMenu document-key="training-spot-labels" :media="$page.props.printing.palletLabelMedia" variant="form"
							label="Table Spot Labels" download-href="/report/training/spot-labels.pdf" />
					</div>
					<p v-if="status.products.units < status.products.plan.target" class="text-sm text-gray-500">
						Scan at least {{ status.products.plan.target }} items first ({{ status.products.plan.target - status.products.units }} more).
					</p>
					<p class="text-xs text-gray-500">
						Last prepared: <span class="font-mono">{{ status.prepared_at ? formatTime(status.prepared_at) : 'never' }}</span>.
						Running it again adds another full set from the same scanned goods (it never removes anything).
						How many items each station gets is set in <span class="font-mono">config/training.php</span>.
						The table spot labels (A-1 to C-4, with a barcode for Inventory Movement to scan) print on the
						pallet label stock.
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
					<h3 class="font-semibold">Certificates</h3>
					<form class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm" @submit.prevent="saveEvent">
						<label class="block">
							<span class="text-gray-700">Training event title</span>
							<input v-model="event.event_title" maxlength="150" placeholder="Hands-on Warehouse Training"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
						<label class="block">
							<span class="text-gray-700">Where the class was held</span>
							<input v-model="event.event_location" maxlength="150" placeholder="e.g. Statesville Warehouse, Statesville, NC"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
						<div v-for="trainer in status.trainers" :key="trainer.slot" class="border rounded p-3 space-y-2">
							<label class="block">
								<span class="text-gray-700">{{ trainer.slot === 1 ? 'Left' : 'Right' }} signature — trainer's name</span>
								<input v-model="event['trainer' + trainer.slot + '_name']" maxlength="100" placeholder="Trainer"
									class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
							</label>
							<div class="h-16 border border-dashed rounded flex items-center justify-center bg-gray-50">
								<img v-if="trainer.has_signature" :src="'/json/training/signatures/' + trainer.slot + '?v=' + trainer.signature_version"
									alt="Signature" class="max-h-14 max-w-full" />
								<span v-else class="text-xs text-gray-400">No signature — the line prints blank to sign by hand</span>
							</div>
							<div class="flex flex-wrap items-center gap-3">
								<label class="ri_formbutton cursor-pointer">
									{{ uploadingSlot === trainer.slot ? 'Uploading…' : (trainer.has_signature ? 'Replace signature' : 'Upload signature') }}
									<input type="file" accept="image/png,image/jpeg" class="hidden" :disabled="uploadingSlot !== null"
										@change="uploadSignature(trainer.slot, $event.target)" />
								</label>
								<button v-if="trainer.has_signature" type="button" class="text-sm underline" @click="removeSignature(trainer.slot)">Remove</button>
							</div>
						</div>
						<div class="sm:col-span-2"><PrimaryButton :disabled="savingEvent">{{ savingEvent ? 'Saving…' : 'Save certificate details' }}</PrimaryButton></div>
					</form>
					<p class="text-xs text-gray-500">
						A certificate reads "has completed <em>title</em> held at <em>location</em> on <em>date</em>", with the two
						trainers' signature lines at the bottom left and right. Left blank, the title is "Hands-on Warehouse
						Training", the location is left out and each line is labelled "Trainer". Signatures are PNG or JPEG
						(up to 2 MB) — a scan or photo of a signature on white paper cropped close works; a PNG with a
						transparent background looks best. Uploading a signature takes effect right away; names need Save.
					</p>
					<p class="text-xs text-gray-500">
						Each team's sheet can also be printed from the <Link href="/help" class="underline">Help</Link> page.
						Print the team sheets after Prepare Training so they include each team's driver login.
					</p>
				</section>

				<!-- ======================= CLASS ARCHIVES ======================= -->
				<section v-if="status.archives.length" class="bg-white shadow rounded-lg p-6 space-y-3">
					<h2 class="text-lg font-semibold">Class archives</h2>
					<p class="text-sm text-gray-700">Saved by each "reset to before training" just before the database was put back.</p>
					<table class="ri_datatable w-full text-sm" border="1">
						<thead><tr><th>Saved</th><th>Files</th></tr></thead>
						<tbody>
							<tr v-for="a in status.archives" :key="a.stamp">
								<td>{{ archiveTime(a.stamp) }}</td>
								<td>
									<a v-for="f in a.files" :key="f.name" :href="$withWarehouse('/report/training/archives/' + a.stamp + '/' + f.name)"
										class="underline text-blue-700 mr-4">{{ f.label }}</a>
								</td>
							</tr>
						</tbody>
					</table>
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
