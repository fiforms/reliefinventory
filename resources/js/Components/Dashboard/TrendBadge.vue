<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- TrendBadge.vue — small up/down/static indicator shared by the Warehouse
     Dashboard and Situation Report. Direction and (optional) percent are
     computed server-side (WarehouseMetrics::trend) — this just renders. -->

<script>
export default {
	props: {
		direction: { type: String, required: true }, // 'up' | 'down' | 'static'
		percent: { type: Number, default: null },
		label: { type: String, default: '' }, // e.g. "vs. prior 7 days"
	},
	computed: {
		arrow() {
			return { up: '▲', down: '▼', static: '▬' }[this.direction] || '';
		},
		text() {
			if (this.percent === null) return this.direction === 'up' ? 'new activity' : 'no change';
			return Math.abs(this.percent) + '%';
		},
	},
};
</script>

<template>
	<span class="trend_badge" :class="'trend_' + direction">
		{{ arrow }} {{ text }}
		<span v-if="label" class="trend_label">{{ label }}</span>
	</span>
</template>

<style scoped>
.trend_badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 0.85rem;
	font-weight: bold;
	padding: 2px 8px;
	border-radius: 10px;
}
.trend_up {
	background: #dcfce7;
	color: #15803d;
}
.trend_down {
	background: #fee2e2;
	color: #b91c1c;
}
.trend_static {
	background: #f3f4f6;
	color: #666;
}
.trend_label {
	font-weight: normal;
	color: #888;
	font-size: 0.75rem;
}
</style>
