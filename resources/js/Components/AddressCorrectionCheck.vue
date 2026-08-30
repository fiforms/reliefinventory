<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- AddressCorrectionCheck.vue

	Address-lookup result, shown right after the Address field. The
	"checking" state renders as a plain inline line (nothing to interact
	with yet), but once a suggestion is ready to review, it's shown in a
	real modal (built on the shared Modal.vue) — this used to be inline
	throughout, but that was a workaround for a modal that had been
	accidentally placed in the wrong template branch on OrderEntry.vue
	(fixed 2026-08-30) and was never actually a Modal.vue/dialog problem,
	so there's no reason not to use one now that the real bug is fixed.

	Three states, driven by the `status` prop:
	  - 'checking': lookup in flight — just a "Looking up…" line. The
	    caller should disable City/State while this is showing (the actual
	    fields live in the caller, not here) — typing into a field this
	    component doesn't own can't race with the response.
	  - 'ready', casingOnly=true: same address, different capitalization
	    only — a single "Accept Address" action (there's no real second
	    option worth offering when the only change is formatting).
	  - 'ready', casingOnly=false: a genuine content difference — both the
	    as-typed and geocod.io's version are shown as one-click choices.
	'idle' (or any other value) renders nothing.

	Props: status ('idle'|'checking'|'ready'), casingOnly, entered, suggested
	Events: accept (use geocod.io's version), keep (use what was typed)
-->

<script setup>
import Modal from '@/Components/Modal.vue';

defineProps({
	status: { type: String, default: 'idle' },
	casingOnly: { type: Boolean, default: false },
	entered: { type: Object, default: () => ({}) },
	suggested: { type: Object, default: () => ({}) },
});

defineEmits(['accept', 'keep']);
</script>

<template>
	<p v-if="status === 'checking'" class="acc_checking">Looking up address…</p>
	<Modal :show="status === 'ready'" @close="$emit('keep')">
		<div class="acc_body">
			<h2 class="acc_title">Confirm Address</h2>
			<template v-if="casingOnly">
				<p class="acc_line">Corrected: <strong>{{ suggested.address }}</strong></p>
				<div class="acc_actions">
					<button type="button" class="ri_defaultbutton" @click="$emit('accept')">Accept Address</button>
					<button type="button" class="ri_formbutton" @click="$emit('keep')">Keep As Typed</button>
				</div>
			</template>
			<template v-else>
				<p class="acc_line">Geocodio found a different address — which is right?</p>
				<div class="acc_actions acc_actions_stacked">
					<button type="button" class="ri_defaultbutton" @click="$emit('accept')">
						Use: {{ suggested.address }}, {{ suggested.city }}, {{ suggested.state }} {{ suggested.zip }}
					</button>
					<button type="button" class="ri_formbutton" @click="$emit('keep')">
						Keep: {{ entered.address }}
					</button>
				</div>
			</template>
		</div>
	</Modal>
</template>

<style scoped>
.acc_checking {
	color: #6b7280;
	font-size: 0.85rem;
	font-style: italic;
	margin: 0.3em 0;
}
.acc_body {
	padding: 1.5em;
}
.acc_title {
	font-size: 1.25rem;
	font-weight: 700;
	color: #111827;
	margin: 0 0 0.8em;
}
.acc_line {
	margin: 0 0 0.8em;
	font-size: 0.95rem;
	color: #374151;
}
.acc_actions {
	display: flex;
	gap: 0.6em;
	flex-wrap: wrap;
}
.acc_actions_stacked {
	flex-direction: column;
	align-items: flex-start;
}
</style>
