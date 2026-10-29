<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- PartnerReport.vue

	Partner Report (PartnerReportController): how much each partner has
	requested and received — requests, units requested vs. filled, fill
	rate on finished requests, shipped/delivered counts, last request —
	with a county roll-up and an expandable list of each partner's
	requests. Order-date range and county filter carry into the CSV and
	PDF/print. Shortfall detail lives in the Unfulfilled Requests Report.
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrintMenu from '@/Components/PrintMenu.vue';
import ChartCanvas from '@/Components/ChartCanvas.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';

export default {
	components: { AuthenticatedLayout, Head, PrintMenu, ChartCanvas },
	props: {
		breadcrumb: { type: Array },
	},
	data() {
		return {
			report: null,
			loading: false,
			error: null,
			from: '',
			to: '',
			countyId: '',
			search: '',
			expanded: {},
		};
	},
	computed: {
		appliedParams() {
			const f = this.report?.filters || {};
			const params = {};
			for (const k of ['from', 'to', 'county_id']) {
				if (f[k]) params[k] = f[k];
			}
			return params;
		},
		query() {
			const q = new URLSearchParams(this.appliedParams).toString();
			return q ? '?' + q : '';
		},
		partners() {
			const q = this.search.trim().toLowerCase();
			return (this.report?.partners || []).filter((p) => !q
				|| (p.name || '').toLowerCase().includes(q)
				|| (p.county || '').toLowerCase().includes(q)
				|| String(p.zip || '').includes(q));
		},
		totals() {
			return this.report?.totals;
		},
		countyChart() {
			const rows = (this.report?.by_county || []).slice(0, 15);
			return {
				type: 'bar',
				data: {
					labels: rows.map((r) => r.county),
					datasets: [
						{ label: 'Requested', data: rows.map((r) => r.requested), backgroundColor: '#2a78d6', borderRadius: 4 },
						{ label: 'Filled', data: rows.map((r) => r.filled), backgroundColor: '#eb6834', borderRadius: 4 },
					],
				},
				options: {
					indexAxis: 'y',
					scales: { x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.06)' } }, y: { grid: { display: false } } },
					plugins: { legend: { position: 'top', align: 'start' } },
				},
			};
		},
		chartHeight() {
			return Math.max(160, Math.min(15, this.report?.by_county?.length || 0) * 44 + 60);
		},
	},
	methods: {
		async fetchReport() {
			this.loading = true;
			this.error = null;
			const params = {};
			if (this.from) params.from = this.from;
			if (this.to) params.to = this.to;
			if (this.countyId) params.county_id = this.countyId;
			try {
				const response = await axios.get('/json/reports/partners', { params });
				this.report = response.data;
				this.expanded = {};
			} catch (error) {
				this.error = error.response?.status === 422
					? 'Check the dates: the "to" date can\'t be before the "from" date.'
					: 'Could not load the partner report.';
			} finally {
				this.loading = false;
			}
		},
		clearFilters() {
			this.from = '';
			this.to = '';
			this.countyId = '';
			this.fetchReport();
		},
		toggleExpanded(key) {
			this.expanded = { ...this.expanded, [key]: !this.expanded[key] };
		},
		rate(value) {
			return value === null || value === undefined ? '—' : value + '%';
		},
		n(value) {
			return Number(value || 0).toLocaleString();
		},
	},
	created() {
		this.fetchReport();
	},
};
</script>

