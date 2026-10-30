<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Help/Guide.vue

	Every Help guide's page (/help/<slug>). The text is not here: it is the
	guide's Markdown source (resources/help/en/<slug>.md), rendered to HTML on
	the server by App\Support\HelpGuide — the same HTML the printable PDF uses
	(reports/help/guide.blade.php). Each section is one card.

	The HTML comes only from those repo files, with raw HTML stripped, so v-html
	is safe here; never pass user-entered text through this page.
-->

<script setup>
import HelpPage from '@/Components/HelpPage.vue';
import { router } from '@inertiajs/vue3';

defineProps({
	guide: { type: String, required: true },
	title: { type: String, required: true },
	intro: { type: String, default: '' },
	sections: { type: Array, required: true },
	breadcrumb: { type: Array },
});

// Links inside the Markdown are plain <a> tags; send same-site ones through
// Inertia so moving between guides doesn't reload the whole app.
function followLink(event) {
	const link = event.target.closest('a');
	if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
		return;
	}
	const href = link.getAttribute('href');
	if (href && href.startsWith('/') && !href.startsWith('//')) {
		event.preventDefault();
		router.visit(href);
	}
}
</script>

<template>
	<HelpPage :guide="guide" :title="title" :breadcrumb="breadcrumb">
		<template #intro><span class="ri_helpguide" v-html="intro" @click="followLink" /></template>

		<section
			v-for="(html, i) in sections"
			:key="i"
			class="ri_helpguide bg-white shadow rounded-lg p-6"
			v-html="html"
			@click="followLink"
		/>
	</HelpPage>
</template>

<style scoped>
/* Typography for the rendered Markdown, matching the hand-written guide pages this replaced. */
.ri_helpguide > :deep(* + *) { margin-top: 1rem; }
.ri_helpguide :deep(h2) { font-size: 1.125rem; line-height: 1.75rem; font-weight: 600; }
.ri_helpguide :deep(h3) { font-weight: 600; }
.ri_helpguide :deep(p),
.ri_helpguide :deep(ul),
.ri_helpguide :deep(ol) { font-size: 0.875rem; line-height: 1.25rem; color: #374151; }
.ri_helpguide :deep(ul) { list-style: disc; padding-left: 1.25rem; }
.ri_helpguide :deep(ol) { list-style: decimal; padding-left: 1.25rem; }
.ri_helpguide :deep(ul > li + li) { margin-top: 0.5rem; }
.ri_helpguide :deep(ol > li + li) { margin-top: 0.75rem; }
.ri_helpguide :deep(li > * + *) { margin-top: 0.5rem; }
.ri_helpguide :deep(li ul > li + li) { margin-top: 0.25rem; }
.ri_helpguide :deep(a) { color: #2563eb; text-decoration: underline; }
.ri_helpguide :deep(code) { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; }
</style>
