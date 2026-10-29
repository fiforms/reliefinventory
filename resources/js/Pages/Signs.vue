<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Signs.vue

	Warehouse signs on Letter paper (SignController), from Setup > Signs.
	Pick a ready-made sign (App\Support\SignTemplates) or start blank, change any of
	it — title, smaller text, safety header, portrait/landscape, arrows at the edges
	and corners — and Print (download the PDF, or send it to a Letter printer).

	The preview is the sign's own print view (GET /setup/signs/preview, the same Blade view
	the PDF renders, laid out by App\Support\SignLayout) in a scaled-down iframe, so it
	shows exactly where the text wraps and how big it prints.
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrintMenu from '@/Components/PrintMenu.vue';
import { Head } from '@inertiajs/vue3';

// The arrow picker, laid out like the page it points around; null is the text in the middle.
const ARROW_GRID = [
	['up-left', '↖'], ['up', '↑'], ['up-right', '↗'],
	['left', '←'], null, ['right', '→'],
	['down-left', '↙'], ['down', '↓'], ['down-right', '↘'],
];

// Letter at 96 CSS px per inch, the size the preview page renders at before scaling.
const PAGE_PX = { portrait: [816, 1056], landscape: [1056, 816] };
const PREVIEW_WIDTH = { portrait: 330, landscape: 420 };

export default {
	components: { AuthenticatedLayout, Head, PrintMenu },
	props: {
		breadcrumb: { type: Array },
		groups: { type: Object, required: true },
		headers: { type: Object, required: true },
	},
	data() {
		return {
			arrowGrid: ARROW_GRID,
			templateKey: null,
			title: '',
			subtitle: '',
			header: '',
			orientation: 'landscape',
			arrows: [],
			previewQuery: '',
			previewTimer: null,
		};
	},
	computed: {
		params() {
			const params = { title: this.title.trim(), orientation: this.orientation };
			if (this.subtitle.trim()) params.subtitle = this.subtitle.trim();
			if (this.header) params.header = this.header;
			if (this.arrows.length) params.arrows = [...this.arrows];
			return params;
		},
		query() {
			const query = new URLSearchParams();
			for (const [key, value] of Object.entries(this.params)) {
				if (Array.isArray(value)) value.forEach((v) => query.append(key + '[]', v));
				else query.append(key, value);
			}
			return query.toString();
		},
		ready() {
			return this.params.title !== '';
		},
		previewBox() {
			const [w, h] = PAGE_PX[this.orientation];
			const scale = PREVIEW_WIDTH[this.orientation] / w;
			return {
				outer: { width: w * scale + 'px', height: h * scale + 'px' },
				frame: { width: w + 'px', height: h + 'px', transform: `scale(${scale})` },
			};
		},
	},
	watch: {
		// Typing shouldn't reload the preview on every key.
		query: {
			immediate: true,
			handler(query) {
				clearTimeout(this.previewTimer);
				this.previewTimer = setTimeout(() => { this.previewQuery = this.ready ? query : ''; }, this.previewQuery ? 400 : 0);
			},
		},
	},
	methods: {
		useTemplate(sign) {
			this.templateKey = sign.key;
			this.title = sign.title;
			this.subtitle = sign.subtitle || '';
			this.header = sign.header || '';
			this.orientation = sign.orientation;
			this.arrows = [...sign.arrows];
		},
		startBlank() {
			this.templateKey = null;
			this.title = '';
			this.subtitle = '';
			this.header = '';
			this.arrows = [];
			this.$nextTick(() => this.$refs.title?.focus());
		},
		toggleArrow(direction) {
			this.arrows = this.arrows.includes(direction)
				? this.arrows.filter((a) => a !== direction)
				: [...this.arrows, direction];
		},
	},
};
</script>

