<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- OutstandingOrdersReport.vue

	Every order not yet Shipped (New Order / Ready to Fill / Filling /
	Filled) — the "what's still owed to a partner" view Order Entry's own
	list doesn't provide (that page is an entry tool, not a report: no
	export, no line-level detail across orders at a glance).

	Rolled up per order, with an expandable row for its requested lines —
	same interaction pattern as InventoryReport.vue.
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ReportDownloadButton from '@/Components/ReportDownloadButton.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';

export default {
	components: { AuthenticatedLayout, Head, ReportDownloadButton },
	props: {
		breadcrumb: { type: Array },
	},
	data() {
		return {
			records: [],
			loading: false,
			error: null,
			search: '',
			statusFilter: '',
			expanded: {},
		};
	},
	computed: {
		statuses() {
			return [...new Set(this.records.map((r) => r.status).filter(Boolean))].sort();
		},
		filtered() {
			const q = this.search.trim().toLowerCase();
			return this.records.filter((r) => {
				if (this.statusFilter && r.status !== this.statusFilter) return false;
				if (q && !(
					String(r.id).includes(q) ||
					(r.partner || '').toLowerCase().includes(q)
				)) return false;
				return true;
			});
		},
		totals() {
			return this.filtered.reduce((acc, r) => ({
				line_count: acc.line_count + r.line_count,
				qty_requested: acc.qty_requested + r.qty_requested,
			}), { line_count: 0, qty_requested: 0 });
		},
	},
	methods: {
		async fetchReport() {
			this.loading = true;
			this.error = null;
			try {
				const response = await axios.get('/json/reports/orders');
				this.records = response.data.records;
			} catch (error) {
				this.error = 'Could not load the outstanding orders report.';
			} finally {
				this.loading = false;
			}
		},
		toggleExpanded(id) {
			this.expanded = { ...this.expanded, [id]: !this.expanded[id] };
		},
	},
	created() {
		this.fetchReport();
	},
};
</script>

<template>
	<Head title="Outstanding Orders Report" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<div class="oor_container">
			<h2 class="ri_datatable_head oor_head">
				Outstanding Orders Report
				<ReportDownloadButton :options="[
					{ label: 'CSV', href: '/report/orders.csv' },
					{ label: 'PDF', href: '/report/orders.pdf', target: '_blank' },
				]" />
			</h2>

			<p v-if="error" class="oor_error">{{ error }}</p>
			<p v-if="loading">Loading...</p>

			<div class="oor_toolbar">
				<input
					type="text"
					v-model="search"
					class="ri_forminput oor_search"
					placeholder="Search by order # or partner..."
				/>
				<select v-model="statusFilter" class="ri_forminput">
					<option value="">All statuses</option>
					<option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
				</select>
			</div>

			<div class="oor_tablewrap"><table class="ri_datatable" border="1">
				<thead>
					<tr>
						<th></th>
						<th>Order #</th>
						<th>Partner</th>
						<th>Status</th>
						<th>Order Date</th>
						<th>Needed By</th>
						<th>Fulfillment</th>
						<th>Lines</th>
						<th>Qty Requested</th>
					</tr>
				</thead>
				<tbody>
					<template v-for="record in filtered" :key="record.id">
						<tr class="oor_rowlink" @click="toggleExpanded(record.id)">
							<td class="oor_expander">{{ expanded[record.id] ? '−' : '+' }}</td>
							<td>{{ record.id }}</td>
							<td>{{ record.partner || '(no partner)' }}</td>
							<td><span class="oor_status_badge">{{ record.status }}</span></td>
							<td>{{ record.order_date }}</td>
							<td>{{ record.needed_by_date || '—' }}</td>
							<td>{{ record.fulfillment_method }}</td>
							<td class="oor_num">{{ record.line_count }}</td>
							<td class="oor_num">{{ record.qty_requested }}</td>
						</tr>
						<tr v-if="expanded[record.id]" class="oor_detailrow">
							<td></td>
							<td colspan="8">
								<p v-if="record.status === 'Filled'" class="oor_bollink">
									<a :href="'/report/bol/' + record.id + '.pdf'" target="_blank" @click.stop class="ri_defaultbutton">Generate BOL</a>
								</p>
								<table class="oor_subtable" v-if="record.lines.length">
									<thead>
										<tr><th>Item #</th><th>Item</th><th>Qty Requested</th><th>Unit</th></tr>
									</thead>
									<tbody>
										<tr v-for="(line, idx) in record.lines" :key="idx">
											<td>{{ line.display_number || '(unnumbered)' }}</td>
											<td>{{ line.itemtype }}</td>
											<td class="oor_num">{{ line.qty_requested }}</td>
											<td>{{ line.unit }}</td>
										</tr>
									</tbody>
								</table>
								<p v-else class="oor_empty">No lines on this order.</p>
							</td>
						</tr>
					</template>
					<tr v-if="!filtered.length && !loading">
						<td colspan="9" class="oor_empty">No outstanding orders match.</td>
					</tr>
				</tbody>
				<tfoot v-if="filtered.length">
					<tr class="oor_totalsrow">
						<td colspan="7">Totals ({{ filtered.length }} order{{ filtered.length === 1 ? '' : 's' }})</td>
						<td class="oor_num">{{ totals.line_count }}</td>
						<td class="oor_num">{{ totals.qty_requested }}</td>
					</tr>
				</tfoot>
			</table></div>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.oor_container {
	max-width: 1200px;
	margin: 0 auto;
	padding: 16px;
}
.oor_head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}
.oor_toolbar {
	display: flex;
	align-items: center;
	gap: 14px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}
.oor_search {
	min-width: 220px;
}
.oor_tablewrap {
	overflow-x: auto;
}
.oor_rowlink {
	cursor: pointer;
}
.oor_rowlink:hover {
	background-color: #eef2ff;
}
.oor_expander {
	width: 1.5em;
	text-align: center;
	font-weight: bold;
	color: #666;
}
.oor_num {
	text-align: right;
	font-variant-numeric: tabular-nums;
}
.oor_status_badge {
	background: #fef3c7;
	color: #92400e;
	font-size: 0.7rem;
	font-weight: bold;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	padding: 2px 6px;
	border-radius: 8px;
}
.oor_bollink {
	margin: 0 0 10px 0;
}
.oor_detailrow td {
	background: #fafafa;
	padding: 8px 8px 12px 8px;
}
.oor_subtable {
	width: 100%;
	border-collapse: collapse;
}
.oor_subtable th, .oor_subtable td {
	padding: 4px 10px;
	font-size: 0.9rem;
	border-bottom: 1px solid #eee;
	text-align: left;
}
.oor_subtable th {
	color: #666;
	font-weight: normal;
}
.oor_empty {
	text-align: center;
	color: #777;
	padding: 1em;
}
.oor_totalsrow td {
	font-weight: bold;
	border-top: 2px solid #ccc;
}
.oor_error {
	color: #b91c1c;
	margin: 6px 0;
}
</style>
