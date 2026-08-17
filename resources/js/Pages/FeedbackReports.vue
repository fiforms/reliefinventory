<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- FeedbackReports.vue

	Triage list for in-app bug/feature reports (permission: manage-feedback),
	plus the settings card for the single site-wide banner slot (BannerSetting
	— see Banner.vue for how it's rendered). Each report shows its full
	history (submission + every status_log entry) always visible, not behind
	a click — history entries are either a real status transition ("Moved to
	X") or a same-status note ("Note"), distinguished purely by comparing
	each entry's status to the one before it (historyEntries()), no extra
	DB column needed. Two actions share the same PATCH endpoint
	(FeedbackReportController@update): advancing to the next status
	(required comment only for Resolved), and leaving a note on the current
	status without advancing it (comment always required, available any
	time before Resolved).
-->

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import axios from 'axios';

defineProps({
	breadcrumb: { type: Array },
});

const reports = ref([]);
const loading = ref(true);
const composing = ref(null); // { reportId, status, isNote }
const comment = ref('');
const error = ref(null);

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

// Banner settings
const bannerType = ref('');
const bannerMessage = ref('');
const bannerSaving = ref(false);
const bannerSavedAt = ref(null);
const bannerError = ref(null);
const maintenanceStart = ref('');
const maintenanceEnd = ref('');

// The active banner is already available via the shared Inertia prop, so
// seed the form from it rather than a separate fetch. Start/stop time isn't
// stored separately (BannerSetting only persists the composed message text —
// scheduling display automatically was explicitly decided against, this is
// only for generating the wording), so it can't be re-populated on load.
const page = usePage();
bannerType.value = page.props.banner?.type || '';
bannerMessage.value = page.props.banner?.message || '';

const bannerNeedsMessage = computed(() => bannerType.value === 'maintenance' || bannerType.value === 'message');

function formatForMessage(datetimeLocal) {
	if (!datetimeLocal) return null;

	return new Date(datetimeLocal).toLocaleString([], {
		weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
	});
}

// Regenerates the maintenance banner's message text from the start/stop
// fields whenever either changes — still a plain editable textarea
// afterward, so an admin can hand-adjust the wording after generating it.
watch([maintenanceStart, maintenanceEnd], ([start, end]) => {
	if (bannerType.value !== 'maintenance') return;

	const startText = formatForMessage(start);
	const endText = formatForMessage(end);

	if (startText && endText) {
		bannerMessage.value = `Scheduled maintenance from ${startText} to ${endText}. The site may be briefly unavailable during this time.`;
	} else if (startText) {
		bannerMessage.value = `Scheduled maintenance starting ${startText}. The site may be briefly unavailable during this time.`;
	}
});

async function saveBanner() {
	bannerSaving.value = true;
	bannerError.value = null;
	try {
		await axios.put('/json/banner-settings', {
			type: bannerType.value || null,
			message: bannerNeedsMessage.value ? bannerMessage.value : null,
		});
		bannerSavedAt.value = new Date().toLocaleTimeString();
	} catch (e) {
		bannerError.value = e.response?.data?.message || 'Could not save banner settings.';
	} finally {
		bannerSaving.value = false;
	}
}
</script>

<template>
	<Head title="Feedback & Bug Reports" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Feedback & Bug Reports</h1>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Site Banner</h2>
				<p class="text-sm text-gray-600">
					Only one banner shows at a time. Choose a kind, add a message if needed, and save —
					every user sees it until they dismiss it or you change it again.
				</p>

				<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
					<label class="block">
						<span class="text-gray-700">Banner</span>
						<select v-model="bannerType" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
							<option value="">None</option>
							<option value="feedback">Report a Bug/Feature (built-in message)</option>
							<option value="maintenance">Maintenance Notice</option>
							<option value="message">General Message</option>
						</select>
					</label>
					<template v-if="bannerType === 'maintenance'">
						<label class="block">
							<span class="text-gray-700">Starts</span>
							<input type="datetime-local" v-model="maintenanceStart" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
						<label class="block">
							<span class="text-gray-700">Ends</span>
							<input type="datetime-local" v-model="maintenanceEnd" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
						</label>
					</template>
					<label v-if="bannerNeedsMessage" class="block sm:col-span-2">
						<span class="text-gray-700">Message</span>
						<textarea v-model="bannerMessage" rows="2" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></textarea>
						<span v-if="bannerType === 'maintenance'" class="text-xs text-gray-500">
							Auto-filled from the start/end times above — edit freely, it won't be regenerated unless you change those.
						</span>
					</label>
				</div>

				<div v-if="bannerError" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3 text-sm">
					{{ bannerError }}
				</div>

				<div class="flex items-center gap-3">
					<PrimaryButton :disabled="bannerSaving" @click="saveBanner">
						{{ bannerSaving ? 'Saving…' : 'Save Banner' }}
					</PrimaryButton>
					<span v-if="bannerSavedAt" class="text-xs text-gray-500">Saved {{ bannerSavedAt }}</span>
				</div>

				<p class="text-xs text-gray-500">
					Changing the message resets everyone's dismissal, so it shows again for anyone who
					already closed it.
				</p>
			</section>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">Reports</h2>

				<p v-if="loading" class="text-gray-500">Loading reports…</p>

				<div v-else-if="!reports.length" class="text-gray-400">No reports yet.</div>

				<div v-else class="space-y-4">
					<div v-for="report in reports" :key="report.id" class="border rounded-lg p-4">
						<div class="flex items-start justify-between gap-4">
							<div class="flex-1">
								<div class="flex items-center gap-2 text-sm flex-wrap">
									<span class="font-semibold">{{ report.type === 'bug' ? 'Bug' : 'Feature' }}</span>
									<span v-if="report.urgent" class="px-2 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-800">
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
								<span class="font-semibold">Submitted</span>
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
