<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
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
import HelpLink from '@/Components/HelpLink.vue';
import { Head, router } from '@inertiajs/vue3';
import SearchSelect, { invalidateOptions } from '@/Components/SearchSelect.vue';
import ScanButton from '@/Components/ScanButton.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import Modal from '@/Components/Modal.vue';
import NewItemModal from '@/Components/NewItemModal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import axios from 'axios';
import { playScanCarton, playScanError, playScanMatched, playScanUnknown, scanSoundsEnabled, setScanSoundsEnabled } from '@/scanSounds';

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
	components: { AuthenticatedLayout, HelpLink, Head, SearchSelect, ScanButton, TextArea, TextInput, Modal, NewItemModal, InputLabel, PrimaryButton, SecondaryButton },
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
			confirmingConsolidate: false,
			consolidating: false,

			// donor quick-add
			creatingDonor: false,
			newDonor: { first_name: '', last_name: '', organization: '' },
			donorSaving: false,
			donorError: null,

			// pallet context
			palletTagInput: '',
			pallet: null,
			palletError: null,
			emptyingPallet: false,

			// line entry
			entry: { item_id: null, qty: null, choice: 'usable' },
			expOpen: false, // the expiration-date popover next to Qty
			lineError: null,
			nextTempId: -1,
			confirmingLineKey: null,
			retryTimer: null,

			// quick-add item modal
			showItemModal: false,
			itemModalInit: {}, // what the NewItemModal opens with: { description, upc } or { scan }

			// barcode scan -> item (confirmed mappings first, online suggestion on a miss)
			upcResolving: false,
			qtyBurst: { startValue: '', digits: '', lastT: 0 }, // a scanner's keystroke burst in the Qty box
			cartonDialog: null, // an unknown carton barcode being mapped to the item inside it
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
		// How many saved lines "Combine duplicate lines" would remove (an estimate:
		// the server also keeps different people and different days apart).
		mergeableCount() {
			const saved = this.lines.filter((line) => line.id != null && line.status === 'saved' && line.qty_added > 0);
			const groups = new Set(saved.map((line) =>
				[line.item_id, line.pallet_id ?? 0, line.disposition, line.item_condition ?? '', line.expires_on ?? ''].join('|')));
			return saved.length - groups.size;
		},
		// Something would be lost by leaving: an item picked but not added, or lines still saving / failed.
		hasUnsavedWork() {
			return this.view === 'session' && !this.readonly && (!!this.entry.item_id || this.pendingCount > 0);
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
			this.expOpen = false;
			this.entry = { item_id: null, qty: null, choice: 'usable' };
			this.view = 'session';
			this.$nextTick(() => this.$refs.palletInput?.focus());
		},
		// The "All Sessions" button: back to the list without losing the item being entered.
		// This is an in-page view switch (no router visit), so the navigation guards never see it.
		async leaveSession() {
			if (!this.readonly) {
				if (!(await this.flushPendingWork())) return;
				if (this.pendingCount > 0 && !window.confirm(
					this.pendingCount + ' line(s) have not finished saving and will be lost. Leave anyway?')) return;
			}
			await this.closeSession();
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
		// An item picked but not yet added (quantity still in the box) is real work that
		// Complete must not drop: add it, then wait for in-flight saves to land.
		async flushPendingWork() {
			if (this.entry.item_id) {
				const raw = String(this.entry.qty ?? '').trim();
				if (!/^\d{1,7}$/.test(raw) || parseInt(raw, 10) < 1) {
					this.lineError = 'Enter a quantity for the selected item (or clear it) before completing.';
					this.$refs.qtyInput?.focus();
					return false;
				}
				this.addLine();
			}
			const deadline = Date.now() + 10000;
			while (this.lines.some((line) => line.status === 'saving') && Date.now() < deadline) {
				await new Promise((resolve) => setTimeout(resolve, 100));
			}
			return true;
		},
		async completeSession() {
			if (!(await this.flushPendingWork())) return;
			// Two-step inline confirm when lines still haven't reached the server (failed saves).
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
		// ---------- leaving the page with unsaved work ----------
		leaveMessage() {
			return 'This sorting session has an item that is not saved yet (or lines still saving). Leave anyway?';
		},
		// Menu / link navigation (Inertia visits): returning false cancels the visit.
		onInertiaBefore() {
			if (this.hasUnsavedWork && !window.confirm(this.leaveMessage())) return false;
		},
		// Refresh, close tab, typed URL.
		onBeforeUnload(event) {
			if (!this.hasUnsavedWork) return;
			event.preventDefault();
			event.returnValue = '';
		},
		// Browser Back/Forward inside the app: Inertia can't veto these, but the Navigation API
		// (Chromium) lets us cancel the traversal. Browsers without it just leave without a prompt.
		onNavigate(event) {
			if (!this.hasUnsavedWork || !event.cancelable || event.hashChange || event.downloadRequest) return;
			if (event.navigationType === 'traverse' && event.destination?.sameDocument && !window.confirm(this.leaveMessage())) {
				event.preventDefault();
			}
		},
		// Keep the scanner's target ready: after any click on the page (a disposition
		// button, blank space...) put focus back where the next scan should go -
		// the Qty box if an item is already chosen (a scan there saves it as 1 and
		// moves on), otherwise the item box. Never steals focus from a field the
		// sorter is typing in (Qty, comments, pallet tag, donor search...), nor
		// while a modal is open.
		refocusScanTarget() {
			if (this.view !== 'session' || this.readonly || this.showItemModal || this.cartonDialog) return;
			setTimeout(() => {
				const el = document.activeElement;
				if (el && el !== document.body && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))) return;
				if (this.entry.item_id) this.$refs.qtyInput?.focus();
				else this.$refs.itemSelect?.focus();
			}, 0);
		},
		// Sorters may scan every unit; this folds identical lines (same item, pallet,
		// disposition and condition) into one with the summed quantity. Not undoable,
		// so it takes a second click.
		async consolidateLines() {
			if (!this.confirmingConsolidate) {
				this.confirmingConsolidate = true;
				return;
			}
			this.confirmingConsolidate = false;
			this.consolidating = true;
			this.headerError = null;
			try {
				const response = await axios.post('/json/sorting-sessions/' + this.session.id + '/consolidate');
				const unsaved = this.lines.filter((line) => line.id == null);
				this.lines = [...unsaved, ...(response.data.record.item_ledgers || []).map((line) => ({ ...line, status: 'saved' }))];
				const merged = response.data.merged;
				this.notice = merged
					? 'Combined ' + merged + ' duplicate line' + (merged === 1 ? '' : 's') + '.'
					: 'No duplicate lines to combine.';
			} catch (error) {
				this.headerError = error.response?.data?.message || 'Could not combine the lines. Try again.';
			} finally {
				this.consolidating = false;
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
		// Where the entry-row camera scan goes: the carton dialog's box if it is open, else Qty when an item is waiting
		// on a quantity (a scan there saves it and moves on), else the item box - same choice as refocusScanTarget.
		itemScanTarget() {
			if (this.cartonDialog) return this.$refs.cartonItemSelect;
			if (this.entry.item_id && this.$refs.qtyInput) return this.$refs.qtyInput;
			return this.$refs.itemSelect;
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
		// An item was picked (scanned or searched): quantity defaults to 1 and is selected, so
		// "Enter" saves one, "3" + Enter saves three, and scanning the next item saves this one as 1.
		// A carton scan then overrides it with the carton's count (prefillQty again).
		itemChosen() {
			this.prefillQty(1);
		},
		setDisposition(key) {
			this.entry.choice = key;
		},
		// Enter in the Qty box. A long all-digit entry there is a barcode scanned
		// instead of a quantity: save the current item (qty 1, or its case
		// quantity if the scan had prefilled one) and handle the code as the
		// next item scan - so "scan, scan, scan" logs one of each, while
		// "scan, type a qty, Enter" still works. Read the raw field text:
		// v-model on a number input would drop a UPC's leading zero.
		onQtyEnter() {
			const raw = String(this.$refs.qtyInput?.value ?? '').trim();
			const burst = this.qtyBurst;
			this.qtyBurst = { startValue: '', digits: '', lastT: 0 };

			// What was scanned, and what the quantity was before the scan typed over/after it.
			let scanned = null;
			let before = '';
			if (burst.digits.length >= 8 && raw.endsWith(burst.digits)) {
				scanned = burst.digits;
				before = burst.startValue.trim();
			} else if (/^\d{8,}$/.test(raw)) {
				scanned = raw; // arrived without keystrokes (paste): nothing to recover the old quantity from
			}
			if (!scanned) {
				this.addLine();
				return;
			}

			const previous = /^\d{1,7}$/.test(before) && parseInt(before, 10) >= 1 ? parseInt(before, 10) : (this.entry.defaultQty || 1);
			this.lineError = null;

			// The same item again: count it (one more, or one more carton) instead of saving.
			if (this.entry.item_id && this.entry.scanCode && this.normalize14(scanned) === this.entry.scanCode) {
				this.prefillQty(previous + (this.entry.scanUnit || 1));
				playScanMatched();
				return;
			}

			// A different barcode: save the item being counted with its running quantity, then look this one up.
			if (this.entry.item_id) {
				this.entry.qty = previous;
				this.addLine();
			} else {
				this.entry.qty = null;
			}
			this.onBarcodeScan(scanned);
		},
		// Tracks a scanner's burst of digits in the Qty box. A scan types over (or after) the number
		// that was there, so remember the field's value when the burst starts - that is the running
		// quantity - and the digits that follow it.
		onQtyKeydown(event) {
			if (event.ctrlKey || event.metaKey || event.altKey) return;
			if (/^\d$/.test(event.key)) {
				const now = performance.now();
				if (this.qtyBurst.digits === '' || now - this.qtyBurst.lastT > 80) {
					this.qtyBurst = { startValue: event.target.value, digits: '', lastT: now };
				}
				this.qtyBurst.digits += event.key;
				this.qtyBurst.lastT = now;
			} else if (event.key !== 'Enter' && event.key !== 'Shift') {
				this.qtyBurst = { startValue: '', digits: '', lastT: 0 };
			}
		},
		// Barcodes compare as 14 digits, so UPC-A, EAN-13 and GTIN-14 spellings of one code match.
		normalize14(code) {
			return String(code).padStart(14, '0');
		},
		toggleExpiry() {
			this.expOpen = !this.expOpen;
			if (this.expOpen) this.$nextTick(() => this.$refs.expInput?.focus());
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
				expires_on: this.entry.expires_on || null,
				pallet_id: this.pallet ? this.pallet.id : null,
				pallet: this.pallet,
				created_at: new Date().toISOString(),
				status: 'saving',
			};
			this.lines.push(line);
			this.saveLine(line);

			// reset for the next scan; keep the disposition (runs of similar
			// goods are common) and, of course, the pallet context
			this.expOpen = false;
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
					expires_on: line.expires_on ?? null,
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
				// online=0: our own catalog only, so a miss is known (and heard) at once;
				// the slower online suggestion is fetched separately below.
				const response = await axios.get('/json/upc/resolve', { params: { code: text, online: 0 } });
				const result = response.data;
				if (result.status === 'matched') {
					await this.applyScanMatch(result);
					if (result.kind === 'carton') playScanCarton();
					else playScanMatched();
				} else if (result.kind === 'carton') {
					playScanUnknown();
					this.openCartonDialog(result);
				} else {
					playScanUnknown();
					this.openItemModalForScan(result);
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
		onScanMatchedLocally(item, typed) {
			this.entry.scanCode = this.normalize14(typed);
			this.entry.scanUnit = (item.case_qty ?? 0) > 1 ? Math.round(item.case_qty * (parseFloat(item.size) || 1)) : 1;
			if ((item.case_qty ?? 0) > 1) {
				this.prefillQty(Math.round(item.case_qty * (parseFloat(item.size) || 1)));
				playScanCarton();
			} else {
				playScanMatched();
			}
		},
		// A scan filled in the quantity (a carton's count). Select it again once the field
		// has the new text - writing the value clears the selection, and without it the
		// next scan's digits would be appended to it ("30" + the barcode) instead of
		// replacing it. defaultQty is what "scan the next item" saves this line with.
		prefillQty(qty) {
			this.entry.qty = this.entry.defaultQty = qty;
			this.$nextTick(() => {
				const field = this.$refs.qtyInput;
				if (field) {
					field.focus();
					field.select();
				}
			});
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
			if (result.qty) this.prefillQty(result.qty);
			// remember which scan picked this item, so scanning it again just counts up
			this.entry.scanCode = result.code;
			this.entry.scanUnit = result.qty || 1;
		},
		// show the barcode the way it is printed: UPC-A / EAN-13 without the GTIN-14 padding zeros
		displayCode(code) {
			return code.startsWith('00') ? code.slice(2) : code.startsWith('0') ? code.slice(1) : code;
		},

		// ---------- quick-add item (NewItemModal) ----------
		openItemModal(searchText) {
			// A scanned barcode with no match lands here as all-digit text — that's
			// the new item's UPC, not its description.
			const text = (searchText || '').trim();
			this.itemModalInit = /^\d{6,14}$/.test(text) ? { upc: text } : { description: text };
			this.showItemModal = true;
		},
		// An unknown barcode opens the normal Add New Item screen, prefilled: the
		// scanned UPC and whatever an online lookup suggests (the sorter checks it).
		// Saving adds a SKU row to the master list.
		openItemModalForScan(result) {
			this.itemModalInit = { scan: { code: result.code, online: result.online, suggestion: result.suggestion } };
			this.showItemModal = true;
		},
		closeItemModal(fromScan) {
			this.showItemModal = false;
			if (this.cartonDialog) {
				this.$nextTick(() => this.$refs.cartonItemSelect?.focus()); // back to the carton dialog underneath
			} else if (fromScan) {
				this.$refs.itemSelect?.clearSelection(); // drop the unresolved barcode text
			}
		},
		// "This barcode is on a case of items already in the list": map it in the carton dialog instead.
		switchToCarton(code) {
			this.showItemModal = false;
			this.openCartonDialog({ code, guess_item: null, candidates: [] });
		},
		async onItemModalSaved(newItem, scan) {
			this.showItemModal = false;
			if (this.cartonDialog) {
				// added from the carton dialog: it is the item inside the carton
				await this.$refs.cartonItemSelect?.refresh(newItem.id);
				this.$refs.cartonPerPack?.focus();
				return;
			}
			await this.$refs.itemSelect?.refresh(newItem.id);
			if (scan) {
				this.entry.scanCode = scan.code;
				this.entry.scanUnit = 1;
			}
			this.$refs.qtyInput?.focus();
		},

		// ---------- carton barcode -> the item inside ----------
		// A carton is not a new product: its barcode is mapped to the single-unit item row
		// it holds, plus how many units a carton has (packs x items per pack). Scanning that
		// carton later selects the item with the quantity filled in.
		openCartonDialog(result) {
			this.cartonDialog = {
				code: result.code,
				guessItem: result.guess_item || null,
				candidates: result.candidates || [],
				item_id: result.guess_item?.id ?? null,
				packs: '1',
				per_pack: '',
				error: null,
				saving: false,
			};
			this.$nextTick(() => {
				if (this.cartonDialog.item_id) this.$refs.cartonPerPack?.focus();
				else this.$refs.cartonItemSelect?.focus();
			});
		},
		cartonTotal() {
			const d = this.cartonDialog;
			return (parseInt(d.packs, 10) || 0) * (parseInt(d.per_pack, 10) || 0);
		},
		// A single item scanned inside the carton dialog: select the matching row, or add it.
		async onCartonItemScan(text) {
			const d = this.cartonDialog;
			d.error = null;
			try {
				const result = (await axios.get('/json/upc/resolve', { params: { code: text, online: 0 } })).data;
				if (result.status === 'matched') {
					const select = this.$refs.cartonItemSelect;
					const known = select.optionlist.find((option) => option.id === result.item.id);
					if (known) select.selectOption(known);
					else await select.refresh(result.item.id);
					playScanMatched();
					this.$refs.cartonPerPack?.focus();
				} else {
					playScanUnknown();
					this.openItemModalForScan(result);
				}
			} catch (error) {
				playScanError();
				d.error = error.response?.data?.message || 'Could not look up that barcode.';
				this.$refs.cartonItemSelect?.clearSelection();
			}
		},
		async saveCartonDialog() {
			const d = this.cartonDialog;
			d.error = null;
			if (!d.item_id) {
				d.error = 'Choose (or scan) the item that is inside the carton.';
				return;
			}
			if (this.cartonTotal() < 1) {
				d.error = 'Enter how many packs are in the carton and how many items are in each pack.';
				return;
			}
			d.saving = true;
			try {
				const response = await axios.post('/json/upc/cartons', {
					code: d.code,
					item_id: d.item_id,
					inner_packs: parseInt(d.packs, 10),
					items_per_pack: parseInt(d.per_pack, 10),
				});
				this.cartonDialog = null;
				await this.applyScanMatch(response.data);
				playScanCarton();
			} catch (error) {
				d.error = error.response?.data?.message
					|| Object.values(error.response?.data?.errors || {}).flat().join(' ')
					|| 'Could not save the carton.';
			} finally {
				d.saving = false;
			}
		},
		cancelCartonDialog() {
			this.cartonDialog = null;
			this.$refs.itemSelect?.clearSelection();
		},
		// "This is actually a single retail item": add it as a new item row instead.
		cartonIsSingle() {
			const code = this.cartonDialog.code;
			this.cartonDialog = null;
			this.openItemModalForScan({ code, kind: 'each', online: 'pending', suggestion: null });
		},
	},
	created() {
		this.fetchSessions();
	},
	mounted() {
		this.removeInertiaGuard = router.on('before', this.onInertiaBefore);
		window.addEventListener('beforeunload', this.onBeforeUnload);
		window.navigation?.addEventListener('navigate', this.onNavigate);
		window.addEventListener('click', this.refocusScanTarget);
		window.addEventListener('online', this.autoRetry);
		this.retryTimer = setInterval(this.autoRetry, 30000);
	},
	unmounted() {
		this.removeInertiaGuard?.();
		window.removeEventListener('beforeunload', this.onBeforeUnload);
		window.navigation?.removeEventListener('navigate', this.onNavigate);
		window.removeEventListener('click', this.refocusScanTarget);
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
				<HelpLink guide="sorting">Donation Sorting Guide</HelpLink>
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
				<button @click="leaveSession" class="ri_formbutton">&larr; All Sessions</button>
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
					<button v-if="confirmingConsolidate" @click="confirmingConsolidate = false" class="ri_linkbutton">Cancel</button>
					<button v-if="mergeableCount > 0 || confirmingConsolidate" @click="consolidateLines"
						:disabled="consolidating || pendingCount > 0"
						:class="confirmingConsolidate ? 'ri_deletebutton' : 'ri_formbutton'"
						title="Add up identical lines (same item, pallet, disposition and condition) into one">
						{{ confirmingConsolidate
							? 'Combine ' + mergeableCount + ' duplicate line(s)? Cannot be undone'
							: 'Combine duplicate lines' }}
					</button>
					<button v-if="confirmingComplete" @click="confirmingComplete = false" class="ri_linkbutton">Keep Sorting</button>
					<button @click="completeSession" :class="confirmingComplete ? 'ri_deletebutton' : 'ri_defaultbutton'">
						{{ confirmingComplete
							? 'Complete with ' + pendingCount + ' unsaved line(s)' + (failedLines.length ? ' (' + failedLines.length + ' failed)' : '') + '?'
							: 'Complete Session' }}
					</button>
					<button type="button" @click="toggleScanSounds" class="sort_sound_toggle"
						:aria-label="scanSounds ? 'Scan sounds on' : 'Scan sounds off'"
						:title="scanSounds ? 'Scan sounds on (beep = matched, low tone = not found). Click to mute.' : 'Scan sounds off. Click to turn on.'">
						{{ scanSounds ? '🔊' : '🔇' }}
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
				<div class="sort_field sort_field_stacked sort_header_donor">
					<label>Donor / Source:</label>
					<div v-if="readonly || (fromReceiving && !editingDonor)" class="sort_donor_read">
						<span>
							{{ personLabel(session.person) }}
							<em v-if="fromReceiving" class="sort_donor_src">(recorded at receiving)</em>
						</span>
						<button v-if="!readonly" @click="editingDonor = true" class="ri_linkbutton">Edit</button>
					</div>
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
				<div class="sort_field sort_field_stacked sort_header_comments">
					<label>Comments:</label>
					<TextArea v-model="session.comments" :enabled="!readonly" @change="commentsChanged" />
				</div>
				<div class="sort_field sort_header_check">
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
					<span class="sort_scangroup">
						<input
							ref="palletInput"
							type="text"
							v-model="palletTagInput"
							class="ri_forminput"
							placeholder="Scan or type pallet tag (e.g. R00000042)"
							@keydown.enter.prevent="resolvePallet"
						/>
						<ScanButton :target="() => $refs.palletInput" compact />
					</span>
					<button @click="resolvePallet" class="ri_formbutton">Set Pallet</button>
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
					<div class="sort_scangroup">
					<SearchSelect
						ref="itemSelect"
						v-model="entry.item_id"
						optionsource="/json/items"
						display="name"
						:searchfields="['name', 'upc', 'description']"
						placeholder="Scan UPC or search items..."
						:allowcreate="true"
						:scanDigits="true"
						:smartOpen="true"
						:debounce="150"
						:openOnFocus="false"
						@selected="(item) => { entry.item = item; entry.defaultQty = null; entry.scanCode = null; entry.scanUnit = 1; if (item) itemChosen(); }"
						@create="openItemModal"
						@scan="onBarcodeScan"
						@scanmatch="onScanMatchedLocally"
					/>
					<ScanButton :target="itemScanTarget" compact />
					</div>
					<span v-if="upcResolving" class="sort_notice">Looking up barcode...</span>
				</div>
				<!-- type=text on purpose: a number input's v-model rewrites "012..." to 12...,
				     which drops a scanned UPC's leading zero when a scan lands here -->
				<input
					ref="qtyInput"
					type="text"
					inputmode="numeric"
					autocomplete="off"
					v-model="entry.qty"
					class="ri_forminput sort_qty"
					placeholder="Qty"
					title="Type a quantity and press Enter - or scan the next barcode to save this item as 1 and move on"
					@focus="$event.target.select()"
					@keydown="onQtyKeydown"
					@keydown.enter.prevent="onQtyEnter"
				/>
				<span class="sort_exp">
					<button
						type="button"
						class="sort_exp_btn"
						:class="{ sort_exp_set: entry.expires_on }"
						:title="entry.expires_on ? 'Expires ' + entry.expires_on + ' - click to change' : 'Add an expiration date (optional)'"
						@click="toggleExpiry"
					>&#128197;<span v-if="entry.expires_on" class="sort_exp_date">{{ entry.expires_on }}</span></button>
					<div v-if="expOpen" class="sort_exp_pop">
						<label class="sort_exp_label">
							<span class="sort_exp_sub">Expiration Date</span>
							<input
								ref="expInput"
								type="date"
								class="ri_forminput"
								v-model="entry.expires_on"
								@keydown.enter.prevent="expOpen = false"
								@keydown.esc.prevent="expOpen = false"
							/>
						</label>
						<div class="sort_exp_actions">
							<button type="button" class="ri_formbutton" @click="entry.expires_on = null; expOpen = false">Clear</button>
							<button type="button" class="ri_defaultbutton" @click="expOpen = false">Done</button>
						</div>
					</div>
				</span>
				<select
					v-model="entry.choice"
					class="ri_forminput sort_disp_select"
					:class="'sort_disp_' + entry.choice"
					:title="dispositions.find((d) => d.key === entry.choice)?.hint"
					aria-label="Condition / disposition"
				>
					<option v-for="d in dispositions" :key="d.key" :value="d.key" :title="d.hint">{{ d.label }}</option>
				</select>
				<button @click="addLine" class="ri_defaultbutton">Add</button>
			</div>
			<p v-if="lineError" class="sort_error">{{ lineError }}</p>

			<!-- entered lines -->
			<div class="sort_tablewrap"><table class="ri_datatable sort_lines" border="1">
				<thead>
					<tr>
						<th>ACS #</th><th>Item</th><th>Qty</th><th>Expires</th><th>Disposition</th><th>Pallet</th><th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="line in sortedLines" :key="line.id ?? line.tempId"
						:class="{ sort_line_failed: line.status === 'failed' }">
						<td :title="lineTime(line)">{{ line.item?.itemtype?.display_number || '' }}</td>
						<td>{{ line.item ? (line.item.itemtype ? (line.item.description || line.item.name) : (line.item.name || line.item.description)) : '(item #' + line.item_id + ')' }}</td>
						<td>{{ line.qty_added }}</td>
						<td>{{ line.expires_on || '' }}</td>
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
						<td colspan="7" class="sort_empty">No lines yet - scan a pallet tag, then start entering items.</td>
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

		<!-- ======================= UNKNOWN CARTON MODAL ======================= -->
		<div v-if="cartonDialog" class="sort_modal_overlay">
			<div class="sort_modal">
				<h3>New Carton Barcode {{ displayCode(cartonDialog.code) }}</h3>
				<p v-if="cartonDialog.error" class="sort_error">{{ cartonDialog.error }}</p>
				<p class="sort_upc_note" v-if="cartonDialog.guessItem">
					This carton's number matches <strong>{{ cartonDialog.guessItem.name }}</strong>. Check that, then enter how many are inside.
				</p>
				<p class="sort_upc_note" v-else>
					We haven't seen this carton before. <strong>Scan one of the single items inside it</strong> (or search for it) so every future scan of this carton counts the items for you.
				</p>

				<div class="sort_field sort_field_stacked">
					<label>Item inside the carton:</label>
					<SearchSelect
						ref="cartonItemSelect"
						v-model="cartonDialog.item_id"
						optionsource="/json/items"
						display="name"
						:searchfields="['name', 'upc', 'description']"
						placeholder="Scan a single item, or search..."
						:allowcreate="true"
						:scanDigits="true"
						:smartOpen="true"
						:debounce="150"
						:openOnFocus="false"
						@scan="onCartonItemScan"
						@create="openItemModal"
					/>
				</div>
				<div class="sort_field" v-if="cartonDialog.candidates.length && !cartonDialog.item_id">
					<label>Same manufacturer:</label>
					<button v-for="candidate in cartonDialog.candidates" :key="candidate.id" type="button"
						class="ri_formbutton" @click="cartonDialog.item_id = candidate.id">{{ candidate.name }}</button>
				</div>
				<div class="sort_field">
					<label>Packs in the carton:</label>
					<input type="text" inputmode="numeric" v-model="cartonDialog.packs" class="ri_forminput sort_qty" />
					<label>Items in each pack:</label>
					<input ref="cartonPerPack" type="text" inputmode="numeric" v-model="cartonDialog.per_pack" class="ri_forminput sort_qty"
						@keydown.enter.prevent="saveCartonDialog" />
				</div>
				<p class="sort_upc_note">
					= <strong>{{ cartonTotal() }}</strong> items per carton
					<span class="sort_donor_src">(loose items with no inner packs: leave Packs at 1 and put the count in Items in each pack)</span>
				</p>

				<div class="sort_modal_buttons">
					<button @click="saveCartonDialog" class="ri_defaultbutton" :disabled="cartonDialog.saving">
						{{ cartonDialog.saving ? 'Saving...' : 'Confirm & Use' }}
					</button>
					<button @click="cancelCartonDialog" class="ri_formbutton">Cancel</button>
					<button @click="cartonIsSingle" class="ri_linkbutton">This is a single item, not a carton</button>
				</div>
			</div>
		</div>

		<!-- ======================= QUICK-ADD ITEM MODAL ======================= -->
		<NewItemModal v-if="showItemModal" :initial="itemModalInit" :allow-carton="!cartonDialog"
			@saved="onItemModalSaved" @close="closeItemModal" @carton="switchToCarton" />
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
.sort_field_stacked {
	flex-direction: column;
	align-items: stretch; /* caption above the field, field full width */
	gap: 4px;
}
.sort_field_stacked label {
	min-width: 0;
}
.sort_header_donor {
	flex: 0 1 24rem;
	min-width: 16rem;
}
.sort_header_comments {
	flex: 1 1 18rem; /* takes the rest of the row, out to the right margin */
}
.sort_header_check {
	flex-basis: 100%;
}
.sort_donor_read {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: 2.4rem;
}
.sort_sound_toggle {
	font-size: 1.3rem;
	line-height: 1;
	background: none;
	border: none;
	cursor: pointer;
	padding: 2px 4px;
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
.sort_exp {
	position: relative;
	display: inline-block;
}
.sort_exp_btn {
	background: #1d6fd1;
	color: #fff;
	border: 0;
	border-radius: 4px;
	padding: 0.35em 0.6em;
	cursor: pointer;
	font-size: 1em;
}
.sort_exp_btn:hover {
	background: #1558a8;
}
.sort_exp_date {
	margin-left: 0.4em;
	font-size: 0.85em;
}
.sort_exp_pop {
	position: absolute;
	z-index: 20;
	top: 100%;
	left: 0;
	margin-top: 0.25em;
	padding: 0.6em 0.75em;
	background: #fff;
	color: #222;
	border: 1px solid #999;
	border-radius: 6px;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
	white-space: nowrap;
}
.sort_exp_label {
	display: block;
}
.sort_exp_sub {
	display: block;
	font-size: 0.85em;
	margin-bottom: 0.25em;
}
.sort_exp_actions {
	display: flex;
	gap: 0.5em;
	justify-content: flex-end;
	margin-top: 0.5em;
}
.sort_qty {
	width: 6em;
}
.sort_scangroup {
	display: flex;
	align-items: stretch;
	flex-wrap: nowrap;
}
.sort_scangroup > :first-child {
	flex: 1;
	min-width: 0;
}
.sort_scangroup :deep(input) {
	border-top-right-radius: 0;
	border-bottom-right-radius: 0;
}
.sort_disp_select {
	width: auto;
	font-weight: bold;
	border-width: 2px;
}
.sort_disp_select.sort_disp_new { background: #ccfbf1; border-color: #0d9488; color: #134e4a; }
.sort_disp_select.sort_disp_usable { background: #dcfce7; border-color: #16a34a; color: #14532d; }
.sort_disp_select.sort_disp_outdated { background: #ffedd5; border-color: #ea580c; color: #7c2d12; }
.sort_disp_select.sort_disp_trashed { background: #fee2e2; border-color: #dc2626; color: #7f1d1d; }
.sort_disp_select.sort_disp_diverted { background: #e0e7ff; border-color: #4f46e5; color: #312e81; }
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
.sort_modal:focus {
	outline: none; /* focused only to catch stray scans; no visible ring */
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
