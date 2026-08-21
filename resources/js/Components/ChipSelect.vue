<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- ChipSelect.vue

	One-tap group of chip buttons — all options visible at once (no
	dropdown-then-select two-tap flow). Sized for touch/iPad use (~44px
	minimum tap height, per WCAG/Apple HIG), unlike the existing bespoke chip
	patterns in Users.vue (.role-chip) and OrderEntry.vue (.oe_daychip),
	which are desktop-sized. Those two are left as-is for now; this is meant
	to be the shared version going forward.

	Single-select by default (modelValue is a plain value). Pass
	`multiple` to make modelValue an array instead — clicking a chip toggles
	its membership rather than replacing the whole selection.
-->

<script setup>
const props = defineProps({
	modelValue: {
		default: null,
	},
	options: {
		// [{ value, label }]
		type: Array,
		required: true,
	},
	enabled: {
		type: Boolean,
		default: true,
	},
	multiple: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['update:modelValue']);

function isActive(value) {
	return props.multiple ? (props.modelValue || []).includes(value) : props.modelValue === value;
}

function select(value) {
	if (!props.multiple) {
		emit('update:modelValue', value);
		return;
	}
	const current = props.modelValue || [];
	const next = current.includes(value) ? current.filter((v) => v !== value) : [...current, value];
	emit('update:modelValue', next);
}
</script>

<template>
	<div class="chipselect_group ri_formcontrol">
		<button
			v-for="option in options"
			:key="option.value"
			type="button"
			class="chipselect_chip"
			:class="{ chipselect_chip_active: isActive(option.value) }"
			:disabled="!enabled"
			@click="select(option.value)"
		>
			{{ option.label }}
		</button>
	</div>
</template>

<style scoped>
.chipselect_group {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5em;
}
.chipselect_chip {
	min-height: 44px;
	padding: 0.5em 1.1em;
	border: 1px solid #ccc;
	border-radius: 999px;
	background: white;
	cursor: pointer;
	font-size: 1rem;
}
.chipselect_chip:disabled {
	cursor: default;
	opacity: 0.6;
}
.chipselect_chip:hover:not(:disabled) {
	background: #f3f4f6;
}
.chipselect_chip_active {
	background: #007bff;
	border-color: #007bff;
	color: white;
}
.chipselect_chip_active:hover:not(:disabled) {
	background: #0056b3;
	border-color: #0056b3;
}
</style>
