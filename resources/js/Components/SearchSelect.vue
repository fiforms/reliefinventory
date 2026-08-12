<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- SearchSelect.vue

	Searchable select control — the successor to ComboBox.vue.

	Improvements over ComboBox:
	  - Standard v-model (the selected record's id), so it works like any input:
	        <SearchSelect v-model="record.item_id" optionsource="/json/items" />
	    The full selected object is emitted separately via @selected.
	  - Option lists fetched from `optionsource` are cached module-wide, so ten
	    controls on a page (or one control re-mounted per line) cost one request.
	    Call refresh() (exposed) or invalidateOptions(url) after creating a new
	    option server-side.
	  - Keyboard support: ArrowUp/ArrowDown to highlight, Enter to select,
	    Escape to close. A scanned barcode (keyboard-wedge scanners type the
	    code then send Enter) selects an exact match instantly.
	  - `searchfields` lets one control match on several fields (e.g. search
	    items by description OR upc).
	  - Optional "Add new..." row (allowcreate) emits @create with the typed
	    text so the page can open its own creation flow.

	Props:
	  modelValue   - selected id (v-model)
	  options      - static array of option objects (alternative to optionsource)
	  optionsource - JSON url returning { records: [...] }
	  display      - field shown in the input and dropdown (default "name")
	  searchfields - array of fields to match while typing (default [display])
	  placeholder, enabled, allowcreate, autofocus

	Events: update:modelValue, selected(object|null), create(searchText)
-->

<script>
import axios from "axios";

// Module-level cache: one fetch per option source URL, shared by every
// SearchSelect on every page until invalidated.
const optionCache = new Map();

export function invalidateOptions(url) {
	optionCache.delete(url);
}

function loadOptions(url, force = false) {
	if (force) optionCache.delete(url);
	if (!optionCache.has(url)) {
		const promise = axios
			.get(url)
			.then((response) => response.data.records)
			.catch((error) => {
				optionCache.delete(url); // don't cache failures
				throw error;
			});
		optionCache.set(url, promise);
	}
	return optionCache.get(url);
}

export default {
	name: "SearchSelect",
	props: {
		modelValue: { type: [String, Number], default: null },
		options: { type: Array, default: null },
		optionsource: { type: String, default: null },
		display: { type: String, default: "name" },
		searchfields: { type: Array, default: null },
		placeholder: { type: String, default: "Search..." },
		enabled: { type: Boolean, default: true },
		allowcreate: { type: Boolean, default: false },
		autofocus: { type: Boolean, default: false },
	},
	emits: ["update:modelValue", "selected", "create"],
	data() {
		return {
			search: "",
			isOpen: false,
			highlighted: 0,
			optionlist: [],
			loadError: false,
		};
	},
	computed: {
		fields() {
			return this.searchfields || [this.display];
		},
		selectedOption() {
			return this.optionlist.find((o) => o.id === this.modelValue) || null;
		},
		filteredOptions() {
			const text = this.search.trim().toLowerCase();
			// When the input still shows the current selection, list everything
			if (!text || (this.selectedOption && this.search === this.selectedOption[this.display])) {
				return this.optionlist;
			}
			return this.optionlist.filter((option) =>
				this.fields.some(
					(field) =>
						option[field] &&
						String(option[field]).toLowerCase().includes(text)
				)
			);
		},
		exactMatch() {
			const text = this.search.trim().toLowerCase();
			if (!text) return null;
			return (
				this.optionlist.find((option) =>
					this.fields.some(
						(field) =>
							option[field] &&
							String(option[field]).toLowerCase() === text
					)
				) || null
			);
		},
		showCreateRow() {
			return this.allowcreate && this.search.trim() !== "" && !this.exactMatch;
		},
	},
	watch: {
		modelValue: {
			immediate: true,
			handler() {
				this.syncDisplay();
			},
		},
		options: {
			immediate: true,
			handler(value) {
				if (value) {
					this.optionlist = value;
					this.syncDisplay();
				}
			},
		},
	},
	methods: {
		async fetchOptions(force = false) {
			if (this.options) return;
			try {
				this.optionlist = await loadOptions(this.optionsource, force);
				this.loadError = false;
				this.syncDisplay();
			} catch (error) {
				this.loadError = true;
			}
		},
		// Re-fetch options (e.g. after creating a new record server-side),
		// optionally selecting a newly created id.
		async refresh(selectId = null) {
			await this.fetchOptions(true);
			if (selectId !== null) {
				const option = this.optionlist.find((o) => o.id === selectId);
				if (option) this.selectOption(option);
			}
		},
		syncDisplay() {
			if (this.isOpen) return; // don't clobber active typing
			this.search = this.selectedOption ? String(this.selectedOption[this.display] ?? "") : "";
		},
		open() {
			if (!this.enabled) return;
			this.isOpen = true;
			this.highlighted = 0;
			this.$refs.input?.select();
		},
		close() {
			this.isOpen = false;
			this.syncDisplay();
		},
		onInput() {
			this.isOpen = true;
			this.highlighted = 0;
		},
		selectOption(option) {
			this.isOpen = false;
			this.search = String(option[this.display] ?? "");
			this.$emit("update:modelValue", option.id);
			this.$emit("selected", option);
		},
		clearSelection() {
			this.search = "";
			this.$emit("update:modelValue", null);
			this.$emit("selected", null);
		},
		emitCreate() {
			this.isOpen = false;
			this.$emit("create", this.search.trim());
		},
		onEnter() {
			// A scanned barcode arrives as text + Enter: exact match wins first
			if (this.exactMatch) {
				this.selectOption(this.exactMatch);
				return;
			}
			const visible = this.filteredOptions;
			if (this.isOpen && visible.length > 0 && this.highlighted < visible.length) {
				this.selectOption(visible[this.highlighted]);
			} else if (this.showCreateRow) {
				this.emitCreate();
			}
		},
		onArrow(delta) {
			if (!this.isOpen) {
				this.isOpen = true;
				return;
			}
			const max = this.filteredOptions.length + (this.showCreateRow ? 1 : 0);
			if (max === 0) return;
			this.highlighted = (this.highlighted + delta + max) % max;
		},
		onBlur() {
			// Delay so a mousedown on an option row lands before the list closes
			setTimeout(() => this.close(), 150);
		},
		focus() {
			this.$refs.input?.focus();
		},
	},
	created() {
		this.fetchOptions();
	},
	mounted() {
		if (this.autofocus) this.focus();
	},
};
</script>

<template>
	<div class="ri_formcontrol ss_container">
		<template v-if="enabled">
			<input
				ref="input"
				type="text"
				v-model="search"
				@focus="open"
				@blur="onBlur"
				@input="onInput"
				@keydown.enter.prevent="onEnter"
				@keydown.down.prevent="onArrow(1)"
				@keydown.up.prevent="onArrow(-1)"
				@keydown.esc.prevent="close"
				class="ri_forminput ss_input"
				:placeholder="placeholder"
				autocomplete="off"
			/>
			<ul v-if="isOpen && (filteredOptions.length > 0 || showCreateRow)" class="ss_dropdown">
				<li
					v-for="(option, index) in filteredOptions"
					:key="option.id"
					@mousedown.prevent="selectOption(option)"
					class="ss_option"
					:class="{ ss_highlighted: index === highlighted }"
				>
					{{ option[display] }}
				</li>
				<li
					v-if="showCreateRow"
					@mousedown.prevent="emitCreate"
					class="ss_option ss_create"
					:class="{ ss_highlighted: highlighted === filteredOptions.length }"
				>
					+ Add "{{ search.trim() }}"...
				</li>
			</ul>
			<div v-if="loadError" class="ss_error">
				Could not load options.
				<a href="#" @click.prevent="fetchOptions(true)">Retry</a>
			</div>
		</template>
		<span v-else class="ir_disabled_input">{{ selectedOption ? selectedOption[display] : "" }}</span>
	</div>
</template>

<style scoped>
.ss_container {
	position: relative;
}
.ss_input {
	width: 100%;
}
.ss_dropdown {
	position: absolute;
	z-index: 30;
	left: 0;
	right: 0;
	max-height: 16rem;
	overflow-y: auto;
	margin: 0;
	padding: 0;
	list-style: none;
	background: #fff;
	border: 1px solid #ccc;
	border-radius: 0 0 6px 6px;
	box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
}
.ss_option {
	padding: 0.45em 0.6em;
	cursor: pointer;
}
.ss_option:hover,
.ss_highlighted {
	background-color: #e0e7ff;
}
.ss_create {
	font-style: italic;
	color: #4338ca;
	border-top: 1px solid #eee;
}
.ss_error {
	color: #b91c1c;
	font-size: 0.85em;
	padding-top: 0.2em;
}
</style>
