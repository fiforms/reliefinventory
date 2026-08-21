<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- TileSelect.vue

	Small square icon+label tiles, laid out in a fixed grid so the same
	option always lands in the same spot — unlike ChipSelect's flex-wrap
	pills, which reflow order/position as chips are added/removed. That
	fixed positioning is the point: for a option set a user picks from
	repeatedly (e.g. Receiving's Truck Size), a stable spatial layout lets
	them build muscle memory and select by position without reading the
	label. Visually modeled on the Dashboard.vue menu tiles, but smaller
	and generic (emoji/text icon instead of an uploaded image, and driven
	by a plain options prop instead of a /json/menu-data fetch).

	Single-select by default (modelValue is a plain value). Pass
	`multiple` to make modelValue an array instead — clicking a tile
	toggles its membership rather than replacing the whole selection.

	Image icons render at full size with no added border/background —
	they're already complete tile graphics (rounded-square badge baked
	in). The label sits below the image, and the whole button (image +
	label + surrounding padding) is one tap target, so tapping either the
	picture or the word selects the option; there's no dead space between
	them where a tap wouldn't register. Since the image can't be
	recolored to show selection, the active state is shown by a ring
	around the tile plus a highlighted label.
-->

<script setup>
const props = defineProps({
	modelValue: {
		default: null,
	},
	options: {
		// [{ value, label, icon }] — icon is either an emoji/short text
		// string, or an image path (anything starting with "/" or "http",
		// matching the style of the Dashboard menu tile graphics under
		// public/img/).
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

function isImageIcon(icon) {
	return typeof icon === 'string' && (icon.startsWith('/') || icon.startsWith('http'));
}

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
	<div class="tileselect_group ri_formcontrol">
		<button
			v-for="option in options"
			:key="option.value"
			type="button"
			class="tileselect_tile"
			:class="{ tileselect_tile_active: isActive(option.value) }"
			:disabled="!enabled"
			@click="select(option.value)"
		>
			<img v-if="isImageIcon(option.icon)" :src="option.icon" :alt="option.label" class="tileselect_iconimg" />
			<span v-else class="tileselect_icon">{{ option.icon }}</span>
			<span class="tileselect_label">{{ option.label }}</span>
		</button>
	</div>
</template>

<style scoped>
.tileselect_group {
	display: grid;
	grid-template-columns: repeat(auto-fill, 84px);
	gap: 0.5em;
}
.tileselect_tile {
	width: 84px;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: flex-start;
	gap: 0.35em;
	border: none;
	border-radius: 10px;
	background: transparent;
	cursor: pointer;
	padding: 0.4em;
	text-align: center;
}
.tileselect_tile:disabled {
	cursor: default;
	opacity: 0.6;
}
.tileselect_tile:hover:not(:disabled) {
	background: #f3f4f6;
}
.tileselect_icon {
	font-size: 2.2rem;
	line-height: 1;
}
.tileselect_iconimg {
	width: 64px;
	height: 64px;
	object-fit: contain;
	border-radius: 12px;
}
.tileselect_label {
	font-size: 0.75rem;
	font-weight: bold;
	color: #333;
	line-height: 1.15;
}
.tileselect_tile_active {
	background: #007bff;
}
.tileselect_tile_active .tileselect_label {
	color: white;
}
.tileselect_tile_active:hover:not(:disabled) {
	background: #0056b3;
}
</style>
