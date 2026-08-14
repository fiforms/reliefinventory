<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- BarBreakdownCard.vue — simple horizontal-bar breakdown card (counties,
     categories, pipeline stages, ...). No charting library in this project;
     plain CSS bars are enough for this shape of data and keep the bundle
     lean. Shared by the Warehouse Dashboard and Situation Report. -->

<script>
export default {
	props: {
		title: { type: String, required: true },
		// [{ label, value }], already sorted by the caller
		rows: { type: Array, required: true },
		emptyText: { type: String, default: 'No data yet.' },
	},
	computed: {
		max() {
			return Math.max(1, ...this.rows.map((r) => r.value));
		},
	},
};
</script>

<template>
	<div class="bar_card">
		<h3>{{ title }}</h3>
		<p v-if="!rows.length" class="bar_empty">{{ emptyText }}</p>
		<div v-else class="bar_rows">
			<div v-for="row in rows" :key="row.label" class="bar_row">
				<span class="bar_label">{{ row.label }}</span>
				<div class="bar_track">
					<div class="bar_fill" :style="{ width: (row.value / max * 100) + '%' }"></div>
				</div>
				<span class="bar_value">{{ row.value }}</span>
			</div>
		</div>
	</div>
</template>

<style scoped>
.bar_card {
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 10px;
	padding: 16px 18px;
}
.bar_card h3 {
	font-size: 1rem;
	font-weight: bold;
	margin: 0 0 12px 0;
}
.bar_rows {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.bar_row {
	display: grid;
	grid-template-columns: minmax(90px, auto) 1fr 2.5em;
	align-items: center;
	gap: 8px;
}
.bar_label {
	font-size: 0.85rem;
	color: #444;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.bar_track {
	background: #f3f4f6;
	border-radius: 6px;
	height: 10px;
	overflow: hidden;
}
.bar_fill {
	background: #3b82f6;
	height: 100%;
	border-radius: 6px;
}
.bar_value {
	font-size: 0.85rem;
	font-weight: bold;
	text-align: right;
}
.bar_empty {
	color: #888;
	font-size: 0.9rem;
}
</style>
