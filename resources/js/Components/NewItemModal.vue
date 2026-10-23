<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- NewItemModal.vue

	The "Add New Item" box: adds a SKU row to the master item list (POST /json/items), creating a new item type
	and category on the way if asked. Shared by Donation Sorting and Training Mode's product scan page. Mount it
	with v-if when it should show; it starts from `initial`:

	  { description, upc }                    opened by hand (e.g. the item search's "add new")
	  { scan: { code, online, suggestion } }  opened from an unknown barcode scan (a UpcResolver result): the UPC
	                                          and any suggestion are filled in, and if the online lookup is still
	                                          'pending' it is fetched here (GET /json/upc/suggest)

	Emits `saved(item, scan)` with the new item row, `close(fromScan)` on Cancel or a click outside, and — only
	when `allowCarton` — `carton(code)` when the person says the barcode is on a case of items already in the list
	(the parent opens its carton dialog). Keys typed while the box itself has focus are a scanner firing again
	before the person chose anything: they are swallowed and the "not found" tone repeats.
-->

<script>
import SearchSelect, { invalidateOptions } from '@/Components/SearchSelect.vue';
import axios from 'axios';
import { playScanUnknown } from '@/scanSounds';

// show the barcode the way it is printed: UPC-A / EAN-13 without the GTIN-14 padding zeros
export function displayCode(code) {
	return code.startsWith('00') ? code.slice(2) : code.startsWith('0') ? code.slice(1) : code;
}

export default {
	components: { SearchSelect },
	props: {
		initial: { type: Object, default: () => ({}) },
		allowCarton: { type: Boolean, default: true },
	},
	emits: ['saved', 'close', 'carton'],
	data() {
		const scan = this.initial.scan ? { ...this.initial.scan } : null;
		const suggestion = scan?.suggestion;
		return {
			modal: {
				description: scan
					? [suggestion?.brand, suggestion?.description, suggestion?.size_text].filter(Boolean).join(' ')
					: (this.initial.description || ''),
				upc: scan ? displayCode(scan.code) : (this.initial.upc || ''),
				scan,
				kind: 'each',
				itemtype_id: null,
				creatingType: false,
				newTypeName: '',
				unit_id: null,
				category_id: null,
				creatingCategory: false,
				newCategoryName: '',
			},
			saving: false,
			error: null,
			scanBuffer: '',
		};
	},
	mounted() {
		if (this.modal.scan) {
			// Park focus on the box itself, not a field, so the next scan can't land in one (see onStrayKey).
			this.$nextTick(() => this.$refs.box?.focus());
			if (this.modal.scan.online === 'pending') this.fetchSuggestion(this.modal.scan.code);
		}
	},
	methods: {
		onStrayKey(event) {
			if (event.key === 'Enter') {
				if (this.scanBuffer.length >= 8) playScanUnknown();
				this.scanBuffer = '';
				event.preventDefault();
			} else if (/^\d$/.test(event.key)) {
				this.scanBuffer += event.key;
				event.preventDefault();
			} // anything else (Tab to reach the fields, Escape) behaves normally
		},
		// The online half of an unknown scan, filled in when it arrives. Never overwrites a description
		// the person has started typing.
		async fetchSuggestion(code) {
			let outcome;
			try {
				outcome = (await axios.get('/json/upc/suggest', { params: { code } })).data;
			} catch (error) {
				outcome = { online: 'unavailable', suggestion: null };
			}
			const scan = this.modal.scan;
			if (!scan || scan.code !== code) return;
			scan.online = outcome.online;
			scan.suggestion = outcome.suggestion;
			const suggestion = outcome.suggestion;
			if (suggestion && !this.modal.description.trim()) {
				this.modal.description = [suggestion.brand, suggestion.description, suggestion.size_text].filter(Boolean).join(' ');
			}
		},
		scanNote() {
			const scan = this.modal.scan;
			if (scan.suggestion) {
				return 'Suggested by ' + scan.suggestion.source_name + ' - check it against the product in front of you.';
			}
			return {
				pending: 'Looking up product details online...',
				none: 'Not found in Open Food Facts or Open Products Facts - enter the details by hand.',
				unavailable: 'The online lookup is not responding right now - enter the details by hand.',
				throttled: 'Too many online lookups this minute - enter the details by hand.',
				disabled: 'Online lookup is off - enter the details by hand.',
			}[scan.online] || '';
		},
		close() {
			this.$emit('close', !!this.modal.scan);
		},
		// The radio on a scanned item's form: this barcode is really a case/multipack of items we
		// already carry, so map it to the item inside instead of adding a new row.
		switchToCarton() {
			this.modal.kind = 'each';
			if (this.modal.scan?.code) this.$emit('carton', this.modal.scan.code);
		},
		startNewType(name) {
			this.modal.creatingType = true;
			this.modal.newTypeName = name || '';
			this.modal.itemtype_id = null;
		},
		startNewCategory(name) {
			this.modal.creatingCategory = true;
			this.modal.newCategoryName = name || '';
			this.modal.category_id = null;
		},
		async save() {
			this.error = null;
			const modal = this.modal;

			if (!modal.description.trim()) {
				this.error = 'A description is required.';
				return;
			}
			if (!modal.creatingType && !modal.itemtype_id) {
				this.error = 'Choose an item type (or add a new one).';
				return;
			}
			if (modal.creatingType && (!modal.newTypeName.trim() || !modal.unit_id ||
				(!modal.creatingCategory && !modal.category_id) ||
				(modal.creatingCategory && !modal.newCategoryName.trim()))) {
				this.error = 'A new item type needs a name, a unit, and a category.';
				return;
			}

			this.saving = true;
			try {
				let categoryId = modal.category_id;
				if (modal.creatingType && modal.creatingCategory) {
					const response = await axios.post('/json/categories', { name: modal.newCategoryName.trim() });
					categoryId = response.data.category.id;
					invalidateOptions('/json/categories');
				}

				let itemtypeId = modal.itemtype_id;
				if (modal.creatingType) {
					const response = await axios.post('/json/itemtypes', {
						name: modal.newTypeName.trim(),
						category_id: categoryId,
						unit_id: modal.unit_id,
						active: true,
						items: [],
					});
					itemtypeId = response.data.id;
					invalidateOptions('/json/itemtypes/noitems');
				}

				const response = await axios.post('/json/items', {
					itemtype_id: itemtypeId,
					description: modal.description.trim(),
					upc: modal.upc.trim() || null,
				});
				invalidateOptions('/json/items');
				this.$emit('saved', response.data.record, modal.scan);
			} catch (error) {
				this.error = error.response?.data?.message
					|| Object.values(error.response?.data?.errors || {}).flat().join(' ')
					|| 'Could not save the new item.';
			} finally {
				this.saving = false;
			}
		},
	},
};
</script>

<template>
	<div class="nim_overlay" @click.self="close">
		<div class="nim_box" ref="box" tabindex="-1" @keydown.self="onStrayKey">
			<h3>Add New Item</h3>
			<p v-if="error" class="nim_error">{{ error }}</p>
			<template v-if="modal.scan">
				<p class="nim_note">{{ scanNote() }}</p>
				<img v-if="modal.scan.suggestion?.image_url" :src="modal.scan.suggestion.image_url" alt="" class="nim_image" />
				<div class="nim_field" v-if="allowCarton">
					<label>This barcode is on:</label>
					<label class="nim_radio"><input type="radio" value="each" v-model="modal.kind" /> One item</label>
					<label class="nim_radio"><input type="radio" value="carton" v-model="modal.kind" @change="switchToCarton" /> A case of items already in the list</label>
				</div>
			</template>

			<div class="nim_field">
				<label>Description:</label>
				<input type="text" v-model="modal.description" class="ri_forminput" autofocus />
			</div>
			<div class="nim_field">
				<label>UPC (optional):</label>
				<input type="text" v-model="modal.upc" class="ri_forminput" placeholder="Scan barcode" />
			</div>

			<div class="nim_field" v-if="!modal.creatingType">
				<label>Item Type:</label>
				<SearchSelect
					v-model="modal.itemtype_id"
					optionsource="/json/itemtypes/noitems"
					display="name"
					:searchfields="['name', 'display_number']"
					placeholder="Search item types..."
					:allowcreate="true"
					@create="startNewType"
				/>
			</div>
			<template v-else>
				<div class="nim_field">
					<label>New Item Type:</label>
					<input type="text" v-model="modal.newTypeName" class="ri_forminput" placeholder="Type name" />
					<button @click="modal.creatingType = false" class="ri_formbutton">Pick existing instead</button>
				</div>
				<div class="nim_field">
					<label>Measured By:</label>
					<SearchSelect
						v-model="modal.unit_id"
						optionsource="/json/units"
						display="name"
						placeholder="Unit (e.g. Each, Pound)..."
					/>
				</div>
				<div class="nim_field" v-if="!modal.creatingCategory">
					<label>Category:</label>
					<SearchSelect
						v-model="modal.category_id"
						optionsource="/json/categories"
						display="name"
						placeholder="Search categories..."
						:allowcreate="true"
						@create="startNewCategory"
					/>
				</div>
				<div class="nim_field" v-else>
					<label>New Category:</label>
					<input type="text" v-model="modal.newCategoryName" class="ri_forminput" placeholder="Category name" />
					<button @click="modal.creatingCategory = false" class="ri_formbutton">Pick existing instead</button>
				</div>
			</template>

			<div class="nim_buttons">
				<button @click="save" class="ri_defaultbutton" :disabled="saving">
					{{ saving ? 'Saving...' : 'Save Item' }}
				</button>
				<button @click="close" class="ri_formbutton">Cancel</button>
			</div>
		</div>
	</div>
</template>

<style scoped>
.nim_overlay {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.45);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 50;
}
.nim_box {
	background: #fff;
	border-radius: 10px;
	padding: 20px;
	width: min(560px, 94vw);
	max-height: 90vh;
	overflow-y: auto;
}
.nim_box:focus {
	outline: none; /* focused only to catch stray scans; no visible ring */
}
.nim_box h3 {
	font-weight: bold;
	font-size: 1.1rem;
	margin-bottom: 12px;
}
.nim_field {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
	flex-wrap: wrap;
}
.nim_field label {
	font-weight: bold;
	min-width: 8em;
}
.nim_error {
	color: #b91c1c;
	margin: 6px 0;
}
.nim_note {
	color: #374151;
	margin: 6px 0;
}
.nim_image {
	max-height: 90px;
	margin: 4px 0 8px;
	border-radius: 6px;
}
.nim_radio {
	font-weight: normal !important;
	min-width: 0 !important;
}
.nim_buttons {
	display: flex;
	gap: 10px;
	margin-top: 14px;
}
</style>
