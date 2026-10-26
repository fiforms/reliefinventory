<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- MailingLabels.vue

	Mailing labels (MailingLabelController) for a partner, donor, volunteer or
	"other" mailing, on Avery 8160 / 5160 sheets (30 per Letter page) for an
	office printer. Pick the audience, narrow by county or search, uncheck
	anyone to leave out, choose where to start on a partly-used sheet, then
	Print (download the PDF, or send it to a Letter printer in the queue).
	Everyone is checked by default; changing a filter resets that.

	People without a full address (street, city, state, zip) are listed
	separately, unprintable, so they can be fixed on the People page. Partners
	and donors are found by tag (the Partner/Donor role, which the mailing-list
	CSV import on Data Import sets) or by their request/donation history.
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrintMenu from '@/Components/PrintMenu.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';

const AUDIENCES = { partner: 'Partners', donor: 'Donors', volunteer: 'Volunteers', other: 'Other (no tag)' };

export default {
	components: { AuthenticatedLayout, Head, PrintMenu },
	props: {
		breadcrumb: { type: Array },
	},
	data() {
		return {
			audiences: AUDIENCES,
			audience: 'partner',
			countyId: '',
			search: '',
			sort: 'name',
			start: 1,
			perSheet: 30,
			records: [],
			counties: [],
			unchecked: {},
			loading: false,
			error: null,
			searchTimer: null,
			showMissing: false,
			// The filters the list on screen was loaded with — what the PDF is built from.
			loaded: null,
		};
	},
	computed: {
		mailable() {
			return this.records.filter((r) => r.mailable);
		},
		missing() {
			return this.records.filter((r) => !r.mailable);
		},
		selected() {
			return this.mailable.filter((r) => !this.unchecked[r.id]);
		},
		sheets() {
			const cells = this.selected.length + this.start - 1;
			return this.selected.length ? Math.ceil(cells / this.perSheet) : 0;
		},
		allChecked() {
			return this.mailable.length > 0 && this.selected.length === this.mailable.length;
		},
		printParams() {
			if (!this.loaded) return {};
			const params = { ...this.loaded, start: this.start };
			const excluded = this.mailable.filter((r) => this.unchecked[r.id]).map((r) => r.id);
			const ids = this.selected.map((r) => r.id);
			// Whichever list is shorter, so the download URL stays short.
			if (excluded.length) {
				if (ids.length < excluded.length) params.ids = ids;
				else params.exclude = excluded;
			}
			return params;
		},
		downloadHref() {
			const query = new URLSearchParams();
			for (const [key, value] of Object.entries(this.printParams)) {
				if (Array.isArray(value)) value.forEach((v) => query.append(key + '[]', v));
				else query.append(key, value);
			}
			return '/report/mailing-labels.pdf?' + query.toString();
		},
		sheetCells() {
			return Array.from({ length: this.perSheet }, (_, i) => i + 1);
		},
	},
	watch: {
		audience() { this.load(); },
		countyId() { this.load(); },
		sort() { this.load(); },
		search() {
			clearTimeout(this.searchTimer);
			this.searchTimer = setTimeout(() => this.load(), 350);
		},
	},
	methods: {
		async load() {
			this.loading = true;
			this.error = null;
			const params = { audience: this.audience, sort: this.sort };
			if (this.countyId) params.county_id = this.countyId;
			if (this.search.trim()) params.q = this.search.trim();
			try {
				const { data } = await axios.get('/json/mailing-labels', { params });
				this.records = data.records;
				this.counties = data.options.counties;
				this.perSheet = data.options.per_sheet;
				this.unchecked = {};
				this.loaded = params;
			} catch (error) {
				this.error = 'Could not load the mailing list.';
			} finally {
				this.loading = false;
			}
		},
		toggle(record) {
			this.unchecked = { ...this.unchecked, [record.id]: !this.unchecked[record.id] };
		},
		setAll(checked) {
			const unchecked = {};
			if (!checked) this.mailable.forEach((r) => { unchecked[r.id] = true; });
			this.unchecked = unchecked;
		},
		setStart(value) {
			const n = Math.round(Number(value));
			this.start = Number.isFinite(n) ? Math.min(this.perSheet, Math.max(1, n)) : 1;
		},
		addressLine(r) {
			return [r.address, r.city, [r.state, r.zip].filter(Boolean).join(' ')].filter(Boolean).join(', ');
		},
	},
	created() {
		this.load();
	},
};
</script>

