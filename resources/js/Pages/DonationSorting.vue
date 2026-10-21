<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- DonationSorting.vue

	Scan-driven donation sorting, built as a session-based event stream
	rather than an RIForm document:

	  - A sorting session (donation transaction) is opened when work starts.
	  - The sorter scans a pallet tag once; every line entered afterward
	    carries that pallet's id, so the source of each item stays traceable.
	  - Each line is saved to the server the moment it is entered (autosave);
	    a crash or dropped connection never loses more than the current line.
	  - Each line records a disposition: usable goods count into inventory,
	    while outdated / trashed / diverted quantities feed donor-quality
	    reporting. Usable goods are further marked New or Usable (used, good
	    condition) via item_condition — both count toward on-hand the same.
	  - Sorters can add new items (and item types / categories) on the fly
	    when unfamiliar goods show up.
	  - Finishing a pallet is one tap ("Pallet Empty") plus an optional
	    damage observation; the empty transition rolls the donation to
	    Complete when its last pallet empties. Final QC stays with a
	    supervisor - the sorter's observation is a status-history note only.
	  - Completed sessions open read-only (server-enforced too); Reopen
	    Session is the explicit way back in. Donor is read-only-with-Edit
	    for donations recorded at Receiving, fully editable for ad-hoc ones.
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import SearchSelect, { invalidateOptions } from '@/Components/SearchSelect.vue';
import QrScanner from '@/Components/QrScanner.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import axios from 'axios';
import { playScanError, playScanMatched, playScanUnknown, scanSoundsEnabled, setScanSoundsEnabled } from '@/scanSounds';

// One button per choice. New and Usable are both disposition 'usable' (both
// count into inventory) and differ only in item_condition, which exists so
// new goods can be reported separately.
const DISPOSITIONS = [
	{ key: 'new',      value: 'usable',   condition: 'new',  label: 'New',      hint: 'Unused, in original packaging - counted into inventory' },
	{ key: 'usable',   value: 'usable',   condition: 'used', label: 'Usable',   hint: 'Used or opened, in good condition - counted into inventory' },
	{ key: 'outdated', value: 'outdated', condition: null,   label: 'Outdated', hint: 'Expired on arrival - discarded' },
	{ key: 'trashed',  value: 'trashed',  condition: null,   label: 'Trash',    hint: 'Damaged or unusable - discarded' },
	{ key: 'diverted', value: 'diverted', condition: null,   label: 'Divert',   hint: 'Usable, but passed to another organization' },
];

// The button a saved line corresponds to (lines sorted before item_condition
// existed have none, and show as plain Usable).
function dispositionFor(disposition, condition) {
	return DISPOSITIONS.find((d) => d.value === disposition && (d.condition === condition || d.condition === null))
		|| DISPOSITIONS.find((d) => d.value === disposition);
}

