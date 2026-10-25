<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- DesignVote.vue

	Public Design Votes page (/vote/{slug}, DesignVoteController): one card per option with its live demo
	(resources/js/DesignPolls), then a vote for one. Options are shuffled once per visit so the first one shown
	doesn't win just by being first. Open: vote (and change it later from the same browser). Closed: the results,
	with the demos still there to try. Either way a "Have another idea?" box at the bottom takes any idea, about
	this question or not, as often as someone has one. Turnstile renders from its class when enabled, the same way
	Join.vue does — one widget per form, so each form reads and resets its own.
-->

<script setup>
import { Head } from '@inertiajs/vue3';
import { ref, reactive, computed, onMounted } from 'vue';
import axios from 'axios';
import demos from '@/DesignPolls/index.js';

const props = defineProps({
	poll: { type: Object, required: true },
	open: { type: Boolean, default: false },
	my_vote: { type: Object, default: null },
	results: { type: Object, default: null },
	turnstile_enabled: { type: Boolean, default: false },
	turnstile_site_key: { type: String, default: null },
	turnstile_unavailable: { type: Boolean, default: false },
});

function shuffled(list) {
	const copy = [...list];
	for (let i = copy.length - 1; i > 0; i--) {
		const j = Math.floor(Math.random() * (i + 1));
		[copy[i], copy[j]] = [copy[j], copy[i]];
	}
	return copy;
}

const options = ref(shuffled(props.poll.options));
const demoFor = (key) => demos[props.poll.slug]?.[key];
const labelFor = (key) => props.poll.options.find((o) => o.key === key)?.label || key;

const form = reactive({
	option_key: props.my_vote?.option_key || null,
	voter_name: props.my_vote?.voter_name || '',
	comment: props.my_vote?.comment || '',
});
const votedFor = ref(props.my_vote?.option_key || null);
const changing = ref(!props.my_vote);
const errors = ref({});
const message = ref(null);
const submitting = ref(false);

const voteWidget = ref(null);
const ideaWidget = ref(null);

function turnstileToken(widget) {
	return widget?.querySelector("[name='cf-turnstile-response']")?.value || '';
}
function resetTurnstile(widget) {
	if (props.turnstile_enabled && window.turnstile && widget) window.turnstile.reset(widget);
}

const idea = reactive({ text: '', voter_name: '' });
const ideaErrors = ref({});
const ideaMessage = ref(null);
const ideaSending = ref(false);
const ideasSent = ref(0);

async function sendIdea() {
	ideaErrors.value = {};
	ideaMessage.value = null;
	if (!idea.text.trim()) {
		ideaMessage.value = 'Type your idea first.';
		return;
	}
	ideaSending.value = true;
	const payload = { idea: idea.text.trim(), voter_name: (idea.voter_name || form.voter_name).trim() || null };
	if (props.turnstile_enabled) payload['cf-turnstile-response'] = turnstileToken(ideaWidget.value);
	try {
		await axios.post(`/vote/${props.poll.slug}/idea`, payload);
		idea.text = '';
		ideasSent.value++;
	} catch (e) {
		ideaErrors.value = e.response?.data?.errors || {};
		ideaMessage.value = e.response?.status === 422
			? (ideaErrors.value.idea?.[0] || 'Please check your idea and try again.')
			: (e.response?.data?.message || 'Something went wrong — please try again.');
	} finally {
		resetTurnstile(ideaWidget.value);
		ideaSending.value = false;
	}
}

const resultFor = computed(() => Object.fromEntries((props.results?.options || []).map((r) => [r.key, r])));
const ranked = computed(() => props.results?.options || []);

onMounted(() => {
	if (!props.turnstile_enabled) return;
	const script = document.createElement('script');
	script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
	script.async = true;
	document.head.appendChild(script);
});

function choose(key) {
	if (!props.open || !changing.value) return;
	form.option_key = key;
	errors.value = {};
	message.value = null;
}