<template>
	<Head title="Mailing Labels" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>
		<div class="ml_container">
			<h2 class="ri_datatable_head ml_head">
				Mailing Labels
				<span class="ml_actions">
					<PrintMenu document-key="mailing-labels" media="report" label="Print Labels"
						:disabled="!selected.length || loading" :params="printParams" :download-href="downloadHref" />
				</span>
			</h2>
			<p class="ml_intro">
				Address labels on Avery 8160 / 5160 sheets (30 per page) for an office printer. To add a list of
				names and addresses, import a CSV on <a href="/setup/import">Data Import</a> ("Names &amp; addresses")
				and tag each row donor, partner, volunteer or other.
			</p>

			<div class="ml_audiences" role="radiogroup" aria-label="Mailing list">
				<button v-for="(label, key) in audiences" :key="key" type="button" role="radio"
					:aria-checked="audience === key" :class="['ml_audience', { ml_active: audience === key }]"
					@click="audience = key">{{ label }}</button>
			</div>

			<div class="ml_toolbar">
				<label>County
					<select v-model="countyId" class="ri_forminput">
						<option value="">All</option>
						<option v-for="c in counties" :key="c.id" :value="c.id">{{ c.county }}, {{ c.state }}</option>
					</select>
				</label>
				<label>Order
					<select v-model="sort" class="ri_forminput">
						<option value="name">By name</option>
						<option value="zip">By zip code</option>
					</select>
				</label>
				<input type="text" v-model="search" class="ri_forminput ml_search" placeholder="Search name, organization, city or zip..." />
			</div>

			<p v-if="error" class="ml_error">{{ error }}</p>

			<div class="ml_layout">
				<div class="ml_list">
					<p v-if="loading">Loading...</p>
					<template v-else>
						<p class="ml_summary">
							<strong>{{ selected.length }}</strong> label{{ selected.length === 1 ? '' : 's' }} selected
							<template v-if="selected.length"> &middot; {{ sheets }} sheet{{ sheets === 1 ? '' : 's' }}</template>
							<template v-if="mailable.length">
								&middot;
								<button type="button" class="ml_link" @click="setAll(true)" :disabled="allChecked">Select all</button>
								/
								<button type="button" class="ml_link" @click="setAll(false)" :disabled="!selected.length">none</button>
							</template>
						</p>
						<p v-if="!records.length" class="ml_empty">Nobody in this list{{ countyId || search ? ' for these filters' : '' }}.</p>
						<div v-else-if="mailable.length" class="ml_tablewrap">
							<table class="ri_datatable" border="1">
								<thead>
									<tr>
										<th class="ml_check"><input type="checkbox" :checked="allChecked" @change="setAll($event.target.checked)" aria-label="Select all" /></th>
										<th>Name</th><th>Address</th><th>County</th><th>Tags</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="r in mailable" :key="r.id" :class="{ ml_off: unchecked[r.id] }" @click="toggle(r)">
										<td class="ml_check"><input type="checkbox" :checked="!unchecked[r.id]" @click.stop="toggle(r)" :aria-label="'Print a label for ' + r.name" /></td>
										<td>{{ r.name }}<div v-if="r.organization && r.organization !== r.name" class="ml_org">{{ r.organization }}</div></td>
										<td>{{ addressLine(r) }}</td>
										<td>{{ r.county || '' }}</td>
										<td>{{ r.tags.join(', ') }}</td>
									</tr>
								</tbody>
							</table>
						</div>

						<div v-if="missing.length" class="ml_missing">
							<button type="button" class="ml_link" @click="showMissing = !showMissing">
								{{ showMissing ? '−' : '+' }} {{ missing.length }} without a complete address (no label)
							</button>
							<ul v-if="showMissing">
								<li v-for="r in missing" :key="r.id">
									{{ r.name }}<span v-if="r.organization && r.organization !== r.name"> ({{ r.organization }})</span>
									— {{ addressLine(r) || 'no address' }}
								</li>
							</ul>
							<p v-if="showMissing" class="ml_note">Add the missing street, city, state or zip on the <a href="/setup/people">People</a> page.</p>
						</div>
					</template>
				</div>

				<aside class="ml_side">
					<h3>Start at label</h3>
					<p class="ml_note">Using a partly-used sheet? Click the first empty label (1 is top left, across then down).</p>
					<div class="ml_sheet" aria-label="Avery 8160 sheet">
						<button v-for="n in sheetCells" :key="n" type="button"
							:class="['ml_cell', { ml_used: n < start, ml_start: n === start }]"
							:aria-pressed="n === start" :title="'Start at label ' + n" @click="setStart(n)">{{ n === start ? n : '' }}</button>
					</div>
					<label class="ml_startinput">Label #
						<input type="number" min="1" :max="perSheet" :value="start" class="ri_forminput" @change="setStart($event.target.value)" />
					</label>

					<template v-if="selected.length">
						<h3>First label</h3>
						<div class="ml_preview">
							<div v-for="(line, i) in selected[0].lines" :key="i">{{ line }}</div>
						</div>
					</template>
					<p class="ml_note">
						Print at <strong>Actual size</strong> (100%, not "Fit to page"). Try a plain sheet first and hold it
						against a label sheet to check the alignment.
					</p>
				</aside>
			</div>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.ml_container { max-width: 1200px; margin: 0 auto; padding: 16px; }
