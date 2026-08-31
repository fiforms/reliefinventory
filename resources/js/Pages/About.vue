<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- About.vue

	Public "About This Project" page, linked from Welcome.vue's HOSTED_BY
	credit line (config('app.hosted_link'), typically pointed at this page
	via a relative /about URL rather than an external domain — see
	routes/web.php). No auth required, matching Welcome's own guest access.
-->

<script setup>
import { Head } from '@inertiajs/vue3';
</script>

<template>
	<Head title="About This Project" />
	<div class="min-h-screen bg-gray-50 flex flex-col">
		<header class="bg-white shadow-md">
			<div class="container mx-auto px-4 py-4 flex justify-between items-center">
				<a href="/" class="text-2xl font-bold text-blue-600">Relief Inventory</a>
				<nav class="space-x-4">
					<a href="/" class="text-gray-700 hover:text-blue-600">Home</a>
					<a href="/login" class="text-gray-700 hover:text-blue-600">Login</a>
				</nav>
			</div>
		</header>

		<section class="flex-1">
			<div class="container mx-auto px-4 py-16 max-w-3xl">
				<h1 class="text-4xl font-bold text-gray-800 mb-6">About This Project</h1>

				<div class="prose prose-lg text-gray-700 space-y-6">
					<p>
						Relief Inventory began during the response to Hurricane Helene in Western North
						Carolina. A disaster relief warehouse needed a way to track incoming donations
						accurately and quickly, route approval decisions, and manage outgoing shipments — and
						nothing on the market fit the unique needs of a donations warehouse. A Google Sheets
						system was cobbled together to keep pace, then reworked again and again as the
						situation evolved. Its limitations soon became clear, and work began on something
						better — starting not with a spec, but with a meeting of the minds among the people
						actually running the warehouse. Out of that collaboration, a framework began to take
						shape: one meant to guide a donation all the way from the initial offer through
						delivery to a distribution point, while tracking volunteer hours along the way.
					</p>
					<p>
						That collaboration has continued ever since, alongside Adventist Community Services
						and the volunteers and staff who use the system day to day. The software has been
						shaped less by a spec written in advance and more by real operations — it went into
						use while it was still being built, and most of what exists today — from how sorting
						sessions autosave line-by-line so a dropped connection never loses a scan, to how an
						order moves through filling, shipping, and delivery — traces back to a specific gap
						someone hit while the work was happening, not a feature that sounded good on paper.
					</p>

					<h2 class="text-2xl font-semibold text-gray-800 pt-4">Where this is headed</h2>
					<p>
						The starting point for this project was inventory: what came in, what's on hand, what
						went out. That's necessary, but on its own it's just a database — and a database
						doesn't help a new volunteer figure out what to do next, doesn't remember why a
						donation needs a second look, and doesn't adapt when two warehouses run their
						operations differently for good reasons.
					</p>
					<p>
						The direction this project is growing toward is software that <strong>guides the
						work</strong>, not just records it — a system that walks someone through the next
						step instead of handing them an empty form and a set of dropdowns, and that treats
						training as something built into the everyday screens people already use, not a
						separate manual nobody has time to read during an actual response.
					</p>
					<p>
						Just as important: every warehouse and every incident is not the same. A small
						volunteer-run site and a large multi-agency distribution point don't operate
						identically, and a tool that forces both into one rigid workflow ends up fighting
						the people using it. The goal here is flexibility by design — configurable enough
						that a warehouse manager's real process shapes how the software behaves, rather than
						the other way around.
					</p>
					<p>
						This is a work in progress, built in the open, adjusted continuously based on what
						actually happens on the ground.
					</p>
				</div>
			</div>
		</section>

		<footer class="bg-white border-t">
			<div class="container mx-auto px-4 py-6 text-center text-gray-600">
				&copy; {{ new Date().getFullYear() }} Relief Inventory Project,
				<a href="https://www.gnu.org/licenses/gpl-3.0.en.html">Licensed under the GNU General Public License v. 3</a>
			</div>
		</footer>
	</div>
</template>
