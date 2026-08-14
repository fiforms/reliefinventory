<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- OrderEntry.vue

	Order intake, built as a session-based event stream (the same pattern as
	DonationSorting.vue, not an RIForm document):

	  - Step 1 is the customer screen: select (or quick-add) the customer and
	    confirm their contact/shipping details are current. Confirming creates
	    the order header on the server immediately.
	  - Step 2 is the line-entry screen: a slim one-line customer bar (the
	    details deliberately don't take screen space here), then rapid
	    item-number-first entry — item # or name search, quantity, Enter,
	    next line. Each line saves the moment it is entered; a crash never
	    loses more than the line being typed.
	  - Entering the same item twice offers Combine / separate-line.
	  - An advisory "~N usable on hand" hint shows for the selected item.
	    This is a staff-facing page; customer-facing surfaces must never
	    show actual stock numbers (three-state availability at most).
	  - Orders lock once filling starts (server-enforced); they open here
	    read-only. Phone orders and hand-entered PDF order forms are the
	    same activity and both use this screen.
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import SearchSelect, { invalidateOptions } from '@/Components/SearchSelect.vue';
import TextArea from '@/Components/TextArea.vue';
import axios from 'axios';

const CONTACT_FIELDS = ['first_name', 'last_name', 'organization', 'phone', 'email', 'address', 'city', 'state', 'zip'];

export default {
	components: { AuthenticatedLayout, Head, SearchSelect, TextArea },
	props: {
		breadcrumb: { type: Array },
	},
	data() {
		return {
			// list view
			view: 'list', // 'list' | 'customer' | 'order'
			orders: { open: [], recent: [] },
			listLoading: false,
			listError: null,

			// customer screen
			customerMode: 'new', // 'new' (start an order) | 'change' (repoint an open order)
			customerId: null,
			customer: null,
			contact: null,           // editable copy of the customer's contact fields
			contactOriginal: null,   // JSON snapshot for the dirty check
			customerError: null,
			customerSaving: false,
			creatingCustomer: false,
			newCustomer: {},

			// active order
			order: null,
			lines: [],
			headerSaving: false,
			headerError: null,
			confirmingDeleteOrder: false,

			// line entry
			entry: { itemtype_id: null, itemtype: null, qty: null, comments: '' },
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
			if (!person) return '(no customer)';
			const name = [person.first_name, person.last_name].filter(Boolean).join(' ');
			return person.organization ? person.organization + (name ? ' - ' + name : '') : name || '(unnamed)';
		},
		orderSummary(record) {
			const lines = record.order_lines || [];
			return {
				lines: lines.length,
				qty: lines.reduce((sum, line) => sum + Number(line.qty_requested || 0), 0),
			};
		},

		// ---------- customer screen ----------
		startNewOrder() {
			this.customerMode = 'new';
			this.resetCustomerScreen();
			this.view = 'customer';
			this.$nextTick(() => this.$refs.customerSelect?.focus());
		},
		changeCustomer() {
			this.customerMode = 'change';
			this.resetCustomerScreen();
			this.view = 'customer';
			this.$nextTick(() => this.$refs.customerSelect?.focus());
		},
		resetCustomerScreen() {
			this.customerId = null;
			this.customer = null;
			this.contact = null;
			this.contactOriginal = null;
			this.customerError = null;
			this.creatingCustomer = false;
		},
		cancelCustomerScreen() {
			this.view = this.customerMode === 'change' && this.order ? 'order' : 'list';
		},
		customerSelected(person) {
			this.customer = person;
			this.creatingCustomer = false;
			this.customerError = null;
			if (person) {
				this.contact = Object.fromEntries(CONTACT_FIELDS.map((f) => [f, person[f] ?? '']));
				this.contactOriginal = JSON.stringify(this.contact);
			} else {
				this.contact = null;
			}
		},
		startNewCustomer(name) {
			this.creatingCustomer = true;
			this.customerError = null;
			const parts = (name || '').trim().split(/\s+/);
			this.newCustomer = {
				first_name: parts[0] || '',
				last_name: parts.slice(1).join(' '),
				organization: parts.length <= 1 ? (name || '') : '',
				phone: '', email: '', address: '', city: '', state: '', zip: '',
			};
		},
		async saveNewCustomer() {
			this.customerError = null;
			if (!this.newCustomer.first_name.trim() || !this.newCustomer.last_name.trim()) {
				this.customerError = 'First and last name are required (use the organization name for both if this is an organization-only customer).';
				return;
			}
			this.customerSaving = true;
			try {
				const response = await axios.post('/json/people', this.newCustomer);
				invalidateOptions('/json/people');
				await this.$refs.customerSelect?.refresh(response.data.record.id);
				this.customerId = response.data.record.id;
				this.customerSelected(response.data.record);
			} catch (error) {
				this.customerError = error.response?.data?.message
					|| Object.values(error.response?.data?.errors || {}).flat().join(' ')
					|| 'Could not save the new customer.';
			} finally {
				this.customerSaving = false;
			}
		},
		/**
		 * Confirm the customer: push any contact edits to the person record,
		 * then create the order (or repoint the open one). The people update
		 * endpoint replaces roles/permissions from the payload, so the
		 * person's existing ones are sent back unchanged.
		 */
		async confirmCustomer() {
			if (!this.customerId) {
				this.customerError = 'Choose or add a customer first.';
				return;
			}
			this.customerError = null;
			this.customerSaving = true;
			try {
				if (this.contactDirty) {
					await axios.put('/json/people/' + this.customerId, {
						...this.contact,
						email: this.contact.email || null,
						people_roles: (this.customer.people_roles || []).map((r) => ({ role_id: r.role_id })),
						person_permissions: this.customer.person_permissions || [],
					});
					invalidateOptions('/json/people');
				}
				if (this.customerMode === 'change' && this.order) {
					const response = await axios.patch('/json/orders/' + this.order.id, { person_id: this.customerId });
					this.openOrder(response.data.record, this.lines);
				} else {
					const response = await axios.post('/json/orders', { person_id: this.customerId });
					this.openOrder(response.data.record);
				}
			} catch (error) {
				this.customerError = error.response?.data?.message
					|| Object.values(error.response?.data?.errors || {}).flat().join(' ')
					|| 'Could not start the order.';
			} finally {
				this.customerSaving = false;
			}
		},

		// ---------- active order ----------
		openOrder(record, keepLines) {
			this.order = record;
			this.lines = keepLines ?? (record.order_lines || []).map((line) => ({ ...line, status: 'saved' }));
			this.entry = { itemtype_id: null, itemtype: null, qty: null, comments: '' };
			this.lineError = null;
			this.headerError = null;
			this.duplicateOf = null;
			this.confirmingLineKey = null;
			this.confirmingDeleteOrder = false;
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
		async patchOrder(fields) {
			this.headerSaving = true;
			this.headerError = null;
			try {
				const response = await axios.patch('/json/orders/' + this.order.id, fields);
				this.order = { ...response.data.record, order_lines: undefined };
			} catch (error) {
				this.headerError = error.response?.data?.message || 'Could not save order details.';
			} finally {
				this.headerSaving = false;
			}
		},
		orderDateChanged() {
			this.patchOrder({ order_date: this.order.order_date });
		},
		commentsChanged() {
			this.patchOrder({ comments: this.order.comments });
		},
		async deleteOrder() {
			// Two-step inline confirm, same convention as line delete.
			if (!this.confirmingDeleteOrder) {
				this.confirmingDeleteOrder = true;
				return;
			}
			try {
				await axios.delete('/json/orders/' + this.order.id);
				await this.closeOrder();
			} catch (error) {
				this.confirmingDeleteOrder = false;
				this.headerError = error.response?.data?.message || 'Could not delete the order.';
			}
		},

		// ---------- line entry (autosaved per line) ----------
		itemChosen(itemtype) {
			this.entry.itemtype = itemtype;
			this.duplicateOf = null;
			if (itemtype) this.$refs.qtyInput?.focus();
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

			// Same item already on the order? Offer Combine before adding a
			// duplicate line (easy to do transcribing a long paper form).
			if (!this.duplicateOf) {
				const existing = this.lines.find(
					(line) => line.itemtype_id === this.entry.itemtype_id && line.status === 'saved'
				);
				if (existing) {
					this.duplicateOf = existing;
					return;
				}
			}
			this.duplicateOf = null;

			const line = {
				tempId: this.nextTempId--,
				itemtype_id: this.entry.itemtype_id,
				itemtype: this.entry.itemtype || null,
				qty_requested: qty,
				comments: this.entry.comments || null,
				status: 'saving',
			};
			this.lines.push(line);
			this.saveLine(line);
			this.resetEntry();
		},
		async combineDuplicate() {
			const target = this.duplicateOf;
			const qty = parseInt(this.entry.qty, 10) || 0;
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
		resetEntry() {
			this.entry = { itemtype_id: null, itemtype: null, qty: null, comments: '' };
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
				const response = await axios.post('/json/orders/' + this.order.id + '/lines', {
					itemtype_id: line.itemtype_id,
					qty_requested: line.qty_requested,
					comments: line.comments,
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
	created() {
		this.fetchOrders();
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
	<Head title="Order Entry" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<!-- ======================= ORDER LIST ======================= -->
		<div v-if="view === 'list'" class="oe_container">
			<h2 class="ri_datatable_head">
				Order Entry
				<button @click="startNewOrder" class="ri_defaultbutton ri_floating">New Order</button>
			</h2>

			<p v-if="listError" class="oe_error">{{ listError }}</p>
			<p v-if="listLoading">Loading orders...</p>

			<div class="oe_section">
				<h3>Open Orders - tap to open</h3>
				<p v-if="!orders.open.length && !listLoading">No open orders.</p>
				<div v-else class="oe_tablewrap"><table class="ri_datatable" border="1">
					<thead>
						<tr><th>Date</th><th>Customer</th><th>Lines</th><th>Items</th><th>Status</th><th>Entered By</th></tr>
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
						<tr><th>Date</th><th>Customer</th><th>Lines</th><th>Items</th><th>Status</th><th>Entered By</th></tr>
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

		<!-- ======================= CUSTOMER SCREEN ======================= -->
		<div v-else-if="view === 'customer'" class="oe_container oe_customer">
			<div class="oe_topbar">
				<button @click="cancelCustomerScreen" class="ri_formbutton">&larr; Back</button>
				<span class="oe_title">
					{{ customerMode === 'change' ? 'Change Customer' : 'New Order — Who is this for?' }}
				</span>
			</div>
			<p v-if="customerError" class="oe_error">{{ customerError }}</p>

			<div class="oe_field">
				<label>Customer:</label>
				<SearchSelect
					ref="customerSelect"
					v-model="customerId"
					optionsource="/json/people"
					display="full_name"
					:searchfields="['full_name', 'organization', 'city']"
					placeholder="Search by name or organization..."
					:allowcreate="true"
					@selected="customerSelected"
					@create="startNewCustomer"
				/>
			</div>

			<!-- quick-add: a phone-in customer who isn't in People yet -->
			<div v-if="creatingCustomer" class="oe_card">
				<h3>New Customer</h3>
				<div class="oe_contactgrid">
					<div class="oe_field"><label>First Name:</label>
						<input type="text" v-model="newCustomer.first_name" class="ri_forminput" autofocus /></div>
					<div class="oe_field"><label>Last Name:</label>
						<input type="text" v-model="newCustomer.last_name" class="ri_forminput" /></div>
					<div class="oe_field"><label>Organization:</label>
						<input type="text" v-model="newCustomer.organization" class="ri_forminput" /></div>
					<div class="oe_field"><label>Phone:</label>
						<input type="text" v-model="newCustomer.phone" class="ri_forminput" /></div>
					<div class="oe_field"><label>Email:</label>
						<input type="email" v-model="newCustomer.email" class="ri_forminput" /></div>
					<div class="oe_field"><label>Address:</label>
						<input type="text" v-model="newCustomer.address" class="ri_forminput" /></div>
					<div class="oe_field"><label>City:</label>
						<input type="text" v-model="newCustomer.city" class="ri_forminput" /></div>
					<div class="oe_field"><label>State:</label>
						<input type="text" v-model="newCustomer.state" maxlength="2" class="ri_forminput oe_state" /></div>
					<div class="oe_field"><label>Zip:</label>
						<input type="text" v-model="newCustomer.zip" maxlength="10" class="ri_forminput oe_zip" /></div>
				</div>
				<div class="oe_actions">
					<button @click="saveNewCustomer" class="ri_defaultbutton" :disabled="customerSaving">
						{{ customerSaving ? 'Saving...' : 'Save Customer' }}
					</button>
					<button @click="creatingCustomer = false" class="ri_formbutton">Cancel</button>
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
					<div class="oe_field" v-if="customer?.county"><label>County:</label>
						<span>{{ customer.county.county }}</span></div>
				</div>
				<div class="oe_actions">
					<button @click="confirmCustomer" class="ri_defaultbutton" :disabled="customerSaving">
						{{ customerSaving ? 'Saving...'
							: (contactDirty ? 'Save Details & ' : '')
								+ (customerMode === 'change' ? 'Use This Customer' : 'Start Order') }} &rarr;
					</button>
				</div>
			</div>
		</div>

		<!-- ======================= LINE ENTRY ======================= -->
		<div v-else-if="order" class="oe_container">
			<div class="oe_topbar">
				<button @click="closeOrder" class="ri_formbutton">&larr; All Orders</button>
				<span class="oe_title">
					Order #{{ order.id }}
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
				<template v-if="!readonly">
					<button v-if="confirmingDeleteOrder" @click="confirmingDeleteOrder = false" class="ri_linkbutton">Keep Order</button>
					<button @click="deleteOrder" :class="confirmingDeleteOrder ? 'ri_deletebutton' : 'ri_formbutton'">
						{{ confirmingDeleteOrder ? 'Delete this entire order?' : 'Delete Order' }}
					</button>
				</template>
			</div>
			<p v-if="headerError" class="oe_error">{{ headerError }}</p>

			<!-- slim customer bar: just enough to know who you're talking to -->
			<div class="oe_customerbar">
				<span class="oe_customer_name">{{ personLabel(order.person) }}</span>
				<span v-if="order.person && (order.person.city || order.person.state)" class="oe_customer_where">
					{{ [order.person.city, order.person.state].filter(Boolean).join(', ') }}
				</span>
				<span v-if="order.person && order.person.phone" class="oe_customer_where">{{ order.person.phone }}</span>
				<button v-if="!readonly" @click="changeCustomer" class="ri_linkbutton">Change</button>
			</div>

			<div class="oe_header">
				<div class="oe_field">
					<label>Order Date:</label>
					<input type="date" v-model="order.order_date" class="ri_forminput"
						:disabled="readonly" @change="orderDateChanged" />
				</div>
				<div class="oe_field oe_comments">
					<label>Comments:</label>
					<TextArea v-model="order.comments" :enabled="!readonly" @change="commentsChanged" />
				</div>
			</div>

			<!-- rapid entry: item # (or name search), qty, Enter, next line -->
			<div v-if="!readonly && !duplicateOf" class="oe_entry">
				<div class="oe_entry_item">
					<SearchSelect
						ref="itemSelect"
						v-model="entry.itemtype_id"
						optionsource="/json/itemtypes/noitems"
						display="display_number"
						:searchfields="['display_number', 'name']"
						placeholder="Item # or name..."
						@selected="itemChosen"
					/>
				</div>
				<span v-if="entry.itemtype" class="oe_entry_name">
					{{ entry.itemtype.name }}
					<span v-if="entry.itemtype.unit" class="oe_entry_unit">({{ entry.itemtype.unit.name }})</span>
					<span v-if="entryHint" class="oe_entry_hint">&mdash; {{ entryHint }}</span>
				</span>
				<input
					ref="qtyInput"
					type="number"
					min="1"
					v-model="entry.qty"
					class="ri_forminput oe_qty"
					placeholder="Qty"
					@keydown.enter.prevent="addLine"
				/>
				<input
					type="text"
					v-model="entry.comments"
					class="ri_forminput oe_entry_comment"
					placeholder="Line comment (optional)"
					@keydown.enter.prevent="addLine"
				/>
				<button @click="addLine" class="ri_defaultbutton">Add</button>
			</div>

			<!-- duplicate item: combine or keep separate -->
			<div v-if="duplicateOf" class="oe_entry oe_duplicate">
				<span>
					<strong>{{ duplicateOf.itemtype ? duplicateOf.itemtype.name : 'This item' }}</strong>
					is already on this order (qty {{ duplicateOf.qty_requested }}).
				</span>
				<button @click="combineDuplicate" class="ri_defaultbutton">
					Combine &rarr; {{ Number(duplicateOf.qty_requested) + (parseInt(entry.qty, 10) || 0) }}
				</button>
				<button @click="addLine" class="ri_formbutton">Add as separate line</button>
				<button @click="duplicateOf = null; resetEntry()" class="ri_linkbutton">Cancel</button>
			</div>
			<p v-if="lineError" class="oe_error">{{ lineError }}</p>

			<!-- entered lines -->
			<div class="oe_tablewrap"><table class="ri_datatable oe_lines" border="1">
				<thead>
					<tr>
						<th>Item #</th><th>Item</th><th>Unit</th><th>Qty</th><th>Comments</th><th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="line in sortedLines" :key="lineKey(line)"
						:class="{ oe_line_failed: line.status === 'failed' }">
						<td>{{ line.itemtype ? line.itemtype.display_number : '' }}</td>
						<td>{{ line.itemtype ? line.itemtype.name : '(item type #' + line.itemtype_id + ')' }}</td>
						<td>{{ line.itemtype && line.itemtype.unit ? line.itemtype.unit.name : '' }}</td>
						<td>{{ line.qty_requested }}</td>
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
					<tr v-if="!lines.length">
						<td colspan="6" class="oe_empty">No items yet - enter an item number to begin.</td>
					</tr>
				</tbody>
			</table></div>

			<div class="oe_totals" v-if="lines.length">
				<span>Lines: <strong>{{ totals.lines }}</strong></span>
				<span>Total items: <strong>{{ totals.qty }}</strong></span>
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
.oe_customer {
	max-width: 720px;
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
.oe_customerbar {
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
.oe_customer_name {
	font-weight: bold;
	font-size: 1.05rem;
}
.oe_customer_where {
	color: #555;
}
.oe_header {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}
.oe_comments {
	flex: 1;
	min-width: 260px;
}
.oe_entry {
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
.oe_entry_item {
	min-width: 180px;
}
.oe_entry_name {
	font-weight: bold;
}
.oe_entry_unit {
	color: #555;
	font-weight: normal;
}
.oe_entry_hint {
	color: #92400e;
	font-weight: normal;
	font-size: 0.85rem;
}
.oe_entry_comment {
	flex: 1;
	min-width: 140px;
}
.oe_qty {
	width: 6em;
}
.oe_duplicate {
	border: 2px dashed #f59e0b;
	background: #fffbeb;
}
.oe_lines {
	width: 100%;
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