async function submit() {
	errors.value = {};
	message.value = null;
	if (!form.option_key) {
		message.value = 'Pick the design you like best first.';
		return;
	}
	submitting.value = true;
	const payload = {
		option_key: form.option_key,
		voter_name: form.voter_name.trim() || null,
		comment: form.comment.trim() || null,
	};
	if (props.turnstile_enabled) {
		payload['cf-turnstile-response'] = turnstileToken(voteWidget.value);
	}
	try {
		await axios.post(`/vote/${props.poll.slug}`, payload);
		votedFor.value = form.option_key;
		changing.value = false;
		window.scrollTo({ top: 0, behavior: 'smooth' });
	} catch (e) {
		errors.value = e.response?.data?.errors || {};
		message.value = e.response?.status === 422
			? 'Please check the highlighted fields.'
			: (e.response?.data?.message || 'Something went wrong — please try again.');
		resetTurnstile(voteWidget.value);
	} finally {
		submitting.value = false;
	}
}

function changeVote() {
	changing.value = true;
	resetTurnstile(voteWidget.value);
}
</script>

<template>
	<Head :title="poll.title" />
	<div class="min-h-screen bg-gray-100 py-6 px-4">
		<div class="max-w-2xl mx-auto space-y-5">
			<div class="bg-white shadow rounded-lg p-5 space-y-3">
				<p class="text-xs text-gray-500 uppercase font-semibold">Design vote</p>
				<h1 class="text-2xl font-bold text-gray-800">{{ poll.question }}</h1>
				<p class="text-gray-700">{{ poll.intro }}</p>

				<p v-if="!open" class="rounded bg-gray-100 border border-gray-300 text-gray-700 px-4 py-3 text-sm">
					Voting has closed. Here is how people voted ({{ results?.total || 0 }} vote{{ results?.total === 1 ? '' : 's' }}).
				</p>
				<div v-else-if="votedFor && !changing" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3 text-sm space-y-1">
					<p>Thank you! Your vote for <strong>{{ labelFor(votedFor) }}</strong> is in. Results are shown once voting closes.</p>
					<button type="button" class="underline" @click="changeVote">Change my vote</button>
				</div>
			</div>

			<!-- ======================= RESULTS (closed) ======================= -->
			<div v-if="!open && ranked.length" class="bg-white shadow rounded-lg p-5 space-y-3">
				<h2 class="text-lg font-semibold">Results</h2>
				<div v-for="row in ranked" :key="row.key" class="space-y-1">
					<div class="flex justify-between text-sm">
						<span class="font-medium">{{ labelFor(row.key) }}</span>
						<span class="text-gray-600">{{ row.votes }} ({{ row.percent }}%)</span>
					</div>
					<div class="h-3 rounded bg-gray-200 overflow-hidden">
						<div class="h-full bg-blue-600" :style="{ width: row.percent + '%' }"></div>
					</div>
				</div>
			</div>

			<!-- ======================= OPTIONS ======================= -->
			<div
				v-for="(option, index) in options" :key="option.key"
				class="bg-white shadow rounded-lg p-5 space-y-3 border-2"
				:class="open && form.option_key === option.key && changing ? 'border-blue-600' : (votedFor === option.key ? 'border-green-500' : 'border-transparent')"
			>
				<div class="flex items-start justify-between gap-3">
					<div>
						<h2 class="text-lg font-semibold text-gray-800">{{ index + 1 }}. {{ option.label }}</h2>
						<p class="text-sm text-gray-600">{{ option.description }}</p>
					</div>
					<span v-if="!open && resultFor[option.key]" class="text-sm text-gray-600 whitespace-nowrap">
						{{ resultFor[option.key].votes }} vote{{ resultFor[option.key].votes === 1 ? '' : 's' }}
					</span>
				</div>
				<p class="text-xs text-gray-500">Try it:</p>
				<component :is="demoFor(option.key)" v-if="demoFor(option.key)" />
				<label v-if="open && changing" class="flex items-center gap-3 py-2 px-3 rounded-md bg-gray-50 border border-gray-200 cursor-pointer">
					<input type="radio" name="option" :value="option.key" :checked="form.option_key === option.key" class="h-5 w-5" @change="choose(option.key)" />
					<span class="font-medium text-gray-800">This one</span>
				</label>
			</div>

			<!-- ======================= VOTE FORM ======================= -->
			<!-- v-show, not v-if: Turnstile only draws into elements present when its script loads -->
			<form v-if="open" v-show="changing" class="bg-white shadow rounded-lg p-5 space-y-4" @submit.prevent="submit">
				<p class="text-sm text-gray-800">
					Your choice:
					<strong v-if="form.option_key">{{ labelFor(form.option_key) }}</strong>
					<span v-else class="text-gray-500">none yet — tap "This one" under the design you like best</span>
				</p>
				<span v-if="errors.option_key" class="text-xs text-red-600">{{ errors.option_key[0] }}</span>

				<p class="text-xs text-gray-500 uppercase font-semibold">Optional</p>
				<label class="block">
					<span class="text-sm text-gray-700">Your name</span>
					<input v-model="form.voter_name" autocomplete="name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base" />
				</label>
				<label class="block">
					<span class="text-sm text-gray-700">Why did you pick this one?</span>
					<textarea v-model="form.comment" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base"></textarea>
					<span v-if="errors.comment" class="text-xs text-red-600">{{ errors.comment[0] }}</span>
				</label>

				<div v-if="turnstile_enabled" ref="voteWidget" class="cf-turnstile" :data-sitekey="turnstile_site_key"></div>
				<p v-if="turnstile_unavailable" class="text-sm text-amber-900 bg-amber-50 border border-amber-300 rounded p-3">
					Voting can't accept entries right now because its spam protection isn't set up on this server.
				</p>
				<p v-if="message" class="text-sm text-red-600">{{ message }}</p>

				<button type="submit" :disabled="submitting || turnstile_unavailable"
					class="w-full py-3 bg-blue-600 text-white rounded-md font-semibold text-lg hover:bg-blue-700 disabled:opacity-50">
					{{ submitting ? 'Sending…' : (votedFor ? 'Change My Vote' : 'Vote') }}
				</button>
			</form>

			<!-- ======================= ANOTHER IDEA ======================= -->
			<form class="bg-white shadow rounded-lg p-5 space-y-3" @submit.prevent="sendIdea">
				<h2 class="text-lg font-semibold text-gray-800">Have another idea?</h2>
				<p class="text-sm text-gray-600">
					A different way to do this, or something else entirely — anything that would make the system better.
					You don't have to vote to send one, and you can send as many as you like.
				</p>
				<textarea v-model="idea.text" rows="3" class="block w-full border-gray-300 rounded-md shadow-sm text-base"></textarea>
				<label v-if="!open || !changing" class="block">
					<span class="text-sm text-gray-700">Your name (optional)</span>
					<input v-model="idea.voter_name" autocomplete="name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base" />
				</label>
				<div v-if="turnstile_enabled" ref="ideaWidget" class="cf-turnstile" :data-sitekey="turnstile_site_key"></div>
				<p v-if="ideasSent" class="text-sm text-green-700">Thank you — got it.{{ ideasSent > 1 ? ` (${ideasSent} sent)` : '' }} Send another any time.</p>
				<p v-if="ideaMessage" class="text-sm text-red-600">{{ ideaMessage }}</p>
				<button type="submit" :disabled="ideaSending || turnstile_unavailable"
					class="px-4 py-2 bg-gray-700 text-white rounded-md font-semibold hover:bg-gray-800 disabled:opacity-50">
					{{ ideaSending ? 'Sending…' : 'Send Idea' }}
				</button>
			</form>
		</div>
	</div>
</template>
