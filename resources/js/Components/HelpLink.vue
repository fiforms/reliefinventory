<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- HelpLink.vue

	The "Help: <guide>" text link a page shows for its Help guide
	(resources/help/en/<slug>.md, shown by Pages/Help/Guide.vue), opening it in a new tab so work in
	progress on the page isn't lost.

	Usage: <HelpLink guide="receiving">Receiving Guide</HelpLink>
	(guide must be a slug in HelpReportController::GUIDES — a test checks
	every guide is linked from at least one page this way).
-->

<script setup>
defineProps({
	guide: { type: String, required: true },
});
</script>

<template>
	<a :href="'/help/' + guide" target="_blank" class="ri_helplink">Help: <slot /></a>
</template>

<style scoped>
.ri_helplink {
	color: #007bff;
	text-decoration: underline;
	font-size: 0.9rem;
	font-weight: normal;
	align-self: center;
	white-space: nowrap;
}
</style>
