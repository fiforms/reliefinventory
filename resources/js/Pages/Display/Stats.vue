<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<script>
import DisplayLayout from '@/Layouts/DisplayLayout.vue';
import { Head } from '@inertiajs/vue3';
import PeriodStatCard from '@/Components/Dashboard/PeriodStatCard.vue';
import BarBreakdownCard from '@/Components/Dashboard/BarBreakdownCard.vue';
import axios from 'axios';

// An always-on stats sign for an R Station (or any screen): the Situation Report ('sitrep') or the
// warehouse dashboard ('dashboard') with no menus, refreshing itself. Data comes from
// /json/display/<mode>, open to a paired device with the matching Function — see EnsureDeviceAccess.
const REFRESH_MS = 60 * 1000;
const DONATION_STAGE_LABELS = { Received: 'Received', Sorting: 'Sorting', Complete: 'Complete' };
const ORDER_STAGE_LABELS = { 'New Order': 'New Order', Filling: 'Filling', Filled: 'Filled', Shipped: 'Shipped' };

export default {
	components: { DisplayLayout, Head, PeriodStatCard, BarBreakdownCard },
	props: {
		mode: { type: String, default: 'sitrep' },
	},
	data() {
		return { data: null, error: null, timer: null, clockTimer: null, now: new Date() };
	},
	computed: {
		title() {
			return this.mode === 'dashboard' ? 'Warehouse Stats' : 'Situation Report';
		},
		donationPipelineRows() {
			return Object.entries(DONATION_STAGE_LABELS).map(([key, label]) => ({ label, value: this.data.pipeline.donations[key] || 0 }));
		},
		orderPipelineRows() {
			return Object.entries(ORDER_STAGE_LABELS).map(([key, label]) => ({ label, value: this.data.pipeline.orders[key] || 0 }));
		},
		countyRows() {
			return this.data.county_breakdown.map((c) => ({ label: c.county, value: c.count }));
		},
		categoryRows() {
			return this.data.inventory_summary.top_categories.map((c) => ({ label: c.category, value: c.units }));
		},
	},
	methods: {
		async fetchData() {
			try {
				this.data = (await axios.get(`/json/display/${this.mode}`)).data;
				this.error = null;
			} catch (e) {
				// Keep showing the last good numbers across a Wi-Fi drop; only say so if there are none.
				// A 403 means the device's session ended (revoked, re-paired, Function changed): the
				// launcher gets a fresh one on the next restart, so just report it.
				this.error = e.response?.status === 403
					? 'This screen is no longer authorised — restart the R Station or log in.'
					: 'Can’t reach the server — retrying.';
			}
		},
	},
	created() {
		this.fetchData();
		this.timer = setInterval(this.fetchData, REFRESH_MS);
		this.clockTimer = setInterval(() => (this.now = new Date()), 30 * 1000);
	},
	beforeUnmount() {
		clearInterval(this.timer);
		clearInterval(this.clockTimer);
	},
};
</script>

<template>
	<Head :title="title" />
	<DisplayLayout>
		<div class="ds_container">
			<div class="ds_topbar">
				<h1>{{ title }}</h1>
				<span class="ds_time">{{ now.toLocaleDateString() }} {{ now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</span>
			</div>

			<p v-if="error" class="ds_error">{{ error }}</p>
			<p v-if="!data && !error" class="ds_loading">Loading...</p>

			<div v-if="data" class="ds_grid">
				<PeriodStatCard title="Orders Fulfilled" :counts="data.orders_fulfilled" :trend="data.orders_trend" />
				<PeriodStatCard title="Donations Completed" :counts="data.donations_completed" :trend="data.donations_trend" />
				<BarBreakdownCard title="Donations in Progress" :rows="donationPipelineRows" />
				<BarBreakdownCard title="Orders in Progress" :rows="orderPipelineRows" />
				<BarBreakdownCard title="Orders by County" :rows="countyRows" empty-text="No orders placed yet." />
				<BarBreakdownCard title="Stock by Category" :rows="categoryRows" empty-text="No stock on hand yet." />
				<div class="ds_card">
					<h3>Stock on Hand</h3>
					<div class="ds_inv">
						<div><span class="ds_inv_value">{{ data.inventory_summary.item_types_with_stock }}</span><span class="ds_inv_label">Item types stocked</span></div>
						<div><span class="ds_inv_value">{{ data.inventory_summary.total_units_on_hand }}</span><span class="ds_inv_label">Total units on hand</span></div>
					</div>
				</div>
			</div>
		</div>
	</DisplayLayout>
</template>

<style scoped>
.ds_container {
	padding: 20px 24px 28px;
}
.ds_topbar {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	margin-bottom: 14px;
}
.ds_topbar h1 {
	font-size: 1.8rem;
	font-weight: bold;
	margin: 0;
}
.ds_time {
	font-size: 1.1rem;
	color: #6b7280;
}
.ds_grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
	gap: 18px;
}
.ds_card {
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 10px;
	padding: 16px 18px;
}
.ds_card h3 {
	font-size: 1rem;
	font-weight: bold;
	margin: 0 0 12px 0;
}
.ds_inv {
	display: flex;
	gap: 32px;
}
.ds_inv_value {
	display: block;
	font-size: 2.2rem;
	font-weight: bold;
	color: #1e3a8a;
}
.ds_inv_label {
	display: block;
	font-size: 0.85rem;
	color: #888;
}
.ds_error {
	color: #b91c1c;
	margin-bottom: 10px;
}
.ds_loading {
	color: #6b7280;
}
</style>