<template>
	<Head title="Partner Report" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<div class="ptr_container">
			<h2 class="ri_datatable_head ptr_head">
				Partner Report
				<span class="ptr_exports">
					<a :href="$withWarehouse('/report/partners.csv' + query)" class="ri_defaultbutton">Download CSV</a>
					<PrintMenu document-key="partner-report" media="report" label="Print / PDF"
						:params="appliedParams" :download-href="'/report/partners.pdf' + query" />
				</span>
			</h2>

			<p class="ptr_intro">
				What each partner has requested and received. The fill rate counts only requests whose filling
				is finished; for what was short and why, see the
				<a href="/reports/unfulfilled">Unfulfilled Requests Report</a>.
			</p>

			<form class="ptr_toolbar" @submit.prevent="fetchReport">
				<label>Ordered from <input type="date" v-model="from" class="ri_forminput" /></label>
				<label>to <input type="date" v-model="to" class="ri_forminput" /></label>
				<label>County
					<select v-model="countyId" class="ri_forminput" @change="fetchReport">
						<option value="">All</option>
						<option v-for="c in report?.options?.counties || []" :key="c.id" :value="c.id">{{ c.county }}, {{ c.state }}</option>
					</select>
				</label>
				<button type="submit" class="ri_defaultbutton">Apply</button>
				<button v-if="from || to || countyId" type="button" class="ri_defaultbutton" @click="clearFilters">Clear</button>
				<input type="text" v-model="search" class="ri_forminput ptr_search"
					placeholder="Search partner, county or zip..." />
			</form>

			<p v-if="error" class="ptr_error">{{ error }}</p>
			<p v-if="loading">Loading...</p>

			<template v-if="report && !loading">
				<div class="ptr_cards">
					<div class="ptr_card"><span>Partners</span><strong>{{ n(totals.partners) }}</strong></div>
					<div class="ptr_card"><span>Requests</span><strong>{{ n(totals.requests) }}</strong></div>
					<div class="ptr_card"><span>Requested</span><strong>{{ n(totals.requested) }}</strong></div>
					<div class="ptr_card"><span>Filled</span><strong>{{ n(totals.filled) }}</strong></div>
					<div class="ptr_card"><span>Fill rate (finished)</span><strong>{{ rate(totals.fill_rate) }}</strong></div>
					<div class="ptr_card">
						<span>Shipped / delivered</span><strong>{{ n(totals.shipped) }} / {{ n(totals.delivered) }}</strong>
						<small>requests</small>
					</div>
				</div>

				<p v-if="!report.partners.length" class="ptr_empty">
					No requests{{ report.filters.from || report.filters.to || report.filters.county_id ? ' for these filters' : '' }}.
				</p>

				<template v-else>
					<div class="ptr_places">
						<div class="ptr_chartbox">
							<h3>Requested vs. filled by county</h3>
							<ChartCanvas :config="countyChart" :height="chartHeight" label="Units requested and filled per county" />
						</div>
						<div class="ptr_tablewrap">
							<h3>By County</h3>
							<table class="ri_datatable" border="1">
								<thead><tr><th>County</th><th>Partners</th><th>Requests</th><th>Requested</th><th>Filled</th></tr></thead>
								<tbody>
									<tr v-for="row in report.by_county" :key="row.county">
										<td>{{ row.county }}</td>
										<td class="ptr_num">{{ n(row.partners) }}</td>
										<td class="ptr_num">{{ n(row.requests) }}</td>
										<td class="ptr_num">{{ n(row.requested) }}</td>
										<td class="ptr_num">{{ n(row.filled) }}</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<h3>Partners</h3>
					<div class="ptr_tablewrap"><table class="ri_datatable" border="1">
						<thead>
							<tr>
								<th></th><th>Partner</th><th>County</th><th>Zip</th><th>Requests</th><th>Requested</th>
								<th>Filled</th><th title="Filled ÷ requested, finished requests only">Fill Rate</th>
								<th>Shipped</th><th>Delivered</th><th>Last Request</th>
							</tr>
						</thead>
						<tbody>
							<template v-for="p in partners" :key="p.person_id ?? 'none'">
								<tr class="ptr_rowlink" @click="toggleExpanded(p.person_id ?? 'none')">
									<td class="ptr_expander">{{ expanded[p.person_id ?? 'none'] ? '−' : '+' }}</td>
									<td>{{ p.name }}</td>
									<td>{{ p.county }}</td>
									<td>{{ p.zip }}</td>
									<td class="ptr_num">{{ n(p.requests) }}</td>
									<td class="ptr_num">{{ n(p.requested) }}</td>
									<td class="ptr_num">{{ n(p.filled) }}</td>
									<td class="ptr_num">{{ rate(p.fill_rate) }}</td>
									<td class="ptr_num">{{ n(p.shipped) }}</td>
									<td class="ptr_num">{{ n(p.delivered) }}</td>
									<td>{{ p.last_request }}</td>
								</tr>
								<tr v-if="expanded[p.person_id ?? 'none']" class="ptr_detailrow">
									<td></td>
									<td colspan="10">
										<table class="ptr_subtable">
											<thead><tr><th>Request #</th><th>Order Date</th><th>Status</th><th>Requested</th><th>Filled</th></tr></thead>
											<tbody>
												<tr v-for="o in p.orders" :key="o.id">
													<td>{{ o.id }}</td>
													<td>{{ o.order_date }}</td>
													<td>{{ o.status }}</td>
													<td class="ptr_num">{{ n(o.requested) }}</td>
													<td class="ptr_num">{{ n(o.filled) }}</td>
												</tr>
											</tbody>
										</table>
									</td>
								</tr>
							</template>
							<tr v-if="!partners.length"><td colspan="11" class="ptr_empty">No partners match.</td></tr>
						</tbody>
					</table></div>
					<p class="ptr_note">
						Quantities are in each item's own unit, so unit totals are a volume count, not a weight.
						County and zip are the partner's.
					</p>
				</template>
			</template>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.ptr_container { max-width: 1200px; margin: 0 auto; padding: 16px; }
.ptr_head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.ptr_exports { display: flex; align-items: center; gap: 10px; }
.ptr_intro { color: #555; margin: 0 0 12px 0; }
.ptr_toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.ptr_toolbar label { display: flex; align-items: center; gap: 6px; }
.ptr_search { min-width: 220px; margin-left: auto; }
.ptr_cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 18px; }
.ptr_card { border: 1px solid #ddd; border-radius: 6px; padding: 10px 12px; background: #fff; display: flex; flex-direction: column; gap: 2px; }
.ptr_card span { color: #666; font-size: 0.85rem; }
.ptr_card strong { font-size: 1.4rem; }
.ptr_card small { color: #666; font-size: 0.75rem; }
.ptr_places { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 18px; margin-bottom: 18px; }
.ptr_chartbox { border: 1px solid #ddd; border-radius: 6px; padding: 10px 12px; background: #fff; }
h3 { font-weight: bold; margin: 6px 0 8px 0; }
.ptr_tablewrap { overflow-x: auto; }
.ptr_rowlink { cursor: pointer; }
.ptr_rowlink:hover { background-color: #eef2ff; }
.ptr_expander { width: 1.5em; text-align: center; font-weight: bold; color: #666; }
.ptr_num { text-align: right; font-variant-numeric: tabular-nums; }
.ptr_detailrow td { background: #fafafa; padding: 8px 8px 12px 8px; }
.ptr_subtable { width: 100%; border-collapse: collapse; }
.ptr_subtable th, .ptr_subtable td { padding: 4px 10px; font-size: 0.9rem; border-bottom: 1px solid #eee; text-align: left; }
.ptr_subtable th { color: #666; font-weight: normal; }
.ptr_subtable td.ptr_num { text-align: right; }
.ptr_empty { text-align: center; color: #777; padding: 1em; }
.ptr_note { color: #777; font-size: 0.85rem; margin-top: 8px; }
.ptr_error { color: #b91c1c; margin: 6px 0; }
</style>
