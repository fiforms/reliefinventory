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
-->

<script setup>
import { computed, onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import axios from 'axios';
import { uaSummary } from '@/userAgent';

defineProps({
	breadcrumb: { type: Array },
});

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
			const haystack = [report.message, report.page_title, report.page_url, report.person?.full_name, ...commentText]
				.filter(Boolean)
				.join(' ')
				.toLowerCase();
			if (!haystack.includes(text)) return false;
		}
		return true;
	});
});

const statusLabels = {
	new: 'New',
	seen: 'Acknowledged',
	in_development: 'In Development',
	resolved: 'Resolved',
};

// What the triage button says for the transition it starts, distinct from
// the resulting status label (statusLabels) — "Acknowledge" reads far
// clearer as an action than "Mark Acknowledged" would.
const actionLabels = {
	seen: 'Acknowledge',
	in_development: 'Start Development',
	resolved: 'Resolve',
};

const statusClasses = {
	new: 'bg-gray-100 text-gray-800',
	seen: 'bg-blue-100 text-blue-800',
	in_development: 'bg-amber-100 text-amber-800',
	resolved: 'bg-green-100 text-green-800',
};

const nextStatus = {
	new: 'seen',
	seen: 'in_development',
	in_development: 'resolved',
};

function formatDateTime(value) {
	return value ? new Date(value).toLocaleString() : '';
}

// Turns a report's flat status_logs into a display list where each entry
// knows whether it was a real transition ("Moved to X") or a note left
// while status stayed the same ("Note") — derived purely by comparing each
// entry's status to the one before it (implicit start state: New).
function historyEntries(report) {
	let previousStatus = 'new';

	return (report.status_logs || []).map((log) => {
		const isTransition = log.status !== previousStatus;
		previousStatus = log.status;

		return { ...log, isTransition };
	});
}

async function fetchReports() {
	loading.value = true;
	const response = await axios.get('/json/feedback-reports');
	reports.value = response.data.records;
	loading.value = false;
}

onMounted(fetchReports);

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

function cancelCompose() {
	composing.value = null;
	comment.value = '';
}

async function confirmCompose() {
	const { reportId, status, isNote } = composing.value;

	if ((isNote || status === 'resolved') && !comment.value.trim()) {
		error.value = isNote ? 'Enter a note to add.' : 'A resolution note is required.';
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

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Reports</h2>

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
					<div v-for="report in filteredReports" :key="report.id" class="border rounded-lg p-4">
						<div class="flex items-start justify-between gap-4">
							<div class="flex-1">
								<div class="flex items-center gap-2 text-sm flex-wrap">
									<span class="font-semibold">{{ report.type === 'bug' ? 'Bug' : 'Feature' }}</span>
									<span v-if="report.urgent && report.status !== 'resolved'" class="px-2 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-800">
										URGENT
									</span>
									<span class="px-2 py-0.5 rounded text-xs font-medium" :class="statusClasses[report.status]">
										{{ statusLabels[report.status] }}
									</span>
									<span class="text-gray-500">{{ report.person?.full_name }}</span>
									<span class="text-gray-400 font-mono text-xs">{{ report.page_title || report.page_url }}</span>
								</div>
								<p class="text-sm text-gray-700 mt-1">{{ report.message }}</p>
								<div class="text-xs text-gray-400 font-mono mt-1">
									{{ report.page_url }}
									<span v-if="report.commit_hash"> — commit {{ report.commit_hash }}</span>
									<span v-if="report.user_agent" :title="report.user_agent"> — {{ uaSummary(report.user_agent) }}</span>
								</div>
							</div>
							<a
								v-if="report.screenshot_path"
								:href="`/json/feedback-reports/${report.id}/screenshot`"
								target="_blank"
								class="text-xs text-blue-600 underline shrink-0"
							>
								Screenshot
							</a>
						</div>

						<!-- History: always visible, indented cards — submission first, then every log entry -->
						<div class="mt-3 pl-4 border-l-2 border-gray-200 space-y-2">
							<div class="rounded border bg-gray-50 px-3 py-2 text-xs">
								<span class="font-semibold">Submitted<template v-if="report.urgent"> - URGENT</template></span>
								by {{ report.person?.full_name }}
								<span class="text-gray-400">— {{ formatDateTime(report.created_at) }}</span>
							</div>
							<div
								v-for="log in historyEntries(report)"
								:key="log.id"
								class="rounded border bg-white shadow-sm px-3 py-2 text-xs"
							>
								<span class="font-semibold">
									{{ log.isTransition ? `Moved to ${statusLabels[log.status]}` : `Note (${statusLabels[log.status]})` }}
								</span>
								by {{ log.person?.full_name }}
								<span class="text-gray-400">— {{ formatDateTime(log.created_at) }}</span>
								<div v-if="log.comment" class="text-gray-700 mt-1">{{ log.comment }}</div>
							</div>
						</div>

						<!-- Compose: either advancing status or leaving a note, same form -->
						<div v-if="composing?.reportId === report.id" class="mt-3 pl-4 space-y-2">
							<textarea
								v-model="comment"
								rows="2"
								class="block w-full border-gray-300 rounded-md shadow-sm text-sm"
								:placeholder="composing.isNote
									? 'Note to add to this ticket'
									: (composing.status === 'resolved' ? 'Required: what was done or decided' : 'Optional note to the reporter')"
							></textarea>
							<div v-if="error" class="text-red-700 text-xs">{{ error }}</div>
							<div class="flex gap-2">
								<PrimaryButton @click="confirmCompose">
									{{ composing.isNote ? 'Add Note' : actionLabels[composing.status] }}
								</PrimaryButton>
								<button class="text-xs text-gray-500 underline" @click="cancelCompose">Cancel</button>
							</div>
						</div>

						<div v-else-if="report.status !== 'resolved'" class="mt-3 pl-4 flex gap-4 text-xs">
							<button class="text-blue-600 underline" @click="startNote(report)">
								+ Add note
							</button>
							<button
								v-if="nextStatus[report.status]"
								class="text-blue-600 underline font-medium"
								@click="startAdvance(report.id, nextStatus[report.status])"
							>
								{{ actionLabels[nextStatus[report.status]] }}
							</button>
						</div>
					</div>
				</div>
			</section>
		</div>
	</AuthenticatedLayout>
</template>
