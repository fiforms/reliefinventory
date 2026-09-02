<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- ActiveSessions.vue

	"Who's logged in" admin view (permission: admin-system) — read-only list
	of currently active sessions (active in the last 15 minutes) with each
	person's last page and how long ago they were last seen, so an admin can
	check whether anyone's actively working before pushing an update or
	restarting a service.

	Polls /json/active-sessions every 60s — cheap enough not to need push
	(see TrackSessionActivity / ActiveSessionController). A fresh login shows
	up on the very next poll; a logout ages out of the 15-minute window
	rather than being detected explicitly.

	Below the active-sessions table is a separate, permanent Login History
	section (LoginHistory model, /json/login-history) — one row per person
	showing their most recent successful login (both password and
	PIN-unlock), which unlike the table above never ages out. Tap a row to
	expand that person's full login history (fetched on demand via
	/json/login-history?person_id=...) rather than pre-loading every login
	for every person up front.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { uaSummary } from '@/userAgent';

defineProps({
	breadcrumb: {
		type: Array,
	},
});

const sessions = ref([]);
const activeWindowMinutes = ref(15);
const error = ref('');
const loading = ref(true);
const lastRefreshed = ref(null);

const history = ref([]);
const historyError = ref('');
const historyLoading = ref(true);

const expandedPersonId = ref(null);
const personHistory = ref([]);
const personHistoryError = ref('');
const personHistoryLoading = ref(false);

let pollTimer = null;

async function fetchSessions() {
	try {
		const response = await axios.get('/json/active-sessions');
		sessions.value = response.data.sessions;
		activeWindowMinutes.value = response.data.active_window_minutes;
		lastRefreshed.value = new Date();
		error.value = '';
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not load active sessions.';
	} finally {
		loading.value = false;
	}
}

async function fetchHistory() {
	try {
		const response = await axios.get('/json/login-history');
		history.value = response.data.history;
		historyError.value = '';
	} catch (e) {
		historyError.value = e.response?.data?.message || 'Could not load login history.';
	} finally {
		historyLoading.value = false;
	}
}

function agoText(iso) {
	const seconds = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
	if (seconds < 60) return `${seconds}s ago`;
	const minutes = Math.round(seconds / 60);
	return `${minutes}m ago`;
}

// Tap-to-expand for the raw user-agent string, keyed by login_history id —
// separate from expandedPersonId (which expands a person's full history)
// so tapping one doesn't fight with the other.
const expandedUserAgentIds = ref(new Set());
function toggleUserAgent(id) {
	const next = new Set(expandedUserAgentIds.value);
	if (next.has(id)) next.delete(id);
	else next.add(id);
	expandedUserAgentIds.value = next;
}

function methodLabel(method) {
	return method === 'pin' ? 'PIN unlock' : 'Password';
}

async function toggleExpanded(entry) {
	if (expandedPersonId.value === entry.person_id) {
		expandedPersonId.value = null;
		return;
	}

	expandedPersonId.value = entry.person_id;
	personHistory.value = [];
	personHistoryError.value = '';
	personHistoryLoading.value = true;

	try {
		const response = await axios.get('/json/login-history', {
			params: { person_id: entry.person_id, limit: 200 },
		});
		personHistory.value = response.data.history;
	} catch (e) {
		personHistoryError.value = e.response?.data?.message || 'Could not load login history.';
	} finally {
		personHistoryLoading.value = false;
	}
}

onMounted(() => {
	fetchSessions();
	fetchHistory();
	pollTimer = setInterval(fetchSessions, 60000);
});
onUnmounted(() => {
	if (pollTimer) clearInterval(pollTimer);
});

const sortedSessions = computed(() =>
	[...sessions.value].sort((a, b) => new Date(b.last_activity) - new Date(a.last_activity))
);
</script>

