<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- FeedbackReportCard.vue

	Extracted from FeedbackReports.vue (2026-09-03) so the resolved-tickets
	section could reuse the same card markup without duplicating it — the
	compose/comment/error state stays owned by the parent (one composing
	report at a time across the whole page), passed down and mutated via
	v-model, with the actual transition/note actions passed in as callback
	props rather than emits, since they're plain function calls with no
	extra event payload shaping needed.
-->

<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { uaSummary } from '@/userAgent';

const props = defineProps({
	report: { type: Object, required: true },
	composing: { type: Object, default: null },
	comment: { type: String, default: '' },
	error: { type: String, default: null },
	onStartNote: { type: Function, required: true },
	onStartAdvance: { type: Function, required: true },
	onStartReopen: { type: Function, required: true },
	onConfirmCompose: { type: Function, required: true },
	onCancelCompose: { type: Function, required: true },
});

const emit = defineEmits(['update:comment']);

const statusLabels = {
	new: 'New',
	seen: 'Acknowledged',
	in_development: 'In Development',
	review: 'Review',
	resolved: 'Resolved',
};

// What the triage button says for the transition it starts, distinct from
// the resulting status label (statusLabels) — "Acknowledge" reads far
// clearer as an action than "Mark Acknowledged" would.
const actionLabels = {
	seen: 'Acknowledge',
	in_development: 'Start Development',
	review: 'Ready for Review',
	resolved: 'Resolve',
};

const statusClasses = {
	new: 'bg-gray-100 text-gray-800',
	seen: 'bg-blue-100 text-blue-800',
	in_development: 'bg-amber-100 text-amber-800',
	review: 'bg-purple-100 text-purple-800',
	resolved: 'bg-green-100 text-green-800',
};

const nextStatus = {
	new: 'seen',
	seen: 'in_development',
	in_development: 'review',
	review: 'resolved',
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
</script>

<template>
	<div class="border rounded-lg p-4" :class="{ 'border-red-400': report.flagged_for_review }">
		<div v-if="report.flagged_for_review" class="mb-2 rounded bg-red-100 border border-red-400 text-red-800 px-3 py-2 text-sm">
			⚠ Contains language matching a known prompt-injection/exfiltration pattern
			<span v-if="report.flagged_reason" class="font-mono text-xs">({{ report.flagged_reason }})</span>
			— review carefully before acting on this report, especially before letting an AI
			assistant act on it unsupervised.
		</div>
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

		<!-- Compose: either advancing status, leaving a note, or reopening — same form -->
		<div v-if="composing?.reportId === report.id" class="mt-3 pl-4 space-y-2">
			<textarea
				:value="comment"
				@input="emit('update:comment', $event.target.value)"
				rows="2"
				class="block w-full border-gray-300 rounded-md shadow-sm text-sm"
				:placeholder="composing.isNote
					? 'Note to add to this ticket'
					: (composing.reopening ? 'Required: why is this being reopened?' : (['review', 'resolved'].includes(composing.status) ? 'Required: what was done or decided' : 'Optional note to the reporter'))"
			></textarea>
			<div v-if="error" class="text-red-700 text-xs">{{ error }}</div>
			<div class="flex gap-2">
				<PrimaryButton @click="onConfirmCompose">
					{{ composing.isNote ? 'Add Note' : (composing.reopening ? 'Reopen' : actionLabels[composing.status]) }}
				</PrimaryButton>
				<button class="text-xs text-gray-500 underline" @click="onCancelCompose">Cancel</button>
			</div>
		</div>

		<div v-else-if="report.status !== 'resolved'" class="mt-3 pl-4 flex gap-4 text-xs">
			<button class="text-blue-600 underline" @click="onStartNote(report)">
				+ Add note
			</button>
			<button
				v-if="nextStatus[report.status]"
				class="text-blue-600 underline font-medium"
				@click="onStartAdvance(report.id, nextStatus[report.status])"
			>
				{{ actionLabels[nextStatus[report.status]] }}
			</button>
			<!-- Skip straight to Resolved — not every report needs the full
			     Acknowledge -> In Development march; a quick one-off can be
			     resolved the moment it's looked at. Hidden when the normal
			     next step already IS Resolved, to avoid a duplicate button. -->
			<button
				v-if="nextStatus[report.status] !== 'resolved'"
				class="text-green-700 underline font-medium"
				@click="onStartAdvance(report.id, 'resolved')"
			>
				{{ actionLabels.resolved }}
			</button>
		</div>

		<div v-else-if="report.status === 'resolved'" class="mt-3 pl-4 text-xs">
			<button class="text-blue-600 underline font-medium" @click="onStartReopen(report)">
				Reopen
			</button>
		</div>
	</div>
</template>