export default {
	components: { AuthenticatedLayout, Head, SearchSelect, QrScanner, TextArea, TextInput, Modal, InputLabel, PrimaryButton, SecondaryButton },
	props: {
		breadcrumb: { type: Array },
	},
	data() {
		return {
			dispositions: DISPOSITIONS,

			// list view
			view: 'list', // 'list' | 'session'
			sessions: { open: [], receivable: [], recent: [] },
			listLoading: false,
			listError: null,

			// active session
			session: null,
			lines: [],
			headerSaving: false,
			headerError: null,
			notice: null,
			editingDonor: false,
			confirmingComplete: false,

			// donor quick-add
			creatingDonor: false,
			newDonor: { first_name: '', last_name: '', organization: '' },
			donorSaving: false,
			donorError: null,

			// pallet context
			palletTagInput: '',
			pallet: null,
			palletError: null,
			showScanner: false,
			emptyingPallet: false,

			// line entry
			entry: { item_id: null, qty: null, choice: 'usable' },
			lineError: null,
			nextTempId: -1,
			confirmingLineKey: null,
			retryTimer: null,

			// quick-add item modal
			showItemModal: false,
			itemModal: this.blankItemModal(),
			itemModalSaving: false,
			itemModalError: null,

			// barcode scan -> item (confirmed mappings first, online suggestion on a miss)
			upcResolving: false,
			scanSounds: scanSoundsEnabled(),
		};
	},
	computed: {
		// Completed sessions open read-only; "Reopen Session" is the way back in.
		readonly() {
			return this.session?.status?.name === 'Complete';
		},
		// Donations that came through Receiving carry their donor from the
		// dock; ad-hoc untagged sessions have no Receiving record.
		fromReceiving() {
			return !!(this.session && ((this.session.pallets_count ?? 0) > 0 || this.session.manifest));
		},
		failedLines() {
			return this.lines.filter((line) => line.status === 'failed');
		},
		sortedLines() {
			// Unsaved lines are the newest — keep them on top instead of
			// letting them jump from bottom to top when the save lands.
			return [...this.lines].sort((a, b) => {
				const aSaved = a.id != null;
				const bSaved = b.id != null;
				if (aSaved !== bSaved) return aSaved ? 1 : -1;
				return aSaved ? b.id - a.id : a.tempId - b.tempId;
			});
		},
		totals() {
			const sum = (disposition) => this.lines
				.filter((line) => line.disposition === disposition && line.status !== 'failed')
				.reduce((total, line) => total + Number(line.qty_added || 0), 0);
			const usable = sum('usable');
			const outdated = sum('outdated');
			const trashed = sum('trashed');
			const diverted = sum('diverted');
			const all = usable + outdated + trashed + diverted;
			return {
				usable, outdated, trashed, diverted, all,
				trashPct: all > 0 ? Math.round(((outdated + trashed) / all) * 100) : 0,
			};
		},
		pendingCount() {
			return this.lines.filter((line) => line.status === 'saving' || line.status === 'failed').length;
		},
	},
	methods: {
		// ---------- session list ----------
		async fetchSessions() {
			this.listLoading = true;
			this.listError = null;
			try {
				const response = await axios.get('/json/sorting-sessions');
				this.sessions = response.data;
			} catch (error) {
				this.listError = 'Could not load sorting sessions.';
			} finally {
				this.listLoading = false;
			}
		},
		async startSession(donationId) {
			this.listError = null;
			try {
				const response = await axios.post('/json/sorting-sessions', donationId ? { donation_id: donationId } : {});
				this.openSession(response.data.record);
			} catch (error) {
				this.listError = donationId ? 'Could not pick up that donation.' : 'Could not start a new sorting session.';
			}
		},
		async resumeSession(id) {
			this.listError = null;
			try {
				const response = await axios.get('/json/sorting-sessions/' + id);
				this.openSession(response.data.record);
			} catch (error) {
				this.listError = 'Could not open that session.';
			}
		},
		openSession(record) {
			this.session = record;
			this.lines = (record.item_ledgers || []).map((line) => ({ ...line, status: 'saved' }));
			this.pallet = null;
			this.palletTagInput = '';
			this.palletError = null;
			this.notice = null;
			this.editingDonor = false;
			this.emptyingPallet = false;
			this.confirmingComplete = false;
			this.confirmingLineKey = null;
			this.entry = { item_id: null, qty: null, choice: 'usable' };
			this.view = 'session';
			this.$nextTick(() => this.$refs.palletInput?.focus());
		},
		async closeSession() {
			this.view = 'list';
			this.session = null;
			await this.fetchSessions();
		},
		sessionSummary(record) {
			const ledgers = record.item_ledgers || [];
			const total = ledgers.reduce((sum, line) => sum + Number(line.qty_added || 0), 0);
			return { lines: ledgers.length, total };
		},
		personLabel(person) {
			if (!person) return '(no donor recorded)';
			const name = [person.first_name, person.last_name].filter(Boolean).join(' ');
			return person.organization ? person.organization + (name ? ' - ' + name : '') : name || '(unnamed)';
		},
		// A completed donation's "Date" and "Completed By" come from when it
		// actually finished sorting, not from Receiving's own order_date /
		// entered_by (the dock clerk who logged it in, not the sorter) —
		// status_changed_at tracks the Complete transition itself, and the
		// per-line item_ledgers.person_user rows (stamped per line since
		// Order Filling/Picking) name whoever actually sorted it, which may
		// be more than one person across a session.
		completedDateLabel(record) {
			return record.status_changed_at ? new Date(record.status_changed_at).toLocaleString() : record.order_date;
		},
		completedByLabel(record) {
			const names = [...new Set(
				(record.item_ledgers || [])
					.map((line) => line.person_user)
					.filter(Boolean)
					.map((person) => this.personLabel(person))
			)];
			return names.length ? names.join(', ') : this.personLabel(record.entered_by);
		},

		// ---------- session header autosave ----------
		async patchSession(fields) {
			this.headerSaving = true;
			this.headerError = null;
			try {
				const response = await axios.patch('/json/sorting-sessions/' + this.session.id, fields);
				const record = response.data.record;
				this.session = { ...record, item_ledgers: undefined };
			} catch (error) {
				this.headerError = 'Could not save session details.';
			} finally {
				this.headerSaving = false;
			}
		},
		donorSelected(person) {
			this.editingDonor = false;
			this.patchSession({ person_id: person ? person.id : null });
		},
		commentsChanged() {
			this.patchSession({ comments: this.session.comments });
		},
		donorIdPendingChanged() {
			this.patchSession({ donor_identification_pending: this.session.donor_identification_pending });
		},

		// ---------- donor quick-add ----------
		startNewDonor(name) {
			this.creatingDonor = true;
			this.donorError = null;
			const parts = (name || '').trim().split(/\s+/);
			this.newDonor = {
				first_name: parts[0] || '',
				last_name: parts.slice(1).join(' '),
				organization: parts.length <= 1 ? (name || '') : '',
			};
		},
		async saveNewDonor() {
			this.donorError = null;
			const hasName = this.newDonor.first_name.trim() && this.newDonor.last_name.trim();
			const hasOrg = (this.newDonor.organization || '').trim();
			if (!hasName && !hasOrg) {
				this.donorError = 'Enter a name (first and last) or an organization.';
				return;
			}
			this.donorSaving = true;
			try {
				const response = await axios.post('/json/people', this.newDonor);
				invalidateOptions('/json/people');
				await this.$refs.donorSelect?.refresh(response.data.record.id);
				this.creatingDonor = false;
				this.donorSelected(response.data.record);
			} catch (error) {
				this.donorError = error.response?.data?.message || 'Could not save donor.';
			} finally {
				this.donorSaving = false;
			}
		},
		async completeSession() {
			// Two-step inline confirm when lines haven't reached the server.
			if (this.pendingCount > 0 && !this.confirmingComplete) {
				this.confirmingComplete = true;
				return;
			}
			this.confirmingComplete = false;
			await this.patchSession({ completed: true });
			if (!this.headerError) {
				await this.closeSession();
			}
		},
		async reopenSession() {
			this.notice = null;
			await this.patchSession({ completed: false });
		},

		// ---------- pallet context ----------
		async resolvePallet() {
			const tag = this.palletTagInput.trim();
			if (!tag) return;
			this.palletError = null;
			try {
				const response = await axios.get('/json/sorting-sessions/pallet/' + encodeURIComponent(tag));
				this.pallet = response.data.record;
				this.palletTagInput = this.palletTagStr(this.pallet.id);
				this.$refs.itemSelect?.focus();
			} catch (error) {
				this.pallet = null;
				this.palletError = 'Unknown pallet tag "' + tag + '". Check the label, or continue without a pallet.';
			}
		},
		clearPallet() {
			this.pallet = null;
			this.palletTagInput = '';
			this.palletError = null;
			this.emptyingPallet = false;
			this.$nextTick(() => this.$refs.palletInput?.focus());
		},
		/**
		 * One-tap "pallet finished": records the sorter's damage observation
		 * as a note, transitions the pallet to empty (which rolls the donation
		 * to Complete once its last pallet empties), and readies the next scan.
		 */
		async markPalletEmpty(observation) {
			this.palletError = null;
			const tag = this.palletTagStr(this.pallet.id);
			try {
				const response = await axios.post(
					'/json/sorting-sessions/pallet/' + encodeURIComponent(tag) + '/empty',
					{ observation }
				);
				this.notice = 'Pallet ' + tag + ' marked empty'
					+ (observation === 'damaged' ? ' and flagged for QC' : '')
					+ (response.data.donation_status === 'Complete'
						? ' — that was the last pallet, donation is fully sorted!' : '.');
				this.clearPallet();
			} catch (error) {
				this.emptyingPallet = false;
				this.palletError = error.response?.data?.message || 'Could not mark the pallet empty.';
			}
		},
		onScanned(text) {
			this.showScanner = false;
			this.palletTagInput = text;
			this.resolvePallet();
		},
		palletTagStr(id) {
			// Sorting only ever scans Receiving pallets (enforced
			// server-side), so the R prefix is always correct here.
			return 'R' + String(id).padStart(8, '0');
		},
		// Contents recorded at Receiving (item tag for single-item pallets,
		// or a free-text description like "Mixed pallet").
		palletContents(pallet) {
			if (pallet.content_item) {
				return pallet.content_item.name || pallet.content_item.description;
			}
			return pallet.content_description || null;
		},

		// ---------- line entry (autosaved per line) ----------
		itemChosen() {
			this.$refs.qtyInput?.focus();
		},
		setDisposition(key) {
			this.entry.choice = key;
		},
		addLine() {
			this.lineError = null;
			this.confirmingLineKey = null;
			if (!this.entry.item_id) {
				this.lineError = 'Choose an item first.';
				return;
			}
			const qty = parseInt(this.entry.qty, 10);
			if (!qty || qty < 1) {
				this.lineError = 'Enter a quantity of 1 or more.';
				return;
			}
			const line = {
				tempId: this.nextTempId--,
				item_id: this.entry.item_id,
				item: this.entry.item || null,
				qty_added: qty,
				disposition: DISPOSITIONS.find((d) => d.key === this.entry.choice).value,
				item_condition: DISPOSITIONS.find((d) => d.key === this.entry.choice).condition,
				pallet_id: this.pallet ? this.pallet.id : null,
				pallet: this.pallet,
				created_at: new Date().toISOString(),
				status: 'saving',
			};
			this.lines.push(line);
			this.saveLine(line);

			// reset for the next scan; keep the disposition (runs of similar
			// goods are common) and, of course, the pallet context
			this.entry = { item_id: null, item: null, qty: null, choice: this.entry.choice };
			this.$refs.itemSelect?.focus();
		},
		findLineIndex(line) {
			return this.lines.findIndex((entry) =>
				(line.tempId !== undefined && entry.tempId === line.tempId) || entry === line);
		},
		async saveLine(line) {
			const startIdx = this.findLineIndex(line);
			if (startIdx !== -1) this.lines.splice(startIdx, 1, { ...line, status: 'saving' });
			try {
				const response = await axios.post('/json/sorting-sessions/' + this.session.id + '/lines', {
					item_id: line.item_id,
					qty: line.qty_added,
					disposition: line.disposition,
					item_condition: line.item_condition ?? null,
					pallet_tag: line.pallet_id ? String(line.pallet_id) : null,
				});
				const idx = this.findLineIndex(line);
				if (idx !== -1) {
					this.lines.splice(idx, 1, { ...line, ...response.data.record, status: 'saved' });
				}
			} catch (error) {
				const idx = this.findLineIndex(line);
				const errorMessage = error.response?.data?.message
					|| Object.values(error.response?.data?.errors || {}).flat().join(' ')
					|| 'Network error';
				if (idx !== -1) {
					this.lines.splice(idx, 1, { ...line, status: 'failed', errorMessage });
				}
			}
		},
		lineKey(line) {
			return line.id ?? line.tempId;
		},
		async deleteLine(line) {
			// Two-step inline confirm: first tap arms this line, second deletes.
			if (this.confirmingLineKey !== this.lineKey(line)) {
				this.confirmingLineKey = this.lineKey(line);
				return;
			}
			this.confirmingLineKey = null;
			if (!line.id) {
				// never reached the server; just drop it locally
				this.lines = this.lines.filter((entry) => entry !== line);
				return;
			}
			try {
				await axios.delete('/json/sorting-sessions/' + this.session.id + '/lines/' + line.id);
				this.lines = this.lines.filter((entry) => entry !== line);
			} catch (error) {
				this.lineError = 'Could not delete the line. Try again.';
			}
		},
		retryFailed() {
			this.failedLines.forEach((line) => this.saveLine(line));
		},
		// Auto-retry failed autosaves: whenever connectivity returns, and on a
		// slow heartbeat as backup — 45-minute sessions on warehouse Wi-Fi
		// shouldn't need the sorter to babysit a Retry button.
		autoRetry() {
			if (this.view === 'session' && !this.readonly && this.failedLines.length) {
				this.retryFailed();
			}
		},
		lineTime(line) {
			const date = new Date(line.created_at);
			return isNaN(date) ? '' : date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
		},
		dispositionLabel(line) {
			return dispositionFor(line.disposition, line.item_condition ?? null)?.label || line.disposition;
		},
		dispositionKey(line) {
			return dispositionFor(line.disposition, line.item_condition ?? null)?.key || line.disposition;
		},

		// ---------- barcode scan -> item ----------
		// A scanned barcode is looked up server-side: our own confirmed
		// mappings first (no network), and only for a barcode we've never
		// seen an online suggestion, which the sorter reviews and confirms
		// before it is stored. See UpcController / UpcResolver.
		async onBarcodeScan(text) {
			this.lineError = null;
			this.upcResolving = true;
			try {
				const response = await axios.get('/json/upc/resolve', { params: { code: text } });
				const result = response.data;
				if (result.status === 'matched') {
					await this.applyScanMatch(result);
					playScanMatched();
				} else {
					this.openItemModalForScan(result);
					playScanUnknown();
				}
			} catch (error) {
				playScanError();
				this.lineError = error.response?.data?.message || 'Could not look up that barcode. Search for the item instead.';
				this.$refs.itemSelect?.clearSelection();
			} finally {
				this.upcResolving = false;
			}
		},
		// The item box matched the barcode against its own loaded list (no server lookup).
		onScanMatchedLocally(item) {
			if ((item.case_qty ?? 0) > 1) this.entry.qty = Math.round(item.case_qty * (parseFloat(item.size) || 1));
			playScanMatched();
		},
		toggleScanSounds() {
			this.scanSounds = !this.scanSounds;
			setScanSoundsEnabled(this.scanSounds);
			if (this.scanSounds) playScanMatched(); // preview what it sounds like
		},
		async applyScanMatch(result) {
			const select = this.$refs.itemSelect;
			const known = select.optionlist.find((option) => option.id === result.item.id);
			if (known) {
				select.selectOption(known);
			} else {
				await select.refresh(result.item.id);
			}
			// a case adds all the single units in it; the sorter can still change the number
			if (result.qty) this.entry.qty = result.qty;
		},
		// show the barcode the way it is printed: UPC-A / EAN-13 without the GTIN-14 padding zeros
		displayCode(code) {
			return code.startsWith('00') ? code.slice(2) : code.startsWith('0') ? code.slice(1) : code;
		},

		// ---------- quick-add item ----------
		blankItemModal() {
			return {
				description: '',
				upc: '',
				scan: null, // set when opened from an unknown barcode scan: { kind, online, suggestion, guessItem, source }
				kind: 'each',
				case_qty: null,
				itemtype_id: null,
				creatingType: false,
				newTypeName: '',
				unit_id: null,
				category_id: null,
				creatingCategory: false,
				newCategoryName: '',
			};
		},
		openItemModal(searchText) {
			this.itemModal = this.blankItemModal();
			// A scanned barcode with no match lands here as all-digit text — that's
			// the new item's UPC, not its description.
			const text = (searchText || '').trim();
			if (/^\d{6,14}$/.test(text)) {
				this.itemModal.upc = text;
			} else {
				this.itemModal.description = text;
			}
			this.itemModalError = null;
			this.showItemModal = true;
		},
		// An unknown barcode opens the normal Add New Item screen, prefilled: the
		// scanned UPC, whatever an online lookup suggested (the sorter checks it),
		// and, for a carton whose single-item barcode we already carry, that
		// item's type and description. Saving adds a SKU row to the master list.
		openItemModalForScan(result) {
			const suggestion = result.suggestion;
			const guess = result.guess_item;
			this.itemModal = this.blankItemModal();
			this.itemModal.upc = this.displayCode(result.code);
			this.itemModal.kind = result.kind;
			this.itemModal.itemtype_id = guess?.itemtype_id ?? null;
			this.itemModal.description = guess?.description
				|| [suggestion?.brand, suggestion?.description, suggestion?.size_text].filter(Boolean).join(' ');
			this.itemModal.scan = { code: result.code, online: result.online, suggestion, guessItem: guess };
			this.itemModalError = null;
			this.showItemModal = true;
		},
		scanNote() {
			const scan = this.itemModal.scan;
			if (scan.guessItem) {
				return 'This looks like a case of ' + scan.guessItem.name + ' (its single-item barcode is already in the master list). Check it, and enter how many are inside.';
			}
			if (scan.suggestion) {
				const unit = scan.suggestion.code !== scan.code ? ' (for the single item inside the case)' : '';
				return 'Suggested by ' + scan.suggestion.source_name + unit + ' - check it against the product in front of you.';
			}
			return {
				none: 'Not found in Open Food Facts or Open Products Facts - enter the details by hand.',
				unavailable: 'The online lookup is not responding right now - enter the details by hand.',
				throttled: 'Too many online lookups this minute - enter the details by hand.',
				disabled: 'Online lookup is off - enter the details by hand.',
			}[scan.online] || '';
		},
		closeItemModal() {
			const fromScan = !!this.itemModal.scan;
			this.showItemModal = false;
			if (fromScan) this.$refs.itemSelect?.clearSelection(); // drop the unresolved barcode text
		},
		startNewType(name) {
			this.itemModal.creatingType = true;
			this.itemModal.newTypeName = name || '';
			this.itemModal.itemtype_id = null;
		},
		startNewCategory(name) {
			this.itemModal.creatingCategory = true;
			this.itemModal.newCategoryName = name || '';
			this.itemModal.category_id = null;
		},
		async saveItemModal() {
			this.itemModalError = null;
			const modal = this.itemModal;

			if (!modal.description.trim()) {
				this.itemModalError = 'A description is required.';
				return;
			}
			const caseQty = parseInt(modal.case_qty, 10);
			if (modal.scan && modal.kind === 'carton' && !(caseQty >= 2)) {
				this.itemModalError = 'Enter how many items are in one case.';
				return;
			}
			if (!modal.creatingType && !modal.itemtype_id) {
				this.itemModalError = 'Choose an item type (or add a new one).';
				return;
			}
			if (modal.creatingType && (!modal.newTypeName.trim() || !modal.unit_id ||
				(!modal.creatingCategory && !modal.category_id) ||
				(modal.creatingCategory && !modal.newCategoryName.trim()))) {
				this.itemModalError = 'A new item type needs a name, a unit, and a category.';
				return;
			}

			this.itemModalSaving = true;
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

				const payload = {
					itemtype_id: itemtypeId,
					description: modal.description.trim(),
					upc: modal.upc.trim() || null,
				};
				if (modal.scan && modal.kind === 'carton') {
					// a case is just another SKU row with its own UPC: package "Case", Case Qty N
					payload.case_qty = caseQty;
					const packages = (await axios.get('/json/packagetypes')).data.records || [];
					const casePackage = packages.find((p) => /^case$/i.test(p.singular));
					if (casePackage) payload.packagetypes_id = casePackage.id;
				}
				const response = await axios.post('/json/items', payload);
				const newItem = response.data.record;

				invalidateOptions('/json/items');
				this.showItemModal = false;
				await this.$refs.itemSelect?.refresh(newItem.id);
				if (modal.scan && modal.kind === 'carton') this.entry.qty = caseQty;
				this.$refs.qtyInput?.focus();
			} catch (error) {
				this.itemModalError = error.response?.data?.message
					|| Object.values(error.response?.data?.errors || {}).flat().join(' ')
					|| 'Could not save the new item.';
			} finally {
				this.itemModalSaving = false;
			}
		},
	},
	created() {
		this.fetchSessions();
	},
	mounted() {
		window.addEventListener('online', this.autoRetry);
		this.retryTimer = setInterval(this.autoRetry, 30000);
	},
	unmounted() {
		window.removeEventListener('online', this.autoRetry);
		clearInterval(this.retryTimer);
	},
};
</script>

