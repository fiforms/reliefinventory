<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- ReportDownloadButton.vue

	Single "Download ▾" button + dropdown of format links, for report pages
	that offer more than one export format (e.g. CSV and PDF). Renders each
	link as a plain <a href> (browser-driven download/new-tab), no emitted
	events — the page doesn't need to know which format was picked.

	Usage:
		<ReportDownloadButton :options="[
			{ label: 'CSV', href: '/report/inventory.csv' },
			{ label: 'PDF', href: '/report/inventory.pdf', target: '_blank' },
		]" />
-->

<script>
export default {
	props: {
		label: { type: String, default: 'Download' },
		options: {
			type: Array,
			required: true,
			// each: { label, href, target? }
		},
	},
	data() {
		return {
			open: false,
		};
	},
	methods: {
		onBlur() {
			// Delay so a click on a menu link fires before the menu closes.
			setTimeout(() => (this.open = false), 150);
		},
	},
};
</script>

<template>
	<span class="rdb_container">
		<button type="button" class="ri_defaultbutton" @click="open = !open" @blur="onBlur">
			{{ label }} &#9662;
		</button>
		<div v-if="open" class="rdb_menu">
			<a
				v-for="option in options"
				:key="option.href"
				:href="option.href"
				:target="option.target"
			>{{ option.label }}</a>
		</div>
	</span>
</template>

<style scoped>
.rdb_container {
	position: relative;
	display: inline-block;
}
.rdb_menu {
	position: absolute;
	top: calc(100% + 4px);
	right: 0;
	background: white;
	border: 1px solid #ddd;
	border-radius: 5px;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
	min-width: 100px;
	z-index: 10;
	overflow: hidden;
}
.rdb_menu a {
	display: block;
	padding: 8px 14px;
	color: #333;
	text-decoration: none;
	font-size: 0.95rem;
}
.rdb_menu a:hover {
	background-color: #eef2ff;
}
</style>
