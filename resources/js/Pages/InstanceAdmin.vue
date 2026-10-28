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
	  Warehouses (planned)     List / enable / disable / create / delete warehouses, with the 30-days-inactive rule
	                           before deletion (the artisan command can still delete at once).

	`runtime` ('systemd' | 'docker') comes from config('system.runtime'). During an update the site enters
	maintenance mode, so status polls start failing with 503 — expected, and shown as progress rather than an error.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref } from 'vue';

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

onMounted(() => {
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
				<button v-for="t in [['updates', 'Updates & Mode'], ['backups', 'Full Instance Backups']]" :key="t[0]"
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
						list and download them will come here later, next to warehouse management (list, enable/disable, create and delete).
					</p>
				</section>
			</div>
		</div>
	</AuthenticatedLayout>
</template>
