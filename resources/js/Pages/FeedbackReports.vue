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
import FeedbackReportCard from '@/Components/FeedbackReportCard.vue';
import axios from 'axios';

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
					<FeedbackReportCard
						v-for="report in activeReports"
						:key="report.id"
						:report="report"
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
