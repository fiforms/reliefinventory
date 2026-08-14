<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- PeriodStatCard.vue — one metric shown across four trailing windows
     (today / 7 days / 30 days / all-time), with an optional trend badge.
     Shared by the Warehouse Dashboard (full detail) and Situation Report
     (same card, same component — only the data passed in differs). -->

<script>
import TrendBadge from './TrendBadge.vue';

export default {
	components: { TrendBadge },
	props: {
		title: { type: String, required: true },
		counts: { type: Object, required: true }, // {today, last_7_days, last_30_days, all_time}
		trend: { type: Object, default: null }, // {direction, percent} — compared to the prior 7-day window
	},
};
</script>

<template>
	<div class="stat_card">
		<div class="stat_card_head">
			<h3>{{ title }}</h3>
			<TrendBadge v-if="trend" :direction="trend.direction" :percent="trend.percent" label="vs. prior week" />
		</div>
		<div class="stat_card_periods">
			<div class="stat_period">
				<span class="stat_value">{{ counts.today }}</span>
				<span class="stat_label">Today</span>
			</div>
			<div class="stat_period">
				<span class="stat_value">{{ counts.last_7_days }}</span>
				<span class="stat_label">Last 7 days</span>
			</div>
			<div class="stat_period">
				<span class="stat_value">{{ counts.last_30_days }}</span>
				<span class="stat_label">Last 30 days</span>
			</div>
			<div class="stat_period">
				<span class="stat_value">{{ counts.all_time }}</span>
				<span class="stat_label">All time</span>
			</div>
		</div>
	</div>
</template>

<style scoped>
.stat_card {
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 10px;
	padding: 16px 18px;
}
.stat_card_head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	flex-wrap: wrap;
	margin-bottom: 10px;
}
.stat_card_head h3 {
	font-size: 1rem;
	font-weight: bold;
	margin: 0;
}
.stat_card_periods {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 10px;
}
.stat_period {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
}
.stat_value {
	font-size: 1.6rem;
	font-weight: bold;
	color: #1e3a8a;
}
.stat_label {
	font-size: 0.75rem;
	color: #888;
}
@media (max-width: 560px) {
	.stat_card_periods {
		grid-template-columns: repeat(2, 1fr);
		row-gap: 14px;
	}
}
</style>
