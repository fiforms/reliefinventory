<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- UnfulfilledRequestsReport.vue

	Requested but not fulfilled (UnfulfilledRequestsReportController): every
	finished request line that got less than was asked for, rolled up per
	item with an expandable county/zip breakdown, plus totals by county and
	by zip. Optional order-date range, carried into the CSV and PDF/print.
	Same expandable-row pattern as OutstandingOrdersReport.vue.
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrintMenu from '@/Components/PrintMenu.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';

export default {
	components: { AuthenticatedLayout, Head, PrintMenu },
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
			search: '',
			expanded: {},
		};
	},
	computed: {
		// The range the loaded report was built for — so the exports always
		// match what's on screen, even if the date inputs were edited since.
		appliedParams() {
			const f = this.report?.filters || {};
			const params = {};
			if (f.from) params.from = f.from;
			if (f.to) params.to = f.to;
			return params;
		},
		query() {
			const q = new URLSearchParams(this.appliedParams).toString();
			return q ? '?' + q : '';
		},
		items() {
			const q = this.search.trim().toLowerCase();
			return (this.report?.items || []).filter((i) => !q
				|| (i.name || '').toLowerCase().includes(q)
				|| (i.display_number || '').toLowerCase().includes(q)
				|| (i.category || '').toLowerCase().includes(q));
		},
		totals() {
			return this.report?.totals;
		},
	},
	methods: {
		async fetchReport() {
			this.loading = true;
			this.error = null;
			const params = {};
			if (this.from) params.from = this.from;
			if (this.to) params.to = this.to;
			try {
				const response = await axios.get('/json/reports/unfulfilled', { params });
				this.report = response.data;
				this.expanded = {};
			} catch (error) {
				this.error = error.response?.status === 422
					? 'Check the dates: the "to" date can\'t be before the "from" date.'
					: 'Could not load the unfulfilled requests report.';
			} finally {
				this.loading = false;
			}
		},
		clearDates() {
			this.from = '';
			this.to = '';
			this.fetchReport();
		},
		toggleExpanded(id) {
			this.expanded = { ...this.expanded, [id]: !this.expanded[id] };
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
	<Head title="Unfulfilled Requests Report" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<div class="urr_container">
			<h2 class="ri_datatable_head urr_head">
				Unfulfilled Requests Report
				<span class="urr_exports">
					<a :href="$withWarehouse('/report/unfulfilled.csv' + query)" class="ri_defaultbutton">Download CSV</a>
					<PrintMenu document-key="unfulfilled-requests" media="report" label="Print / PDF"
						:params="appliedParams" :download-href="'/report/unfulfilled.pdf' + query" />
				</span>
			</h2>

			<p class="urr_intro">
				What partners asked for but didn't get, on requests whose filling is finished. Requests still
				being entered or filled aren't counted yet.
			</p>

			<form class="urr_toolbar" @submit.prevent="fetchReport">
				<label>Ordered from <input type="date" v-model="from" class="ri_forminput" /></label>
				<label>to <input type="date" v-model="to" class="ri_forminput" /></label>
				<button type="submit" class="ri_defaultbutton">Apply</button>
				<button v-if="from || to" type="button" class="ri_defaultbutton" @click="clearDates">All dates</button>
				<input type="text" v-model="search" class="ri_forminput urr_search"
					placeholder="Search item, item # or category..." />
			</form>

			<p v-if="error" class="urr_error">{{ error }}</p>
			<p v-if="loading">Loading...</p>

			<template v-if="report && !loading">
				<div class="urr_cards">
					<div class="urr_card"><span>Item types short</span><strong>{{ n(totals.item_types) }}</strong></div>
					<div class="urr_card"><span>Requests affected</span><strong>{{ n(totals.requests) }}</strong></div>
					<div class="urr_card"><span>Requested</span><strong>{{ n(totals.requested) }}</strong></div>
					<div class="urr_card"><span>Filled</span><strong>{{ n(totals.filled) }}</strong></div>
					<div class="urr_card urr_card_short">
						<span>Not filled</span><strong>{{ n(totals.not_filled) }}</strong>
						<small>{{ n(totals.cut_at_allocation) }} cut at allocation · {{ n(totals.short_at_picking) }} short at picking</small>
					</div>
				</div>

				<p v-if="!report.items.length" class="urr_empty">
					No unfulfilled requests{{ report.filters.from || report.filters.to ? ' in this date range' : '' }}.
				</p>

				<template v-else>
					<div class="urr_places">
						<div v-for="place in [['county', 'County'], ['zip', 'Zip']]" :key="place[0]" class="urr_tablewrap">
							<h3>Not Filled by {{ place[1] }}</h3>
							<table class="ri_datatable" border="1">
								<thead>
									<tr><th>{{ place[1] }}</th><th>Requests</th><th>Item Types</th><th>Not Filled</th></tr>
								</thead>
								<tbody>
									<tr v-for="row in report['by_' + place[0]]" :key="row[place[0]]">
										<td>{{ row[place[0]] }}</td>
										<td class="urr_num">{{ n(row.requests) }}</td>
										<td class="urr_num">{{ n(row.item_types) }}</td>
										<td class="urr_num">{{ n(row.not_filled) }}</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<h3>Items Not Filled</h3>
					<div class="urr_tablewrap"><table class="ri_datatable" border="1">
						<thead>
							<tr>
								<th></th>
								<th>Item #</th>
								<th>Item</th>
								<th>Unit</th>
								<th>Requests</th>
								<th>Requested</th>
								<th>Filled</th>
								<th>Not Filled</th>
								<th title="Less approved than requested in Review Allocation">Cut at Allocation</th>
								<th title="Approved but not picked">Short at Picking</th>
							</tr>
						</thead>
						<tbody>
							<template v-for="item in items" :key="item.itemtype_id">
								<tr class="urr_rowlink" @click="toggleExpanded(item.itemtype_id)">
									<td class="urr_expander">{{ expanded[item.itemtype_id] ? '−' : '+' }}</td>
									<td>{{ item.display_number || '—' }}</td>
									<td>{{ item.name }}<span v-if="item.category" class="urr_category">{{ item.category }}</span></td>
									<td>{{ item.unit }}</td>
									<td class="urr_num">{{ n(item.requests) }}</td>
									<td class="urr_num">{{ n(item.requested) }}</td>
									<td class="urr_num">{{ n(item.filled) }}</td>
									<td class="urr_num urr_short">{{ n(item.not_filled) }}</td>
									<td class="urr_num">{{ n(item.cut_at_allocation) }}</td>
									<td class="urr_num">{{ n(item.short_at_picking) }}</td>
								</tr>
								<tr v-if="expanded[item.itemtype_id]" class="urr_detailrow">
									<td></td>
									<td colspan="9">
										<table class="urr_subtable">
											<thead>
												<tr>
													<th>County</th><th>Zip</th><th>Requests</th><th>Requested</th><th>Filled</th>
													<th>Not Filled</th><th>Cut at Allocation</th><th>Short at Picking</th>
												</tr>
											</thead>
											<tbody>
												<tr v-for="loc in item.locations" :key="loc.county + '|' + loc.zip">
													<td>{{ loc.county }}</td>
													<td>{{ loc.zip }}</td>
													<td class="urr_num">{{ n(loc.requests) }}</td>
													<td class="urr_num">{{ n(loc.requested) }}</td>
													<td class="urr_num">{{ n(loc.filled) }}</td>
													<td class="urr_num">{{ n(loc.not_filled) }}</td>
													<td class="urr_num">{{ n(loc.cut_at_allocation) }}</td>
													<td class="urr_num">{{ n(loc.short_at_picking) }}</td>
												</tr>
											</tbody>
										</table>
									</td>
								</tr>
							</template>
							<tr v-if="!items.length">
								<td colspan="10" class="urr_empty">No items match.</td>
							</tr>
						</tbody>
					</table></div>
					<p class="urr_note">
						Quantities are in each item's own unit. County and zip are the requesting partner's.
					</p>
				</template>
			</template>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.urr_container {
	max-width: 1200px;
	margin: 0 auto;
	padding: 16px;
}
.urr_head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 12px;
}
.urr_exports {
	display: flex;
	align-items: center;
	gap: 10px;
}
.urr_intro {
	color: #555;
	margin: 0 0 12px 0;
}
.urr_toolbar {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 14px;
}
.urr_toolbar label {
	display: flex;
	align-items: center;
	gap: 6px;
}
.urr_search {
	min-width: 220px;
	margin-left: auto;
}
.urr_cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 12px;
	margin-bottom: 18px;
}
.urr_card {
	border: 1px solid #ddd;
	border-radius: 6px;
	padding: 10px 12px;
	background: #fff;
	display: flex;
	flex-direction: column;
	gap: 2px;
}
.urr_card span {
	color: #666;
	font-size: 0.85rem;
}
.urr_card strong {
	font-size: 1.4rem;
}
.urr_card small {
	color: #666;
	font-size: 0.75rem;
}
.urr_card_short strong {
	color: #b91c1c;
}
.urr_places {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 18px;
	margin-bottom: 18px;
}
h3 {
	font-weight: bold;
	margin: 6px 0 8px 0;
}
.urr_tablewrap {
	overflow-x: auto;
}
.urr_rowlink {
	cursor: pointer;
}
.urr_rowlink:hover {
	background-color: #eef2ff;
}
.urr_expander {
	width: 1.5em;
	text-align: center;
	font-weight: bold;
	color: #666;
}
.urr_num {
	text-align: right;
	font-variant-numeric: tabular-nums;
}
.urr_short {
	color: #b91c1c;
	font-weight: bold;
}
.urr_category {
	display: block;
	color: #777;
	font-size: 0.8rem;
}
.urr_detailrow td {
	background: #fafafa;
	padding: 8px 8px 12px 8px;
}
.urr_subtable {
	width: 100%;
	border-collapse: collapse;
}
.urr_subtable th, .urr_subtable td {
	padding: 4px 10px;
	font-size: 0.9rem;
	border-bottom: 1px solid #eee;
	text-align: left;
}
.urr_subtable th {
	color: #666;
	font-weight: normal;
}
.urr_subtable td.urr_num {
	text-align: right;
}
.urr_empty {
	text-align: center;
	color: #777;
	padding: 1em;
}
.urr_note {
	color: #777;
	font-size: 0.85rem;
	margin-top: 8px;
}
.urr_error {
	color: #b91c1c;
	margin: 6px 0;
}
</style>
