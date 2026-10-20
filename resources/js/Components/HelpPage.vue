<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- HelpPage.vue

	Shared shell for every in-app Help guide (resources/js/Pages/Help/*.vue):
	layout, page title, and the "Print these instructions" button, which is
	always present — the printable PDF comes from /report/help/<guide>
	(HelpReportController::GUIDES), and the same key queues it to a site
	printer via the print menu. A guide page only supplies its content.

	Props: guide (slug, must be in HelpReportController::GUIDES), title,
	breadcrumb. Slots: #intro (the line under the title), default (the
	guide's sections).
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import PrintMenu from '@/Components/PrintMenu.vue';

defineProps({
	guide: { type: String, required: true },
	title: { type: String, required: true },
	breadcrumb: { type: Array },
});
</script>

<template>
	<Head :title="'Help: ' + title" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<div class="flex items-start justify-between gap-4">
				<div>
					<h1 class="text-2xl font-bold">{{ title }}</h1>
					<p class="text-gray-600 mt-1"><slot name="intro" /></p>
				</div>
				<PrintMenu
					:document-key="'help-' + guide"
					media="report"
					variant="plain"
					label="Print these instructions"
					:download-href="'/report/help/' + guide"
					extra-class="shrink-0 px-3 py-2 text-sm rounded bg-blue-600 text-white hover:bg-blue-700"
				/>
			</div>

			<slot />

			<p class="text-xs text-gray-500">
				Questions or something not working the way this guide describes? Use
				<strong>Report an Issue</strong> from the profile menu.
			</p>
		</div>
	</AuthenticatedLayout>
</template>
