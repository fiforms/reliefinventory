<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- DesignVote.vue

	Public Design Votes page (/vote/{slug}, DesignVoteController): one card per option with its live demo
	(resources/js/DesignPolls), then a vote for one. Options are shuffled once per visit so the first one shown
	doesn't win just by being first. Open: vote (and change it later from the same browser). Closed: the results,
	with the demos still there to try. Turnstile renders from its class when enabled, the same way Join.vue does.
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
	voter_roles: { type: Object, default: () => ({}) },
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
	voter_role: props.my_vote?.voter_role || '',
	comment: props.my_vote?.comment || '',
});
const votedFor = ref(props.my_vote?.option_key || null);
const changing = ref(!props.my_vote);
const errors = ref({});
const message = ref(null);
const submitting = ref(false);

const resultFor = computed(() => Object.fromEntries((props.results?.options || []).map((r) => [r.key, r])));
const ranked = computed(() => props.results?.options || []);

onMounted(() => {
	if (!props.open || !props.turnstile_enabled) return;
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
		voter_role: form.voter_role || null,
		comment: form.comment.trim() || null,
	};
	if (props.turnstile_enabled) {
		payload['cf-turnstile-response'] = document.querySelector("[name='cf-turnstile-response']")?.value || '';
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
		if (props.turnstile_enabled && window.turnstile) window.turnstile.reset();
	} finally {
		submitting.value = false;
	}
}

function changeVote() {
	changing.value = true;
	if (props.turnstile_enabled && window.turnstile) window.turnstile.reset();
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
				<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
					<label class="block">
						<span class="text-sm text-gray-700">Your name</span>
						<input v-model="form.voter_name" autocomplete="name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base" />
					</label>
					<label class="block">
						<span class="text-sm text-gray-700">I am a…</span>
						<select v-model="form.voter_role" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base">
							<option value="">—</option>
							<option v-for="(text, key) in voter_roles" :key="key" :value="key">{{ text }}</option>
						</select>
					</label>
				</div>
				<label class="block">
					<span class="text-sm text-gray-700">Why, or any other idea?</span>
					<textarea v-model="form.comment" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base"></textarea>
					<span v-if="errors.comment" class="text-xs text-red-600">{{ errors.comment[0] }}</span>
				</label>

				<div v-if="turnstile_enabled" class="cf-turnstile" :data-sitekey="turnstile_site_key"></div>
				<p v-if="turnstile_unavailable" class="text-sm text-amber-900 bg-amber-50 border border-amber-300 rounded p-3">
					Voting can't accept entries right now because its spam protection isn't set up on this server.
				</p>
				<p v-if="message" class="text-sm text-red-600">{{ message }}</p>

				<button type="submit" :disabled="submitting || turnstile_unavailable"
					class="w-full py-3 bg-blue-600 text-white rounded-md font-semibold text-lg hover:bg-blue-700 disabled:opacity-50">
					{{ submitting ? 'Sending…' : (votedFor ? 'Change My Vote' : 'Vote') }}
				</button>
			</form>
		</div>
	</div>
</template>
