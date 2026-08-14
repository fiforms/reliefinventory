<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- WarehouseDashboard.vue

	Internal, full-detail warehouse activity dashboard (view-dashboard —
	management/admins only, not general volunteer access). Built from the
	same modular card components as the external Situation Report
	(resources/js/Components/Dashboard/) so the two views can never drift
	apart visually — only the data each one is handed differs, and that
	restriction happens server-side in SitrepController, never here.

	This is the "nothing hidden" internal view: exact counts, full pipeline
	detail, and drill-down links into the underlying pages (Orders,
	Sorting, Receiving, Inventory Report).
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import PeriodStatCard from '@/Components/Dashboard/PeriodStatCard.vue';
import BarBreakdownCard from '@/Components/Dashboard/BarBreakdownCard.vue';
import axios from 'axios';

const DONATION_STAGE_LABELS = { Received: 'Received', Sorting: 'Sorting', Complete: 'Complete' };
const ORDER_STAGE_LABELS = { 'New Order': 'New Order', Filling: 'Filling', Filled: 'Filled', Shipped: 'Shipped' };

export default {
	components: { AuthenticatedLayout, Head, Link, PeriodStatCard, BarBreakdownCard },
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
		async fetchDashboard() {
			this.loading = true;
			this.error = null;
			try {
				const response = await axios.get('/json/dashboard');
				this.data = response.data;
			} catch (error) {
				this.error = 'Could not load the dashboard.';
			} finally {
				this.loading = false;
			}
		},
	},
	created() {
		this.fetchDashboard();
	},
};
</script>

<template>
	<Head title="Warehouse Dashboard" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<div class="wd_container">
			<div class="wd_topbar">
				<h2 class="ri_datatable_head">Warehouse Dashboard</h2>
				<span v-if="data" class="wd_generated">
					Live as of {{ new Date(data.generated_at).toLocaleString() }}
					<button @click="fetchDashboard" class="ri_linkbutton">Refresh</button>
				</span>
			</div>

			<p v-if="error" class="wd_error">{{ error }}</p>
			<p v-if="loading && !data">Loading...</p>

			<div v-if="data" class="wd_grid">
				<PeriodStatCard title="Orders Fulfilled" :counts="data.orders_fulfilled" :trend="data.orders_trend" />
				<PeriodStatCard title="Donations Completed" :counts="data.donations_completed" :trend="data.donations_trend" />

				<BarBreakdownCard title="Donations in Progress" :rows="donationPipelineRows" />
				<BarBreakdownCard title="Orders in Progress" :rows="orderPipelineRows" />

				<BarBreakdownCard title="Orders by County" :rows="countyRows" empty-text="No orders placed yet." />
				<BarBreakdownCard title="Stock by Category" :rows="categoryRows" empty-text="No stock on hand yet." />

				<div class="wd_card wd_inventory">
					<h3>Inventory Summary</h3>
					<div class="wd_inv_stats">
						<div><span class="wd_inv_value">{{ data.inventory_summary.item_types_with_stock }}</span><span class="wd_inv_label">Item types stocked</span></div>
						<div><span class="wd_inv_value">{{ data.inventory_summary.total_units_on_hand }}</span><span class="wd_inv_label">Total units on hand</span></div>
					</div>
					<Link href="/reports/inventory" class="ri_linkbutton">View full Inventory Report &rarr;</Link>
				</div>

				<div class="wd_card wd_donorquality">
					<h3>Donor Quality (last 30 days)</h3>
					<div class="wd_dq_stats">
						<span class="wd_dq_usable">{{ data.donor_quality.usable }} usable</span>
						<span class="wd_dq_loss">{{ data.donor_quality.outdated }} outdated</span>
						<span class="wd_dq_loss">{{ data.donor_quality.trashed }} trashed</span>
						<span class="wd_dq_diverted">{{ data.donor_quality.diverted }} diverted</span>
					</div>
					<p v-if="data.donor_quality.loss_rate_percent !== null" class="wd_dq_rate">
						Loss rate: <strong>{{ data.donor_quality.loss_rate_percent }}%</strong>
					</p>
				</div>
			</div>

			<div v-if="data" class="wd_links">
				<Link href="/order-entry" class="ri_formbutton">Order Entry</Link>
				<Link href="/donation-sorting" class="ri_formbutton">Donation Sorting</Link>
				<Link href="/receiving" class="ri_formbutton">Receiving</Link>
				<Link href="/reports/inventory" class="ri_formbutton">Inventory Report</Link>
				<Link href="/reports/sitrep" class="ri_formbutton">Situation Report &rarr;</Link>
			</div>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.wd_container {
	max-width: 1200px;
	margin: 0 auto;
	padding: 16px;
}
.wd_topbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 10px;
	margin-bottom: 12px;
}
.wd_generated {
	font-size: 0.85rem;
	color: #888;
}
.wd_grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
	gap: 16px;
}
.wd_card {
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 10px;
	padding: 16px 18px;
}
.wd_card h3 {
	font-size: 1rem;
	font-weight: bold;
	margin: 0 0 12px 0;
}
.wd_inv_stats {
	display: flex;
	gap: 24px;
	margin-bottom: 10px;
}
.wd_inv_value {
	display: block;
	font-size: 1.6rem;
	font-weight: bold;
	color: #1e3a8a;
}
.wd_inv_label {
	display: block;
	font-size: 0.75rem;
	color: #888;
}
.wd_dq_stats {
	display: flex;
	gap: 14px;
	flex-wrap: wrap;
	font-size: 0.9rem;
	margin-bottom: 8px;
}
.wd_dq_usable {
	color: #15803d;
	font-weight: bold;
}
.wd_dq_loss {
	color: #b91c1c;
}
.wd_dq_diverted {
	color: #4f46e5;
}
.wd_dq_rate {
	font-size: 0.9rem;
	color: #444;
}
.wd_links {
	display: flex;
	gap: 10px;
	flex-wrap: wrap;
	margin-top: 20px;
}
.wd_error {
	color: #b91c1c;
	margin: 6px 0;
}
</style>
