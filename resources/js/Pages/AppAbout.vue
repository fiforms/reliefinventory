<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- AppAbout.vue

	Reached from "About" in the profile menu (AuthenticatedLayout.vue); route /about-this-install in routes/web.php.
	The version is the release tag (GitVersionService::release()) — null means an unversioned source install.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
	version: { type: String, default: null },
	commit: { type: String, default: null },
	docker: { type: Boolean, default: false },
});
</script>

<template>
	<Head title="About This Installation" />
	<AuthenticatedLayout>
		<div class="max-w-3xl mx-auto p-6 space-y-6">
			<h1 class="text-2xl font-semibold">About Relief Inventory</h1>

			<section class="bg-white shadow rounded-lg p-6 space-y-3 text-sm text-gray-700">
				<p>
					Relief Inventory is a warehouse management system for disaster relief. It tracks
					donated goods from the moment they arrive, through sorting onto shelves and pallets,
					to the orders that partner organizations request and the shipments that fill them.
				</p>
				<p>
					It was originally built with Adventist Community Services.
					<a href="/about" class="text-blue-700 underline">Project History</a>
					tells the story of how it started and where it's headed.
				</p>
			</section>

			<section class="bg-white shadow rounded-lg p-6 space-y-2 text-sm text-gray-700">
				<h2 class="text-lg font-semibold">This installation</h2>
				<p>
					Version:
					<span v-if="version" class="font-semibold">{{ version }}</span>
					<span v-else class="text-gray-500">unversioned (installed from source)</span>
				</p>
				<p v-if="commit">Commit: <span class="font-mono">{{ commit }}</span></p>
				<p>Running in: {{ docker ? 'a Docker container' : 'a standard (non-Docker) installation' }}</p>
			</section>

			<section class="bg-white shadow rounded-lg p-6 space-y-3 text-sm text-gray-700">
				<h2 class="text-lg font-semibold">Free software</h2>
				<p>
					Relief Inventory is free software, licensed under the
					<a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank" rel="noopener"
						class="text-blue-700 underline">GNU General Public License, version 3</a>.
					You can run your own copy for free, for any purpose, and you can study and modify
					the source code. If you share a modified version, the same license terms apply to it.
				</p>
				<p>
					The program comes with no warranty, to the extent permitted by law. The full license
					text is in the LICENSE.md file of the source code.
				</p>
				<p>
					Source code, downloads, and installation instructions:
					<a href="https://github.com/fiforms/reliefinventory" target="_blank" rel="noopener"
						class="text-blue-700 underline">github.com/fiforms/reliefinventory</a>
				</p>
				<p>
					Project website:
					<a href="https://reliefinventory.fiforms.net" target="_blank" rel="noopener"
						class="text-blue-700 underline">reliefinventory.fiforms.net</a>
				</p>
			</section>
		</div>
	</AuthenticatedLayout>
</template>
