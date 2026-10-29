<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- TrainingReport.vue

	Training report cards (TrainingReportController, manage-training): for each trainee, which of the
	six stations they did real work at in a date range (default today), from the activity log. A
	trainee who did all six gets a certificate. Tap a row for what they did at each station.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrintMenu from '@/Components/PrintMenu.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

defineProps({
	breadcrumb: { type: Array },
});

const today = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD, local
const filters = ref({ from: today, to: today, team: '' });
const report = ref(null);
const error = ref(null);
const loading = ref(false);
const open = ref(null);

const params = computed(() => {
	const p = { from: filters.value.from, to: filters.value.to };
	if (filters.value.team) p.team = Number(filters.value.team);
	return p;
});
const query = computed(() => new URLSearchParams(params.value).toString());

async function load() {
	error.value = null;
	loading.value = true;
	try {
		report.value = (await axios.get('/json/reports/training', { params: params.value })).data;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not load the report cards.';
	} finally {
		loading.value = false;
	}
}

onMounted(load);
</script>

<template>
	<Head title="Training Report Cards" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-6xl mx-auto p-4 space-y-6">
			<div class="flex flex-wrap items-start justify-between gap-4">
				<div>
					<h1 class="text-2xl font-bold">Training Report Cards</h1>
					<p class="text-gray-600 mt-1">
						Which stations each trainee did real work at. A station counts once they've recorded at
						least one action there.
					</p>
				</div>
				<div class="flex flex-wrap gap-2">
					<PrintMenu document-key="training-report-cards" media="report" variant="form" label="Print Report Cards"
						:params="params" :download-href="'/report/training/report-cards.pdf?' + query" />
					<PrintMenu document-key="training-certificates" media="report" variant="form" label="Print All Certificates"
						:params="params" :download-href="'/report/training/certificates.pdf?' + query" />
					<a :href="$withWarehouse('/report/training/report-cards.csv?' + query)" class="ri_formbutton">CSV</a>
				</div>
			</div>

			<section class="bg-white shadow rounded-lg p-4">
				<form class="flex flex-wrap items-end gap-4 text-sm" @submit.prevent="load">
					<label class="block">
						<span class="text-gray-700">From</span>
						<input type="date" v-model="filters.from" class="mt-1 block border-gray-300 rounded-md shadow-sm" />
					</label>
					<label class="block">
						<span class="text-gray-700">To</span>
						<input type="date" v-model="filters.to" class="mt-1 block border-gray-300 rounded-md shadow-sm" />
					</label>
					<label class="block">
						<span class="text-gray-700">Team</span>
						<select v-model="filters.team" class="mt-1 block border-gray-300 rounded-md shadow-sm">
							<option value="">All teams</option>
							<option v-for="n in [1, 2, 3, 4, 5]" :key="n" :value="n">Team {{ n }}</option>
						</select>
					</label>
					<button type="submit" class="ri_formbutton">Show</button>
				</form>
			</section>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<p v-if="loading" class="text-gray-500">Loading…</p>

			<section v-if="report" class="bg-white shadow rounded-lg p-4 space-y-3">
				<p class="text-sm text-gray-700">
					{{ report.totals.complete }} of {{ report.totals.trainees }} trainees completed all six stations.
				</p>
				<p v-if="!report.trainees.length" class="text-sm text-gray-400">No trainee activity in this range.</p>
				<div v-else class="overflow-x-auto">
					<table class="ri_datatable w-full text-sm" border="1">
						<thead>
							<tr>
								<th>Trainee</th>
								<th>Team</th>
								<th v-for="(name, n) in report.stations" :key="n">{{ n }}. {{ name }}</th>
								<th>Result</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<template v-for="t in report.trainees" :key="t.person_id">
								<tr class="cursor-pointer" @click="open = open === t.person_id ? null : t.person_id">
									<td>{{ t.name }}</td>
									<td>{{ t.team }}</td>
									<td v-for="(s, n) in t.stations" :key="n" class="text-center">
										<span v-if="s.done" class="text-green-700 font-bold">✓ {{ s.count }}</span>
										<span v-else class="text-gray-400">—</span>
									</td>
									<td :class="t.complete ? 'text-green-700 font-semibold' : ''">{{ t.complete ? 'Complete' : t.done + ' of 6' }}</td>
									<td @click.stop>
										<PrintMenu v-if="t.complete" document-key="training-certificates" media="report" variant="link"
											label="Certificate" :params="{ ...params, person_id: t.person_id }"
											:download-href="'/report/training/certificates.pdf?' + query + '&person_id=' + t.person_id" />
									</td>
								</tr>
								<tr v-if="open === t.person_id">
									<td :colspan="Object.keys(report.stations).length + 4" class="bg-gray-50">
										<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 py-2">
											<div v-for="(s, n) in t.stations" :key="n">
												<div class="font-semibold">{{ n }}. {{ s.name }}</div>
												<div v-if="!s.actions.length" class="text-gray-400">Nothing yet</div>
												<div v-for="a in s.actions" :key="a.label" class="text-gray-700">{{ a.label }} × {{ a.count }}</div>
											</div>
										</div>
									</td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>
				<p class="text-xs text-gray-500">
					A Driver Portal delivery is logged against the team's driver, so it counts for everyone who
					worked on that team. Tap a row for the details.
				</p>
			</section>
		</div>
	</AuthenticatedLayout>
</template>
