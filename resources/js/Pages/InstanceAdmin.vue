<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- InstanceAdmin.vue

	Instance Admin (/setup/instance) — what belongs to the whole instance rather than one warehouse. Instance
	administrators only (route middleware `instance-admin`; the JSON half is /json/instance/*, which reuses
	SystemController's actions). Tabs:

	  Updates & Mode           Software version / update (Docker: through the host-side updater, see InstanceUpdate;
	                           systemd: the DEPRECATED in-app updater) and Offline mode.
	  Full Instance Backups    One zip of every warehouse plus the control and accounts schemas: the schedule (off until turned
	                           on), the list, Back up now and download (InstanceBackupController). The provisioner container
	                           makes them, since it alone holds the database administrator's password; this page only writes
	                           fixed requests for it. Each zip holds the warehouse keys sealed to the instance's recovery key
	                           (the panel at the top of the tab makes it and shows the private half once; see dev/notes/BACKUPS.md).
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
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';

const props = defineProps({
	breadcrumb: { type: Array },
	runtime: { type: String, default: 'systemd' },
});

const docker = computed(() => props.runtime === 'docker');

// ?tab=backups opens that tab directly (the recovery-key banner links there).
const tab = ref(['updates', 'warehouses', 'backups'].includes(new URLSearchParams(location.search).get('tab')) ? new URLSearchParams(location.search).get('tab') : 'updates');
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
	requested: 'Waiting for the updater service…',
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
		if (data.private_key) recoveryShown.value = data;   // the one time it exists outside the person's hands: shown in the dialog below
		else if (data.code && data.address) pairCode.value = data;
		else if (data.setup_code) result.value = { code: data.setup_code, url: data.setup_url, note: data.message };
		else if (data.backup !== undefined) result.value = { removed: data };
		else if (data.note) notice.value += ` ${data.note}`;
		dialog.value = null;
		await loadWarehouses();
		if (tab.value === 'backups') await loadInstanceBackups();
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

// ---- Full Instance Backups (Docker) ----
const instBackups = ref(null);   // the server's payload: { available, schedule, backups, activity, working }
const instForm = ref(null);
const weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
let instTimer = null;

const instFormOf = (sc) => ({
	enabled: !!sc.enabled, daily_hour: sc.daily_hour, daily_keep: sc.daily_keep, daily_with_files: !!sc.daily_with_files,
	weekly_enabled: !!sc.weekly_enabled, weekly_dow: sc.weekly_dow, weekly_keep: sc.weekly_keep, weekly_with_files: !!sc.weekly_with_files,
});

async function loadInstanceBackups() {
	try {
		const { data } = await axios.get('/json/instance/backups');
		instBackups.value = data;
		// Filled once (and after a save): the poll must not overwrite what is being typed.
		if (data.available && !instForm.value) instForm.value = instFormOf(data.schedule);
	} catch (e) {
		/* a poll that fails (an update is restarting the site) is not worth showing */
		if (!instBackups.value) error.value = e.response?.data?.message || 'Could not load the instance backups.';
	}
}

watch(tab, (t) => {
	clearInterval(instTimer);
	if (t === 'backups') {
		loadInstanceBackups();
		instTimer = setInterval(loadInstanceBackups, 4000);
	}
}, { immediate: true });   // ?tab=backups starts on this tab, so the value never "changes"

function saveInstanceSchedule() {
	openDialog({
		title: 'Save the full instance backup schedule', button: 'Save schedule',
		text: instForm.value.enabled
			? 'The provisioner will make a full instance backup on this schedule and keep the newest few.'
			: 'Automatic full instance backups will be off. Nothing already made is deleted.',
		run: (v, password) => axios.put('/json/instance/backups/schedule', { ...instForm.value, password }).then((r) => {
			instForm.value = instFormOf(r.data.schedule);
			return r;
		}),
	});
}

function backUpInstanceNow(files) {
	openDialog({
		title: files ? 'Back up the whole instance, with every warehouse\'s files' : 'Back up the whole instance (databases only)', button: 'Back up now',
		text: 'The provisioner makes one consistent backup of every warehouse and the shared databases. It can take a while, and warehouse creation waits until it is done. It will not contain the warehouse keys.',
		run: (v, password) => axios.post('/json/instance/backups', { files, password }),
	});
}

