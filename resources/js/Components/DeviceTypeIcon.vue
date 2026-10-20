<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Tile icon telling the two kinds of managed device apart: an R Station (the Raspberry Pi
     device platform) and an RI Print Server (the desktop print client for a PC or Mac).
     Plain inline SVG so it follows the text colour in light and dark; swap in real logo art
     here and every device tile picks it up. -->
<script setup>
defineProps({
	type: { type: String, required: true }, // 'rstation' | 'print_server'
});
</script>

<template>
	<svg v-if="type === 'rstation'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-8 h-8 shrink-0 text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="R Station">
		<rect x="3" y="4" width="18" height="11" rx="1.5" />
		<path d="M8 19h8M12 15v4" />
		<path d="M9.5 11.5V8h2.2a1.2 1.2 0 0 1 0 2.4H9.5m2.2 0 1.6 1.6" />
	</svg>
	<svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-8 h-8 shrink-0 text-sky-700" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="RI Print Server">
		<path d="M7 9V3.5h10V9" />
		<rect x="3" y="9" width="18" height="8" rx="1.5" />
		<rect x="7" y="14" width="10" height="6.5" rx="0.5" />
		<circle cx="17.5" cy="11.5" r="0.6" fill="currentColor" />
	</svg>
</template>
