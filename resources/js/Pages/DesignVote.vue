<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- DesignVote.vue

	Public Design Votes page (/vote/{slug}, DesignVoteController): one card per option with its live demo
	(resources/js/DesignPolls) and two buttons, "I like this" / "Not for me", each opening an optional "why" box.
	Options left alone stay neutral; at least one reaction is needed, and disliking all of them is fine. Options are
	shuffled once per visit so the first one shown doesn't get an edge. Open: vote (and change it later from the
	same browser). Closed: likes and dislikes per option, with the demos still there to try. Either way a "Have another idea?" box at the bottom takes any idea, about
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

// option key => { reaction: null | 'like' | 'dislike', comment }; null is neutral and isn't sent.
const reactions = reactive(Object.fromEntries(props.poll.options.map((o) => {
	const mine = props.my_vote?.reactions?.find((r) => r.option_key === o.key);
	return [o.key, { reaction: mine?.reaction || null, comment: mine?.comment || '' }];
})));
const form = reactive({ voter_name: props.my_vote?.voter_name || '' });
const voted = ref(!!props.my_vote);
const changing = ref(!props.my_vote);
const chosen = computed(() => props.poll.options.filter((o) => reactions[o.key].reaction));
const liked = computed(() => chosen.value.filter((o) => reactions[o.key].reaction === 'like').map((o) => o.label));
const disliked = computed(() => chosen.value.filter((o) => reactions[o.key].reaction === 'dislike').map((o) => o.label));
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

