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
	    text so the page can open its own creation flow. When nothing already
	    matches and the typed text is a likely typo of an existing option
	    (edit distance <= 2), a "Did you mean..." suggestion appears above the
	    create row so allowcreate doesn't invite near-duplicate records.
	  - A selected value shows a "x" clear button so a wrong pick can be
	    backed out of without having to type over it and lose the selection.

	Props:
	  modelValue   - selected id (v-model)
	  options      - static array of option objects (alternative to optionsource)
	  optionsource - JSON url returning { records: [...] }
	  display      - field shown in the input and dropdown (default "name")
	  secondary    - optional second field shown alongside `display` in each
	                 dropdown row only (e.g. display="display_number"
	                 secondary="name" for an item-number-first search that
	                 still shows the item's name) — widens the dropdown so
	                 both fit. The input itself always shows just `display`.
	  searchfields - array of fields to match while typing (default [display])
	  openOnFocus  - open the full dropdown as soon as the field is focused
	                 (default true — click-to-browse). Set false for a
	                 rapid/scan-driven field that should just wait for input
	                 and then open filtered to what's been typed, rather than
	                 popping the whole list open with the first row
	                 pre-highlighted (which risks an accidental Enter picking
	                 the wrong item before anything's been typed).
	  filter       - optional (option) => boolean predicate narrowing which
	                 options are browsable/searchable (e.g. counties scoped
	                 to whichever state is entered elsewhere on the record).
	                 The already-selected option is still resolved/displayed
	                 even if it wouldn't pass the filter.
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

// Small edit-distance check used to nudge "did you mean...?" for a typo'd
// name before letting allowcreate spawn a near-duplicate record.
function levenshtein(a, b) {
	const rows = a.length + 1;
	const cols = b.length + 1;
	const d = Array.from({ length: rows }, (_, i) => [i, ...Array(cols - 1).fill(0)]);
	for (let j = 1; j < cols; j++) d[0][j] = j;
	for (let i = 1; i < rows; i++) {
		for (let j = 1; j < cols; j++) {
			const cost = a[i - 1] === b[j - 1] ? 0 : 1;
			d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
		}
	}
	return d[rows - 1][cols - 1];
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
		secondary: { type: String, default: null },
		searchfields: { type: Array, default: null },
		placeholder: { type: String, default: "Search..." },
		enabled: { type: Boolean, default: true },
		allowcreate: { type: Boolean, default: false },
		autofocus: { type: Boolean, default: false },
		openOnFocus: { type: Boolean, default: true },
		// Optional (option) => boolean predicate narrowing which options are
		// browsable/searchable — e.g. counties scoped to whichever state is
		// currently entered elsewhere on the same record. The already-
		// selected option is always still resolved/displayed even if it
		// wouldn't pass the filter, so a filter never silently blanks an
		// existing selection (same contract as ComboBox's `filter` prop).
		filter: { type: Function, default: null },
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
		baseOptions() {
			return this.filter ? this.optionlist.filter(this.filter) : this.optionlist;
		},
		selectedOption() {
			return this.optionlist.find((o) => o.id === this.modelValue) || null;
		},
		filteredOptions() {
			const text = this.search.trim().toLowerCase();
			// When the input still shows the current selection, list everything
			if (!text || (this.selectedOption && this.search === this.selectedOption[this.display])) {
				return this.baseOptions;
			}
			return this.baseOptions.filter((option) =>
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
				this.baseOptions.find((option) =>
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
		// Typo'd near-misses ("Jon Smith" vs "John Smith") that substring
		// search (filteredOptions) wouldn't catch — only worth surfacing
		// when we're about to offer "+ Add ..." and nothing already matched.
		closeMatches() {
			if (!this.allowcreate || this.filteredOptions.length > 0) return [];
			const text = this.search.trim().toLowerCase();
			if (!text) return [];
			const maxDistance = text.length <= 4 ? 1 : 2;
			return this.baseOptions
				.map((option) => ({
					option,
					distance: levenshtein(text, String(option[this.display] ?? "").toLowerCase()),
				}))
				.filter((entry) => entry.distance > 0 && entry.distance <= maxDistance)
				.sort((a, b) => a.distance - b.distance)
				.slice(0, 3)
				.map((entry) => entry.option);
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
		// Lets a control's option source change at runtime (e.g. a contact
		// picker scoped to whichever org is currently selected elsewhere on
		// the page) — refetch under the new URL instead of keeping stale
		// options from the old one.
		optionsource(newVal, oldVal) {
			if (newVal !== oldVal) this.fetchOptions();
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
		onFocus() {
			if (this.openOnFocus) this.open();
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
			this.focus();
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
				@focus="onFocus"
				@blur="onBlur"
				@input="onInput"
				@keydown.enter.prevent="onEnter"
				@keydown.down.prevent="onArrow(1)"
				@keydown.up.prevent="onArrow(-1)"
				@keydown.esc.prevent="close"
				class="ri_forminput ss_input"
				:class="{ ss_input_clearable: selectedOption }"
				:placeholder="placeholder"
				autocomplete="off"
			/>
			<button
				v-if="selectedOption"
				type="button"
				class="ss_clear"
				aria-label="Clear selection"
				@mousedown.prevent="clearSelection"
			>&times;</button>
			<ul v-if="isOpen && (filteredOptions.length > 0 || showCreateRow)"
				class="ss_dropdown" :class="{ ss_dropdown_wide: secondary }">
				<li
					v-for="(option, index) in filteredOptions"
					:key="option.id"
					@mousedown.prevent="selectOption(option)"
					class="ss_option"
					:class="{ ss_highlighted: index === highlighted }"
				>
					<span class="ss_primary">{{ option[display] }}</span>
					<span v-if="secondary" class="ss_secondary">{{ option[secondary] }}</span>
				</li>
				<li v-if="closeMatches.length" class="ss_didyoumean_label">Did you mean:</li>
				<li
					v-for="option in closeMatches"
					:key="'dym-' + option.id"
					@mousedown.prevent="selectOption(option)"
					class="ss_option ss_suggestion"
				>
					<span class="ss_primary">{{ option[display] }}</span>
					<span v-if="secondary" class="ss_secondary">{{ option[secondary] }}</span>
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
.ss_input_clearable {
	padding-right: 1.8em;
}
.ss_clear {
	position: absolute;
	right: 0.3em;
	top: 50%;
	transform: translateY(-50%);
	border: none;
	background: none;
	cursor: pointer;
	font-size: 1.1em;
	line-height: 1;
	color: #888;
	padding: 0.2em 0.3em;
}
.ss_clear:hover {
	color: #333;
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
.ss_dropdown_wide {
	width: max-content;
	min-width: 100%;
	max-width: 26rem;
}
.ss_option {
	padding: 0.45em 0.6em;
	cursor: pointer;
	display: flex;
	gap: 0.6em;
	align-items: baseline;
	white-space: nowrap;
}
.ss_secondary {
	color: #666;
	font-size: 0.9em;
	overflow: hidden;
	text-overflow: ellipsis;
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
.ss_didyoumean_label {
	padding: 0.3em 0.6em 0;
	font-size: 0.8em;
	color: #888;
	white-space: nowrap;
}
.ss_suggestion .ss_primary {
	font-weight: 600;
}
.ss_error {
	color: #b91c1c;
	font-size: 0.85em;
	padding-top: 0.2em;
}
</style>
