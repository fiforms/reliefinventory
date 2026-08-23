<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- BuildingSafety.vue

	"Who's In The Building" — reachable from the profile menu (view-
	building-occupancy), not the main nav, since it needs to work from
	wherever someone happens to be, including their phone mid-evacuation.

	Two modes:
	  - No active roll call: a plain occupancy list (who's in, when they
	    showed up, why).
	  - An active roll call: the fire-safety headcount view — alphabetical,
	    each name tapped once confirmed. No live sync between devices —
	    everyone marks off their own group independently against the same
	    shared confirmation list; "who's missing" is just the frozen
	    roster minus everyone confirmed so far, recomputed each time this
	    page loads/refreshes.

	Starting/closing a roll call and building closeout are NOT here — those
	are PIN-gated actions on the kiosk itself (VolunteerKiosk.vue), since
	they're "declare official building-safety state" actions, not viewing/
	participating.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
	<Head title="Who's In The Building" />
	<AuthenticatedLayout>
		<div class="bs_page">
			<h1 class="bs_title">Who's In The Building</h1>

			<p v-if="loading" class="bs_hint">Loading…</p>
			<p v-else-if="error" class="bs_error">{{ error }}</p>

			<template v-else-if="rollCall">
				<div class="bs_rollcall_banner">
					<strong>Active roll call</strong> — started {{ formatTime(rollCall.started_at) }}.
					{{ rollCall.confirmed_count }} of {{ rollCall.total }} accounted for.
				</div>
				<p class="bs_hint">
					As people respond, have them step over to the Safe group — that way you can see at a glance
					that everyone in your group has responded. Tap each name below as they're confirmed.
				</p>

				<button class="ri_formbutton" :disabled="refreshing" @click="load">Refresh</button>

				<div class="bs_list">
					<button
						v-for="person in rollCall.roster"
						:key="person.id"
						type="button"
						class="bs_row"
						:class="{ bs_row_confirmed: person.confirmed }"
						:disabled="person.confirmed || confirming === person.id"
						@click="confirm(person)"
					>
						<span>{{ person.name }}</span>
						<span v-if="person.confirmed" class="bs_row_badge">Safe</span>
						<span v-else class="bs_row_hint">Tap when confirmed</span>
					</button>
				</div>
			</template>

			<template v-else>
				<p class="bs_hint">No active roll call — showing everyone currently signed in.</p>
				<div class="bs_list">
					<div v-for="person in occupants" :key="person.id" class="bs_row">
						<span>{{ person.name }}</span>
						<span class="bs_row_hint">
							Since {{ formatTime(person.signed_in_at) }} — {{ person.why }}
						</span>
					</div>
					<p v-if="occupants.length === 0" class="bs_hint">Nobody is currently signed in.</p>
				</div>
			</template>
		</div>
	</AuthenticatedLayout>
</template>

<script>
import axios from 'axios';

export default {
	data() {
		return {
			loading: true,
			refreshing: false,
			error: null,
			rollCall: null,
			occupants: [],
			confirming: null,
		};
	},
	methods: {
		formatTime(value) {
			if (!value) return '';
			return new Date(value).toLocaleString(undefined, { hour: 'numeric', minute: '2-digit' });
		},
		async load() {
			this.refreshing = true;
			this.error = null;
			try {
				const activeResponse = await axios.get('/json/building-safety/roll-calls/active');
				this.rollCall = activeResponse.data.record;
				if (!this.rollCall) {
					const occupancyResponse = await axios.get('/json/building-safety/occupancy');
					this.occupants = occupancyResponse.data.records;
				}
			} catch (e) {
				this.error = 'Could not load building occupancy.';
			} finally {
				this.loading = false;
				this.refreshing = false;
			}
		},
		async confirm(person) {
			this.confirming = person.id;
			try {
				const response = await axios.post(
					`/json/building-safety/roll-calls/${this.rollCall.id}/confirmations/${person.id}`
				);
				this.rollCall = response.data.record;
			} catch (e) {
				this.error = 'Could not confirm this person.';
			} finally {
				this.confirming = null;
			}
		},
	},
	mounted() {
		this.load();
	},
};
</script>

<style scoped>
.bs_page {
	padding: 1em;
	max-width: 640px;
}
.bs_title {
	font-size: 1.4rem;
	font-weight: bold;
	margin-bottom: 0.75em;
}
.bs_hint {
	color: #666;
	margin: 0.75em 0;
}
.bs_error {
	color: #b91c1c;
}
.bs_rollcall_banner {
	background: #fef3c7;
	border: 1px solid #f59e0b;
	border-radius: 8px;
	padding: 0.75em 1em;
	margin-bottom: 0.5em;
}
.bs_list {
	margin-top: 1em;
	display: flex;
	flex-direction: column;
	gap: 0.5em;
}
.bs_row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	border: 1px solid #d1d5db;
	border-radius: 8px;
	padding: 0.75em 1em;
	background: white;
	text-align: left;
	font-size: 1rem;
	cursor: pointer;
}
button.bs_row:disabled {
	cursor: default;
}
.bs_row_confirmed {
	background: #ecfdf5;
	border-color: #10b981;
}
.bs_row_badge {
	color: #047857;
	font-weight: bold;
}
.bs_row_hint {
	color: #888;
	font-size: 0.85rem;
}
</style>
