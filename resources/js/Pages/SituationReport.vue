<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- SituationReport.vue

	External Situation Report (view-sitrep) — a restricted subset of the
	same warehouse metrics as WarehouseDashboard.vue, built from the same
	shared card components so the two views stay visually consistent.
	Meant to be shared outside the organization: movement counts, trends,
	county-level order distribution (no names), and a coarse stock summary.
	Nothing here is the final/official report — it's a live snapshot,
	exportable to PDF at any time via the same data this page shows.

	What NEVER appears here (enforced server-side in SitrepController, not
	just hidden in this template): partner/donor names, addresses,
	comments, exact order line items, or donor-quality figures.
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import PeriodStatCard from '@/Components/Dashboard/PeriodStatCard.vue';
import BarBreakdownCard from '@/Components/Dashboard/BarBreakdownCard.vue';
import axios from 'axios';

const DONATION_STAGE_LABELS = { Received: 'Received', Sorting: 'Sorting', Complete: 'Complete' };
const ORDER_STAGE_LABELS = { 'New Order': 'New Order', Filling: 'Filling', Filled: 'Filled', Shipped: 'Shipped' };

export default {
	components: { AuthenticatedLayout, Head, PeriodStatCard, BarBreakdownCard },
	props: {
		breadcrumb: { type: Array },
	},
	data() {
		return {
			data: null,
			loading: false,
			error: null,
		};
	},
	computed: {
		donationPipelineRows() {
			if (!this.data) return [];
			return Object.entries(DONATION_STAGE_LABELS).map(([key, label]) => ({
				label, value: this.data.pipeline.donations[key] || 0,
			}));
		},
		orderPipelineRows() {
			if (!this.data) return [];
			return Object.entries(ORDER_STAGE_LABELS).map(([key, label]) => ({
				label, value: this.data.pipeline.orders[key] || 0,
			}));
		},
		countyRows() {
			if (!this.data) return [];
			return this.data.county_breakdown.map((c) => ({ label: c.county, value: c.count }));
		},
		categoryRows() {
			if (!this.data) return [];
			return this.data.inventory_summary.top_categories.map((c) => ({ label: c.category, value: c.units }));
		},
	},
	methods: {
		async fetchReport() {
			this.loading = true;
			this.error = null;
			try {
				const response = await axios.get('/json/sitrep');
				this.data = response.data;
			} catch (error) {
				this.error = 'Could not load the Situation Report.';
			} finally {
				this.loading = false;
			}
		},
	},
	created() {
		this.fetchReport();
	},
};
</script>

<template>
	<Head title="Situation Report" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<div class="sr_container">
			<div class="sr_topbar">
				<h2 class="ri_datatable_head">Situation Report</h2>
				<div class="sr_actions">
					<span v-if="data" class="sr_generated">
						As of {{ new Date(data.generated_at).toLocaleString() }}
					</span>
					<a href="/report/sitrep.pdf" class="ri_defaultbutton" target="_blank">Download PDF</a>
				</div>
			</div>
			<p class="sr_note">
				A live, informal snapshot for sharing outside the organization — not the official report.
				No names or identifying details are included.
			</p>

			<p v-if="error" class="sr_error">{{ error }}</p>
			<p v-if="loading && !data">Loading...</p>

			<div v-if="data" class="sr_grid">
				<PeriodStatCard title="Orders Fulfilled" :counts="data.orders_fulfilled" :trend="data.orders_trend" />
				<PeriodStatCard title="Donations Completed" :counts="data.donations_completed" :trend="data.donations_trend" />

				<BarBreakdownCard title="Donations in Progress" :rows="donationPipelineRows" />
				<BarBreakdownCard title="Orders in Progress" :rows="orderPipelineRows" />

				<BarBreakdownCard title="Orders by County" :rows="countyRows" empty-text="No orders placed yet." />
				<BarBreakdownCard title="Stock by Category" :rows="categoryRows" empty-text="No stock on hand yet." />

				<div class="sr_card">
					<h3>Stock on Hand</h3>
					<div class="sr_inv_stats">
						<div><span class="sr_inv_value">{{ data.inventory_summary.item_types_with_stock }}</span><span class="sr_inv_label">Item types stocked</span></div>
						<div><span class="sr_inv_value">{{ data.inventory_summary.total_units_on_hand }}</span><span class="sr_inv_label">Total units on hand</span></div>
					</div>
				</div>
			</div>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.sr_container {
	max-width: 1200px;
	margin: 0 auto;
	padding: 16px;
}
.sr_topbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 10px;
	margin-bottom: 6px;
}
.sr_actions {
	display: flex;
	align-items: center;
	gap: 12px;
}
.sr_generated {
	font-size: 0.85rem;
	color: #888;
}
.sr_note {
	color: #666;
	font-size: 0.85rem;
	margin-bottom: 16px;
}
.sr_grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
	gap: 16px;
}
.sr_card {
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 10px;
	padding: 16px 18px;
}
.sr_card h3 {
	font-size: 1rem;
	font-weight: bold;
	margin: 0 0 12px 0;
}
.sr_inv_stats {
	display: flex;
	gap: 24px;
}
.sr_inv_value {
	display: block;
	font-size: 1.6rem;
	font-weight: bold;
	color: #1e3a8a;
}
.sr_inv_label {
	display: block;
	font-size: 0.75rem;
	color: #888;
}
.sr_error {
	color: #b91c1c;
	margin: 6px 0;
}
</style>
