<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- OrderFilling.vue

	Order Filling/Picking: the first place item_ledgers.qty_subtracted ever
	becomes real (previously always 0 in production). One shared backend
	serves two capture modes, not two systems — a fill record is created the
	same way whether staff typed it live while scanning, or transcribed it
	afterward from a printed pick sheet. See OrderFillingController's doc
	comment for the full architecture.

	  - List view: two queues. "Ready to Fill" orders can either be started
	    individually (opens the fill screen directly — the live/manual path)
	    or all printed together as one batch of pick sheets (the paper path,
	    which also locks every included order into Filling). "Filling"
	    orders show per-order fill progress and link into the same fill
	    screen to continue or finish.
	  - Review Allocation is additive and non-blocking: a read-only panel
	    showing, for any itemtype where total requested across Ready-to-Fill
	    orders exceeds on-hand, a straight-proportional suggested split per
	    order line alongside that line's self-reported need level. It never
	    changes anything on its own — Print Pick Sheets and Start Filling
	    both work immediately without ever opening it. A warehouse that just
	    wants first-come-first-served can ignore this panel entirely.
	  - Fill screen: per requested line, an add-a-fill-record control (item
	    variant search/scan, matching Sorting's exact item-scan pattern,
	    auto-skipped when the itemtype has only one variant) plus qty. Fill
	    records are append-only (matches Sorting's ledger-row-per-scan model)
	    — "filled so far" is their sum, never an overwritten total. Complete
	    Filling requires every line to have at least one record (a
	    deliberate zero counts).
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import SearchSelect from '@/Components/SearchSelect.vue';
import QrScanner from '@/Components/QrScanner.vue';
import axios from 'axios';

const LOW_STOCK_THRESHOLD_DEFAULT = 10; // mirrors config('inventory.low_stock_threshold')
const NEED_LEVEL_LABELS = { critical: 'Critical', moderate: 'Moderate', low: 'Low' };

export default {
	components: { AuthenticatedLayout, Head, SearchSelect, QrScanner },
	props: {
		breadcrumb: { type: Array },
	},
	data() {
		return {
			view: 'list', // 'list' | 'fill'
			readyToFill: [],
			filling: [],
			listLoading: false,
			listError: null,
			printing: false,
			showAllocation: false,

			// stock-on-hand per itemtype, reused from Order Entry's own endpoint
			stockHints: {},

			// fill screen
			order: null,
			fillError: null,
			confirmingFillKey: null,
			savingComplete: false,

			// add-a-fill-record entry, per line (keyed by line id)
			entryByLine: {},
			showScanner: false,
			scanningLineId: null,

			// item catalog, fetched once and filtered client-side per line's
			// itemtype — same pattern DonationSorting.vue uses for /json/items
			items: [],

			needLevelLabels: NEED_LEVEL_LABELS,
		};
	},
	computed: {
		lowStockThreshold() {
			return LOW_STOCK_THRESHOLD_DEFAULT;
		},
		// Itemtypes where total requested across Ready-to-Fill orders
		// exceeds on-hand — the Allocation Review panel's contents.
		allocationRows() {
			const totals = {}; // itemtype_id -> { itemtype, lines: [{order, line}] }
			this.readyToFill.forEach((order) => {
				(order.order_lines || []).forEach((line) => {
					const key = line.itemtype_id;
					if (!totals[key]) totals[key] = { itemtype: line.itemtype, lines: [] };
					totals[key].lines.push({ order, line });
				});
			});

			return Object.values(totals)
				.map((group) => {
					const onHand = Number(this.stockHints[group.itemtype?.id] ?? 0);
					const totalRequested = group.lines.reduce((sum, { line }) => sum + Number(line.qty_requested || 0), 0);
					return { itemtype: group.itemtype, onHand, totalRequested, lines: group.lines };
				})
				.filter((row) => row.totalRequested > row.onHand)
				.map((row) => ({
					...row,
					lines: row.lines
						.map(({ order, line }) => ({
							order,
							line,
							suggested: row.totalRequested > 0
								? Math.floor(Number(line.qty_requested) * (row.onHand / row.totalRequested))
								: 0,
						}))
						.sort((a, b) => this.needLevelRank(a.line.need_level) - this.needLevelRank(b.line.need_level)),
				}));
		},
		orderLines() {
			return this.order?.order_lines || [];
		},
	},
	methods: {
		needLevelRank(level) {
			return { critical: 0, moderate: 1, low: 2 }[level] ?? 3;
		},
		personLabel(person) {
			if (!person) return '(no partner)';
			const name = [person.first_name, person.last_name].filter(Boolean).join(' ');
			return person.organization ? person.organization + (name ? ' - ' + name : '') : name || '(unnamed)';
		},
		lineFilled(line) {
			return (line.item_ledgers || []).reduce((sum, fill) => sum + Number(fill.qty_subtracted || 0), 0);
		},
		orderProgress(order) {
			const lines = order.order_lines || [];
			const filledCount = lines.filter((line) => (line.item_ledgers || []).length > 0).length;
			return { filled: filledCount, total: lines.length };
		},

		// ---------- list / queues ----------
		async fetchQueue() {
			this.listLoading = true;
			this.listError = null;
			try {
				const response = await axios.get('/json/order-filling');
				this.readyToFill = response.data.ready_to_fill || [];
				this.filling = response.data.filling || [];
			} catch (error) {
				this.listError = 'Could not load the filling queue.';
			} finally {
				this.listLoading = false;
			}
		},
		async fetchStockHints() {
			try {
				const response = await axios.get('/json/orders/stock-hints');
				this.stockHints = response.data.hints || {};
			} catch (error) {
				this.stockHints = {};
			}
		},
		async fetchItems() {
			try {
				const response = await axios.get('/json/items');
				this.items = response.data.records || [];
			} catch (error) {
				this.items = [];
			}
		},
		itemsForItemtype(itemtypeId) {
			return this.items.filter((item) => item.itemtype_id === itemtypeId);
		},

		async startFilling(order) {
			this.listError = null;
			try {
				const response = await axios.patch('/json/order-filling/' + order.id + '/start');
				await this.openFillScreen(response.data.record.id);
			} catch (error) {
				this.listError = error.response?.data?.message || 'Could not start filling that order.';
			}
		},
		async printPickSheets() {
			this.listError = null;
			this.printing = true;
			try {
				const response = await axios.post('/json/order-filling/print-pick-sheets');
				const ids = response.data.order_ids || [];
				if (ids.length) {
					const query = ids.map((id) => 'ids[]=' + id).join('&');
					window.open('/report/pick-sheets.pdf?' + query, '_blank');
				}
				await this.fetchQueue();
			} catch (error) {
				this.listError = error.response?.data?.message || 'Could not print pick sheets.';
			} finally {
				this.printing = false;
			}
		},
		reprintOrder(order) {
			window.open('/report/pick-sheets.pdf?ids[]=' + order.id, '_blank');
		},

		// ---------- fill screen ----------
		async openFillScreen(orderId) {
			this.fillError = null;
			this.confirmingFillKey = null;
			this.entryByLine = {};
			try {
				const response = await axios.get('/json/order-filling');
				const found = [...(response.data.ready_to_fill || []), ...(response.data.filling || [])]
					.find((o) => o.id === orderId);
				this.order = found || null;
				if (!this.order) {
					this.listError = 'Could not open that order.';
					return;
				}
				this.orderLines.forEach((line) => {
					this.entryByLine[line.id] = { item_id: null, item: null, qty: null };
				});
				this.view = 'fill';
				await Promise.all([this.fetchStockHints(), this.fetchItems()]);
			} catch (error) {
				this.listError = 'Could not open that order.';
			}
		},
		async continueFilling(order) {
			await this.openFillScreen(order.id);
		},
		backToList() {
			this.view = 'list';
			this.order = null;
			this.fetchQueue();
		},
		fillKey(fill) {
			return fill.id;
		},
		lineOnHand(line) {
			return Number(this.stockHints[line.itemtype_id] ?? 0);
		},
		itemChosenForLine(line, item) {
			this.entryByLine[line.id].item = item;
			this.$nextTick(() => this.$refs['qtyInput_' + line.id]?.[0]?.focus());
		},
		openScannerForLine(lineId) {
			this.scanningLineId = lineId;
			this.showScanner = true;
		},
		onScanned(text) {
			this.showScanner = false;
			if (!this.scanningLineId) return;
			const line = this.orderLines.find((l) => l.id === this.scanningLineId);
			if (!line) return;
			const match = this.itemsForItemtype(line.itemtype_id)
				.find((item) => item.upc === text || item.name === text);
			if (match) {
				this.entryByLine[line.id].item_id = match.id;
				this.itemChosenForLine(line, match);
			} else {
				this.fillError = 'Scanned code did not match an item for this line.';
			}
			this.scanningLineId = null;
		},
		async addFill(line) {
			this.fillError = null;
			const entry = this.entryByLine[line.id];
			const variants = this.itemsForItemtype(line.itemtype_id);
			const itemId = variants.length === 1 ? variants[0].id : entry.item_id;
			if (!itemId) {
				this.fillError = 'Choose an item for this line first.';
				return;
			}
			const qty = entry.qty === '' || entry.qty === null ? null : parseInt(entry.qty, 10);
			if (qty === null || isNaN(qty) || qty < 0) {
				this.fillError = 'Enter a quantity of 0 or more.';
				return;
			}

			try {
				const response = await axios.post(
					'/json/order-filling/' + this.order.id + '/lines/' + line.id + '/fills',
					{ item_id: itemId, qty }
				);
				line.item_ledgers = [...(line.item_ledgers || []), response.data.record];
				this.entryByLine[line.id] = { item_id: null, item: null, qty: null };
			} catch (error) {
				this.fillError = error.response?.data?.message
					|| Object.values(error.response?.data?.errors || {}).flat().join(' ')
					|| 'Could not record that fill.';
			}
		},
		async deleteFill(line, fill) {
			if (this.confirmingFillKey !== this.fillKey(fill)) {
				this.confirmingFillKey = this.fillKey(fill);
				return;
			}
			this.confirmingFillKey = null;
			try {
				await axios.delete('/json/order-filling/' + this.order.id + '/lines/' + line.id + '/fills/' + fill.id);
				line.item_ledgers = (line.item_ledgers || []).filter((f) => f.id !== fill.id);
			} catch (error) {
				this.fillError = 'Could not delete that fill record.';
			}
		},
		allLinesAccounted() {
			return this.orderLines.length > 0 && this.orderLines.every((line) => (line.item_ledgers || []).length > 0);
		},
		async completeFilling() {
			this.fillError = null;
			this.savingComplete = true;
			try {
				await axios.patch('/json/order-filling/' + this.order.id + '/complete');
				this.backToList();
			} catch (error) {
				this.fillError = error.response?.data?.message || 'Could not complete filling this order.';
			} finally {
				this.savingComplete = false;
			}
		},
	},
	mounted() {
		this.fetchQueue();
		this.fetchStockHints();
	},
};
</script>

<template>
	<Head title="Order Filling" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<!-- ======================= QUEUE LIST ======================= -->
		<div v-if="view === 'list'" class="of_container">
			<h2 class="ri_datatable_head">
				Order Filling
				<span class="of_headeractions">
					<button @click="showAllocation = !showAllocation" class="ri_formbutton">
						{{ showAllocation ? 'Hide' : 'Review' }} Allocation
					</button>
					<button :disabled="printing || !readyToFill.length" @click="printPickSheets" class="ri_defaultbutton">
						{{ printing ? 'Printing…' : 'Print Pick Sheets' }}
					</button>
				</span>
			</h2>

			<p v-if="listError" class="of_error">{{ listError }}</p>
			<p v-if="listLoading">Loading…</p>

			<!-- Allocation Review: additive, non-blocking, informational only -->
			<div v-if="showAllocation" class="of_card">
				<h3>Allocation Review</h3>
				<p class="of_hint">
					Items where total requested across Ready-to-Fill orders exceeds on-hand. Suggested quantities
					are straight-proportional; need level is self-reported context. Nothing here is enforced —
					fill and print however you choose.
				</p>
				<p v-if="!allocationRows.length" class="of_hint">Nothing is currently short.</p>
				<div v-for="row in allocationRows" :key="row.itemtype?.id" class="of_allocation_group">
					<h4>{{ row.itemtype?.name }} — {{ row.onHand }} on hand, {{ row.totalRequested }} requested</h4>
					<table class="ri_datatable" border="1">
						<thead>
							<tr><th>Order</th><th>Partner</th><th>Requested</th><th>Suggested</th><th>Need</th></tr>
						</thead>
						<tbody>
							<tr v-for="entry in row.lines" :key="entry.order.id + '-' + entry.line.id">
								<td>#{{ entry.order.id }}</td>
								<td>{{ personLabel(entry.order.person) }}</td>
								<td>{{ entry.line.qty_requested }}</td>
								<td>{{ entry.suggested }}</td>
								<td>{{ needLevelLabels[entry.line.need_level] || '—' }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="of_section">
				<h3>Ready to Fill</h3>
				<p v-if="!readyToFill.length && !listLoading">Nothing waiting to be filled.</p>
				<div v-else class="of_tablewrap"><table class="ri_datatable" border="1">
					<thead>
						<tr><th>Order</th><th>Partner</th><th>Lines</th><th></th></tr>
					</thead>
					<tbody>
						<tr v-for="order in readyToFill" :key="order.id">
							<td>#{{ order.id }}</td>
							<td>{{ personLabel(order.person) }}</td>
							<td>{{ (order.order_lines || []).length }}</td>
							<td><button @click="startFilling(order)" class="ri_formbutton">Start Filling</button></td>
						</tr>
					</tbody>
				</table></div>
			</div>

			<div class="of_section">
				<h3>Filling</h3>
				<p v-if="!filling.length && !listLoading">No orders currently being filled.</p>
				<div v-else class="of_tablewrap"><table class="ri_datatable" border="1">
					<thead>
						<tr><th>Order</th><th>Partner</th><th>Progress</th><th></th></tr>
					</thead>
					<tbody>
						<tr v-for="order in filling" :key="order.id">
							<td>#{{ order.id }}</td>
							<td>{{ personLabel(order.person) }}</td>
							<td>{{ orderProgress(order).filled }} of {{ orderProgress(order).total }} lines filled</td>
							<td>
								<button @click="continueFilling(order)" class="ri_formbutton">Continue</button>
								<button @click="reprintOrder(order)" class="ri_linkbutton">Print</button>
							</td>
						</tr>
					</tbody>
				</table></div>
			</div>
		</div>

		<!-- ======================= FILL SCREEN ======================= -->
		<div v-else-if="view === 'fill' && order" class="of_container">
			<div class="of_topbar">
				<button @click="backToList" class="ri_formbutton">&larr; Back</button>
				<span class="of_title">Order #{{ order.id }} — {{ personLabel(order.person) }}</span>
			</div>
			<p v-if="fillError" class="of_error">{{ fillError }}</p>

			<div class="of_tablewrap"><table class="ri_datatable" border="1">
				<thead>
					<tr><th>Item</th><th>Requested</th><th>Filled</th><th>On Hand</th><th>Add Fill</th></tr>
				</thead>
				<tbody>
					<tr v-for="line in orderLines" :key="line.id">
						<td>{{ line.itemtype?.name }}</td>
						<td>{{ line.qty_requested }}</td>
						<td>{{ lineFilled(line) }}</td>
						<td>{{ lineOnHand(line) }}</td>
						<td>
							<div class="of_fillentry">
								<SearchSelect
									v-if="itemsForItemtype(line.itemtype_id).length > 1"
									v-model="entryByLine[line.id].item_id"
									:options="itemsForItemtype(line.itemtype_id)"
									display="name"
									:searchfields="['name', 'upc', 'description']"
									placeholder="Scan or search item..."
									@selected="(item) => itemChosenForLine(line, item)"
								/>
								<button v-if="itemsForItemtype(line.itemtype_id).length > 1"
									@click="openScannerForLine(line.id)" class="ri_linkbutton">Scan</button>
								<input
									:ref="'qtyInput_' + line.id"
									type="number" min="0"
									v-model="entryByLine[line.id].qty"
									class="ri_forminput of_qty"
									placeholder="Qty"
									@keydown.enter.prevent="addFill(line)"
								/>
								<button @click="addFill(line)" class="ri_defaultbutton">Add</button>
							</div>
							<ul v-if="(line.item_ledgers || []).length" class="of_filllist">
								<li v-for="fill in line.item_ledgers" :key="fill.id">
									{{ fill.qty_subtracted }} &mdash; {{ fill.item?.name }}
									<template v-if="confirmingFillKey === fillKey(fill)">
										<button @click="deleteFill(line, fill)" class="of_confirm_delete">Delete?</button>
										<button @click="confirmingFillKey = null" class="ri_linkbutton">Keep</button>
									</template>
									<img v-else src="/img/delete-icon.webp" class="of_delete" @click="deleteFill(line, fill)" />
								</li>
							</ul>
						</td>
					</tr>
					<tr v-if="!orderLines.length">
						<td colspan="5">No lines on this order.</td>
					</tr>
				</tbody>
			</table></div>

			<div class="of_actions">
				<button :disabled="!allLinesAccounted() || savingComplete" @click="completeFilling" class="ri_defaultbutton">
					{{ savingComplete ? 'Completing…' : 'Complete Filling' }}
				</button>
				<span v-if="!allLinesAccounted()" class="of_hint">
					Every line needs at least one fill record (even 0) before this order can be marked Filled.
				</span>
			</div>
		</div>

		<!-- ======================= CAMERA SCAN MODAL ======================= -->
		<div v-if="showScanner" class="of_modal_overlay" @click.self="showScanner = false">
			<div class="of_modal">
				<h3>Scan Item</h3>
				<QrScanner @scanned="onScanned" />
				<button @click="showScanner = false" class="ri_formbutton">Cancel</button>
			</div>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.of_container {
	max-width: 1100px;
	margin: 0 auto;
	padding: 16px;
}
.of_headeractions {
	float: right;
	display: flex;
	gap: 8px;
}
.of_section {
	margin-top: 1.5em;
}
.of_tablewrap {
	overflow-x: auto;
}
.of_error {
	color: #b91c1c;
	margin: 8px 0;
}
.of_hint {
	color: #666;
	font-size: 0.85rem;
}
.of_card {
	border: 1px solid #ddd;
	border-radius: 8px;
	background: #f9f9f9;
	padding: 14px 16px;
	margin-top: 14px;
}
.of_allocation_group {
	margin-top: 12px;
}
.of_allocation_group h4 {
	font-weight: bold;
	margin-bottom: 4px;
}
.of_topbar {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}
.of_title {
	font-weight: bold;
	font-size: 1.15rem;
	flex: 1;
}
.of_fillentry {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
}
.of_qty {
	max-width: 6em;
}
.of_filllist {
	list-style: none;
	margin: 6px 0 0 0;
	padding: 0;
	font-size: 0.85rem;
}
.of_filllist li {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 2px 0;
}
.of_delete {
	width: 14px;
	height: 14px;
	cursor: pointer;
}
.of_confirm_delete {
	color: #b91c1c;
	background: none;
	border: none;
	cursor: pointer;
	font-size: 0.85rem;
	padding: 0;
}
.of_actions {
	margin-top: 16px;
	display: flex;
	align-items: center;
	gap: 12px;
}
.of_modal_overlay {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 50;
}
.of_modal {
	background: white;
	padding: 20px;
	border-radius: 8px;
	max-width: 90vw;
}
</style>
