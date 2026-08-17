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
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref } from 'vue';

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

function agoText(iso) {
	const seconds = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
	if (seconds < 60) return `${seconds}s ago`;
	const minutes = Math.round(seconds / 60);
	return `${minutes}m ago`;
}

onMounted(() => {
	fetchSessions();
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
	<Head title="Who's Logged In" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Who's Logged In</h1>

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
		</div>
	</AuthenticatedLayout>
</template>
