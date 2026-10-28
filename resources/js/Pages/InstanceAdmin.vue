<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- InstanceAdmin.vue

	Instance Admin (/setup/instance) — what belongs to the whole instance rather than one warehouse. Instance
	administrators only (route middleware `instance-admin`; the JSON half is /json/instance/*, which reuses
	SystemController's actions). Tabs:

	  Updates & Mode           Software version / update (Docker: through the host-side updater, see InstanceUpdate;
	                           systemd: the DEPRECATED in-app updater) and Offline mode.
	  Full Instance Backups    One zip of every warehouse plus the control and accounts schemas. Command-line only for
	                           now (it needs the database administrator's password, which only the provisioner container
	                           holds) — this tab says how.
	  Warehouses               The warehouse:* commands as buttons (InstanceWarehouseController): list, create, edit, suspend /
	                           maintenance / activate / resume, archive, remove, setup codes, hostnames. Every change asks for
	                           the person's password again. Removal waits 30 days after archiving here (the artisan command
	                           removes at once).

	`runtime` ('systemd' | 'docker') comes from config('system.runtime'). During an update the site enters
	maintenance mode, so status polls start failing with 503 — expected, and shown as progress rather than an error.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import axios from 'axios';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';

const props = defineProps({
	breadcrumb: { type: Array },
	runtime: { type: String, default: 'systemd' },
});

const docker = computed(() => props.runtime === 'docker');

const tab = ref('updates');
const status = ref(null);
const error = ref('');
const notice = ref('');
const checking = ref(false);
const updating = ref(false);
const confirmingUpdate = ref(false);
const inMaintenance = ref(false);
const savingOfflineMode = ref(false);

// Docker: the self-update flag the host-side updater works from (status.docker_update).
const dockerUpdate = computed(() => status.value?.docker_update ?? null);
const dockerBusy = computed(() => ['requested', 'checking', 'pulling', 'restarting'].includes(dockerUpdate.value?.state));
const requestingDocker = ref(false);
const confirmingDockerUpdate = ref(false);
const dockerStateText = {
	requested: 'Waiting for the updater on the server to pick this up…',
	checking: 'Looking for a new version…',
	pulling: 'Downloading the new version…',
	restarting: 'Installing. The site is offline for a few minutes and this page will recover on its own.',
};
let dockerTimer = null;

let pollTimer = null;

async function fetchStatus() {
	const response = await axios.get('/json/instance/status');
	status.value = response.data;
	// An update left running (or just finished) when the page loaded
	if (response.data.update_status?.state === 'running' && !updating.value) {
		updating.value = true;
		startPolling();
	} else if (response.data.update_status?.state === 'stalled') {
		error.value = `Update stalled: ${response.data.update_status.message}`;
	}
	if (response.data.docker_update) {
		if (dockerBusy.value) startDockerPolling();
		else stopDockerPolling();
	}
	return response.data;
}

async function requestDockerUpdate(action) {
	if (action === 'update' && !confirmingDockerUpdate.value) {
		confirmingDockerUpdate.value = true;
		return;
	}
	confirmingDockerUpdate.value = false;
	requestingDocker.value = true;
	error.value = '';
	notice.value = '';
	try {
		const response = await axios.post(action === 'update' ? '/json/instance/update' : '/json/instance/check-updates');
		status.value.docker_update = response.data.docker_update;
		startDockerPolling();
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not send the request.';
	} finally {
		requestingDocker.value = false;
	}
}

// Poll while a request is open. The app is down while it restarts, so a failed poll is expected, not an error.
function startDockerPolling() {
	if (dockerTimer) return;
	let wasBusy = true;
	dockerTimer = setInterval(async () => {
		try {
			const data = await fetchStatus();
			const du = data.docker_update;
			if (du && !dockerBusy.value && wasBusy) {
				wasBusy = false;
				if (du.state === 'failed') error.value = `Update failed: ${du.message}`;
				else if (du.state === 'stalled') error.value = du.message;
				else notice.value = du.message || 'Finished.';
			}
		} catch (e) {
			// offline while restarting — keep polling
		}
	}, 4000);
}

function stopDockerPolling() {
	if (dockerTimer) {
		clearInterval(dockerTimer);
		dockerTimer = null;
	}
}

async function checkForUpdates() {
	checking.value = true;
	error.value = '';
	notice.value = '';
	try {
		const response = await axios.post('/json/instance/check-updates');
		status.value.version = response.data.version;
		notice.value = response.data.version.behind === 0
			? 'You are running the latest version.'
			: `Update available: ${response.data.version.behind} new commit(s).`;
	} catch (e) {
		error.value = e.response?.data?.message || 'Update check failed.';
	} finally {
		checking.value = false;
	}
}

async function startUpdate() {
	if (!confirmingUpdate.value) {
		confirmingUpdate.value = true;
		return;
	}
	confirmingUpdate.value = false;
	updating.value = true;
	error.value = '';
	notice.value = '';
	try {
		await axios.post('/json/instance/update');
		startPolling();
	} catch (e) {
		updating.value = false;
		error.value = e.response?.data?.message || 'Could not start the update.';
	}
}

function startPolling() {
	stopPolling();
	pollTimer = setInterval(async () => {
		try {
			const data = await fetchStatus();
			inMaintenance.value = false;
			const state = data.update_status?.state;
			if (state === 'success' || state === 'failed' || state === 'stalled') {
				stopPolling();
				updating.value = false;
				if (state === 'success') {
					notice.value = 'Update finished successfully.';
				} else if (state === 'stalled') {
					error.value = `Update stalled: ${data.update_status.message}`;
				} else {
					error.value = `Update failed: ${data.update_status.message} The site may be in maintenance mode — check the server.`;
				}
			}
		} catch (e) {
			// 503 = maintenance mode = update in progress; keep polling
			inMaintenance.value = true;
		}
	}, 4000);
}

function stopPolling() {
	if (pollTimer) {
		clearInterval(pollTimer);
		pollTimer = null;
	}
}

async function toggleOfflineMode() {
	const desired = status.value.offline_mode;
	savingOfflineMode.value = true;
	error.value = '';
	notice.value = '';
	try {
		const response = await axios.put('/json/instance/offline-mode', { enabled: desired });
		status.value.offline_mode = response.data.offline_mode;
		notice.value = response.data.offline_mode ? 'Offline mode enabled.' : 'Offline mode disabled.';
	} catch (e) {
		status.value.offline_mode = !desired; // revert the checkbox — save failed
		error.value = e.response?.data?.message || 'Could not save offline mode.';
	} finally {
		savingOfflineMode.value = false;
	}
}


// ---- Warehouses ----
// Every change goes through one dialog that asks for your password again (the server checks it on each request).
const warehouses = ref(null);
const removalWaitDays = ref(30);
const dialog = ref(null); // { title, text, danger, button, fields: [{ key, label, type, value, help }], run(values, password) }
const dialogPassword = ref('');
const dialogError = ref('');
const dialogBusy = ref(false);
const result = ref(null); // a setup code to show once
const statusNames = {
	provisioning: 'Being created', awaiting_setup: 'Waiting for setup', active: 'Active', maintenance: 'Maintenance', restoring: 'Restoring',
	upgrading: 'Upgrading', held: 'Held', suspended: 'Suspended', archived: 'Archived',
};
const statusClass = (st) => ({
	active: 'bg-green-100 text-green-800',
	awaiting_setup: 'bg-blue-100 text-blue-800',
	archived: 'bg-gray-200 text-gray-700',
	suspended: 'bg-amber-100 text-amber-800',
	maintenance: 'bg-amber-100 text-amber-800',
}[st] || 'bg-red-100 text-red-800');
const shortDate = (iso) => (iso ? new Date(iso).toLocaleDateString() : '');

async function loadWarehouses() {
	try {
		const { data } = await axios.get('/json/instance/warehouses');
		warehouses.value = data.warehouses;
		removalWaitDays.value = data.removal_wait_days;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not load the warehouses.';
	}
}

function openDialog(d) {
	dialog.value = { fields: [], ...d, values: Object.fromEntries((d.fields || []).map((f) => [f.key, f.value ?? (f.type === 'checkbox' ? false : '')])) };
	dialogPassword.value = '';
	dialogError.value = '';
	error.value = '';
	notice.value = '';
	result.value = null;
}

function closeDialog() {
	if (dialogBusy.value) return;
	dialog.value = null;
	dialogPassword.value = '';
}

async function submitDialog() {
	dialogBusy.value = true;
	dialogError.value = '';
	try {
		const { data } = await dialog.value.run(dialog.value.values, dialogPassword.value);
		notice.value = data.message || 'Done.';
		if (data.setup_code) result.value = { code: data.setup_code, url: data.setup_url, note: data.message };
		else if (data.backup !== undefined) result.value = { removed: data };
		else if (data.note) notice.value += ` ${data.note}`;
		dialog.value = null;
		await loadWarehouses();
	} catch (e) {
		dialogError.value = e.response?.data?.errors
			? Object.values(e.response.data.errors).flat().join(' ')
			: e.response?.data?.message || 'That did not work.';
	} finally {
		dialogBusy.value = false;
		dialogPassword.value = '';
	}
}

const post = (id, action, body) => (password) => axios.post(`/json/instance/warehouses/${id}/${action}`, { ...body, password });

function newWarehouse() {
	openDialog({
		title: 'New warehouse', button: 'Create warehouse',
		text: 'Creates an empty warehouse with its own database and files, and a one-time setup code. Whoever holds the code makes themselves its Administrator. This can take a minute.',
		fields: [
			{ key: 'name', label: 'Name', type: 'text' },
			{ key: 'email', label: 'Contact email (optional)', type: 'email' },
			{ key: 'training', label: 'A training warehouse (class tools; disposable)', type: 'checkbox' },
		],
		run: (v, password) => axios.post('/json/instance/warehouses', { name: v.name, email: v.email || null, training: v.training, password }),
	});
}

function editWarehouse(w) {
	openDialog({
		title: `Edit ${w.name}`, button: 'Save',
		fields: [
			{ key: 'name', label: 'Name (the login page and the chooser show it)', type: 'text', value: w.name },
			{ key: 'email', label: 'Contact email', type: 'email', value: w.admin_email || '' },
			{ key: 'listed', label: 'Listed in the chooser on the shared address', type: 'checkbox', value: w.listed },
			{ key: 'notes', label: 'Notes (only instance admins see these)', type: 'textarea', value: w.notes || '' },
		],
		run: (v, password) => axios.put(`/json/instance/warehouses/${w.id}`, { name: v.name, email: v.email || null, listed: v.listed, notes: v.notes || null, password }),
	});
}

function suspendWarehouse(w, maintenance) {
	openDialog({
		title: `${maintenance ? 'Put into maintenance' : 'Suspend'} ${w.name}`, button: maintenance ? 'Start maintenance' : 'Suspend',
		text: `${w.name} stops answering everyone (logins, machine URLs, print clients) until it is activated again. Nothing is deleted.`
			+ (w.is_default ? ' This is the default warehouse: the unprefixed machine URLs stop working too.' : ''),
		danger: true,
		fields: [{ key: 'reason', label: 'Reason (kept in the audit log)', type: 'text' }],
		run: (v, password) => post(w.id, 'suspend', { maintenance, reason: v.reason || null })(password),
	});
}

const simple = (w, action, title, text, button = title, danger = false) => openDialog({ title: `${title}: ${w.name}`, text, button, danger, run: (v, password) => post(w.id, action, {})(password) });
const activateWarehouse = (w) => simple(w, 'activate', 'Activate', `${w.name} goes back into service.`);
const resumeWarehouse = (w) => simple(w, 'resume', 'Resume', `Ends a restore that left ${w.name} out of service. Check the restore log first.`);

function archiveWarehouse(w, undo = false) {
	openDialog({
		title: `${undo ? 'Bring back' : 'Archive'} ${w.name}`, button: undo ? 'Bring back' : 'Archive', danger: !undo,
		text: undo
			? `${w.name} returns to the state it was in before it was archived.`
			: `${w.name} stops serving anyone, but nothing is deleted. After ${removalWaitDays.value} days it can be removed for good from here. You can bring it back at any time before that.`,
		run: (v, password) => post(w.id, 'archive', { undo })(password),
	});
}

function removeWarehouse(w) {
	openDialog({
		title: `Remove ${w.name} for good`, button: 'Remove for good', danger: true,
		text: 'A final backup is taken first and kept in the removed-warehouses folder of the backup directory. Then the warehouse\'s database, files and the logins that belonged only to it are deleted. This cannot be undone. It can take several minutes.',
		fields: [{ key: 'confirm', label: `Type the warehouse's name (${w.name}) to confirm`, type: 'text' }],
		run: (v, password) => axios.delete(`/json/instance/warehouses/${w.id}`, { data: { confirm: v.confirm, password } }),
	});
}

function newSetupCode(w) {
	simple(w, 'setup-code', 'New setup code', 'Issues a new one-time code for whoever will set this warehouse up. Any earlier code stops working. The code is shown once.', 'Issue code');
}

function revokeSetupCode(w) {
	openDialog({ title: `Revoke the setup code: ${w.name}`, button: 'Revoke', danger: true, text: 'The waiting code stops working.', run: (v, password) => post(w.id, 'setup-code', { revoke: true })(password) });
}

function addHostname(w) {
	openDialog({
		title: `Add a hostname to ${w.name}`, button: 'Add hostname',
		text: 'A hostname mapped to a warehouse reaches only that warehouse and never shows the chooser. You must point DNS and TLS for it at this server yourself.',
		fields: [
			{ key: 'hostname', label: 'Hostname (for example warehouse2.example.org)', type: 'text' },
			{ key: 'primary', label: 'Make it the primary address (used in emails and links)', type: 'checkbox' },
		],
		run: (v, password) => post(w.id, 'domains', { hostname: v.hostname, primary: v.primary })(password),
	});
}

function removeHostname(w, hostname) {
	openDialog({
		title: `Remove ${hostname}`, button: 'Remove hostname', danger: true,
		text: `${hostname} will no longer reach ${w.name}.`,
		run: (v, password) => axios.delete(`/json/instance/warehouses/${w.id}/domains`, { data: { hostname, password } }),
	});
}

const copied = ref(false);
async function copyCode() {
	try {
		await navigator.clipboard.writeText(result.value.code);
		copied.value = true;
	} catch {
		copied.value = false;
	}
}

onMounted(() => {
	loadWarehouses();
	fetchStatus().catch(() => { error.value = 'Could not load system status.'; });
});
onUnmounted(() => {
	stopPolling();
	stopDockerPolling();
});
</script>

<template>
	<Head title="Instance Admin" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Instance Admin</h1>
			<p class="text-sm text-gray-600">
				These settings apply to the whole installation, not to a single warehouse. Backups of one warehouse are under
				<a href="/setup/system" class="underline">Warehouse Backups</a>.
			</p>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>

			<div class="flex gap-1 border-b border-gray-300">
				<button v-for="t in [['updates', 'Updates & Mode'], ['warehouses', 'Warehouses'], ['backups', 'Full Instance Backups']]" :key="t[0]"
					class="px-4 py-2 text-sm font-medium -mb-px border-b-2"
					:class="tab === t[0] ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-900'"
					@click="tab = t[0]">{{ t[1] }}</button>
			</div>

			<div v-if="tab === 'updates'" class="space-y-6">
				<div v-if="!status" class="text-gray-500">Loading system status…</div>
					<!-- Software update -->
					<section v-if="status" class="bg-white shadow rounded-lg p-6 space-y-4">
						<h2 class="text-lg font-semibold">Software {{ docker ? 'Version' : 'Update' }}</h2>
						<div class="text-sm text-gray-700">
							<p>
								Current version:
								<span v-if="status.version.release" class="font-semibold">{{ status.version.release }}</span>
								<span v-else class="text-gray-500">unversioned</span>
								(commit <span class="font-mono">{{ status.version.current || 'unknown' }}</span>)
								<span v-if="status.version.current_date"> — {{ new Date(status.version.current_date).toLocaleString() }}</span>
							</p>
							<p v-if="status.version.current_subject" class="text-gray-500">“{{ status.version.current_subject }}”</p>
						</div>

						<!-- Docker / R Station: the host-side updater does the work; this page only asks it -->
						<template v-if="docker">
							<p v-if="!dockerUpdate?.enabled" class="text-sm text-gray-600">
								Updating from this page is not set up. To turn it on, set <span class="font-mono">RI_UPDATE_SECRET</span> in
								compose.yaml and install the updater on the server (“Automatic updates” in the Docker guide). Until then, upgrade by
								pulling the new image and restarting it; the database is backed up automatically first when the upgrade has changes to apply.
							</p>
							<template v-else>
								<p v-if="dockerUpdate.available_version" class="text-sm font-medium text-amber-700">
									Version {{ dockerUpdate.available_version }} is available (checked {{ new Date(dockerUpdate.last_checked_at).toLocaleString() }}).
								</p>
								<p v-else-if="dockerUpdate.last_checked_at" class="text-sm text-gray-600">
									Last checked {{ new Date(dockerUpdate.last_checked_at).toLocaleString() }}: nothing newer.
								</p>

								<div v-if="dockerBusy" class="rounded bg-blue-50 border border-blue-300 text-blue-800 px-4 py-3 text-sm">
									<p class="font-medium">{{ dockerStateText[dockerUpdate.state] }}</p>
									<p v-if="dockerUpdate.message">{{ dockerUpdate.message }}</p>
								</div>
								<div v-else-if="dockerUpdate.state === 'stalled'" class="rounded bg-amber-50 border border-amber-300 text-amber-800 px-4 py-3 text-sm">
									{{ dockerUpdate.message }}
								</div>

								<div v-if="dockerUpdate.can_request && !dockerBusy" class="flex gap-3 items-center">
									<PrimaryButton :disabled="requestingDocker" @click="requestDockerUpdate('check')">Check for Updates</PrimaryButton>
									<button
										v-if="dockerUpdate.available_version || confirmingDockerUpdate"
										class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white"
										:class="confirmingDockerUpdate ? 'bg-red-600 hover:bg-red-500' : 'bg-amber-600 hover:bg-amber-500'"
										:disabled="requestingDocker"
										@click="requestDockerUpdate('update')"
									>
										{{ confirmingDockerUpdate ? 'Confirm — every warehouse goes offline briefly' : 'Install Update' }}
									</button>
									<button v-if="confirmingDockerUpdate" class="text-sm text-gray-500 underline" @click="confirmingDockerUpdate = false">Cancel</button>
								</div>
								<p v-else-if="!dockerUpdate.can_request" class="text-xs text-gray-500">
									Only an instance administrator can check for or install updates: it restarts every warehouse on this system.
								</p>
								<p class="text-xs text-gray-500">
									The updater on the server downloads the new release and restarts the system. The database is backed up first when the release has changes to apply.
								</p>
							</template>
						</template>

						<template v-else>
							<div v-if="status.updater_deprecated" class="rounded bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 text-sm">
								<p class="font-medium">Deprecated: updating from this page will be removed.</p>
								<p>
									It pulls the code from GitHub, then runs the database migration and rebuilds the web assets from inside a web request,
									which can leave the system half-updated if anything goes wrong. It still works for now. Prefer a Docker install, or
									run <span class="font-mono">scripts/update.sh</span> from a shell on the server where you can watch it.
								</p>
							</div>
							<div v-if="status.version.behind > 0" class="text-sm">
								<p class="font-medium text-amber-700">{{ status.version.behind }} update(s) available:</p>
								<ul class="font-mono text-xs text-gray-600 mt-1 space-y-0.5">
									<li v-for="commit in status.version.pending_commits" :key="commit">{{ commit }}</li>
								</ul>
							</div>

							<div v-if="updating" class="rounded bg-blue-50 border border-blue-300 text-blue-800 px-4 py-3 text-sm">
								<p class="font-medium">Update in progress…</p>
								<p v-if="inMaintenance">The site is in maintenance mode while the update runs. This page will recover automatically.</p>
								<p v-else-if="status.update_status?.message">{{ status.update_status.message }}</p>
							</div>

							<div class="flex gap-3 items-center" v-if="!updating">
								<PrimaryButton :disabled="checking" @click="checkForUpdates">
									{{ checking ? 'Checking…' : 'Check for Updates' }}
								</PrimaryButton>
								<button
									v-if="status.version.behind > 0 || confirmingUpdate"
									class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white"
									:class="confirmingUpdate ? 'bg-red-600 hover:bg-red-500' : 'bg-amber-600 hover:bg-amber-500'"
									@click="startUpdate"
								>
									{{ confirmingUpdate ? 'Confirm — site will briefly go offline' : 'Install Update' }}
								</button>
								<button v-if="confirmingUpdate" class="text-sm text-gray-500 underline" @click="confirmingUpdate = false">Cancel</button>
							</div>
							<p class="text-xs text-gray-500">
								Installing an update backs up first, puts the site into maintenance mode, applies the update,
								and verifies health before coming back online.
							</p>
						</template>
					</section>

					<!-- Offline Mode -->
					<section v-if="status" class="bg-white shadow rounded-lg p-6 space-y-4">
						<h2 class="text-lg font-semibold">Offline Mode</h2>
						<p class="text-sm text-gray-600">
							Turn this on when this warehouse has no reliable internet connection. It automatically
							disables Cloudflare Turnstile (bot protection on login/registration/password reset) and
							geocod.io address lookups (county/city/state auto-fill on Order Entry and People) — neither
							is required for normal operation, they just fail slowly without a real network otherwise.
							Nothing else changes — address, order, and inventory entry all work exactly the same either way.
						</p>
						<label class="flex items-center gap-3">
							<input type="checkbox" v-model="status.offline_mode" :disabled="savingOfflineMode"
								@change="toggleOfflineMode" class="h-5 w-5 rounded border-gray-300" />
							<span class="text-sm font-medium text-gray-700">
								{{ status.offline_mode ? 'Offline mode is ON' : 'Offline mode is off' }}
							</span>
						</label>
					</section>

			</div>

			<div v-if="tab === 'warehouses'" class="space-y-6">
				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<div class="flex items-center justify-between gap-3 flex-wrap">
						<h2 class="text-lg font-semibold">Warehouses</h2>
						<PrimaryButton @click="newWarehouse">New warehouse</PrimaryButton>
					</div>
					<p class="text-sm text-gray-600">
						Each warehouse has its own database, files and people. Every change here asks for your password again.
						Archiving takes a warehouse out of service without deleting anything; an archived warehouse can be removed for good from here
						after {{ removalWaitDays }} days. <span class="text-gray-500">(The command
						<span class="font-mono">php artisan warehouse:remove &lt;id&gt;</span> removes an archived warehouse immediately, with no waiting period.)</span>
					</p>

					<div v-if="result?.code" class="rounded border-2 border-amber-400 bg-amber-50 p-4 text-sm space-y-2">
						<p class="font-semibold">{{ result.note }}</p>
						<p>Setup code: <span class="font-mono text-lg font-bold">{{ result.code }}</span>
							<button class="ml-3 text-xs underline" @click="copyCode">{{ copied ? 'Copied' : 'Copy' }}</button></p>
						<p>Address: <span class="font-mono break-all">{{ result.url }}</span></p>
					</div>
					<div v-if="result?.removed" class="rounded border border-gray-300 bg-gray-50 p-4 text-sm space-y-1">
						<p>Final backup: <span class="font-mono break-all">{{ result.removed.backup || 'none' }}</span></p>
						<p>Logins removed with it: {{ result.removed.accounts_removed.length ? result.removed.accounts_removed.join(', ') : 'none' }}; kept (they belong elsewhere): {{ result.removed.accounts_kept }}</p>
						<p v-for="n in result.removed.notes" :key="n" class="text-amber-800">{{ n }}</p>
					</div>

					<div v-if="!warehouses" class="text-gray-500">Loading…</div>
					<div v-for="w in warehouses" :key="w.id" class="border rounded-lg p-4 space-y-2" :class="w.status === 'archived' ? 'bg-gray-50' : ''">
						<div class="flex items-center gap-2 flex-wrap">
							<h3 class="font-semibold">{{ w.name }}</h3>
							<span class="text-xs rounded px-2 py-0.5" :class="statusClass(w.status)">{{ statusNames[w.status] || w.status }}</span>
							<span v-if="w.is_default" class="text-xs rounded px-2 py-0.5 bg-indigo-100 text-indigo-800">Default</span>
							<span v-if="w.is_training" class="text-xs rounded px-2 py-0.5 bg-yellow-100 text-yellow-800">Training</span>
							<span v-if="!w.listed" class="text-xs text-gray-500">not listed in the chooser</span>
							<span class="ml-auto font-mono text-xs text-gray-500">{{ w.id }}</span>
						</div>
						<p v-if="w.admin_email" class="text-xs text-gray-600">Contact: {{ w.admin_email }}</p>
						<p v-if="w.notes" class="text-xs text-gray-600 whitespace-pre-line">{{ w.notes }}</p>
						<p v-if="w.status === 'archived'" class="text-xs text-gray-600">
							Archived {{ shortDate(w.archived_at) }}.
							<template v-if="w.is_default">The default warehouse cannot be removed.</template>
							<template v-else-if="w.can_remove_now">It can be removed for good now.</template>
							<template v-else>It can be removed here from {{ shortDate(w.removable_on) }}.</template>
						</p>
						<p v-if="w.status === 'awaiting_setup'" class="text-xs text-gray-600">
							Setup address <span class="font-mono break-all">{{ w.setup_url }}</span> —
							{{ w.setup_code_until ? `a code is waiting, valid until ${shortDate(w.setup_code_until)}` : 'no setup code is waiting' }}.
						</p>

						<div class="flex gap-2 flex-wrap items-center text-xs">
							<span class="text-gray-500">Hostnames:</span>
							<span v-if="!w.domains.length" class="text-gray-400">none (reached by the shared address)</span>
							<span v-for="d in w.domains" :key="d.hostname" class="inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 font-mono">
								{{ d.hostname }}<span v-if="d.primary" class="text-gray-500 font-sans">(primary)</span>
								<button class="text-red-600 font-sans" :title="`Remove ${d.hostname}`" @click="removeHostname(w, d.hostname)">×</button>
							</span>
							<button class="underline text-indigo-700" @click="addHostname(w)">Add hostname</button>
						</div>

						<div class="flex gap-3 flex-wrap text-sm pt-1">
							<button class="underline text-indigo-700" @click="editWarehouse(w)">Edit</button>
							<template v-if="w.status === 'active'">
								<button class="underline text-amber-700" @click="suspendWarehouse(w, false)">Suspend</button>
								<button class="underline text-amber-700" @click="suspendWarehouse(w, true)">Maintenance</button>
							</template>
							<button v-if="w.status === 'suspended' || w.status === 'maintenance'" class="underline text-green-700" @click="activateWarehouse(w)">Activate</button>
							<button v-if="w.status === 'restoring'" class="underline text-green-700" @click="resumeWarehouse(w)">Resume</button>
							<template v-if="w.status === 'awaiting_setup'">
								<button class="underline text-indigo-700" @click="newSetupCode(w)">New setup code</button>
								<button class="underline text-amber-700" @click="revokeSetupCode(w)">Revoke code</button>
							</template>
							<button v-if="w.status === 'archived'" class="underline text-indigo-700" @click="archiveWarehouse(w, true)">Bring back</button>
							<button v-else-if="!w.is_default && ['active', 'suspended', 'maintenance', 'awaiting_setup'].includes(w.status)" class="underline text-red-700" @click="archiveWarehouse(w)">Archive</button>
							<button v-if="w.status === 'archived' && !w.is_default" class="underline" :class="w.can_remove_now ? 'text-red-700 font-semibold' : 'text-gray-400 cursor-not-allowed'"
								:disabled="!w.can_remove_now" :title="w.can_remove_now ? '' : `Available from ${shortDate(w.removable_on)}`" @click="removeWarehouse(w)">Remove for good</button>
						</div>
					</div>
				</section>

				<!-- The password dialog every change goes through -->
				<div v-if="dialog" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="closeDialog">
					<form class="bg-white rounded-lg shadow-xl w-full max-w-md p-6 space-y-4 max-h-full overflow-y-auto" @submit.prevent="submitDialog">
						<h2 class="text-lg font-semibold" :class="dialog.danger ? 'text-red-800' : ''">{{ dialog.title }}</h2>
						<p v-if="dialog.text" class="text-sm text-gray-700">{{ dialog.text }}</p>
						<div v-for="f in dialog.fields" :key="f.key">
							<label v-if="f.type === 'checkbox'" class="flex items-center gap-2 text-sm">
								<input type="checkbox" v-model="dialog.values[f.key]" class="h-4 w-4 rounded border-gray-300" /> {{ f.label }}
							</label>
							<label v-else class="block text-sm">
								<span class="text-gray-700">{{ f.label }}</span>
								<textarea v-if="f.type === 'textarea'" v-model="dialog.values[f.key]" rows="3" class="mt-1 block w-full rounded border-gray-300 text-sm"></textarea>
								<input v-else :type="f.type" v-model="dialog.values[f.key]" class="mt-1 block w-full rounded border-gray-300 text-sm" autocomplete="off" />
							</label>
						</div>
						<label class="block text-sm">
							<span class="text-gray-700 font-medium">Your password, to confirm</span>
							<input type="password" v-model="dialogPassword" required autocomplete="current-password" class="mt-1 block w-full rounded border-gray-300 text-sm" />
						</label>
						<p v-if="dialogError" class="rounded bg-red-100 border border-red-400 text-red-800 px-3 py-2 text-sm">{{ dialogError }}</p>
						<div class="flex gap-3 justify-end items-center">
							<button type="button" class="text-sm text-gray-600 underline" :disabled="dialogBusy" @click="closeDialog">Cancel</button>
							<PrimaryButton :disabled="dialogBusy || !dialogPassword" :class="dialog.danger ? 'bg-red-600 hover:bg-red-500' : ''">
								{{ dialogBusy ? 'Working…' : dialog.button }}
							</PrimaryButton>
						</div>
					</form>
				</div>
			</div>

			<div v-if="tab === 'backups'" class="space-y-6">
				<section class="bg-white shadow rounded-lg p-6 space-y-4">
					<h2 class="text-lg font-semibold">Full Instance Backups</h2>
					<p class="text-sm text-gray-700">
						A full instance backup is one zip holding the control and accounts databases and <strong>every</strong>
						warehouse's database, optionally with every warehouse's files and (passphrase-encrypted) warehouse keys. It is
						also what the automatic backup before an upgrade is. It is for the person who runs the server: to back up or
						restore a single warehouse, use <a href="/setup/system" class="underline">Warehouse Backups</a>.
					</p>
					<p class="text-sm text-gray-700">
						It can't be started from this page yet, because it needs the database administrator's password, which only the
						<span class="font-mono">provisioner</span> container holds. From the server:
					</p>
					<pre class="bg-gray-100 rounded p-3 text-xs overflow-x-auto">docker compose exec provisioner php artisan instance:backup --files --with-keys
docker compose exec provisioner php artisan restore:instance &lt;zip&gt;   # other containers stopped</pre>
					<p class="text-xs text-gray-500">
						Backups are written to the <span class="font-mono">instance</span> folder of the backup directory. Buttons to make,
						list and download them will come here later.
					</p>
				</section>
			</div>
		</div>
	</AuthenticatedLayout>
</template>
