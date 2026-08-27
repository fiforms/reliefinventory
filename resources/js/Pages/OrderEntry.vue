<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- OrderEntry.vue

	Order intake, built as a session-based event stream (the same pattern as
	DonationSorting.vue, not an RIForm document). NOTE (2026-08-23): "order"
	is this file's/the backend's name for the thing (component name, DB
	status strings, manage-orders permission) — this page's own on-screen
	labels are being moved to "Request" for partner-facing language (matches
	Statesville's original "warehouse request form" wording; "order" reads
	retail, same reasoning as the Customer->Partner rename below). Not fully
	swept through this file's template text yet — treat "Order"/"Request" as
	the same concept if you find both here.

	  - Step 1 is the partner screen: select (or quick-add) the partner and
	    confirm their contact/shipping details are current. Confirming creates
	    the order header on the server immediately. "Partner" here is the
	    receiving org/distribution-site placing the order — see the
	    2026-08-22 Customer→Partner rename (Tim's naming feedback:
	    "Customer" reads as retail, "Partner" matches how the food-bank/
	    disaster-relief world and Adventist Community Services both
	    describe this tier). Internal variable/prop names (partnerMode,
	    newPartner, etc.) were originally left as "customer*" since they're
	    pure internal identifiers with no user-facing meaning — renamed to
	    match on 2026-08-23 for codebase-wide consistency after all, per
	    Mark's request.
	  - Step 2 is the line-entry screen: a slim one-line partner bar (the
	    details deliberately don't take screen space here), then rapid
	    item-number-first entry — item # or name search, quantity, Enter,
	    next line. Each line saves the moment it is entered; a crash never
	    loses more than the line being typed.
	  - Entering the same item twice pops a Combine-or-Cancel modal instead
	    of silently adding a second line — almost always a re-scan/typo.
	  - An advisory "~N usable on hand" hint shows for the selected item.
	    This is a staff-facing page; partner-facing surfaces must never
	    show actual stock numbers (three-state availability at most).
	  - Step 3 is Review & Confirm (Complete Order): comments, needed-but-
	    not-in-catalog items, delivery-vs-pickup with a preferred date/time
	    window, and a contact person (defaulted from the partner record,
	    editable) — mirrors the fields on the offline order form PDF.
	    Confirming here moves the order from New Order to Ready to Fill,
	    which locks it against further intake edits (existing New-Order-only
	    edit rule) and is the hook a future fill/pick workflow queries
	    against. See the order-fulfillment-lifecycle-design memory for the
	    larger (not yet built) picture this sits inside.
	  - Orders lock once they leave New Order (server-enforced); they open
	    here read-only. Phone orders and hand-entered PDF order forms are
	    the same activity and both use this screen.
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import SearchSelect, { invalidateOptions } from '@/Components/SearchSelect.vue';
import TextArea from '@/Components/TextArea.vue';
import axios from 'axios';

const CONTACT_FIELDS = ['first_name', 'last_name', 'organization', 'phone', 'email', 'address', 'city', 'state', 'zip'];
const WEEK_DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export default {
	components: { AuthenticatedLayout, Head, SearchSelect, TextArea },
	props: {
		breadcrumb: { type: Array },
	},
	data() {
		return {
			// list view
			view: 'list', // 'list' | 'partner' | 'order' | 'review'
			orders: { open: [], recent: [] },
			listLoading: false,
			listError: null,

			// partner screen
			partnerMode: 'new', // 'new' (start an order) | 'change' (repoint an open order)
			partnerId: null,
			partner: null,
			contact: null,           // editable copy of the partner's contact fields
			contactOriginal: null,   // JSON snapshot for the dirty check
			partnerError: null,
			partnerSaving: false,
			creatingPartner: false,
			newPartner: {},

			// active order
			order: null,
			lines: [],
			headerSaving: false,
			headerError: null,

			// review & confirm screen (completing intake) — single-shot form,
			// not autosaved like the line-entry screen; only ever persisted
			// once, via Complete Order.
			review: null,
			reviewError: null,
			weekDays: WEEK_DAYS,

			// line entry
			entry: { itemtype_id: null, itemtype: null, qty: null, comments: '', need_level: null },
			lineError: null,
			nextTempId: -1,
			confirmingLineKey: null,
			duplicateOf: null, // saved line matching the entry's itemtype, pending Combine/Add-anyway
			retryTimer: null,

			// advisory stock hints ({itemtype_id: usable on hand}) — staff-facing only
			stockHints: {},
		};
	},
	computed: {
		// Orders lock once filling starts; they open read-only here.
		readonly() {
			return this.order?.status?.name !== 'New Order';
		},
		failedLines() {
			return this.lines.filter((line) => line.status === 'failed');
		},
		pendingCount() {
			return this.lines.filter((line) => line.status === 'saving' || line.status === 'failed').length;
		},
		sortedLines() {
			// Unsaved lines are the newest — keep them on top instead of
			// letting them jump around when the save lands.
			return [...this.lines].sort((a, b) => {
				const aSaved = a.id != null;
				const bSaved = b.id != null;
				if (aSaved !== bSaved) return aSaved ? 1 : -1;
				return aSaved ? b.id - a.id : a.tempId - b.tempId;
			});
		},
		totals() {
			const counted = this.lines.filter((line) => line.status !== 'failed');
			return {
				lines: counted.length,
				qty: counted.reduce((sum, line) => sum + Number(line.qty_requested || 0), 0),
			};
		},
		contactDirty() {
			return this.contact && JSON.stringify(this.contact) !== this.contactOriginal;
		},
		entryHint() {
			if (!this.entry.itemtype_id) return null;
			const onHand = Number(this.stockHints[this.entry.itemtype_id] ?? 0);
			return onHand > 0 ? '~' + onHand + ' usable on hand' : 'none on hand';
		},
		// Three-state availability (PROJECT_ANALYSIS.md Part 5) drives whether
		// the need-level field shows at all — never on healthy-stock items, so
		// it stays a real signal rather than a reflexive habit.
		entryAvailabilityLow() {
			if (!this.entry.itemtype_id) return false;
			const onHand = Number(this.stockHints[this.entry.itemtype_id] ?? 0);
			const threshold = Number(this.entry.itemtype?.low_stock_threshold ?? 10);
			return onHand <= threshold;
		},
		duplicateQty() {
			return parseInt(this.entry.qty, 10) || 0;
		},
	},
	methods: {
		// ---------- order list ----------
		async fetchOrders() {
			this.listLoading = true;
			this.listError = null;
			try {
				const response = await axios.get('/json/orders');
				this.orders = response.data;
			} catch (error) {
				this.listError = 'Could not load orders.';
			} finally {
				this.listLoading = false;
			}
		},
		async resumeOrder(id) {
			this.listError = null;
			try {
				const response = await axios.get('/json/orders/' + id);
				this.openOrder(response.data.record);
			} catch (error) {
				this.listError = 'Could not open that order.';
			}
		},
		personLabel(person) {
			if (!person) return '(no partner)';
			const name = [person.first_name, person.last_name].filter(Boolean).join(' ');
			return person.organization ? person.organization + (name ? ' - ' + name : '') : name || '(unnamed)';
		},
		// Just the person's name, unlike personLabel — for defaulting the
		// Review screen's delivery/pickup contact, which shouldn't carry the
		// organization prefix.
		contactNameDefault(person) {
			if (!person) return '';
			const name = [person.first_name, person.last_name].filter(Boolean).join(' ');
			return name || person.organization || '';
		},
		orderSummary(record) {
			const lines = record.order_lines || [];
			return {
				lines: lines.length,
				qty: lines.reduce((sum, line) => sum + Number(line.qty_requested || 0), 0),
			};
		},

		// ---------- partner screen ----------
		startNewOrder() {
			this.partnerMode = 'new';
			this.resetPartnerScreen();
			this.view = 'partner';
			this.$nextTick(() => this.$refs.partnerSelect?.focus());
		},
		changePartner() {
			this.partnerMode = 'change';
			this.resetPartnerScreen();
			this.view = 'partner';
			this.$nextTick(() => this.$refs.partnerSelect?.focus());
		},
		resetPartnerScreen() {
			this.partnerId = null;
			this.partner = null;
			this.contact = null;
			this.contactOriginal = null;
			this.partnerError = null;
			this.creatingPartner = false;
		},
		cancelPartnerScreen() {
			this.view = this.partnerMode === 'change' && this.order ? 'order' : 'list';
		},
		partnerSelected(person) {
			this.partner = person;
			this.creatingPartner = false;
			this.partnerError = null;
			if (person) {
				this.contact = Object.fromEntries(CONTACT_FIELDS.map((f) => [f, person[f] ?? '']));
				this.contactOriginal = JSON.stringify(this.contact);
			} else {
				this.contact = null;
			}
		},
		startNewPartner(name) {
			this.creatingPartner = true;
			this.partnerError = null;
			const parts = (name || '').trim().split(/\s+/);
			this.newPartner = {
				first_name: parts[0] || '',
				last_name: parts.slice(1).join(' '),
				organization: parts.length <= 1 ? (name || '') : '',
				phone: '', email: '', address: '', city: '', state: '', zip: '',
			};
		},
		async saveNewPartner() {
			this.partnerError = null;
			const hasName = this.newPartner.first_name.trim() && this.newPartner.last_name.trim();
			const hasOrg = (this.newPartner.organization || '').trim();
			if (!hasName && !hasOrg) {
				// An order/partner contact may only have an organization known
				// (no specific person) — that's fine.
				this.partnerError = 'Enter a name (first and last) or an organization.';
				return;
			}
			this.partnerSaving = true;
			try {
				const response = await axios.post('/json/people', this.newPartner);
				invalidateOptions('/json/people');
				await this.$refs.partnerSelect?.refresh(response.data.record.id);
				this.partnerId = response.data.record.id;
				this.partnerSelected(response.data.record);
			} catch (error) {
				this.partnerError = error.response?.data?.message
					|| Object.values(error.response?.data?.errors || {}).flat().join(' ')
					|| 'Could not save the new partner.';
			} finally {
				this.partnerSaving = false;
			}
		},
		/**
		 * Confirm the partner: push any contact edits to the person record,
		 * then create the order (or repoint the open one). The people update
		 * endpoint replaces roles/permissions from the payload, so the
		 * person's existing ones are sent back unchanged.
		 */
		async confirmPartner() {
			if (!this.partnerId) {
				this.partnerError = 'Choose or add a partner first.';
				return;
			}
			this.partnerError = null;
			this.partnerSaving = true;
			try {
				if (this.contactDirty) {
					await axios.put('/json/people/' + this.partnerId, {
						...this.contact,
						email: this.contact.email || null,
						people_roles: (this.partner.people_roles || []).map((r) => ({ role_id: r.role_id })),
						person_permissions: this.partner.person_permissions || [],
					});
					invalidateOptions('/json/people');
				}
				if (this.partnerMode === 'change' && this.order) {
					const response = await axios.patch('/json/orders/' + this.order.id, { person_id: this.partnerId });
					this.openOrder(response.data.record, this.lines);
				} else {
					const response = await axios.post('/json/orders', { person_id: this.partnerId });
					this.openOrder(response.data.record);
				}
			} catch (error) {
				this.partnerError = error.response?.data?.message
					|| Object.values(error.response?.data?.errors || {}).flat().join(' ')
					|| 'Could not start the order.';
			} finally {
				this.partnerSaving = false;
			}
		},

		// ---------- active order ----------
		openOrder(record, keepLines) {
			this.order = record;
			this.lines = keepLines ?? (record.order_lines || []).map((line) => ({ ...line, status: 'saved' }));
			this.entry = { itemtype_id: null, itemtype: null, qty: null, comments: '', need_level: null };
			this.lineError = null;
			this.headerError = null;
			this.duplicateOf = null;
			this.confirmingLineKey = null;
			this.review = null;
			this.reviewError = null;
			this.view = 'order';
			this.fetchStockHints();
			this.$nextTick(() => this.$refs.itemSelect?.focus());
		},
		async closeOrder() {
			this.view = 'list';
			this.order = null;
			await this.fetchOrders();
		},
		async fetchStockHints() {
			try {
				const response = await axios.get('/json/orders/stock-hints');
				this.stockHints = response.data.hints || {};
			} catch (error) {
				this.stockHints = {}; // advisory only — never block entry on it
			}
		},
		// ---------- review & confirm (completing intake) ----------
		openReview() {
			this.reviewError = null;
			this.review = {
				comments: this.order.comments || '',
				fulfillment_method: this.order.fulfillment_method || 'delivery',
				needed_by_date: this.order.needed_by_date || '',
				// Any Day is the default — represented as all days selected,
				// not an empty array, so the "Any Day" chip starts active.
				delivery_days: this.order.delivery_days || [...this.weekDays],
				preferred_time: this.order.preferred_time || '',
				contact_name: this.order.contact_name || this.contactNameDefault(this.order.person),
				contact_phone: this.order.contact_phone || this.order.person?.phone || '',
				other_needs: this.order.other_needs || '',
				special_instructions: this.order.special_instructions || '',
			};
			this.view = 'review';
		},
		backFromReview() {
			this.view = 'order';
		},
		toggleDeliveryDay(day) {
			const days = this.review.delivery_days;
			const idx = days.indexOf(day);
			if (idx === -1) days.push(day);
			else days.splice(idx, 1);
		},
		selectAllDays() {
			this.review.delivery_days = [...this.weekDays];
		},
		async completeOrder() {
			this.reviewError = null;
			this.headerSaving = true;
			try {
				const response = await axios.patch('/json/orders/' + this.order.id + '/complete', this.review);
				this.order = { ...response.data.record, order_lines: undefined };
				await this.closeOrder();
			} catch (error) {
				this.reviewError = error.response?.data?.message
					|| Object.values(error.response?.data?.errors || {}).flat().join(' ')
					|| 'Could not complete the order.';
			} finally {
				this.headerSaving = false;
			}
		},
		// ---------- line entry (autosaved per line) ----------
		itemChosen(itemtype) {
			this.entry.itemtype = itemtype;
			this.duplicateOf = null;
			if (!itemtype) return;

			// Same item already on the order? Flag it the moment it's picked,
			// before a qty's even been typed — almost always a re-scan/typo of
			// the item #, not an intentional split. Only way past this is
			// Combine (typed right there in the modal) or Cancel (see the
			// duplicate-item modal).
			const existing = this.lines.find(
				(line) => line.itemtype_id === this.entry.itemtype_id && line.status === 'saved'
			);
			if (existing) {
				this.duplicateOf = existing;
				this.entry.qty = null;
				this.$nextTick(() => this.$refs.duplicateQtyInput?.focus());
				return;
			}
			this.$refs.qtyInput?.focus();
		},
		addLine() {
			this.lineError = null;
			this.confirmingLineKey = null;
			if (!this.entry.itemtype_id) {
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
				itemtype_id: this.entry.itemtype_id,
				itemtype: this.entry.itemtype || null,
				qty_requested: qty,
				comments: this.entry.comments || null,
				need_level: this.entryAvailabilityLow ? (this.entry.need_level || null) : null,
				status: 'saving',
			};
			this.lines.push(line);
			this.saveLine(line);
			this.resetEntry();
		},
		async combineDuplicate() {
			if (!this.duplicateQty) return;
			const target = this.duplicateOf;
			const qty = this.duplicateQty;
			this.duplicateOf = null;
			try {
				const response = await axios.put(
					'/json/orders/' + this.order.id + '/lines/' + target.id,
					{ qty_requested: Number(target.qty_requested) + qty }
				);
				const idx = this.lines.findIndex((line) => line.id === target.id);
				if (idx !== -1) this.lines.splice(idx, 1, { ...response.data.record, status: 'saved' });
				this.resetEntry();
			} catch (error) {
				this.lineError = 'Could not combine with the existing line.';
			}
		},
		cancelDuplicate() {
			this.duplicateOf = null;
			this.resetEntry();
		},
		onDuplicateKeydown(event) {
			if (event.key === 'Escape') this.cancelDuplicate();
		},
		// Trap Tab inside the modal — the page behind it is still full of
		// tabbable fields, so without this Tab escapes to the main form.
		onDuplicateTab(event) {
			const focusables = [this.$refs.duplicateQtyInput, this.$refs.cancelDuplicateBtn, this.$refs.combineDuplicateBtn]
				.filter((el) => el && !el.disabled);
			if (!focusables.length) return;
			event.preventDefault();
			const current = focusables.indexOf(document.activeElement);
			const next = focusables[(current + (event.shiftKey ? -1 : 1) + focusables.length) % focusables.length];
			next.focus();
		},
		resetEntry() {
			this.entry = { itemtype_id: null, itemtype: null, qty: null, comments: '', need_level: null };
			// Wait for the reactivity flush (which clears SearchSelect's search
			// text via its modelValue watcher) before focusing — focusing first
			// fires SearchSelect's own @focus="open" handler, which sets isOpen
			// early and makes the watcher's "don't clobber active typing" guard
			// skip the clear, leaving the just-entered item # stuck on screen.
			this.$nextTick(() => this.$refs.itemSelect?.focus());
		},
		findLineIndex(line) {
			return this.lines.findIndex((entry) =>
				(line.tempId !== undefined && entry.tempId === line.tempId) || entry === line);
		},
		async saveLine(line) {
			const startIdx = this.findLineIndex(line);
			if (startIdx !== -1) this.lines.splice(startIdx, 1, { ...line, status: 'saving' });
			try {
				const response = await axios.post('/json/orders/' + this.order.id + '/lines', {
					itemtype_id: line.itemtype_id,
					qty_requested: line.qty_requested,
					comments: line.comments,
					need_level: line.need_level,
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
				this.lines = this.lines.filter((entry) => entry !== line);
				return;
			}
			try {
				await axios.delete('/json/orders/' + this.order.id + '/lines/' + line.id);
				this.lines = this.lines.filter((entry) => entry !== line);
			} catch (error) {
				this.lineError = 'Could not delete the line. Try again.';
			}
		},
		retryFailed() {
			this.failedLines.forEach((line) => this.saveLine(line));
		},
		// Auto-retry failed autosaves whenever connectivity returns, and on a
		// slow heartbeat as backup — same approach as sorting sessions.
		autoRetry() {
			if (this.view === 'order' && !this.readonly && this.failedLines.length) {
				this.retryFailed();
			}
		},
	},
	watch: {
		// Esc closes the duplicate-item modal — listen globally only while
		// it's actually open, since the backdrop div itself can't hold focus.
		duplicateOf(value) {
			if (value) {
				window.addEventListener('keydown', this.onDuplicateKeydown);
			} else {
				window.removeEventListener('keydown', this.onDuplicateKeydown);
			}
		},
	},
	created() {
		this.fetchOrders();
	},
	mounted() {
		window.addEventListener('online', this.autoRetry);
		this.retryTimer = setInterval(this.autoRetry, 30000);
	},
	unmounted() {
		window.removeEventListener('online', this.autoRetry);
		window.removeEventListener('keydown', this.onDuplicateKeydown);
		clearInterval(this.retryTimer);
	},
};
</script>

<template>
	<Head title="Order Entry" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<!-- ======================= ORDER LIST ======================= -->
		<div v-if="view === 'list'" class="oe_container">
			<h2 class="ri_datatable_head">
				Order Entry
				<span class="oe_headeractions">
					<a href="/report/order-form.pdf" class="ri_formbutton" target="_blank">Offline Order Form</a>
					<button @click="startNewOrder" class="ri_defaultbutton">New Order</button>
				</span>
			</h2>

			<p v-if="listError" class="oe_error">{{ listError }}</p>
			<p v-if="listLoading">Loading orders...</p>

			<div class="oe_section">
				<h3>Open Orders - tap to open</h3>
				<p v-if="!orders.open.length && !listLoading">No open orders.</p>
				<div v-else class="oe_tablewrap"><table class="ri_datatable" border="1">
					<thead>
						<tr><th>Date</th><th>Partner</th><th>Lines</th><th>Items</th><th>Status</th><th>Entered By</th></tr>
					</thead>
					<tbody>
						<tr v-for="record in orders.open" :key="record.id"
							class="oe_rowlink" @click="resumeOrder(record.id)">
							<td>{{ record.order_date }}</td>
							<td>{{ personLabel(record.person) }}</td>
							<td>{{ orderSummary(record).lines }}</td>
							<td>{{ orderSummary(record).qty }}</td>
							<td>{{ record.status ? record.status.name : '' }}</td>
							<td>{{ personLabel(record.entered_by) }}</td>
						</tr>
					</tbody>
				</table></div>
			</div>

			<div v-if="orders.recent.length" class="oe_section">
				<h3>Recently Completed</h3>
				<div class="oe_tablewrap"><table class="ri_datatable" border="1">
					<thead>
						<tr><th>Date</th><th>Partner</th><th>Lines</th><th>Items</th><th>Status</th><th>Entered By</th></tr>
					</thead>
					<tbody>
						<tr v-for="record in orders.recent" :key="record.id"
							class="oe_rowlink" @click="resumeOrder(record.id)">
							<td>{{ record.order_date }}</td>
							<td>{{ personLabel(record.person) }}</td>
							<td>{{ orderSummary(record).lines }}</td>
							<td>{{ orderSummary(record).qty }}</td>
							<td>{{ record.status ? record.status.name : '' }}</td>
							<td>{{ personLabel(record.entered_by) }}</td>
						</tr>
					</tbody>
				</table></div>
			</div>
		</div>

		<!-- ======================= PARTNER SCREEN ======================= -->
		<div v-else-if="view === 'partner'" class="oe_container oe_partner">
			<div class="oe_topbar">
				<button @click="cancelPartnerScreen" class="ri_formbutton">&larr; Back</button>
				<span class="oe_title">
					{{ partnerMode === 'change' ? 'Change Partner' : 'New Order — Who is this for?' }}
				</span>
			</div>
			<p v-if="partnerError" class="oe_error">{{ partnerError }}</p>

			<div class="oe_field">
				<label>Partner:</label>
				<SearchSelect
					ref="partnerSelect"
					v-model="partnerId"
					optionsource="/json/people"
					display="full_name"
					:searchfields="['full_name', 'organization', 'city']"
					placeholder="Search by name or organization..."
					:allowcreate="true"
					@selected="partnerSelected"
					@create="startNewPartner"
				/>
			</div>

			<!-- quick-add: a phone-in partner who isn't in People yet -->
			<div v-if="creatingPartner" class="oe_card">
				<h3>New Partner</h3>
				<div class="oe_contactgrid">
					<div class="oe_field"><label>First Name:</label>
						<input type="text" v-model="newPartner.first_name" class="ri_forminput" autofocus /></div>
					<div class="oe_field"><label>Last Name:</label>
						<input type="text" v-model="newPartner.last_name" class="ri_forminput" /></div>
					<div class="oe_field"><label>Organization:</label>
						<input type="text" v-model="newPartner.organization" class="ri_forminput" /></div>
					<div class="oe_field"><label>Phone:</label>
						<input type="text" v-model="newPartner.phone" class="ri_forminput" /></div>
					<div class="oe_field"><label>Email:</label>
						<input type="email" v-model="newPartner.email" class="ri_forminput" /></div>
					<div class="oe_field"><label>Address:</label>
						<input type="text" v-model="newPartner.address" class="ri_forminput" /></div>
					<div class="oe_field"><label>City:</label>
						<input type="text" v-model="newPartner.city" class="ri_forminput" /></div>
					<div class="oe_field"><label>State:</label>
						<input type="text" v-model="newPartner.state" maxlength="2" class="ri_forminput oe_state" /></div>
					<div class="oe_field"><label>Zip:</label>
						<input type="text" v-model="newPartner.zip" maxlength="10" class="ri_forminput oe_zip" /></div>
				</div>
				<div class="oe_actions">
					<button @click="saveNewPartner" class="ri_defaultbutton" :disabled="partnerSaving">
						{{ partnerSaving ? 'Saving...' : 'Save Partner' }}
					</button>
					<button @click="creatingPartner = false" class="ri_formbutton">Cancel</button>
				</div>
			</div>

			<!-- confirm/update contact & shipping details before starting -->
			<div v-else-if="contact" class="oe_card">
				<h3>Confirm Contact &amp; Shipping Details</h3>
				<p class="oe_hint">Make sure this is up to date — it's where the order ships.</p>
				<div class="oe_contactgrid">
					<div class="oe_field"><label>First Name:</label>
						<input type="text" v-model="contact.first_name" class="ri_forminput" /></div>
					<div class="oe_field"><label>Last Name:</label>
						<input type="text" v-model="contact.last_name" class="ri_forminput" /></div>
					<div class="oe_field"><label>Organization:</label>
						<input type="text" v-model="contact.organization" class="ri_forminput" /></div>
					<div class="oe_field"><label>Phone:</label>
						<input type="text" v-model="contact.phone" class="ri_forminput" /></div>
					<div class="oe_field"><label>Email:</label>
						<input type="email" v-model="contact.email" class="ri_forminput" /></div>
					<div class="oe_field"><label>Address:</label>
						<input type="text" v-model="contact.address" class="ri_forminput" /></div>
					<div class="oe_field"><label>City:</label>
						<input type="text" v-model="contact.city" class="ri_forminput" /></div>
					<div class="oe_field"><label>State:</label>
						<input type="text" v-model="contact.state" maxlength="2" class="ri_forminput oe_state" /></div>
					<div class="oe_field"><label>Zip:</label>
						<input type="text" v-model="contact.zip" maxlength="10" class="ri_forminput oe_zip" /></div>
					<div class="oe_field" v-if="partner?.county"><label>County:</label>
						<span>{{ partner.county.county }}</span></div>
				</div>
				<div class="oe_actions">
					<button @click="confirmPartner" class="ri_defaultbutton" :disabled="partnerSaving">
						{{ partnerSaving ? 'Saving...'
							: (contactDirty ? 'Save Details & ' : '')
								+ (partnerMode === 'change' ? 'Use This Partner' : 'Start Order') }} &rarr;
					</button>
				</div>
			</div>
		</div>

		<!-- ======================= REVIEW & CONFIRM ======================= -->
		<div v-else-if="view === 'review' && order && review" class="oe_container oe_partner">
			<div class="oe_topbar">
				<button @click="backFromReview" class="ri_formbutton">&larr; Back</button>
				<span class="oe_title">Review &amp; Confirm — Order #{{ order.id }}</span>
			</div>
			<p v-if="reviewError" class="oe_error">{{ reviewError }}</p>
			<p v-if="headerError" class="oe_error">{{ headerError }}</p>

			<div class="oe_card">
				<h3>Order Summary</h3>
				<p class="oe_hint">
					{{ personLabel(order.person) }} &mdash; {{ totals.lines }} line(s), {{ totals.qty }} item(s) total.
				</p>
			</div>

			<div class="oe_card">
				<h3>Comments</h3>
				<TextArea v-model="review.comments" placeholder="Anything the warehouse should know about this order..." />
			</div>

			<div class="oe_card">
				<h3>Needed But Not in Catalog</h3>
				<p class="oe_hint">Anything this partner needs that isn't listed in our items — same as "Other Needs" on the offline order form.</p>
				<TextArea v-model="review.other_needs" placeholder="Items not available to order..." />
			</div>

			<div class="oe_card">
				<h3>Delivery or Pickup</h3>
				<div class="oe_radiogroup">
					<label><input type="radio" value="delivery" v-model="review.fulfillment_method" /> Delivery</label>
					<label><input type="radio" value="pickup" v-model="review.fulfillment_method" /> Pickup</label>
				</div>

				<div class="oe_field">
					<label>Needed By Date:</label>
					<input type="date" v-model="review.needed_by_date" class="ri_forminput" />
				</div>

				<template v-if="review.fulfillment_method === 'delivery'">
					<div class="oe_field oe_daysfield">
						<label>Can Accept Delivery:</label>
						<div class="oe_daypicker">
							<button
								type="button"
								class="oe_daychip"
								:class="{ oe_daychip_active: review.delivery_days.length === weekDays.length }"
								@click="selectAllDays"
							>Any Day</button>
							<button
								v-for="day in weekDays" :key="day"
								type="button"
								class="oe_daychip"
								:class="{ oe_daychip_active: review.delivery_days.includes(day) }"
								@click="toggleDeliveryDay(day)"
							>{{ day }}</button>
						</div>
					</div>
					<p class="oe_hint">
						{{ review.delivery_days.length ? 'Available: ' + review.delivery_days.join(', ') : 'No days selected' }}
					</p>
					<div class="oe_field">
						<label>Preferred Time:</label>
						<input type="text" v-model="review.preferred_time" class="ri_forminput" placeholder="e.g. 10am - 2pm" />
					</div>
				</template>
				<p v-else class="oe_hint">Pickup days and times are set by the warehouse.</p>
			</div>

			<div class="oe_card">
				<h3>{{ review.fulfillment_method === 'pickup' ? 'Pickup' : 'Delivery' }} Contact</h3>
				<p class="oe_hint">Defaults to the partner on file — change it if someone else is receiving this order.</p>
				<div class="oe_contactgrid">
					<div class="oe_field"><label>Contact Name:</label>
						<input type="text" v-model="review.contact_name" class="ri_forminput" /></div>
					<div class="oe_field"><label>Contact Phone:</label>
						<input type="text" v-model="review.contact_phone" class="ri_forminput" /></div>
				</div>
			</div>

			<div class="oe_card">
				<h3>Special Delivery Instructions</h3>
				<p class="oe_hint">Gate codes, dock location, who to contact on arrival &mdash; carried through to the BOL when this order ships. Not the same as "Needed But Not in Catalog" above.</p>
				<TextArea v-model="review.special_instructions" placeholder="Instructions for the driver..." />
			</div>

			<div class="oe_actions">
				<button @click="completeOrder" class="ri_defaultbutton" :disabled="headerSaving">
					{{ headerSaving ? 'Saving...' : 'Submit Order' }}
				</button>
			</div>
		</div>

		<!-- ======================= LINE ENTRY ======================= -->
		<div v-else-if="order" class="oe_container">
			<div class="oe_topbar">
				<button @click="closeOrder" class="ri_formbutton">&larr; All Orders</button>
				<span class="oe_title">
					Order #{{ order.id }}<span v-if="order.order_date" class="oe_title_date"> &middot; {{ order.order_date }}</span>
					<span v-if="readonly" class="oe_status_badge">{{ order.status ? order.status.name : '' }}</span>
				</span>
				<span class="oe_savestate" v-if="!readonly">
					<span v-if="failedLines.length" class="oe_error_inline">
						{{ failedLines.length }} line(s) failed to save
						<button @click="retryFailed" class="ri_linkbutton">Retry All</button>
					</span>
					<span v-else-if="headerSaving || pendingCount > 0">Saving...</span>
					<span v-else class="oe_saved">All changes saved</span>
				</span>
				<button v-if="!readonly" @click="openReview" class="ri_defaultbutton">Complete Order</button>
			</div>
			<p v-if="headerError" class="oe_error">{{ headerError }}</p>

			<!-- slim partner bar: just enough to know who you're talking to -->
			<div class="oe_partnerbar">
				<span class="oe_partner_name">{{ personLabel(order.person) }}</span>
				<span v-if="order.person && (order.person.city || order.person.state)" class="oe_partner_where">
					{{ [order.person.city, order.person.state].filter(Boolean).join(', ') }}
				</span>
				<span v-if="order.person && order.person.phone" class="oe_partner_where">{{ order.person.phone }}</span>
				<button v-if="!readonly" @click="changePartner" class="ri_linkbutton">Change</button>
			</div>

			<p v-if="lineError" class="oe_error">{{ lineError }}</p>

			<!-- entry row shares the table's columns with the entered lines below
			     it, so item #/qty/item/unit/comments never shift around as an
			     item is chosen — same grid, top row just happens to be editable. -->
			<div class="oe_tablewrap"><table class="ri_datatable oe_lines" border="1">
				<colgroup>
					<col class="oe_col_itemnum" />
					<col class="oe_col_qty" />
					<col class="oe_col_item" />
					<col class="oe_col_unit" />
					<col class="oe_col_comments" />
					<col class="oe_col_actions" />
				</colgroup>
				<thead>
					<tr>
						<th>Item #</th><th>Qty</th><th>Item</th><th>Unit</th><th>Comments</th><th></th>
					</tr>
				</thead>
				<tbody>
					<!-- rapid entry: item # (or name search), qty, Enter, next line -->
					<tr v-if="!readonly && !duplicateOf" class="oe_entry_row">
						<td>
							<SearchSelect
								ref="itemSelect"
								v-model="entry.itemtype_id"
								optionsource="/json/itemtypes/noitems"
								display="display_number"
								secondary="name"
								:searchfields="['display_number', 'name']"
								placeholder="Item #..."
								:openOnFocus="false"
								@selected="itemChosen"
							/>
						</td>
						<td>
							<input
								ref="qtyInput"
								type="number"
								min="1"
								v-model="entry.qty"
								class="ri_forminput oe_qty"
								placeholder="Qty"
								@keydown.enter.prevent="addLine"
							/>
						</td>
						<td class="oe_entry_name">
							{{ entry.itemtype ? entry.itemtype.name : '' }}
							<span v-if="entryHint" class="oe_entry_hint">&mdash; {{ entryHint }}</span>
							<select v-if="entryAvailabilityLow" v-model="entry.need_level" class="ri_forminput oe_need_level">
								<option :value="null">Need level (optional)</option>
								<option value="critical">Critical</option>
								<option value="moderate">Moderate</option>
								<option value="low">Low</option>
							</select>
						</td>
						<td class="oe_entry_unit">
							{{ entry.itemtype && entry.itemtype.unit ? entry.itemtype.unit.name : '' }}
						</td>
						<td>
							<input
								type="text"
								v-model="entry.comments"
								class="ri_forminput oe_entry_comment"
								placeholder="Line comment (optional)"
								@keydown.enter.prevent="addLine"
							/>
						</td>
						<td><button @click="addLine" class="ri_defaultbutton">Add</button></td>
					</tr>

					<tr v-for="line in sortedLines" :key="lineKey(line)"
						:class="{ oe_line_failed: line.status === 'failed' }">
						<td>{{ line.itemtype ? line.itemtype.display_number : '' }}</td>
						<td>{{ line.qty_requested }}</td>
						<td>
							{{ line.itemtype ? line.itemtype.name : '(item type #' + line.itemtype_id + ')' }}
							<span v-if="line.need_level" class="oe_entry_hint">&mdash; {{ line.need_level }} need</span>
						</td>
						<td>{{ line.itemtype && line.itemtype.unit ? line.itemtype.unit.name : '' }}</td>
						<td>{{ line.comments }}</td>
						<td class="oe_line_actions">
							<span v-if="line.status === 'saving'">saving...</span>
							<template v-else-if="line.status === 'failed'">
								<span :title="line.errorMessage">failed</span>
								<button @click="saveLine(line)" class="ri_formbutton">Retry</button>
							</template>
							<template v-else-if="confirmingLineKey === lineKey(line)">
								<button @click="deleteLine(line)" class="oe_confirm_delete">Delete?</button>
								<button @click="confirmingLineKey = null" class="ri_linkbutton">Keep</button>
							</template>
							<img v-else-if="!readonly" src="/img/delete-icon.webp" class="oe_delete" @click="deleteLine(line)" />
						</td>
					</tr>
					<tr v-if="!lines.length && readonly">
						<td colspan="6" class="oe_empty">No items on this order.</td>
					</tr>
				</tbody>
			</table></div>

			<div class="oe_totals" v-if="lines.length">
				<span>Lines: <strong>{{ totals.lines }}</strong></span>
				<span>Total items: <strong>{{ totals.qty }}</strong></span>
			</div>

			<!-- duplicate item: almost always a re-scan/typo, not an intentional
			     split order line — the only ways out are Combine or Cancel. -->
			<div v-if="duplicateOf" class="oe_modal_backdrop" @click.self="cancelDuplicate" @keydown.tab="onDuplicateTab">
				<div class="oe_modal">
					<h3>Oops</h3>
					<p>You already entered this item - here's what you requested:</p>
					<table class="oe_modal_table">
						<thead>
							<tr><th>Qty</th><th>Item #</th><th>Item</th><th>Unit</th></tr>
						</thead>
						<tbody>
							<tr>
								<td>{{ duplicateOf.qty_requested }}</td>
								<td>{{ duplicateOf.itemtype ? duplicateOf.itemtype.display_number : '' }}</td>
								<td>{{ duplicateOf.itemtype ? duplicateOf.itemtype.name : 'This item' }}</td>
								<td>{{ duplicateOf.itemtype && duplicateOf.itemtype.unit ? duplicateOf.itemtype.unit.name : '' }}</td>
							</tr>
						</tbody>
					</table>
					<div class="oe_modal_qty">
						<label>Additional qty:</label>
						<input
							ref="duplicateQtyInput"
							type="number"
							min="1"
							v-model="entry.qty"
							class="ri_forminput oe_qty"
							@keydown.enter.prevent="combineDuplicate"
						/>
					</div>
					<div class="oe_modal_actions">
						<button ref="cancelDuplicateBtn" @click="cancelDuplicate" class="ri_defaultbutton">Cancel</button>
						<button ref="combineDuplicateBtn" @click="combineDuplicate" class="ri_formbutton" :disabled="!duplicateQty">
							Combine &rarr; {{ Number(duplicateOf.qty_requested) + duplicateQty }}
						</button>
					</div>
				</div>
			</div>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.oe_container {
	max-width: 1024px;
	margin: 0 auto;
	padding: 16px;
}
.oe_partner {
	max-width: 720px;
}
.oe_headeractions {
	float: right;
	display: flex;
	gap: 8px;
}
.oe_section {
	margin-top: 1.5em;
}
.oe_rowlink {
	cursor: pointer;
}
.oe_rowlink:hover {
	background-color: #eef2ff;
}
.oe_topbar {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}
.oe_title {
	font-weight: bold;
	font-size: 1.15rem;
	flex: 1;
}
.oe_title_date {
	font-weight: normal;
	color: #666;
	font-size: 1rem;
}
.oe_radiogroup {
	display: flex;
	gap: 20px;
	margin-bottom: 10px;
}
.oe_radiogroup label {
	display: flex;
	align-items: center;
	gap: 6px;
	font-weight: bold;
}
.oe_daysfield {
	align-items: flex-start;
}
.oe_daypicker {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}
.oe_daychip {
	padding: 6px 12px;
	border: 1px solid #ccc;
	border-radius: 999px;
	background: white;
	cursor: pointer;
	font-size: 0.9rem;
}
.oe_daychip:hover {
	background: #f3f4f6;
}
.oe_daychip_active {
	background: #007bff;
	border-color: #007bff;
	color: white;
}
.oe_daychip_active:hover {
	background: #0056b3;
	border-color: #0056b3;
}
.oe_savestate {
	font-size: 0.85rem;
	color: #666;
}
.oe_saved {
	color: #15803d;
}
.oe_card {
	border: 1px solid #ddd;
	border-radius: 8px;
	background: #f9f9f9;
	padding: 14px 16px;
	margin-top: 14px;
}
.oe_card h3 {
	font-weight: bold;
	margin-bottom: 8px;
}
.oe_hint {
	color: #666;
	font-size: 0.85rem;
	margin-bottom: 10px;
}
.oe_contactgrid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	column-gap: 16px;
}
.oe_field {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
	flex-wrap: wrap;
}
.oe_field label {
	font-weight: bold;
	min-width: 7.5em;
}
.oe_field .ri_forminput {
	flex: 1;
	min-width: 10em;
}
.oe_state {
	max-width: 4em;
}
.oe_zip {
	max-width: 7em;
}
.oe_actions {
	display: flex;
	gap: 10px;
	margin-top: 10px;
}
.oe_partnerbar {
	display: flex;
	align-items: center;
	gap: 14px;
	flex-wrap: wrap;
	padding: 8px 14px;
	border: 1px solid #c7d2fe;
	border-radius: 8px;
	background: #eef2ff;
	margin-bottom: 12px;
}
.oe_partner_name {
	font-weight: bold;
	font-size: 1.05rem;
}
.oe_partner_where {
	color: #555;
}
.oe_lines {
	width: 100%;
	table-layout: fixed;
}
.oe_col_itemnum {
	width: 13%;
}
.oe_col_qty {
	width: 8%;
}
.oe_col_item {
	width: 32%;
}
.oe_col_unit {
	width: 10%;
}
.oe_col_comments {
	width: 27%;
}
.oe_col_actions {
	width: 10%;
}
.oe_entry_row td {
	background: #f9f9f9;
	vertical-align: middle;
}
.oe_entry_row .ri_forminput,
.oe_entry_row .ri_formcontrol {
	width: 100%;
	box-sizing: border-box;
}
.oe_entry_name {
	font-weight: bold;
	overflow-wrap: break-word;
}
.oe_entry_unit {
	color: #555;
}
.oe_entry_hint {
	display: block;
	color: #92400e;
	font-weight: normal;
	font-size: 0.85rem;
}
.oe_need_level {
	display: block;
	margin-top: 4px;
	font-size: 0.85rem;
	max-width: 12em;
}
.oe_modal_backdrop {
	position: fixed;
	inset: 0;
	z-index: 50;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
	background: rgba(17, 24, 39, 0.35);
	backdrop-filter: blur(2px);
}
.oe_modal {
	width: 100%;
	max-width: 30rem;
	background: #fffbeb;
	border: 1px solid #f59e0b;
	border-radius: 10px;
	box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25);
	padding: 18px 20px;
}
.oe_modal h3 {
	font-weight: bold;
	font-size: 1.05rem;
	margin-bottom: 8px;
	color: #92400e;
}
.oe_modal_table {
	width: 100%;
	margin-top: 10px;
	border-collapse: collapse;
	background: white;
	border: 1px solid #eee;
	border-radius: 6px;
	overflow: hidden;
}
.oe_modal_table th, .oe_modal_table td {
	padding: 6px 10px;
	text-align: left;
	border-bottom: 1px solid #eee;
}
.oe_modal_table th {
	background: #f9f9f9;
	font-size: 0.8rem;
	text-transform: uppercase;
	letter-spacing: 0.03em;
	color: #666;
}
.oe_modal_table tbody tr:last-child td {
	border-bottom: none;
}
.oe_modal_qty {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-top: 14px;
}
.oe_modal_qty label {
	font-weight: bold;
}
.oe_modal_qty .oe_qty {
	width: 6em;
}
.oe_modal_actions {
	display: flex;
	gap: 10px;
	flex-wrap: wrap;
	margin-top: 14px;
}
.oe_line_failed {
	background: #fef2f2;
}
.oe_line_actions {
	white-space: nowrap;
}
.oe_delete {
	width: 1.1em;
	cursor: pointer;
}
.oe_empty {
	text-align: center;
	color: #777;
	padding: 1em;
}
.oe_totals {
	display: flex;
	gap: 20px;
	flex-wrap: wrap;
	padding: 10px 14px;
	background: #f3f4f6;
	border-radius: 8px;
	margin-top: 10px;
}
.oe_error {
	color: #b91c1c;
	margin: 6px 0;
}
.oe_error_inline {
	color: #b91c1c;
	font-weight: bold;
}
.oe_status_badge {
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
.oe_confirm_delete {
	background: #dc2626;
	color: white;
	border: none;
	border-radius: 5px;
	padding: 4px 10px;
	font-size: 0.85rem;
	cursor: pointer;
}
.oe_confirm_delete:hover {
	background: #b91c1c;
}
.oe_tablewrap {
	overflow-x: auto;
}
</style>
