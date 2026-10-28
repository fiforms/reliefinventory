<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- DesignVotes.vue

	Design Votes admin (/setup/design-votes, manage-design-votes; DesignVoteController::status). One card per poll in
	App\Support\DesignPolls: open or close it, the voting link + QR code to share, live likes/dislikes per option,
	the "why" comments grouped by option, the free-form "other ideas", and a CSV of both. Voters only see results once a poll is closed.
-->

<script setup>
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineProps({ breadcrumb: { type: Array } });

const status = ref(null);
const error = ref(null);
const notice = ref(null);
const busy = ref(null); // slug being opened/closed
const confirmingClose = ref(null);
const copied = ref(null);

async function load() {
	try {
		status.value = (await axios.get('/json/design-votes')).data;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not load the polls.';
	}
}

async function setOpen(poll, open) {
	error.value = null;
	notice.value = null;
	busy.value = poll.slug;
	confirmingClose.value = null;
	try {
		status.value = (await axios.put(`/json/design-votes/${poll.slug}/open`, { open })).data;
		notice.value = open ? `"${poll.title}" is open for voting.` : `"${poll.title}" is closed; voters now see the results.`;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not change the poll.';
	} finally {
		busy.value = null;
	}
}

async function copyLink(poll) {
	try {
		await navigator.clipboard.writeText(poll.url);
		copied.value = poll.slug;
	} catch {
		error.value = 'Copying isn\'t allowed here — select the link and copy it by hand.';
	}
}

const labelFor = (poll, key) => poll.options.find((o) => o.key === key)?.label || key;
const when = (iso) => (iso ? new Date(iso).toLocaleString() : null);

onMounted(load);
</script>

<template>
	<Head title="Design Votes" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<div class="max-w-4xl mx-auto p-4 space-y-6">
			<h1 class="text-2xl font-bold">Design Votes</h1>

			<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
			<div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>

			<p v-if="!status" class="text-gray-500">Loading polls…</p>

			<section v-for="poll in status?.polls || []" :key="poll.slug" class="bg-white shadow rounded-lg p-6 space-y-4">
				<div class="flex items-start justify-between gap-4 flex-wrap">
					<div>
						<h2 class="text-lg font-semibold">{{ poll.title }}</h2>
						<p class="text-sm text-gray-600">{{ poll.question }}</p>
					</div>
					<span class="text-xs font-semibold uppercase rounded px-2 py-1"
						:class="poll.open ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'">
						{{ poll.open ? 'Open' : 'Closed' }}
					</span>
				</div>

				<div class="flex items-center gap-3 flex-wrap">
					<PrimaryButton v-if="!poll.open" :disabled="busy === poll.slug" @click="setOpen(poll, true)">
						{{ busy === poll.slug ? 'Opening…' : (poll.results.voters ? 'Reopen Voting' : 'Open Voting') }}
					</PrimaryButton>
					<template v-else>
						<button
							class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white"
							:class="confirmingClose === poll.slug ? 'bg-red-600 hover:bg-red-500' : 'bg-amber-600 hover:bg-amber-500'"
							:disabled="busy === poll.slug"
							@click="confirmingClose === poll.slug ? setOpen(poll, false) : (confirmingClose = poll.slug)"
						>
							{{ confirmingClose === poll.slug ? 'Confirm — stop voting and show results' : 'Close Voting' }}
						</button>
						<button v-if="confirmingClose === poll.slug" class="text-sm underline text-gray-600" @click="confirmingClose = null">Cancel</button>
					</template>
					<a :href="$withWarehouse(`/json/design-votes/${poll.slug}/csv`)" class="text-sm text-blue-600 underline">Download CSV</a>
					<a :href="poll.url" target="_blank" class="text-sm text-blue-600 underline">Open the vote page</a>
				</div>

				<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
					<div class="border rounded p-3 flex flex-col items-center gap-2 text-sm">
						<div class="w-36 h-36 [&>svg]:w-full [&>svg]:h-full" v-html="poll.qr_svg"></div>
						<span class="font-mono text-xs text-gray-600 break-all text-center">{{ poll.url }}</span>
						<button class="text-xs text-blue-600 underline" @click="copyLink(poll)">{{ copied === poll.slug ? 'Copied' : 'Copy link' }}</button>
					</div>

					<div class="border rounded p-3 sm:col-span-2 space-y-2 text-sm">
						<p class="font-medium">{{ poll.results.voters }} {{ poll.results.voters === 1 ? 'person has' : 'people have' }} voted</p>
						<div v-for="row in poll.results.options" :key="row.key" class="space-y-1">
							<div class="flex justify-between gap-3">
								<span>{{ labelFor(poll, row.key) }}</span>
								<span class="text-gray-600 whitespace-nowrap">👍 {{ row.likes }} · 👎 {{ row.dislikes }} · {{ row.percent }}% liked</span>
							</div>
							<div class="h-2.5 rounded bg-gray-200 overflow-hidden">
								<div class="h-full bg-green-600" :style="{ width: row.percent + '%' }"></div>
							</div>
						</div>
						<p class="text-xs text-gray-500">Ranked by likes, then fewest dislikes. A voter can like or dislike any number of designs; ones they skip count as neutral.</p>
					</div>
				</div>


				<div class="text-sm space-y-3">
					<h3 class="font-medium">Why people liked or didn't like each design</h3>
					<p v-if="!poll.comments.length" class="text-gray-400">none yet</p>
					<template v-for="option in poll.options" :key="option.key">
						<div v-if="poll.comments.some((c) => c.option_key === option.key)" class="space-y-2">
							<p class="font-medium text-gray-700">{{ option.label }}</p>
							<div v-for="(c, i) in poll.comments.filter((c) => c.option_key === option.key)" :key="i"
								class="border-l-4 pl-3" :class="c.reaction === 'like' ? 'border-green-300' : 'border-red-300'">
								<p class="whitespace-pre-line">{{ c.reaction === 'like' ? '👍' : '👎' }} {{ c.comment }}</p>
								<p class="text-xs text-gray-500">{{ c.voter_name || 'Anonymous' }}</p>
							</div>
						</div>
					</template>
				</div>

				<div class="text-sm space-y-2">
					<h3 class="font-medium">Other ideas <span class="text-gray-500 font-normal">— from the "Have another idea?" box, about this or anything else</span></h3>
					<p v-if="!poll.ideas.length" class="text-gray-400">none yet</p>
					<div v-for="(idea, i) in poll.ideas" :key="i" class="border-l-4 border-amber-200 pl-3">
						<p class="whitespace-pre-line">{{ idea.idea }}</p>
						<p class="text-xs text-gray-500">
							{{ idea.voter_name || 'Anonymous' }}
							<template v-if="idea.email"> · <a :href="`mailto:${idea.email}`" class="underline">{{ idea.email }}</a></template>
							<span v-if="idea.ok_to_contact" class="text-green-700 font-medium"> · OK to contact</span>
							· {{ when(idea.created_at) }}
						</p>
					</div>
				</div>

				<p class="text-xs text-gray-500">
					Anyone with the link can vote, signed in or not — one vote per browser, and voting again replaces it.
					Voters only see results once voting is closed.
					<template v-if="poll.opened_at"> Last opened {{ when(poll.opened_at) }}.</template>
					<template v-if="poll.closed_at"> Last closed {{ when(poll.closed_at) }}.</template>
				</p>
			</section>
		</div>
	</AuthenticatedLayout>
</template>