const sizeOf = (bytes) => (bytes > 1073741824 ? `${(bytes / 1073741824).toFixed(2)} GB` : bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.ceil(bytes / 1024)} KB`);
const labelNames = { 'scheduled-daily': 'Scheduled (daily)', 'scheduled-weekly': 'Scheduled (weekly)', manual: 'Made by hand', premigrate: 'Before an upgrade' };
const instWhen = (iso) => (iso ? new Date(iso).toLocaleString() : '');

// ---- Recovery key ----
// The server keeps only the public half. The private half arrives once, here, in the response to "Make a recovery key", and is gone from the
// server the moment it is sent. The person must print or save it before closing; if the page is closed first the key is lost and a new one is made.
const recoveryShown = ref(null);   // { private_key, fingerprint, instance, address, made_at } while the dialog is open
const recoveryStored = ref(false);
const recoveryCopied = ref(false);
const recoveryBusy = ref(false);
const recoveryState = computed(() => instBackups.value?.recovery_key ?? { state: 'none' });

function makeRecoveryKey(replace = false) {
	recoveryShown.value = null;
	openDialog({
		title: replace ? 'Make a NEW recovery key' : 'Make the recovery key', button: replace ? 'Replace the key' : 'Make the key', danger: replace,
		text: replace
			? 'Backups made from now on are sealed to the new key and need it to be restored. Backups made before still need the OLD printout, so keep it until they are gone. The new key is shown once.'
			: 'Makes the key that seals every warehouse\'s encryption key into your instance backups. It is shown once, on the next screen: have a printer or somewhere safe ready.',
		run: (v, password) => axios.post('/json/instance/backups/recovery-key', { replace, password }),
	});
}

function recoveryText() {
	const k = recoveryShown.value;
	return [
		`RELIEF INVENTORY — RECOVERY KEY (${k.instance})`,
		'',
		`Instance:     ${k.address}`,
		`Made:         ${new Date(k.made_at).toLocaleString()}`,
		`Fingerprint:  ${k.fingerprint}`,
		'',
		'Recovery key:',
		`  ${k.private_key}`,
		'',
		'WHAT THIS IS',
		'Every whole-instance backup of this server (including the automatic ones) holds each warehouse\'s encryption key, sealed so that only this key',
		'can open it. After a disaster, restore with:  restore:instance <backup.zip> --recovery-key-file <a file containing the line above>',
		'and every warehouse\'s encrypted settings (mail passwords, the federation key) come back.',
		'',
		'KEEP IT SAFE',
		'Store this away from this server and away from your backups (a safe, a sealed envelope, a password manager). Anyone who holds both this',
		'key and a backup can read those settings. The server does NOT keep this key and cannot show it again: if it is lost, make a new one on',
		'Instance Admin > Full Instance Backups (backups made before then still need this one).',
		'',
	].join('\n');
}

function printRecoveryKey() {
	const k = recoveryShown.value;
	const w = window.open('', '_blank', 'width=720,height=900');
	if (!w) { error.value = 'Your browser blocked the print window. Use Download instead.'; return; }
	const esc = (t) => String(t).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
	w.document.write(`<!doctype html><title>Recovery key</title><style>body{font-family:sans-serif;margin:2rem;color:#000}h1{font-size:1.3rem}
		.key{font:700 1.25rem/1.6 monospace;letter-spacing:.05em;border:2px solid #000;padding:1rem;margin:1rem 0;word-break:break-all}
		pre{white-space:pre-wrap;font:0.85rem/1.5 sans-serif}</style>
		<h1>Relief Inventory &mdash; recovery key (${esc(k.instance)})</h1>
		<div>Instance: ${esc(k.address)}<br>Made: ${esc(new Date(k.made_at).toLocaleString())}<br>Fingerprint: <b>${esc(k.fingerprint)}</b></div>
		<div class="key">${esc(k.private_key)}</div>
		<pre>${esc(recoveryText().split('WHAT THIS IS')[1] ? 'WHAT THIS IS' + recoveryText().split('WHAT THIS IS')[1] : '')}</pre>`);
	w.document.close();
	w.focus();
	w.print();
}

function downloadRecoveryKey() {
	const k = recoveryShown.value;
	const url = URL.createObjectURL(new Blob([recoveryText()], { type: 'text/plain' }));
	const a = document.createElement('a');
	a.href = url;
	a.download = `recovery-key-${k.fingerprint}.txt`;
	a.click();
	URL.revokeObjectURL(url);
}

async function copyRecoveryKey() {
	try {
		await navigator.clipboard.writeText(recoveryShown.value.private_key);
		recoveryCopied.value = true;
	} catch {
		recoveryCopied.value = false;
	}
}

async function finishRecoveryKey() {
	recoveryBusy.value = true;
	try {
		await axios.post('/json/instance/backups/recovery-key/confirm');
		recoveryShown.value = null;
		recoveryStored.value = false;
		recoveryCopied.value = false;
		notice.value = 'Recovery key confirmed. Every instance backup from now on contains the warehouse keys, sealed to it.';
		await loadInstanceBackups();
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not confirm the recovery key.';
	} finally {
		recoveryBusy.value = false;
	}
}

// Computers (the RI Launcher) paired to the instance so they can take whole-instance backups. A new code is shown once.
const pairCode = ref(null);   // { code, expires_at, address }

function newBackupClient() {
	pairCode.value = null;
	openDialog({
		title: 'Pair a computer for instance backups', button: 'Make a code',
		text: 'Makes a one-time code, good for 15 minutes. In the RI Launcher, choose Remote backups, then pair with this address and the code, as an instance backup. That computer can then make and download backups of the WHOLE instance (every warehouse and every person\'s information) until you revoke it here.',
		fields: [{ key: 'name', label: 'What it is (optional)', type: 'text' }],
		run: (v, password) => axios.post('/json/instance/backups/clients/code', { name_hint: v.name || null, password }),
	});
}

function revokeBackupClient(c) {
	openDialog({
		title: `Stop ${c.name}`, button: 'Revoke', danger: true,
		text: `${c.name} can no longer make or download instance backups. Copies it already holds stay on that computer. It can be paired again with a new code.`,
		run: (v, password) => axios.delete(`/json/instance/backups/clients/${c.id}`, { data: { password } }),
	});
}

onMounted(() => {
	loadWarehouses();
	fetchStatus().catch(() => { error.value = 'Could not load system status.'; });
});
onUnmounted(() => {
	stopPolling();
	stopDockerPolling();
	clearInterval(instTimer);
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
			</div>

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

			<div v-if="tab === 'backups'" class="space-y-6">
				<section class="bg-white shadow rounded-lg p-6 space-y-3">
					<h2 class="text-lg font-semibold">Full Instance Backups</h2>
					<p class="text-sm text-gray-700">
						A full instance backup is one zip holding the control and accounts databases and <strong>every</strong> warehouse's database,
						optionally with every warehouse's files. It is also what the automatic backup before an upgrade is. It is for the person who runs the
						server, to move or recover the whole installation: to back up or restore a single warehouse, use
						<a href="/setup/system" class="underline">Warehouse Backups</a>. Each zip holds every warehouse's encryption key <strong>sealed to your recovery key</strong> (below),
						so an automatic backup can be restored, keys and all.
					</p>
					<p v-if="instBackups && !instBackups.available" class="rounded bg-amber-100 border border-amber-400 text-amber-900 px-3 py-2 text-sm">
						This needs the Docker install: the backup is made by its <span class="font-mono">provisioner</span> container. On a standard server install, use
						Warehouse Backups.
					</p>
					<p v-else-if="!instBackups" class="text-gray-500 text-sm">Loading…</p>
				</section>

				<template v-if="instBackups?.available && instForm">
					<!-- The recovery key: public half on the server, private half on paper -->
					<section class="rounded-lg border-2 p-6 space-y-3"
						:class="recoveryState.state === 'ok' ? 'bg-white border-green-400' : 'bg-red-50 border-red-400'">
						<h2 class="text-lg font-semibold">Recovery key</h2>
						<template v-if="recoveryState.state === 'none'">
							<p class="text-sm text-red-900">
								<strong>This instance has no recovery key.</strong> Its backups hold everything except the warehouses' encryption keys, so after a disaster
								the mail passwords and the federation key could not be read and would have to be entered again, and every paired instance and client would have to approve this one again.
							</p>
						</template>
						<template v-else-if="recoveryState.state === 'unconfirmed'">
							<p class="text-sm text-red-900">
								<strong>Nobody confirmed that the recovery key {{ recoveryState.fingerprint }} was stored.</strong> The private half was shown once and the server does not
								keep it. If you did not print or save it, make a new key.
							</p>
						</template>
						<template v-else>
							<p class="text-sm text-gray-700">
								Every instance backup contains encrypted copies of the warehouse keys. Those keys are sealed to recovery key <span class="font-mono font-semibold">{{ recoveryState.fingerprint }}</span>
								<span class="text-gray-500">(made by {{ recoveryState.created_by }}{{ recoveryState.source === 'generated' ? '' : `, ${recoveryState.source}` }})</span>.
								Keep the printout away from this server and away from your backups.
							</p>
						</template>
						<p class="text-xs text-gray-600">
							How it works: each backup locks the warehouses' keys with a <em>public</em> key kept here, so the unattended backup can do it but nothing on this server can unlock them.
							Only the <em>private</em> key, which you print once, can. To restore after a disaster:
							<span class="font-mono">restore:instance &lt;zip&gt; --recovery-key-file &lt;file&gt;</span>. Check a printout at any time with
							<span class="font-mono">instance:recovery-key verify</span>.
						</p>
						<div class="flex flex-wrap gap-3">
							<PrimaryButton v-if="recoveryState.state === 'none'" type="button" @click="makeRecoveryKey(false)">Make the recovery key…</PrimaryButton>
							<PrimaryButton v-else type="button" @click="makeRecoveryKey(true)" :class="recoveryState.state === 'ok' ? 'bg-gray-600 hover:bg-gray-500' : ''">
								Make a new recovery key…
							</PrimaryButton>
						</div>
					</section>

					<section class="bg-white shadow rounded-lg p-6 space-y-4">
						<h2 class="text-lg font-semibold">Backup now</h2>
						<div class="flex flex-wrap items-center gap-3">
							<PrimaryButton :disabled="instBackups.working" @click="backUpInstanceNow(false)">{{ instBackups.working ? 'A backup is being made…' : 'Back up (databases)' }}</PrimaryButton>
							<PrimaryButton :disabled="instBackups.working" @click="backUpInstanceNow(true)">Back up (databases + every warehouse's files)</PrimaryButton>
						</div>
						<p v-if="instBackups.working" class="rounded bg-blue-50 border border-blue-300 text-blue-800 px-3 py-2 text-sm">Working… this page updates by itself.</p>
						<p v-else-if="instBackups.activity" class="text-sm" :class="instBackups.activity.status === 'failed' ? 'text-red-700' : 'text-gray-600'">
							Last request: {{ instBackups.activity.status === 'failed' ? 'FAILED — ' : 'done — ' }}{{ instBackups.activity.message }}
							<span v-if="instBackups.activity.finished_at" class="text-xs text-gray-500">({{ instWhen(instBackups.activity.finished_at) }})</span>
						</p>
					</section>

					<section class="bg-white shadow rounded-lg p-6 space-y-4">
						<h2 class="text-lg font-semibold">Schedule</h2>
						<form @submit.prevent="saveInstanceSchedule" class="space-y-3 text-sm">
							<label class="flex items-center gap-2 font-medium"><input type="checkbox" v-model="instForm.enabled" class="rounded border-gray-300" /> Make a full instance backup automatically</label>
							<div class="border rounded p-3 flex flex-wrap items-center gap-x-6 gap-y-2" :class="instForm.enabled ? '' : 'opacity-60'">
								<span class="w-28 font-medium">Daily</span>
								<label class="flex items-center gap-2">After <select v-model.number="instForm.daily_hour" class="rounded-md border-gray-300 text-sm"><option v-for="h in 24" :key="h" :value="h - 1">{{ String(h - 1).padStart(2, '0') }}:00</option></select> server time</label>
								<label class="flex items-center gap-2">Keep the newest <input type="number" min="1" max="90" v-model.number="instForm.daily_keep" class="w-20 rounded-md border-gray-300 text-sm" /></label>
								<label class="flex items-center gap-2"><input type="checkbox" v-model="instForm.daily_with_files" class="rounded border-gray-300" /> include every warehouse's files</label>
							</div>
							<div class="border rounded p-3 flex flex-wrap items-center gap-x-6 gap-y-2" :class="instForm.enabled ? '' : 'opacity-60'">
								<label class="flex items-center gap-2 w-28 font-medium"><input type="checkbox" v-model="instForm.weekly_enabled" class="rounded border-gray-300" /> Weekly</label>
								<label class="flex items-center gap-2">On <select v-model.number="instForm.weekly_dow" class="rounded-md border-gray-300 text-sm"><option v-for="(d, i) in weekdays" :key="d" :value="i">{{ d }}</option></select></label>
								<label class="flex items-center gap-2">Keep the newest <input type="number" min="1" max="52" v-model.number="instForm.weekly_keep" class="w-20 rounded-md border-gray-300 text-sm" /></label>
								<label class="flex items-center gap-2"><input type="checkbox" v-model="instForm.weekly_with_files" class="rounded border-gray-300" /> include every warehouse's files</label>
							</div>
							<div class="flex flex-wrap items-center gap-4">
								<PrimaryButton type="submit">Save Schedule…</PrimaryButton>
								<span v-if="instBackups.schedule.last_run_at" class="text-xs" :class="instBackups.schedule.last_status === 'failed' ? 'text-red-700' : 'text-gray-500'">
									Last scheduled backup: {{ instWhen(instBackups.schedule.last_run_at) }} — {{ instBackups.schedule.last_status === 'failed' ? `FAILED: ${instBackups.schedule.last_message}` : 'made' }}
								</span>
							</div>
						</form>
						<p class="text-xs text-gray-500">
							A weekly backup also counts as that day's daily one. Backups made by hand and the ones taken before an upgrade are not deleted by this schedule
							(the upgrade ones go after the usual number of days). These sit on this machine: copy them elsewhere too (the RI Launcher can).
							The provisioner works on one request at a time, so creating a warehouse waits while a big backup runs.
						</p>
					</section>

					<section class="bg-white shadow rounded-lg p-6 space-y-3">
						<h2 class="text-lg font-semibold">Computers that may take instance backups</h2>
						<p class="text-sm text-gray-700">
							The RI Launcher on a computer you pair here can make and download these backups by itself, on a schedule, to keep copies somewhere else. This is
							separate from the per-warehouse Trust Console: it covers the <strong>whole instance</strong>, so pair only computers you trust with everyone's information.
						</p>
						<div v-if="pairCode" class="rounded border-2 border-green-500 bg-green-50 p-4 text-sm space-y-1">
							<div>Server address: <span class="font-mono font-semibold">{{ pairCode.address }}</span></div>
							<div>Code: <span class="font-mono text-xl font-bold tracking-widest">{{ pairCode.code }}</span></div>
							<div class="text-xs text-gray-600">Shown once. Expires {{ instWhen(pairCode.expires_at) }}. In the Launcher: Remote backups → Pair → “Instance backup”.</div>
						</div>
						<div v-for="c in instBackups.clients" :key="c.id" class="border rounded p-3 text-sm flex flex-wrap items-center justify-between gap-3">
							<div>
								<span class="font-medium">{{ c.name }}</span>
								<span class="ml-2 rounded px-2 py-0.5 text-xs" :class="c.status === 'approved' ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700'">{{ c.status === 'approved' ? 'Paired' : 'Revoked' }}</span>
								<div class="text-xs text-gray-500">
									Paired {{ instWhen(c.approved_at) }}<span v-if="c.last_seen_at"> · last seen {{ instWhen(c.last_seen_at) }}<span v-if="c.last_seen_ip"> from {{ c.last_seen_ip }}</span></span><span v-else> · never connected</span>
								</div>
							</div>
							<button v-if="c.status === 'approved'" @click="revokeBackupClient(c)"
								class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white bg-red-600 hover:bg-red-500">Revoke…</button>
						</div>
						<p v-if="!instBackups.clients.length" class="text-gray-400 text-sm">No computer is paired yet.</p>
						<PrimaryButton type="button" @click="newBackupClient">Pair a computer…</PrimaryButton>
					</section>

					<section class="bg-white shadow rounded-lg p-6 space-y-3">
						<h2 class="text-lg font-semibold">Instance backups</h2>
						<p v-if="!instBackups.backups.length" class="text-gray-400 text-sm">none yet</p>
						<div v-for="z in instBackups.backups" :key="z.name" class="border rounded p-3 text-sm flex flex-wrap items-center justify-between gap-3">
							<div class="space-y-1">
								<div class="font-mono text-xs text-gray-600">{{ z.name }}</div>
								<div>
									{{ instWhen(z.made_at) }} — {{ sizeOf(z.size) }}, {{ z.scope === 'full' ? 'databases + files' : 'databases only' }}
									<span v-if="z.label" class="ml-1 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ labelNames[z.label] || z.label }}</span>
								</div>
							</div>
							<a v-if="z.downloadable" :href="$withWarehouse(`/json/instance/backups/${z.name}/download`)"
								class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white bg-gray-800 hover:bg-gray-700">Download</a>
							<span v-else class="text-xs text-gray-500">Not readable here: copy it from the server's backup folder (<span class="font-mono">instance/</span>)</span>
						</div>
						<p class="text-xs text-gray-500">
							To restore one onto a new machine (other containers stopped):
							<span class="font-mono">docker compose exec provisioner php artisan restore:instance &lt;zip&gt;</span>. Deleting one is done at the server.
						</p>
					</section>
				</template>
			</div>
		</div>

		<!-- The recovery key, shown once. No way to dismiss it until the person says it is stored. -->
		<div v-if="recoveryShown" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="dialog" aria-modal="true" aria-labelledby="recovery-title">
			<div class="max-h-full w-full max-w-2xl overflow-y-auto rounded-lg bg-white p-6 shadow-xl space-y-4">
				<h2 id="recovery-title" class="text-xl font-bold text-red-800">Print this key and store it somewhere safe</h2>
				<p class="text-sm text-gray-800">
					This is your instance's <strong>recovery key</strong>. It is shown <strong>only now</strong>: the server does not keep it and cannot show it again.
					It is what lets an automatic backup restore every warehouse's encrypted settings after a disaster.
				</p>
				<div class="rounded border-2 border-gray-800 bg-gray-50 p-4">
					<div class="text-xs text-gray-500">Fingerprint {{ recoveryShown.fingerprint }}</div>
					<div class="mt-1 break-all font-mono text-lg font-bold leading-relaxed tracking-wide" data-testid="recovery-key">{{ recoveryShown.private_key }}</div>
				</div>
				<div class="flex flex-wrap gap-3">
					<button type="button" class="rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700" @click="printRecoveryKey">Print</button>
					<button type="button" class="rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700" @click="downloadRecoveryKey">Download as a file</button>
					<button type="button" class="rounded-md bg-gray-200 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-800 hover:bg-gray-300" @click="copyRecoveryKey">{{ recoveryCopied ? 'Copied' : 'Copy' }}</button>
				</div>
				<ul class="list-disc space-y-1 pl-5 text-sm text-gray-700">
					<li>Keep it <strong>away from this server and away from your backups</strong>: a safe, a sealed envelope, a password manager. Anyone with both this key and a backup can read the warehouses' encrypted settings.</li>
					<li>Check a printout later with <span class="font-mono">instance:recovery-key verify</span>. The key has check characters, so a typing mistake is caught.</li>
					<li>If it is lost, make a new one here. Backups made before then still need this one.</li>
				</ul>
				<label class="flex items-start gap-2 text-sm font-medium text-gray-900">
					<input type="checkbox" v-model="recoveryStored" class="mt-1 rounded border-gray-400" />
					<span>I have printed or saved this key somewhere safe, away from this server and my backups.</span>
				</label>
				<div class="flex justify-end">
					<PrimaryButton type="button" :disabled="!recoveryStored || recoveryBusy" @click="finishRecoveryKey">{{ recoveryBusy ? 'Saving…' : 'Done' }}</PrimaryButton>
				</div>
			</div>
		</div>
	</AuthenticatedLayout>
</template>
