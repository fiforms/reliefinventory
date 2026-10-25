<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- DemoFrame.vue

	The box every delivery-days demo sits in: the "Can Accept Delivery:" label as Order Entry shows it, the demo
	itself (slot), what would be saved, and Reset. Also holds the chip styles the demos share (dd_ classes, the same
	look as OrderEntry.vue's oe_daychip), so a demo looks like it would on the real screen.
-->

<script setup>
defineProps({
	label: { type: String, default: 'Can Accept Delivery:' },
	summary: { type: String, required: true },
});
defineEmits(['reset']);
</script>

<template>
	<div class="dd_frame">
		<div class="dd_label">{{ label }}</div>
		<slot />
		<div class="dd_footer">
			<span class="dd_summary">{{ summary }}</span>
			<button type="button" class="dd_reset" @click="$emit('reset')">Reset</button>
		</div>
	</div>
</template>

<style>
.dd_frame {
	border: 1px solid #e5e7eb;
	border-radius: 8px;
	background: #f9fafb;
	padding: 12px;
}
.dd_label {
	font-weight: bold;
	font-size: 0.9rem;
	margin-bottom: 8px;
}
.dd_chips {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}
.dd_chip {
	padding: 6px 12px;
	border: 1px solid #ccc;
	border-radius: 999px;
	background: white;
	cursor: pointer;
	font-size: 0.9rem;
	user-select: none;
}
.dd_chip:hover {
	background: #f3f4f6;
}
.dd_chip.dd_ok {
	background: #007bff;
	border-color: #007bff;
	color: white;
}
.dd_chip.dd_pref {
	background: #16a34a;
	border-color: #16a34a;
	color: white;
}
.dd_footer {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 8px;
	margin-top: 10px;
	font-size: 0.85rem;
}
.dd_summary {
	color: #374151;
}
.dd_reset {
	color: #6b7280;
	text-decoration: underline;
	flex-shrink: 0;
}
.dd_legend {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	margin-top: 8px;
	font-size: 0.8rem;
	color: #4b5563;
}
.dd_swatch {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 999px;
	border: 1px solid #ccc;
	vertical-align: -1px;
	margin-right: 4px;
	background: white;
}
.dd_swatch.dd_ok {
	background: #007bff;
	border-color: #007bff;
}
.dd_swatch.dd_pref {
	background: #16a34a;
	border-color: #16a34a;
}
</style>