.ml_head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.ml_actions { display: flex; align-items: center; gap: 10px; }
.ml_intro { color: #555; margin: 0 0 12px 0; }
.ml_audiences { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.ml_audience { border: 1px solid #c7cbd4; border-radius: 999px; padding: 6px 16px; background: #fff; }
.ml_audience:hover { background: #eef2ff; }
.ml_audience.ml_active { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
.ml_toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.ml_toolbar label { display: flex; align-items: center; gap: 6px; }
.ml_search { min-width: 240px; flex: 1 1 240px; }
.ml_layout { display: grid; grid-template-columns: minmax(0, 1fr) 260px; gap: 20px; align-items: start; }
@media (max-width: 800px) { .ml_layout { grid-template-columns: 1fr; } }
.ml_summary { margin: 0 0 8px 0; }
.ml_link { color: #4f46e5; background: none; border: none; padding: 0; cursor: pointer; }
.ml_link:hover:not(:disabled) { text-decoration: underline; }
.ml_link:disabled { color: #999; cursor: default; }
.ml_tablewrap { overflow-x: auto; }
.ml_tablewrap tbody tr { cursor: pointer; }
.ml_tablewrap tbody tr:hover { background-color: #eef2ff; }
.ml_check { width: 2.2em; text-align: center; }
.ml_off td:not(.ml_check) { color: #999; text-decoration: line-through; }
.ml_org { color: #666; font-size: 0.85rem; }
.ml_missing { margin-top: 14px; }
.ml_missing ul { margin: 6px 0 0 1.2em; list-style: disc; color: #555; }
.ml_empty { text-align: center; color: #777; padding: 1em; }
.ml_error { color: #b91c1c; margin: 6px 0; }
.ml_side { border: 1px solid #ddd; border-radius: 6px; padding: 12px; background: #fff; }
h3 { font-weight: bold; margin: 0 0 6px 0; }
.ml_side h3:not(:first-child) { margin-top: 14px; }
.ml_note { color: #666; font-size: 0.85rem; margin: 6px 0; }
/* A Letter sheet in miniature: 3 x 10 labels, 8.5 x 11 proportions. */
.ml_sheet { display: grid; grid-template-columns: repeat(3, 1fr); gap: 3px 6px; padding: 10px 4px; width: 170px;
	aspect-ratio: 8.5 / 11; border: 1px solid #bbb; background: #fafafa; margin: 8px 0; }
.ml_cell { border: 1px solid #c7cbd4; border-radius: 3px; background: #fff; font-size: 0.65rem; line-height: 1; padding: 0; }
.ml_cell:hover { border-color: #4f46e5; }
.ml_cell.ml_used { background: #d1d5db; border-color: #d1d5db; }
.ml_cell.ml_start { background: #1e3a8a; border-color: #1e3a8a; color: #fff; font-weight: bold; }
.ml_startinput { display: flex; align-items: center; gap: 6px; }
.ml_startinput input { width: 5em; }
.ml_preview { border: 1px dashed #999; border-radius: 4px; padding: 8px 10px; font-family: Arial, 'RI Emoji', sans-serif; font-size: 0.85rem; line-height: 1.25; }
</style>