const idea = reactive({ text: '', voter_name: '', email: '', ok_to_contact: false });
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
	if (idea.ok_to_contact && !idea.email.trim()) {
		ideaErrors.value = { email: ['Add your email so we can contact you, or untick the box.'] };
		return;
	}
	ideaSending.value = true;
	const payload = {
		idea: idea.text.trim(),
		voter_name: (idea.voter_name || form.voter_name).trim() || null,
		email: idea.email.trim() || null,
		ok_to_contact: idea.ok_to_contact,
	};
	if (props.turnstile_enabled) payload['cf-turnstile-response'] = turnstileToken(ideaWidget.value);
	try {
		await axios.post(`/vote/${props.poll.slug}/idea`, payload);
		idea.text = '';
		ideasSent.value++;
	} catch (e) {
		ideaErrors.value = e.response?.data?.errors || {};
		ideaMessage.value = e.response?.status === 422
			? (ideaErrors.value.idea?.[0] || (ideaErrors.value.email ? null : 'Please check your idea and try again.'))
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

// Tapping the selected button again goes back to neutral.
function react(key, reaction) {
	if (!props.open || !changing.value) return;
	reactions[key].reaction = reactions[key].reaction === reaction ? null : reaction;
	errors.value = {};
	message.value = null;
}

async function submit() {
	errors.value = {};
	message.value = null;
	if (!chosen.value.length) {
		message.value = 'Tap "I like this" or "Not for me" on at least one design first.';
		return;
	}
	submitting.value = true;
	const payload = {
		reactions: chosen.value.map((o) => ({
			option_key: o.key,
			reaction: reactions[o.key].reaction,
			comment: reactions[o.key].comment.trim() || null,
		})),
		voter_name: form.voter_name.trim() || null,
	};
	if (props.turnstile_enabled) {
		payload['cf-turnstile-response'] = turnstileToken(voteWidget.value);
	}
	try {
		await axios.post(`/vote/${props.poll.slug}`, payload);
		voted.value = true;
		changing.value = false;
		window.scrollTo({ top: 0, behavior: 'smooth' });
	} catch (e) {
		errors.value = e.response?.data?.errors || {};
		message.value = e.response?.status === 422
			? (Object.values(errors.value)[0]?.[0] || 'Please check what you entered.')
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

				<p v-if="open && changing" class="text-sm text-gray-800 bg-blue-50 border border-blue-200 rounded px-4 py-3">
					Try each design, then tap <strong>👍 I like this</strong> or <strong>👎 Not for me</strong> on any of them
					and tell us why if you like. Skip the ones you have no opinion on — you only need to rate one, and it's
					fine if you don't like any.
				</p>
				<p v-if="!open" class="rounded bg-gray-100 border border-gray-300 text-gray-700 px-4 py-3 text-sm">
					Voting has closed. Here is how people voted ({{ results?.voters || 0 }} {{ results?.voters === 1 ? 'person' : 'people' }}).
				</p>
				<div v-else-if="voted && !changing" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3 text-sm space-y-1">
					<p>Thank you! Your vote is in. Results are shown once voting closes.</p>
					<p v-if="liked.length">You like: <strong>{{ liked.join(', ') }}</strong></p>
					<p v-if="disliked.length">Not for you: <strong>{{ disliked.join(', ') }}</strong></p>
					<button type="button" class="underline" @click="changeVote">Change my vote</button>
				</div>
			</div>

			<!-- ======================= RESULTS (closed) ======================= -->
			<div v-if="!open && ranked.length" class="bg-white shadow rounded-lg p-5 space-y-3">
				<h2 class="text-lg font-semibold">Results</h2>
				<div v-for="row in ranked" :key="row.key" class="space-y-1">
					<div class="flex justify-between text-sm gap-3">
						<span class="font-medium">{{ labelFor(row.key) }}</span>
						<span class="text-gray-600 whitespace-nowrap">👍 {{ row.likes }} · 👎 {{ row.dislikes }}</span>
					</div>
					<div class="h-3 rounded bg-gray-200 overflow-hidden" :title="`${row.percent}% liked it`">
						<div class="h-full bg-green-600" :style="{ width: row.percent + '%' }"></div>
					</div>
				</div>
			</div>

			<!-- ======================= OPTIONS ======================= -->
			<div
				v-for="(option, index) in options" :key="option.key"
				class="bg-white shadow rounded-lg p-5 space-y-3 border-2"
				:class="{ like: 'border-green-500', dislike: 'border-red-400' }[reactions[option.key].reaction] || 'border-transparent'"
			>
				<div class="flex items-start justify-between gap-3">
					<div>
						<h2 class="text-lg font-semibold text-gray-800">{{ index + 1 }}. {{ option.label }}</h2>
						<p class="text-sm text-gray-600">{{ option.description }}</p>
					</div>
					<span v-if="!open && resultFor[option.key]" class="text-sm text-gray-600 whitespace-nowrap">
						👍 {{ resultFor[option.key].likes }} · 👎 {{ resultFor[option.key].dislikes }}
					</span>
					<span v-else-if="voted && !changing && reactions[option.key].reaction" class="text-sm whitespace-nowrap"
						:class="reactions[option.key].reaction === 'like' ? 'text-green-700' : 'text-red-700'">
						{{ reactions[option.key].reaction === 'like' ? '👍 You like this' : '👎 Not for you' }}
					</span>
				</div>
				<p class="text-xs text-gray-500">Try it:</p>
				<component :is="demoFor(option.key)" v-if="demoFor(option.key)" />
				<div v-if="open && changing" class="space-y-2">
					<div class="grid grid-cols-2 gap-2">
						<button type="button" :aria-pressed="reactions[option.key].reaction === 'like'"
							class="py-2 px-3 rounded-md border font-medium"
							:class="reactions[option.key].reaction === 'like' ? 'bg-green-600 border-green-600 text-white' : 'bg-white border-gray-300 text-gray-800 hover:bg-green-50'"
							@click="react(option.key, 'like')">👍 I like this</button>
						<button type="button" :aria-pressed="reactions[option.key].reaction === 'dislike'"
							class="py-2 px-3 rounded-md border font-medium"
							:class="reactions[option.key].reaction === 'dislike' ? 'bg-red-600 border-red-600 text-white' : 'bg-white border-gray-300 text-gray-800 hover:bg-red-50'"
							@click="react(option.key, 'dislike')">👎 Not for me</button>
					</div>
					<label v-if="reactions[option.key].reaction" class="block">
						<span class="text-sm text-gray-700">
							{{ reactions[option.key].reaction === 'like' ? 'What do you like about it? Any ideas to make it better?' : 'What don\'t you like? How would you change it?' }}
							<span class="text-gray-400">(optional)</span>
						</span>
						<textarea v-model="reactions[option.key].comment" rows="2" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base"></textarea>
					</label>
				</div>
			</div>

			<!-- ======================= VOTE FORM ======================= -->
			<!-- v-show, not v-if: Turnstile only draws into elements present when its script loads -->
			<form v-if="open" v-show="changing" class="bg-white shadow rounded-lg p-5 space-y-4" @submit.prevent="submit">
				<div class="text-sm text-gray-800 space-y-1">
					<p v-if="!chosen.length" class="text-gray-500">Nothing rated yet — tap 👍 or 👎 on at least one design above.</p>
					<p v-if="liked.length">👍 You like: <strong>{{ liked.join(', ') }}</strong></p>
					<p v-if="disliked.length">👎 Not for you: <strong>{{ disliked.join(', ') }}</strong></p>
				</div>

				<label class="block">
					<span class="text-sm text-gray-700">Your name <span class="text-gray-400">(optional)</span></span>
					<input v-model="form.voter_name" autocomplete="name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base" />
				</label>

				<div v-if="turnstile_enabled" ref="voteWidget" class="cf-turnstile" :data-sitekey="turnstile_site_key"></div>
				<p v-if="turnstile_unavailable" class="text-sm text-amber-900 bg-amber-50 border border-amber-300 rounded p-3">
					Voting can't accept entries right now because its spam protection isn't set up on this server.
				</p>
				<p v-if="message" class="text-sm text-red-600">{{ message }}</p>

				<button type="submit" :disabled="submitting || turnstile_unavailable"
					class="w-full py-3 bg-blue-600 text-white rounded-md font-semibold text-lg hover:bg-blue-700 disabled:opacity-50">
					{{ submitting ? 'Sending…' : (voted ? 'Save My Changes' : 'Send My Vote') }}
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
				<label class="block">
					<span class="text-sm text-gray-700">Your email ({{ idea.ok_to_contact ? 'needed so we can contact you' : 'optional' }})</span>
					<input v-model="idea.email" type="email" autocomplete="email"
						class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-base" />
					<span v-if="ideaErrors.email" class="text-sm text-red-600">{{ ideaErrors.email[0] }}</span>
				</label>
				<label class="flex items-center gap-2">
					<input v-model="idea.ok_to_contact" type="checkbox" class="rounded border-gray-300" />
					<span class="text-sm text-gray-700">OK to contact me about this idea</span>
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
