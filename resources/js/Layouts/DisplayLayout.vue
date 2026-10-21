<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<script setup>
// Chrome-free layout for unattended screens (R Station display pages, shown with nobody logged in —
// see DeviceSessionController). No menu, banner or breadcrumb: just the content and a small "Log in"
// link tucked in the bottom-left corner. (The R Station's own Settings button lives at the other
// corner, so the two don't collide — see rstation/platform/docs/ESCAPE_HATCH.md.)
</script>

<template>
	<div class="display_layout">
		<slot />
		<a href="/login" class="display_login">Log in</a>
	</div>
</template>

<style scoped>
.display_layout {
	min-height: 100vh;
	background: #f3f4f6;
	cursor: default;
}
.display_login {
	position: fixed;
	left: 8px;
	bottom: 6px;
	font-size: 0.7rem;
	color: #9ca3af;
	opacity: 0.7;
	text-decoration: none;
}
.display_login:hover {
	opacity: 1;
	text-decoration: underline;
}
</style>