<template>
	<Head title="Signs" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>
		<div class="sg_container">
			<h2 class="ri_datatable_head sg_head">
				Signs
				<PrintMenu document-key="sign" media="report" label="Print Sign" :disabled="!ready"
					:params="params" :download-href="'/report/sign.pdf?' + query" />
			</h2>
			<p class="sg_intro">
				Pick a ready-made sign or start a blank one, change anything you like, then print it on plain
				8.5 x 11 paper. The text is sized to fill the sign.
			</p>

			<div class="sg_layout">
				<section class="sg_templates" aria-label="Ready-made signs">
					<button type="button" :class="['sg_chip', 'sg_blank', { sg_active: templateKey === null && !title }]" @click="startBlank">+ Blank sign</button>
					<div v-for="(signs, group) in groups" :key="group" class="sg_group">
						<h3>{{ group }}</h3>
						<div class="sg_chips">
							<button v-for="sign in signs" :key="sign.key" type="button"
								:class="['sg_chip', { sg_active: templateKey === sign.key }]" @click="useTemplate(sign)">
								<span v-if="sign.header" :class="['sg_dot', 'sg_' + sign.header]" :title="headers[sign.header]"></span>{{ sign.title }}
							</button>
						</div>
					</div>
				</section>

				<section class="sg_editor" aria-label="Sign">
					<label class="sg_field">Title
						<textarea ref="title" v-model="title" rows="2" maxlength="120" class="ri_forminput" placeholder="e.g. SORTING"></textarea>
					</label>
					<label class="sg_field">Smaller text <span class="sg_optional">(optional)</span>
						<textarea v-model="subtitle" rows="2" maxlength="400" class="ri_forminput"></textarea>
					</label>
					<p class="sg_note">Press Enter to start a new line where you want one; otherwise lines break on their own.</p>

					<div class="sg_row">
						<label class="sg_field">Header
							<select v-model="header" class="ri_forminput">
								<option value="">None</option>
								<option v-for="(label, key) in headers" :key="key" :value="key">{{ label }}</option>
							</select>
						</label>
						<div class="sg_field">
							<span>Paper</span>
							<div class="sg_toggle" role="radiogroup" aria-label="Paper orientation">
								<button v-for="o in ['landscape', 'portrait']" :key="o" type="button" role="radio"
									:aria-checked="orientation === o" :class="{ sg_active: orientation === o }"
									@click="orientation = o">{{ o === 'landscape' ? 'Wide' : 'Tall' }}</button>
							</div>
						</div>
					</div>

					<div class="sg_field">
						<span>Arrows</span>
						<div :class="['sg_arrows', 'sg_arrows_' + orientation]">
							<template v-for="(cell, i) in arrowGrid" :key="i">
								<label v-if="cell" :class="['sg_arrow', { sg_active: arrows.includes(cell[0]) }]" :title="cell[0].replace('-', ' ')">
									<input type="checkbox" :checked="arrows.includes(cell[0])" @change="toggleArrow(cell[0])" :aria-label="'Arrow ' + cell[0].replace('-', ' ')" />
									<span aria-hidden="true">{{ cell[1] }}</span>
								</label>
								<span v-else class="sg_arrow_text" aria-hidden="true">Aa</span>
							</template>
						</div>
					</div>
				</section>

				<section class="sg_preview_col" aria-label="Preview">
					<div class="sg_preview" :style="previewBox.outer">
						<iframe v-if="previewQuery" :src="'/setup/signs/preview?' + previewQuery" :style="previewBox.frame"
							title="Sign preview" tabindex="-1"></iframe>
						<p v-else class="sg_empty">Type a title to see the sign.</p>
					</div>
					<p class="sg_note">Print at <strong>Actual size</strong> (100%). Color headers print gray on a black-and-white printer.</p>
				</section>
			</div>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.sg_container { max-width: 1280px; margin: 0 auto; padding: 16px; }
.sg_head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.sg_intro { color: #555; margin: 0 0 12px 0; }
.sg_layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; gap: 20px; align-items: start; }
@media (max-width: 1100px) { .sg_layout { grid-template-columns: minmax(0, 1fr) auto; } .sg_templates { grid-column: 1 / -1; } }
@media (max-width: 700px) { .sg_layout { grid-template-columns: minmax(0, 1fr); } }
h3 { font-weight: bold; margin: 12px 0 6px 0; }
.sg_templates { max-height: 75vh; overflow-y: auto; padding-right: 4px; }
@media (max-width: 1100px) { .sg_templates { max-height: none; } }
.sg_chips { display: flex; flex-wrap: wrap; gap: 6px; }
.sg_chip { border: 1px solid #c7cbd4; border-radius: 999px; padding: 4px 12px; background: #fff; font-size: 0.85rem; text-align: left; }
.sg_chip:hover { background: #eef2ff; }
.sg_chip.sg_active { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
.sg_blank { font-weight: bold; }
.sg_dot { display: inline-block; width: 0.7em; height: 0.7em; border-radius: 2px; margin-right: 6px; vertical-align: -1px; border: 1px solid rgba(0, 0, 0, 0.25); }
.sg_danger { background: #C8102E; }
.sg_warning { background: #F26722; }
.sg_caution { background: #FFD200; }
.sg_notice { background: #0059A8; }
.sg_safety { background: #008751; }
.sg_editor { border: 1px solid #ddd; border-radius: 6px; padding: 12px; background: #fff; }
.sg_field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; font-weight: 600; }
.sg_field textarea, .sg_field select { font-weight: normal; width: 100%; }
.sg_optional { font-weight: normal; color: #777; }
.sg_row { display: flex; gap: 16px; flex-wrap: wrap; }
.sg_row > .sg_field { flex: 1 1 140px; }
.sg_note { color: #666; font-size: 0.85rem; margin: -4px 0 10px 0; }
.sg_toggle { display: flex; }
.sg_toggle button { border: 1px solid #c7cbd4; padding: 6px 14px; background: #fff; font-weight: normal; }
.sg_toggle button:first-child { border-radius: 6px 0 0 6px; }
.sg_toggle button:last-child { border-radius: 0 6px 6px 0; border-left: none; }
.sg_toggle button.sg_active { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
/* A miniature sheet: the arrow boxes sit where the arrows print. */
.sg_arrows { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; padding: 8px; border: 2px solid #333; border-radius: 4px; background: #fafafa; }
.sg_arrows_landscape { width: 190px; aspect-ratio: 11 / 8.5; }
.sg_arrows_portrait { width: 150px; aspect-ratio: 8.5 / 11; }
.sg_arrow { display: flex; align-items: center; justify-content: center; border: 1px solid #c7cbd4; border-radius: 4px; background: #fff; cursor: pointer; font-size: 1.3rem; font-weight: bold; }
.sg_arrow:hover { border-color: #4f46e5; }
.sg_arrow input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.sg_arrow:focus-within { outline: 2px solid #4f46e5; outline-offset: 1px; }
.sg_arrow.sg_active { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
.sg_arrow_text { display: flex; align-items: center; justify-content: center; color: #999; font-size: 0.8rem; }
.sg_preview { position: relative; overflow: hidden; border: 1px solid #bbb; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15); background: #fff; }
.sg_preview iframe { position: absolute; left: 0; top: 0; border: 0; transform-origin: 0 0; pointer-events: none; }
.sg_empty { color: #777; text-align: center; padding: 40% 1em 0 1em; }
.sg_preview_col .sg_note { margin-top: 8px; max-width: 420px; }
</style>
