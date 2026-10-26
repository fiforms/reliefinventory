<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- FeedbackReports.vue

	Triage list for in-app bug/feature reports (permission: manage-feedback).
	Each report shows its full history (submission + every status_log entry)
	always visible, not behind a click — history entries are either a real
	status transition ("Moved to X") or a same-status note ("Note"),
	distinguished purely by comparing each entry's status to the one before
	it (historyEntries()), no extra DB column needed. Two actions share the
	same PATCH endpoint (FeedbackReportController@update): advancing to the
	next status (required comment only for Resolved), and leaving a note on
	the current status without advancing it (comment always required,
	available any time before Resolved).

	The site-banner editor used to live on this page too — split out to its
	own page/menu item, SiteBanner.vue, on 2026-08-22.

	Filter/search (2026-08-22): Type/Status/Reported by/Page filters plus a
	free-text search box, all applied client-side against the already-loaded
	`reports` list (same "no extra endpoint, derive options from what's
	loaded" approach as People.vue's donor search) — the report list isn't
	large enough to need server-side filtering.

	Centralized feedback aggregation (2026-09-06): on a 'remote' instance
	(instanceRole prop, initially from FeedbackAggregationSetting, kept in
	sync client-side once the settings panel below loads), each locally-
	created report shows a Submitted/Not Sent badge (FeedbackReportCard.vue),
	and "Send Unsent Reports" sends every not-yet-submitted report in one
	batch — the only retry mechanism; there's no background retry. Hidden
	entirely on a 'central' instance, where this concept doesn't apply. A
	report ingested from a remote instance instead shows a "from: <instance>"
	badge and falls back to a denormalized reporter name/email, since central
	has no Person record for it.

	Aggregation settings (2026-09-07): instance role/identifier, central
	ingest URL, and shared secret used to be env-only (FEEDBACK_INSTANCE_ROLE
	etc.) — now admin-editable here via /json/feedback-aggregation-settings
	(FeedbackAggregationSettingsController), stored in
	feedback_aggregation_settings (FeedbackAggregationSetting), same
	singleton-row pattern as PinLoginSetting/BannerSetting. The shared secret
	is never round-tripped back to the client after save — the panel only
	ever shows whether one is currently set, and a blank field on save
	leaves it unchanged.
-->

<script setup>
import { onMounted, computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import FeedbackReportCard from '@/Components/FeedbackReportCard.vue';
import axios from 'axios';

const props = defineProps({
	breadcrumb: { type: Array },
	instanceRole: { type: String, default: 'remote' },
});

// Local, mutable copy — the prop is just the page's initial render; once the
// settings panel loads/saves, this is what the rest of the page (the "Send
// Unsent Reports" button's visibility) actually follows.
const currentInstanceRole = ref(props.instanceRole);

const aggregationSettings = ref(null); // { instance_role, instance_identifier, central_ingest_url, shared_secret_set }
const aggregationForm = ref({ instance_role: 'remote', instance_identifier: '', central_ingest_url: '', shared_secret: '', feedback_trust_id: '' });
const peerTrusts = ref([]); // instances this one is paired with (Trust Console)
const aggregationLoading = ref(true);
const aggregationSaving = ref(false);
const aggregationError = ref(null);
const aggregationSaved = ref(false);

async function fetchAggregationSettings() {
	aggregationLoading.value = true;
	try {
		const response = await axios.get('/json/feedback-aggregation-settings');
		aggregationSettings.value = response.data.settings;
		aggregationForm.value = {
			instance_role: response.data.settings.instance_role,
			instance_identifier: response.data.settings.instance_identifier || '',
			central_ingest_url: response.data.settings.central_ingest_url || '',
			shared_secret: '',
			feedback_trust_id: response.data.settings.feedback_trust_id || '',
		};
		peerTrusts.value = response.data.trusts || [];
		currentInstanceRole.value = response.data.settings.instance_role;
	} finally {
		aggregationLoading.value = false;
	}
}

async function saveAggregationSettings() {
	aggregationSaving.value = true;
	aggregationError.value = null;
	aggregationSaved.value = false;
	try {
		const response = await axios.put('/json/feedback-aggregation-settings', {
			instance_role: aggregationForm.value.instance_role,
			instance_identifier: aggregationForm.value.instance_identifier.trim() || null,
			central_ingest_url: aggregationForm.value.central_ingest_url.trim() || null,
			shared_secret: aggregationForm.value.shared_secret.trim() || null,
			feedback_trust_id: aggregationForm.value.feedback_trust_id || null,
		});
		peerTrusts.value = response.data.trusts || peerTrusts.value;
		applySavedSettings(response.data.settings);
		aggregationSaved.value = true;
		if (response.data.settings.instance_role === 'remote') await testConnection();
	} catch (e) {
		aggregationError.value = e.response?.data?.message || 'Could not save these settings.';
	} finally {
		aggregationSaving.value = false;
	}
}

function applySavedSettings(settings) {
	aggregationSettings.value = settings;
	aggregationForm.value.instance_role = settings.instance_role;
	aggregationForm.value.central_ingest_url = settings.central_ingest_url || '';
	aggregationForm.value.shared_secret = '';
	aggregationForm.value.feedback_trust_id = settings.feedback_trust_id || '';
	currentInstanceRole.value = settings.instance_role;
}

// Remote: paste the code copied from the central instance — sets the
// address and secret in one step, then checks them.
const pastedCode = ref('');
const connecting = ref(false);
const connectError = ref(null);

async function connectWithCode() {
	connecting.value = true;
	connectError.value = null;
	connectionTest.value = null;
	try {
		const response = await axios.put('/json/feedback-aggregation-settings', {
			connection_code: pastedCode.value.trim(),
			instance_identifier: aggregationForm.value.instance_identifier.trim() || null,
		});
		applySavedSettings(response.data.settings);
		pastedCode.value = '';
		await testConnection();
	} catch (e) {
		connectError.value = e.response?.data?.message || 'Could not save the connection code.';
	} finally {
		connecting.value = false;
	}
}

const testingConnection = ref(false);
const connectionTest = ref(null); // { ok, message }

async function testConnection() {
	testingConnection.value = true;
	connectionTest.value = null;
	try {
		const response = await axios.post('/json/feedback-aggregation-settings/test');
		connectionTest.value = response.data;
	} catch (e) {
		connectionTest.value = { ok: false, message: e.response?.data?.message || 'Could not run the test.' };
	} finally {
		testingConnection.value = false;
	}
}

// Central: the code remote instances paste. Fetched only on request, since
// it carries the shared secret.
const centralCode = ref(null);
const loadingCode = ref(false);
const codeError = ref(null);
const codeCopied = ref(false);
const confirmingNewSecret = ref(false);

async function fetchConnectionCode(newSecret = false) {
	loadingCode.value = true;
	codeError.value = null;
	codeCopied.value = false;
	confirmingNewSecret.value = false;
	try {
		const response = await axios.post('/json/feedback-aggregation-settings/connection-code', { new_secret: newSecret });
		centralCode.value = response.data.code;
		aggregationSettings.value = response.data.settings;
	} catch (e) {
		codeError.value = e.response?.data?.message || 'Could not make a connection code.';
	} finally {
		loadingCode.value = false;
	}
}

async function copyConnectionCode() {
	try {
		await navigator.clipboard.writeText(centralCode.value);
		codeCopied.value = true;
	} catch {
		codeError.value = 'Copying isn\'t allowed here — select the code and copy it by hand.';
	}
}

onMounted(fetchAggregationSettings);

const reports = ref([]);
const loading = ref(true);
const composing = ref(null); // { reportId, status, isNote }
const comment = ref('');
const error = ref(null);

// Filters + search
const filterType = ref('');
const filterStatus = ref('');
const filterReporter = ref('');
const filterPage = ref('');
const searchText = ref('');

const reporterOptions = computed(() => {
	const seen = new Map();
	for (const report of reports.value) {
		if (report.person) seen.set(report.person.id, report.person.full_name);
	}
	return [...seen.entries()].map(([id, full_name]) => ({ id, full_name })).sort((a, b) => a.full_name.localeCompare(b.full_name));
});

const pageOptions = computed(() => {
	const seen = new Set();
	for (const report of reports.value) {
		const label = report.page_title || report.page_url;
		if (label) seen.add(label);
	}
	return [...seen].sort((a, b) => a.localeCompare(b));
});

const hasActiveFilters = computed(() =>
	filterType.value || filterStatus.value || filterReporter.value || filterPage.value || searchText.value.trim()
);

function clearFilters() {
	filterType.value = '';
	filterStatus.value = '';
	filterReporter.value = '';
	filterPage.value = '';
	searchText.value = '';
}

const filteredReports = computed(() => {
	const text = searchText.value.trim().toLowerCase();

	return reports.value.filter((report) => {
		if (filterType.value && report.type !== filterType.value) return false;
		if (filterStatus.value && report.status !== filterStatus.value) return false;
		if (filterReporter.value && String(report.person?.id) !== filterReporter.value) return false;
		if (filterPage.value && (report.page_title || report.page_url) !== filterPage.value) return false;
		if (text) {
			const commentText = (report.status_logs || []).map((log) => log.comment).filter(Boolean);
			const haystack = [report.message, report.page_title, report.page_url, report.person?.full_name, report.reporter_name, report.instance_identifier, ...commentText]
				.filter(Boolean)
				.join(' ')
				.toLowerCase();
			if (!haystack.includes(text)) return false;
		}
		return true;
	});
});

// Resolved reports fold into their own section, out of the way of active
// triage, unless the status filter is explicitly set to Resolved — at
// which point everything shown already is resolved, so there's nothing
// left to fold.
const activeReports = computed(() =>
	filterStatus.value === 'resolved' ? filteredReports.value : filteredReports.value.filter((r) => r.status !== 'resolved')
);
const resolvedReports = computed(() =>
	filterStatus.value === 'resolved' ? [] : filteredReports.value.filter((r) => r.status === 'resolved')
);

// Also duplicated in FeedbackReportCard.vue (the only other place status
// labels are shown) — kept here too since the Status filter dropdown below
// needs it and prop-drilling a label map for one <select> isn't worth it.
const statusLabels = {
	new: 'New',
	seen: 'Acknowledged',
	in_development: 'In Development',
	review: 'Review',
	resolved: 'Resolved',
};

async function fetchReports() {
	loading.value = true;
	const response = await axios.get('/json/feedback-reports');
	reports.value = response.data.records;
	loading.value = false;
}

onMounted(fetchReports);

// "Send Unsent Reports": the only retry mechanism for centralized feedback
// aggregation. Deliberately available even when Offline Mode is on — this
// button IS the explicit "I have a connectivity window right now" override,
// so it doesn't check that setting client-side either.
const sendingUnsent = ref(false);
const sendUnsentMessage = ref(null);

async function sendUnsentReports() {
	sendingUnsent.value = true;
	sendUnsentMessage.value = null;
	try {
		const response = await axios.post('/json/feedback-reports/send-unsent');
		const { attempted, total, sent, error: pushError } = response.data;
		if (!attempted) {
			sendUnsentMessage.value = 'Central push isn\'t configured on this instance.';
		} else if (total === 0) {
			sendUnsentMessage.value = 'Nothing to send — every report is already submitted.';
		} else {
			sendUnsentMessage.value = `Sent ${sent} of ${total} report${total === 1 ? '' : 's'}.` + (pushError ? ` ${pushError}` : '');
		}
		await fetchReports();
	} catch (e) {
		sendUnsentMessage.value = e.response?.data?.message || 'Could not reach central — try again later.';
	} finally {
		sendingUnsent.value = false;
	}
}

function startAdvance(reportId, status) {
	composing.value = { reportId, status, isNote: false };
	comment.value = '';
	error.value = null;
}

function startNote(report) {
	composing.value = { reportId: report.id, status: report.status, isNote: true };
	comment.value = '';
	error.value = null;
}

// Reopening is just another transition (the backend has no "resolved is
// final" enforcement — see FeedbackReportController::update), but it needs
// its own required-comment note (why this is being reopened) same as
// resolving needs one, so it's tracked separately from startAdvance.
function startReopen(report) {
	composing.value = { reportId: report.id, status: 'seen', isNote: false, reopening: true };
	comment.value = '';
	error.value = null;
}

function cancelCompose() {
	composing.value = null;
	comment.value = '';
}

async function confirmCompose() {
	const { reportId, status, isNote, reopening } = composing.value;

	if ((isNote || status === 'review' || status === 'resolved' || reopening) && !comment.value.trim()) {
		error.value = isNote ? 'Enter a note to add.' : reopening ? 'Enter why this is being reopened.' : 'A note is required.';
		return;
	}

	try {
		await axios.patch(`/json/feedback-reports/${reportId}`, {
			status,
			comment: comment.value.trim() || null,
		});
		composing.value = null;
		comment.value = '';
		await fetchReports();
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not update status.';
	}
}

</script>

<template>
	<Head title="Feedback & Bug Reports" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Feedback & Bug Reports</h1>

			<details class="bg-white shadow rounded-lg p-6">
				<summary class="cursor-pointer text-lg font-semibold select-none">Centralized Aggregation Settings</summary>

				<div class="mt-4 space-y-4">
					<p class="text-sm text-gray-500">
						Every instance's own feedback flow works standalone regardless of these — only set
						these up if this instance should push its own reports to a central triage instance,
						or should <em>be</em> that central instance.
					</p>

					<p v-if="aggregationLoading" class="text-gray-500 text-sm">Loading settings…</p>

					<form v-else class="space-y-4" @submit.prevent="saveAggregationSettings">
						<label class="block text-sm">
							<span class="text-gray-700">Role</span>
							<select v-model="aggregationForm.instance_role" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
								<option value="remote">Remote — pushes its reports to a central instance</option>
								<option value="central">Central — receives pushes from remote instances</option>
							</select>
						</label>

						<p v-if="aggregationForm.instance_role === 'central'" class="text-sm text-gray-600">
							Remote instances connect to this one from the <a href="/setup/trust" class="underline text-blue-700">Trust Console</a>:
							create a connection code there that allows "Sending feedback", and give it to the remote's administrator.
						</p>

						<div v-if="aggregationForm.instance_role === 'central' && aggregationSettings?.instance_role === 'central' && aggregationSettings?.legacy_enabled" class="rounded-md border border-gray-200 bg-gray-50 p-4 space-y-2 text-sm">
							<p class="font-medium text-gray-700">Connection code</p>
							<p class="text-gray-500">
								Paste this code on each remote instance's Feedback page to connect it here. It contains
								the shared secret, so share it like a password.
							</p>
							<div v-if="centralCode" class="space-y-2">
								<textarea readonly rows="3" :value="centralCode" class="block w-full border-gray-300 rounded-md shadow-sm font-mono text-xs" @focus="$event.target.select()"></textarea>
								<div class="flex items-center gap-3 flex-wrap">
									<button type="button" class="px-3 py-1.5 bg-blue-600 text-white rounded-md" @click="copyConnectionCode">Copy</button>
									<span v-if="codeCopied" class="text-green-600">Copied.</span>
									<template v-if="!confirmingNewSecret">
										<button type="button" class="text-xs text-gray-500 underline" @click="confirmingNewSecret = true">Make a new code…</button>
									</template>
									<template v-else>
										<span class="text-xs text-red-700">A new code disconnects every remote using the old one until it's pasted there.</span>
										<button type="button" class="text-xs text-red-700 font-medium underline" :disabled="loadingCode" @click="fetchConnectionCode(true)">Make new code</button>
										<button type="button" class="text-xs text-gray-500 underline" @click="confirmingNewSecret = false">Cancel</button>
									</template>
								</div>
							</div>
							<button v-else type="button" class="px-3 py-1.5 bg-blue-600 text-white rounded-md disabled:bg-gray-400" :disabled="loadingCode" @click="fetchConnectionCode()">
								{{ loadingCode ? 'Loading…' : (aggregationSettings?.shared_secret_set ? 'Show Connection Code' : 'Create Connection Code') }}
							</button>
							<p v-if="codeError" class="text-red-600">{{ codeError }}</p>
						</div>
						<p v-else-if="aggregationForm.instance_role === 'central' && aggregationSettings?.legacy_enabled" class="text-sm text-gray-500">
							Save to make this instance Central; its (older) shared-secret connection code appears here after.
						</p>

						<div v-if="aggregationForm.instance_role === 'remote'" class="rounded-md border border-gray-200 bg-gray-50 p-4 space-y-2 text-sm">
							<label class="block">
								<span class="font-medium text-gray-700">Send reports to</span>
								<select v-model="aggregationForm.feedback_trust_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
									<option value="">{{ aggregationSettings?.legacy_enabled ? 'Not chosen (use the older shared-secret setup below)' : 'Choose an instance…' }}</option>
									<option v-for="t in peerTrusts" :key="t.id" :value="t.id">{{ t.name }} ({{ t.base_url }}){{ t.may_send_feedback ? '' : ' — has not allowed feedback yet' }}</option>
								</select>
							</label>
							<p class="text-gray-500">
								Only instances this one is paired with appear here. Pair with the central instance in the
								<a href="/setup/trust" class="underline text-blue-700">Trust Console</a> by pasting the connection code its administrator gives you.
							</p>
						</div>

						<div v-if="aggregationForm.instance_role === 'remote' && aggregationSettings?.legacy_enabled && !aggregationForm.feedback_trust_id" class="rounded-md border border-gray-200 bg-gray-50 p-4 space-y-2 text-sm">
							<label class="block">
								<span class="font-medium text-gray-700">Connection code (older shared-secret setup)</span>
								<span class="block text-gray-500">Copy it from the central instance's Feedback page and paste it here.</span>
								<textarea v-model="pastedCode" rows="2" placeholder="RIFEED1-…" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm font-mono text-xs"></textarea>
							</label>
							<div class="flex items-center gap-3">
								<button type="button" class="px-3 py-1.5 bg-blue-600 text-white rounded-md disabled:bg-gray-400" :disabled="connecting || !pastedCode.trim()" @click="connectWithCode">
									{{ connecting ? 'Connecting…' : 'Connect' }}
								</button>
								<span v-if="connectError" class="text-red-600">{{ connectError }}</span>
							</div>
						</div>

						<label class="block text-sm">
							<span class="text-gray-700">Instance identifier (optional)</span>
							<input
								type="text"
								v-model="aggregationForm.instance_identifier"
								placeholder="Defaults to this instance's own URL host"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
							/>
						</label>

						<p v-if="aggregationForm.instance_role === 'remote' && aggregationSettings?.legacy_enabled && !aggregationForm.feedback_trust_id" class="text-sm text-gray-500">Or enter the older details yourself:</p>

						<label v-if="aggregationForm.instance_role === 'remote' && aggregationSettings?.legacy_enabled && !aggregationForm.feedback_trust_id" class="block text-sm">
							<span class="text-gray-700">Central instance address</span>
							<input
								type="text"
								v-model="aggregationForm.central_ingest_url"
								placeholder="demo.reliefinventory.app"
								autocapitalize="off"
								spellcheck="false"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
							/>
							<span class="text-xs text-gray-400">
								The address you'd type to open it. https:// and /json/feedback/ingest are added for you.
							</span>
						</label>

						<label v-if="aggregationSettings?.legacy_enabled && !aggregationForm.feedback_trust_id" class="block text-sm">
							<span class="text-gray-700">
								Shared secret
								<span v-if="aggregationSettings?.shared_secret_set" class="text-green-600">(currently set)</span>
								<span v-else class="text-gray-400">(not set)</span>
							</span>
							<input
								type="password"
								v-model="aggregationForm.shared_secret"
								placeholder="Leave blank to keep the current secret"
								autocomplete="new-password"
								class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
							/>
							<span class="text-xs text-gray-400">
								Both instances in a remote/central pair must be configured with the same secret.
							</span>
						</label>

						<div class="flex items-center gap-3 flex-wrap">
							<button
								type="submit"
								class="px-4 py-2 bg-blue-600 text-white text-sm rounded-md disabled:bg-gray-400"
								:disabled="aggregationSaving"
							>
								{{ aggregationSaving ? 'Saving…' : 'Save Settings' }}
							</button>
							<button
								v-if="aggregationSettings?.instance_role === 'remote'"
								type="button"
								class="px-4 py-2 border border-gray-300 text-sm rounded-md disabled:text-gray-400"
								:disabled="testingConnection"
								@click="testConnection"
							>
								{{ testingConnection ? 'Testing…' : 'Test Connection' }}
							</button>
							<span v-if="aggregationSaved" class="text-sm text-green-600">Saved.</span>
							<span v-if="aggregationError" class="text-sm text-red-600">{{ aggregationError }}</span>
						</div>
						<p v-if="connectionTest" class="text-sm" :class="connectionTest.ok ? 'text-green-700' : 'text-red-600'">
							{{ connectionTest.message }}
						</p>
					</form>
				</div>
			</details>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<div class="flex items-center justify-between gap-4 flex-wrap">
					<h2 class="text-lg font-semibold">Reports</h2>
					<div v-if="currentInstanceRole === 'remote'" class="flex items-center gap-3">
						<button
							class="text-xs font-medium text-blue-600 underline disabled:text-gray-400 disabled:no-underline"
							:disabled="sendingUnsent"
							@click="sendUnsentReports"
						>
							{{ sendingUnsent ? 'Sending…' : 'Send Unsent Reports' }}
						</button>
						<span v-if="sendUnsentMessage" class="text-xs text-gray-500">{{ sendUnsentMessage }}</span>
					</div>
				</div>

				<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm items-end">
					<label class="block">
						<span class="text-gray-700">Type</span>
						<select v-model="filterType" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
							<option value="">All</option>
							<option value="bug">Bug</option>
							<option value="feature">Feature</option>
						</select>
					</label>
					<label class="block">
						<span class="text-gray-700">Status</span>
						<select v-model="filterStatus" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
							<option value="">All</option>
							<option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option>
						</select>
					</label>
					<label class="block">
						<span class="text-gray-700">Reported by</span>
						<select v-model="filterReporter" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
							<option value="">All</option>
							<option v-for="person in reporterOptions" :key="person.id" :value="String(person.id)">{{ person.full_name }}</option>
						</select>
					</label>
					<label class="block">
						<span class="text-gray-700">Page</span>
						<select v-model="filterPage" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
							<option value="">All</option>
							<option v-for="pageLabel in pageOptions" :key="pageLabel" :value="pageLabel">{{ pageLabel }}</option>
						</select>
					</label>
				</div>

				<label class="block text-sm">
					<span class="text-gray-700">Search</span>
					<input
						type="text"
						v-model="searchText"
						class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
						placeholder="Search message, comments, page, reporter..."
					/>
				</label>

				<button v-if="hasActiveFilters" class="text-xs text-blue-600 underline" @click="clearFilters">
					Clear filters
				</button>

				<p v-if="loading" class="text-gray-500">Loading reports…</p>

				<div v-else-if="!reports.length" class="text-gray-400">No reports yet.</div>

				<div v-else-if="!filteredReports.length" class="text-gray-400">No reports match these filters.</div>

				<div v-else class="space-y-4">
					<FeedbackReportCard
						v-for="report in activeReports"
						:key="report.id"
						:report="report"
						:instance-role="currentInstanceRole"
						:composing="composing"
						:comment="comment"
						:error="error"
						:on-start-note="startNote"
						:on-start-advance="startAdvance"
						:on-start-reopen="startReopen"
						:on-confirm-compose="confirmCompose"
						:on-cancel-compose="cancelCompose"
						@update:comment="comment = $event"
					/>

					<!-- Resolved reports fold out of the way of active triage by default
					     (feedback report #30) — collapsed, not hidden, so the count and
					     history are still one click away. Not shown when the Status filter
					     is already narrowed to Resolved, since activeReports covers that
					     case (see the computed above) and this section would just repeat it. -->
					<details v-if="resolvedReports.length" class="pt-2">
						<summary class="cursor-pointer text-sm text-gray-500 select-none">
							Resolved ({{ resolvedReports.length }})
						</summary>
						<div class="space-y-4 mt-4">
							<FeedbackReportCard
								v-for="report in resolvedReports"
								:key="report.id"
								:report="report"
								:instance-role="currentInstanceRole"
								:composing="composing"
								:comment="comment"
								:error="error"
								:on-start-note="startNote"
								:on-start-advance="startAdvance"
								:on-start-reopen="startReopen"
								:on-confirm-compose="confirmCompose"
								:on-cancel-compose="cancelCompose"
								@update:comment="comment = $event"
							/>
						</div>
					</details>
				</div>
			</section>
		</div>
	</AuthenticatedLayout>
</template>