<template>
	<Head title="Donation Sorting" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<!-- ======================= SESSION LIST ======================= -->
		<div v-if="view === 'list'" class="sort_container">
			<h2 class="ri_datatable_head">
				Donation Sorting
				<button @click="startSession()" class="ri_defaultbutton">Start Sorting (untagged)</button>
			</h2>

			<p v-if="listError" class="sort_error">{{ listError }}</p>
			<p v-if="listLoading">Loading sessions...</p>

			<div v-if="sessions.open.length" class="sort_section">
				<h3>In Progress - tap to resume</h3>
				<div class="sort_tablewrap"><table class="ri_datatable" border="1">
					<thead>
						<tr><th>Date</th><th>Started By</th><th>Donor</th><th>Lines</th><th>Items</th></tr>
					</thead>
					<tbody>
						<tr v-for="record in sessions.open" :key="record.id"
							class="sort_rowlink" @click="resumeSession(record.id)">
							<td>{{ record.order_date }}</td>
							<td>{{ personLabel(record.entered_by) }}</td>
							<td>
							{{ personLabel(record.person) }}
							<span v-if="record.donor_identification_pending" class="sort_badge_id">donor ID pending</span>
						</td>
							<td>{{ sessionSummary(record).lines }}</td>
							<td>{{ sessionSummary(record).total }}</td>
						</tr>
					</tbody>
				</table></div>
			</div>

			<div v-if="sessions.receivable.length" class="sort_section">
				<h3>Ready to Sort - tap to pick up</h3>
				<div class="sort_tablewrap"><table class="ri_datatable" border="1">
					<thead>
						<tr><th>Date Received</th><th>Donor</th><th>Manifest</th></tr>
					</thead>
					<tbody>
						<tr v-for="record in sessions.receivable" :key="record.id"
							class="sort_rowlink" @click="startSession(record.id)">
							<td>{{ record.order_date }}</td>
							<td>
							{{ personLabel(record.person) }}
							<span v-if="record.donor_identification_pending" class="sort_badge_id">donor ID pending</span>
						</td>
							<td>{{ record.manifest }}</td>
						</tr>
					</tbody>
				</table></div>
			</div>

			<div class="sort_section">
				<h3>Recently Completed</h3>
				<p v-if="!sessions.recent.length && !listLoading">No completed sorting sessions yet.</p>
				<div v-else class="sort_tablewrap"><table class="ri_datatable" border="1">
					<thead>
						<tr><th>Completed</th><th>Completed By</th><th>Donor</th><th>Lines</th><th>Items</th></tr>
					</thead>
					<tbody>
						<tr v-for="record in sessions.recent" :key="record.id"
							class="sort_rowlink" @click="resumeSession(record.id)">
							<td>{{ completedDateLabel(record) }}</td>
							<td>{{ completedByLabel(record) }}</td>
							<td>
							{{ personLabel(record.person) }}
							<span v-if="record.donor_identification_pending" class="sort_badge_id">donor ID pending</span>
						</td>
							<td>{{ sessionSummary(record).lines }}</td>
							<td>{{ sessionSummary(record).total }}</td>
						</tr>
					</tbody>
				</table></div>
			</div>
		</div>

		<!-- ======================= ACTIVE SESSION ======================= -->
		<div v-else-if="session" class="sort_container">
			<div class="sort_topbar">
				<button @click="closeSession" class="ri_formbutton">&larr; All Sessions</button>
				<span class="sort_title">
					Sorting Session &mdash; {{ session.order_date }}
					<span v-if="readonly" class="sort_completed_badge">Completed</span>
				</span>
				<span class="sort_savestate" v-if="!readonly">
					<span v-if="failedLines.length" class="sort_error_inline">
						{{ failedLines.length }} line(s) failed to save
						<button @click="retryFailed" class="ri_linkbutton">Retry All</button>
					</span>
					<span v-else-if="headerSaving || pendingCount > 0">Saving...</span>
					<span v-else class="sort_saved">All changes saved</span>
				</span>
				<template v-if="!readonly">
					<button v-if="confirmingComplete" @click="confirmingComplete = false" class="ri_linkbutton">Keep Sorting</button>
					<button @click="completeSession" :class="confirmingComplete ? 'ri_deletebutton' : 'ri_defaultbutton'">
						{{ confirmingComplete
							? 'Complete with ' + pendingCount + ' unsaved line(s)' + (failedLines.length ? ' (' + failedLines.length + ' failed)' : '') + '?'
							: 'Complete Session' }}
					</button>
				</template>
				<button v-else @click="reopenSession" class="ri_defaultbutton">Reopen Session</button>
			</div>
			<p v-if="headerError" class="sort_error">{{ headerError }}</p>
			<p v-if="notice" class="sort_notice">{{ notice }}</p>

			<!-- session header: donor + comments, autosaved on change.
			     Donations from Receiving carry the donor recorded at the dock,
			     so it shows read-only with an explicit Edit; ad-hoc untagged
			     sessions edit the donor directly. -->
			<div class="sort_header">
				<div class="sort_field">
					<label>Donor / Source:</label>
					<template v-if="readonly || (fromReceiving && !editingDonor)">
						<span>
							{{ personLabel(session.person) }}
							<em v-if="fromReceiving" class="sort_donor_src">(recorded at receiving)</em>
						</span>
						<button v-if="!readonly" @click="editingDonor = true" class="ri_linkbutton">Edit</button>
					</template>
					<SearchSelect
						v-else
						ref="donorSelect"
						v-model="session.person_id"
						optionsource="/json/people"
						display="search_label"
						:searchfields="['organization', 'first_name', 'last_name']"
						placeholder="Search donors..."
						:allowcreate="true"
						@selected="donorSelected"
						@create="startNewDonor"
					/>
					<Modal :show="creatingDonor" @close="creatingDonor = false" max-width="sm">
						<div class="p-6 space-y-4">
							<h2 class="text-lg font-semibold">New Donor</h2>
							<div>
								<InputLabel value="First Name" />
								<TextInput v-model="newDonor.first_name" class="mt-1 block w-full" autofocus />
							</div>
							<div>
								<InputLabel value="Last Name" />
								<TextInput v-model="newDonor.last_name" class="mt-1 block w-full" />
							</div>
							<div>
								<InputLabel value="Organization" />
								<TextInput v-model="newDonor.organization" class="mt-1 block w-full" />
							</div>
							<p v-if="donorError" class="text-sm text-red-700">{{ donorError }}</p>
							<div class="flex justify-end gap-3">
								<SecondaryButton :disabled="donorSaving" @click="creatingDonor = false">Cancel</SecondaryButton>
								<PrimaryButton :disabled="donorSaving" @click="saveNewDonor">Save Donor</PrimaryButton>
							</div>
						</div>
					</Modal>
				</div>
				<div class="sort_field">
					<label>Comments:</label>
					<TextArea v-model="session.comments" :enabled="!readonly" @change="commentsChanged" />
				</div>
				<div class="sort_field">
					<label class="sort_checkbox">
						<input
							type="checkbox"
							v-model="session.donor_identification_pending"
							:disabled="readonly"
							@change="donorIdPendingChanged"
						/>
						Donor unidentified &mdash; flag for follow-up
					</label>
				</div>
			</div>

			<!-- pallet context: scanned once, applied to every following line -->
			<div v-if="!readonly" class="sort_pallet" :class="pallet ? 'sort_pallet_active' : ''">
				<template v-if="!pallet">
					<label>Pallet Tag:</label>
					<input
						ref="palletInput"
						type="text"
						v-model="palletTagInput"
						class="ri_forminput"
						placeholder="Scan or type pallet tag (e.g. R00000042)"
						@keydown.enter.prevent="resolvePallet"
					/>
					<button @click="resolvePallet" class="ri_formbutton">Set Pallet</button>
					<button @click="showScanner = true" class="ri_formbutton">Camera Scan</button>
					<span class="sort_pallet_hint">Lines entered without a pallet won't be source-traceable.</span>
				</template>
				<template v-else-if="!emptyingPallet">
					<span class="sort_pallet_label">
						Sorting from pallet <strong>{{ palletTagStr(pallet.id) }}</strong>
						(packed {{ pallet.datepacked }}<span v-if="pallet.location">, at {{ pallet.location.code }}</span>)
						<span v-if="palletContents(pallet)" class="sort_pallet_contents">&mdash; {{ palletContents(pallet) }}</span>
					</span>
					<button @click="emptyingPallet = true" class="ri_defaultbutton">Pallet Empty</button>
					<button @click="clearPallet" class="ri_formbutton">Change Pallet</button>
				</template>
				<template v-else>
					<!-- one optional observation tap; final QC stays with a supervisor -->
					<span class="sort_pallet_label">
						Pallet <strong>{{ palletTagStr(pallet.id) }}</strong> finished &mdash; how did it look?
					</span>
					<button @click="markPalletEmpty('ok')" class="ri_defaultbutton">Pallet looks OK</button>
					<button @click="markPalletEmpty('damaged')" class="ri_deletebutton">Set aside &mdash; damaged?</button>
					<button @click="emptyingPallet = false" class="ri_linkbutton">Cancel</button>
				</template>
			</div>
			<p v-if="palletError" class="sort_error">{{ palletError }}</p>

			<!-- line entry -->
			<div v-if="!readonly" class="sort_entry">
				<div class="sort_entry_item">
					<SearchSelect
						ref="itemSelect"
						v-model="entry.item_id"
						optionsource="/json/items"
						display="name"
						:searchfields="['name', 'upc', 'description']"
						placeholder="Scan UPC or search items..."
						:allowcreate="true"
						:scanDigits="true"
						@selected="(item) => { entry.item = item; if (item) itemChosen(); }"
						@create="openItemModal"
						@scan="onBarcodeScan"
						@scanmatch="onScanMatchedLocally"
					/>
					<span v-if="upcResolving" class="sort_notice">Looking up barcode...</span>
					<button type="button" @click="toggleScanSounds" class="ri_linkbutton" :title="'Beeps when a scan matches an item, a lower tone when it does not'">
						Scan sounds: {{ scanSounds ? 'on' : 'off' }}
					</button>
				</div>
				<input
					ref="qtyInput"
					type="number"
					min="1"
					v-model="entry.qty"
					class="ri_forminput sort_qty"
					placeholder="Qty"
					@keydown.enter.prevent="addLine"
				/>
				<div class="sort_dispositions">
					<button
						v-for="d in dispositions"
						:key="d.key"
						:title="d.hint"
						class="sort_disp"
						:class="['sort_disp_' + d.key, entry.choice === d.key ? 'sort_disp_on' : '']"
						@click="setDisposition(d.key)"
					>{{ d.label }}</button>
				</div>
				<button @click="addLine" class="ri_defaultbutton">Add</button>
			</div>
			<p v-if="lineError" class="sort_error">{{ lineError }}</p>

			<!-- entered lines -->
			<div class="sort_tablewrap"><table class="ri_datatable sort_lines" border="1">
				<thead>
					<tr>
						<th>Time</th><th>Item</th><th>Qty</th><th>Disposition</th><th>Pallet</th><th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="line in sortedLines" :key="line.id ?? line.tempId"
						:class="{ sort_line_failed: line.status === 'failed' }">
						<td>{{ lineTime(line) }}</td>
						<td>{{ line.item ? (line.item.name || line.item.description) : '(item #' + line.item_id + ')' }}</td>
						<td>{{ line.qty_added }}</td>
						<td><span class="sort_badge" :class="'sort_disp_' + dispositionKey(line)">{{ dispositionLabel(line) }}</span></td>
						<td>{{ line.pallet ? palletTagStr(line.pallet.id) : (line.pallet_id ? palletTagStr(line.pallet_id) : '-') }}</td>
						<td class="sort_line_actions">
							<span v-if="line.status === 'saving'">saving...</span>
							<template v-else-if="line.status === 'failed'">
								<span :title="line.errorMessage">failed</span>
								<button @click="saveLine(line)" class="ri_formbutton">Retry</button>
							</template>
							<template v-else-if="confirmingLineKey === lineKey(line)">
								<button @click="deleteLine(line)" class="sort_confirm_delete">Delete?</button>
								<button @click="confirmingLineKey = null" class="ri_linkbutton">Keep</button>
							</template>
							<img v-else-if="!readonly" src="/img/delete-icon.webp" class="sort_delete" @click="deleteLine(line)" />
						</td>
					</tr>
					<tr v-if="!lines.length">
						<td colspan="6" class="sort_empty">No lines yet - scan a pallet tag, then start entering items.</td>
					</tr>
				</tbody>
			</table></div>

			<!-- running totals -->
			<div class="sort_totals" v-if="lines.length">
				<span>Usable: <strong>{{ totals.usable }}</strong></span>
				<span>Outdated: <strong>{{ totals.outdated }}</strong></span>
				<span>Trash: <strong>{{ totals.trashed }}</strong></span>
				<span>Diverted: <strong>{{ totals.diverted }}</strong></span>
				<span>Discard rate: <strong>{{ totals.trashPct }}%</strong></span>
			</div>
		</div>

		<!-- ======================= CAMERA SCAN MODAL ======================= -->
		<div v-if="showScanner" class="sort_modal_overlay" @click.self="showScanner = false">
			<div class="sort_modal">
				<h3>Scan Pallet Tag</h3>
				<QrScanner @scanned="onScanned" />
				<button @click="showScanner = false" class="ri_formbutton">Cancel</button>
			</div>
		</div>

		<!-- ======================= QUICK-ADD ITEM MODAL ======================= -->
		<div v-if="showItemModal" class="sort_modal_overlay" @click.self="closeItemModal">
			<div class="sort_modal">
				<h3>Add New Item</h3>
				<p v-if="itemModalError" class="sort_error">{{ itemModalError }}</p>
				<template v-if="itemModal.scan">
					<p class="sort_upc_note">{{ scanNote() }}</p>
					<img v-if="itemModal.scan.suggestion?.image_url" :src="itemModal.scan.suggestion.image_url" alt="" class="sort_upc_image" />
					<div class="sort_field">
						<label>This barcode is on:</label>
						<label class="sort_upc_radio"><input type="radio" value="each" v-model="itemModal.kind" /> One item</label>
						<label class="sort_upc_radio"><input type="radio" value="carton" v-model="itemModal.kind" /> A case / multipack</label>
					</div>
					<div class="sort_field" v-if="itemModal.kind === 'carton'">
						<label>Items per case:</label>
						<input type="number" min="2" v-model="itemModal.case_qty" class="ri_forminput sort_qty" />
					</div>
				</template>

				<div class="sort_field">
					<label>Description:</label>
					<input type="text" v-model="itemModal.description" class="ri_forminput" autofocus />
				</div>
				<div class="sort_field">
					<label>UPC (optional):</label>
					<input type="text" v-model="itemModal.upc" class="ri_forminput" placeholder="Scan barcode" />
				</div>

				<div class="sort_field" v-if="!itemModal.creatingType">
					<label>Item Type:</label>
					<SearchSelect
						v-model="itemModal.itemtype_id"
						optionsource="/json/itemtypes/noitems"
						display="name"
						:searchfields="['name', 'display_number']"
						placeholder="Search item types..."
						:allowcreate="true"
						@create="startNewType"
					/>
				</div>
				<template v-else>
					<div class="sort_field">
						<label>New Item Type:</label>
						<input type="text" v-model="itemModal.newTypeName" class="ri_forminput" placeholder="Type name" />
						<button @click="itemModal.creatingType = false" class="ri_formbutton">Pick existing instead</button>
					</div>
					<div class="sort_field">
						<label>Measured By:</label>
						<SearchSelect
							v-model="itemModal.unit_id"
							optionsource="/json/units"
							display="name"
							placeholder="Unit (e.g. Each, Pound)..."
						/>
					</div>
					<div class="sort_field" v-if="!itemModal.creatingCategory">
						<label>Category:</label>
						<SearchSelect
							v-model="itemModal.category_id"
							optionsource="/json/categories"
							display="name"
							placeholder="Search categories..."
							:allowcreate="true"
							@create="startNewCategory"
						/>
					</div>
					<div class="sort_field" v-else>
						<label>New Category:</label>
						<input type="text" v-model="itemModal.newCategoryName" class="ri_forminput" placeholder="Category name" />
						<button @click="itemModal.creatingCategory = false" class="ri_formbutton">Pick existing instead</button>
					</div>
				</template>

				<div class="sort_modal_buttons">
					<button @click="saveItemModal" class="ri_defaultbutton" :disabled="itemModalSaving">
						{{ itemModalSaving ? 'Saving...' : 'Save Item' }}
					</button>
					<button @click="closeItemModal" class="ri_formbutton">Cancel</button>
				</div>
			</div>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.sort_container {
	max-width: 1024px;
	margin: 0 auto;
	padding: 16px;
}
.sort_section {
	margin-top: 1.5em;
}
.sort_rowlink {
	cursor: pointer;
}
.sort_rowlink:hover {
	background-color: #eef2ff;
}
.sort_topbar {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}
.sort_title {
	font-weight: bold;
	font-size: 1.15rem;
	flex: 1;
}
.sort_savestate {
	font-size: 0.85rem;
	color: #666;
}
.sort_saved {
	color: #15803d;
}
.sort_header {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}
.sort_field {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
	flex-wrap: wrap;
}
.sort_field label {
	font-weight: bold;
	min-width: 8em;
}
.sort_pallet {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
	padding: 10px 14px;
	border: 2px dashed #f59e0b;
	border-radius: 8px;
	background: #fffbeb;
	margin-bottom: 12px;
}
.sort_pallet_active {
	border: 2px solid #16a34a;
	background: #f0fdf4;
}
.sort_pallet_label {
	font-size: 1.05rem;
}
.sort_pallet_hint {
	font-size: 0.8rem;
	color: #92400e;
}
.sort_pallet_contents {
	font-weight: bold;
	color: #14532d;
}
.sort_entry {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
	padding: 10px;
	border: 1px solid #ddd;
	border-radius: 8px;
	background: #f9f9f9;
	margin-bottom: 12px;
}
.sort_entry_item {
	flex: 1;
	min-width: 240px;
}
.sort_qty {
	width: 6em;
}
.sort_dispositions {
	display: flex;
	gap: 4px;
}
.sort_disp {
	padding: 8px 12px;
	border: 1px solid #ccc;
	border-radius: 6px;
	background: #fff;
	cursor: pointer;
	font-size: 0.9rem;
	opacity: 0.55;
}
.sort_disp_on {
	opacity: 1;
	font-weight: bold;
	border-width: 2px;
}
.sort_disp_new.sort_disp_on, .sort_badge.sort_disp_new {
	background: #ccfbf1;
	border-color: #0d9488;
	color: #134e4a;
}
.sort_disp_usable.sort_disp_on, .sort_badge.sort_disp_usable {
	background: #dcfce7;
	border-color: #16a34a;
	color: #14532d;
}
.sort_disp_outdated.sort_disp_on, .sort_badge.sort_disp_outdated {
	background: #ffedd5;
	border-color: #ea580c;
	color: #7c2d12;
}
.sort_disp_trashed.sort_disp_on, .sort_badge.sort_disp_trashed {
	background: #fee2e2;
	border-color: #dc2626;
	color: #7f1d1d;
}
.sort_disp_diverted.sort_disp_on, .sort_badge.sort_disp_diverted {
	background: #e0e7ff;
	border-color: #4f46e5;
	color: #312e81;
}
.sort_badge {
	padding: 2px 8px;
	border-radius: 10px;
	border: 1px solid;
	font-size: 0.8rem;
}
.sort_lines {
	width: 100%;
}
.sort_line_failed {
	background: #fef2f2;
}
.sort_line_actions {
	white-space: nowrap;
}
.sort_delete {
	width: 1.1em;
	cursor: pointer;
}
.sort_empty {
	text-align: center;
	color: #777;
	padding: 1em;
}
.sort_totals {
	display: flex;
	gap: 20px;
	flex-wrap: wrap;
	padding: 10px 14px;
	background: #f3f4f6;
	border-radius: 8px;
	margin-top: 10px;
}
.sort_error {
	color: #b91c1c;
	margin: 6px 0;
}
.sort_error_inline {
	color: #b91c1c;
	font-weight: bold;
}
.sort_notice {
	color: #15803d;
	margin: 6px 0;
}
.sort_completed_badge {
	background: #e5e7eb;
	color: #374151;
	font-size: 0.75rem;
	font-weight: bold;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	padding: 2px 8px;
	border-radius: 10px;
	vertical-align: middle;
}
.sort_donor_src {
	color: #888;
	font-size: 0.85em;
}
.sort_checkbox {
	display: flex;
	align-items: center;
	gap: 0.4em;
	font-weight: normal;
}
.sort_badge_id {
	display: inline-block;
	margin-left: 0.5em;
	padding: 0.1em 0.5em;
	font-size: 0.8em;
	background: #fee2e2;
	color: #991b1b;
	border-radius: 3px;
}
.sort_confirm_delete {
	background: #dc2626;
	color: white;
	border: none;
	border-radius: 5px;
	padding: 4px 10px;
	font-size: 0.85rem;
	cursor: pointer;
}
.sort_confirm_delete:hover {
	background: #b91c1c;
}
.sort_tablewrap {
	overflow-x: auto;
}
.sort_modal_overlay {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.45);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 50;
}
.sort_modal {
	background: #fff;
	border-radius: 10px;
	padding: 20px;
	width: min(560px, 94vw);
	max-height: 90vh;
	overflow-y: auto;
}
.sort_modal h3 {
	font-weight: bold;
	font-size: 1.1rem;
	margin-bottom: 12px;
}
.sort_upc_note {
	color: #374151;
	margin: 6px 0;
}
.sort_upc_image {
	max-height: 90px;
	margin: 4px 0 8px;
	border-radius: 6px;
}
.sort_upc_radio {
	font-weight: normal !important;
	min-width: 0 !important;
}
.sort_modal_buttons {
	display: flex;
	gap: 10px;
	margin-top: 14px;
}
</style>