<template>
	<Head title="User Activity" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">User Activity</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<div class="flex items-center justify-between">
					<h2 class="text-lg font-semibold">
						Active in the last {{ activeWindowMinutes }} minutes
						<span class="text-gray-400 font-normal">({{ sortedSessions.length }})</span>
					</h2>
					<span v-if="lastRefreshed" class="text-xs text-gray-400">
						Updated {{ lastRefreshed.toLocaleTimeString() }}
					</span>
				</div>

				<div v-if="loading" class="text-gray-500">Loading…</div>
				<div v-else-if="sortedSessions.length === 0" class="text-gray-500">Nobody's currently active.</div>

				<table v-else class="min-w-full text-sm">
					<thead>
						<tr class="text-left text-gray-500 border-b">
							<th class="py-2 pr-4">Name</th>
							<th class="py-2 pr-4">Role</th>
							<th class="py-2 pr-4">Last Page</th>
							<th class="py-2 pr-4">Last Active</th>
							<th class="py-2 pr-4">IP</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(session, i) in sortedSessions" :key="i" class="border-b last:border-0">
							<td class="py-2 pr-4 font-medium">{{ session.name }}</td>
							<td class="py-2 pr-4 text-gray-600">{{ session.roles.join(', ') || '—' }}</td>
							<td class="py-2 pr-4 font-mono text-xs text-gray-600">/{{ session.last_url || '—' }}</td>
							<td class="py-2 pr-4 text-gray-600">{{ agoText(session.last_activity) }}</td>
							<td class="py-2 pr-4 font-mono text-xs text-gray-400">{{ session.ip_address }}</td>
						</tr>
					</tbody>
				</table>

				<p class="text-xs text-gray-500">
					Someone who logs out won't disappear from this list instantly — their session just stops
					updating and ages out of the {{ activeWindowMinutes }}-minute window on its own.
				</p>
			</section>

			<section class="bg-white shadow rounded-lg p-6 space-y-4">
				<h2 class="text-lg font-semibold">
					Login History
					<span class="text-gray-400 font-normal">({{ history.length }})</span>
				</h2>

				<div v-if="historyError" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">
					{{ historyError }}
				</div>

				<div v-if="historyLoading" class="text-gray-500">Loading…</div>
				<div v-else-if="history.length === 0" class="text-gray-500">No logins recorded yet.</div>

				<table v-else class="min-w-full text-sm">
					<thead>
						<tr class="text-left text-gray-500 border-b">
							<th class="py-2 pr-4">Name</th>
							<th class="py-2 pr-4">Method</th>
							<th class="py-2 pr-4">Last Login</th>
							<th class="py-2 pr-4">Device / Browser</th>
							<th class="py-2 pr-4">IP</th>
						</tr>
					</thead>
					<tbody>
						<template v-for="entry in history" :key="entry.id">
							<tr
								class="border-b last:border-0 cursor-pointer hover:bg-gray-50"
								@click="toggleExpanded(entry)"
							>
								<td class="py-2 pr-4 font-medium">
									<span class="text-gray-400 inline-block w-3">{{
										expandedPersonId === entry.person_id ? '▾' : '▸'
									}}</span>
									{{ entry.name }}
								</td>
								<td class="py-2 pr-4 text-gray-600">{{ methodLabel(entry.method) }}</td>
								<td class="py-2 pr-4 text-gray-600">{{ new Date(entry.logged_in_at).toLocaleString() }}</td>
								<td
									class="py-2 pr-4 text-xs text-gray-400 max-w-[16rem] cursor-pointer"
									:title="entry.user_agent"
									@click.stop="toggleUserAgent(entry.id)"
								>
									<span v-if="expandedUserAgentIds.has(entry.id)" class="whitespace-normal break-all">
										{{ entry.user_agent || '—' }}
									</span>
									<span v-else class="truncate block hover:text-gray-600">{{ uaSummary(entry.user_agent) }}</span>
								</td>
								<td class="py-2 pr-4 font-mono text-xs text-gray-400">{{ entry.ip_address || '—' }}</td>
							</tr>
							<tr v-if="expandedPersonId === entry.person_id" class="border-b last:border-0 bg-gray-50">
								<td colspan="5" class="py-3 px-4">
									<div v-if="personHistoryError" class="text-red-800 text-xs">
										{{ personHistoryError }}
									</div>
									<div v-else-if="personHistoryLoading" class="text-gray-500 text-xs">Loading…</div>
									<table v-else class="min-w-full text-xs">
										<thead>
											<tr class="text-left text-gray-500 border-b">
												<th class="py-1 pr-4">Method</th>
												<th class="py-1 pr-4">Logged In</th>
												<th class="py-1 pr-4">Device / Browser</th>
												<th class="py-1 pr-4">IP</th>
											</tr>
										</thead>
										<tbody>
											<tr v-for="past in personHistory" :key="past.id" class="border-b last:border-0">
												<td class="py-1 pr-4 text-gray-600">{{ methodLabel(past.method) }}</td>
												<td class="py-1 pr-4 text-gray-600">
													{{ new Date(past.logged_in_at).toLocaleString() }}
												</td>
												<td
													class="py-1 pr-4 text-gray-400 max-w-[16rem] cursor-pointer"
													:title="past.user_agent"
													@click="toggleUserAgent(past.id)"
												>
													<span v-if="expandedUserAgentIds.has(past.id)" class="whitespace-normal break-all">
														{{ past.user_agent || '—' }}
													</span>
													<span v-else class="truncate block hover:text-gray-600">{{ uaSummary(past.user_agent) }}</span>
												</td>
												<td class="py-1 pr-4 font-mono text-gray-400">{{ past.ip_address || '—' }}</td>
											</tr>
										</tbody>
									</table>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</section>
		</div>
	</AuthenticatedLayout>
</template>
