<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Shipping.vue

	Staff side of Filled -> Ready to Ship -> Shipped: assign a driver to a
	Filled order (which also moves it to Ready to Ship), mark an order
	Shipped once the truck actually leaves the dock (a manual, staff-
	observed action — see ShippingController's doc comment), and manage the
	driver directory's Driver Portal PINs. Delivered (the order's practical
	terminus, once the driver's signed BOL comes back) happens over at
	/driver-portal, not here.

	Four sections, one per status, kept separate rather than merged — an
	earlier cut of this page combined Ready to Ship + Shipped into one
	"assigned" section and that was confusing in practice (Mark,
	2026-08-27): Filled = waiting for a driver, Ready to Ship = a driver is
	assigned but hasn't left yet, Shipped = staff confirmed it left the
	dock, Delivered = the driver's signed BOL is back.
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import Modal from '@/Components/Modal.vue';
import axios from 'axios';

export default {
	components: { AuthenticatedLayout, Head, TextInput, Modal },
	props: {
		breadcrumb: { type: Array },
	},
	data() {
		return {
			filled: [],
			readyToShip: [],
			shipped: [],
			delivered: [],
			completed: [],
			drivers: [],
			loading: false,
			error: null,

			assignDriverId: {}, // orderId -> selected driver_id, for the picker
			assigning: {}, // orderId -> bool
			shipping: {}, // orderId -> bool

			showNewDriver: false,
			newDriver: { name: '', phone: '', carrier: '' },
			savingDriver: false,
			driverError: null,

			pinDriverId: null, // which driver's PIN form is open
			pinForm: { pin: '', pin_confirmation: '' },
			savingPin: false,
			pinError: null,

			// Manager review of a Delivered order's signed BOL: a lightweight
			// drag-a-rectangle crop tool over the uploaded image (skipped for
			// a PDF upload — canvas cropping only works on a rendered image).
			reviewOrder: null,
			reviewImageFailed: false,
			reviewObjectUrl: null, // blob: URL for the fetched signed BOL — revoked on close
			crop: { x: 0, y: 0, w: 0, h: 0, startX: 0, startY: 0, dragging: false },
			reviewApproving: false,
			showRejectForm: false,
			rejectReason: '',
			reviewRejecting: false,
			reviewError: null,
		};
	},
	computed: {
		reviewImageUrl() {
			return this.reviewOrder ? '/json/shipping/' + this.reviewOrder.id + '/signed-bol' : null;
		},
		cropBoxStyle() {
			return { left: this.crop.x + 'px', top: this.crop.y + 'px', width: this.crop.w + 'px', height: this.crop.h + 'px' };
		},
		hasCropSelection() {
			return this.crop.w > 4 && this.crop.h > 4;
		},
	},
	methods: {
		async fetchAll() {
			this.loading = true;
			this.error = null;
			try {
				const response = await axios.get('/json/shipping');
				this.filled = response.data.filled || [];
				this.readyToShip = response.data.ready_to_ship || [];
				this.shipped = response.data.shipped || [];
				this.delivered = response.data.delivered || [];
				this.completed = response.data.completed || [];
				this.drivers = response.data.drivers || [];
			} catch (e) {
				this.error = 'Could not load the shipping queue.';
			} finally {
				this.loading = false;
			}
		},
		totals(order) {
			const lines = order.order_lines || order.orderLines || [];
			return { lines: lines.length, qty: lines.reduce((sum, l) => sum + (l.qty_requested || 0), 0) };
		},
		async assignDriver(order) {
			const driverId = this.assignDriverId[order.id];
			if (!driverId) return;
			this.assigning = { ...this.assigning, [order.id]: true };
			this.error = null;
			try {
				await axios.patch('/json/shipping/' + order.id + '/assign', { driver_id: driverId });
				await this.fetchAll();
			} catch (e) {
				this.error = e.response?.data?.message || 'Could not assign that driver.';
			} finally {
				this.assigning = { ...this.assigning, [order.id]: false };
			}
		},
		async markShipped(order) {
			this.shipping = { ...this.shipping, [order.id]: true };
			this.error = null;
			try {
				await axios.patch('/json/shipping/' + order.id + '/mark-shipped');
				await this.fetchAll();
			} catch (e) {
				this.error = e.response?.data?.message || 'Could not mark that order Shipped.';
			} finally {
				this.shipping = { ...this.shipping, [order.id]: false };
			}
		},
		async saveNewDriver() {
			this.savingDriver = true;
			this.driverError = null;
			try {
				await axios.post('/json/drivers', this.newDriver);
				this.newDriver = { name: '', phone: '', carrier: '' };
				this.showNewDriver = false;
				await this.fetchAll();
			} catch (e) {
				this.driverError = e.response?.data?.message || 'Could not save that driver.';
			} finally {
				this.savingDriver = false;
			}
		},
		openPinForm(driver) {
			this.pinDriverId = driver.id;
			this.pinForm = { pin: '', pin_confirmation: '' };
			this.pinError = null;
		},
		async savePin(driver) {
			this.savingPin = true;
			this.pinError = null;
			try {
				await axios.post('/json/drivers/' + driver.id + '/set-pin', this.pinForm);
				this.pinDriverId = null;
				await this.fetchAll();
			} catch (e) {
				this.pinError = e.response?.data?.errors?.pin?.[0] || e.response?.data?.message || 'Could not set that PIN.';
			} finally {
				this.savingPin = false;
			}
		},

		// Fetched once as a blob (rather than pointed at directly via <img
		// src>) so the real Content-Type can be checked — a PDF loaded into
		// an <img> doesn't reliably fire @error across browsers, which
		// would leave the crop tool showing over an undecodable file.
		async openReview(order) {
			this.reviewOrder = order;
			this.reviewImageFailed = false;
			this.showRejectForm = false;
			this.rejectReason = '';
			this.reviewError = null;
			this.reviewObjectUrl = null;
			this.resetCrop();

			try {
				const response = await axios.get('/json/shipping/' + order.id + '/signed-bol', { responseType: 'blob' });
				this.reviewObjectUrl = URL.createObjectURL(response.data);
				this.reviewImageFailed = !response.data.type?.startsWith('image/');
			} catch (e) {
				this.reviewImageFailed = true;
				this.reviewError = 'Could not load the signed BOL file.';
			}
		},
		closeReview() {
			if (this.reviewObjectUrl) URL.revokeObjectURL(this.reviewObjectUrl);
			this.reviewObjectUrl = null;
			this.reviewOrder = null;
		},
		resetCrop() {
			this.crop = { x: 0, y: 0, w: 0, h: 0, startX: 0, startY: 0, dragging: false };
		},
		startCrop(e) {
			if (this.reviewImageFailed) return;
			const rect = this.$refs.cropWrap.getBoundingClientRect();
			const x = e.clientX - rect.left;
			const y = e.clientY - rect.top;
			this.crop = { x, y, w: 0, h: 0, startX: x, startY: y, dragging: true };
		},
		dragCrop(e) {
			if (!this.crop.dragging) return;
			const rect = this.$refs.cropWrap.getBoundingClientRect();
			const curX = Math.min(Math.max(e.clientX - rect.left, 0), rect.width);
			const curY = Math.min(Math.max(e.clientY - rect.top, 0), rect.height);
			this.crop.x = Math.min(curX, this.crop.startX);
			this.crop.y = Math.min(curY, this.crop.startY);
			this.crop.w = Math.abs(curX - this.crop.startX);
			this.crop.h = Math.abs(curY - this.crop.startY);
		},
		endCrop() {
			this.crop.dragging = false;
		},
		// Maps the on-screen selection rectangle back to the image's real
		// (natural) resolution and draws just that region onto an offscreen
		// canvas — same-origin image, so the canvas is never tainted.
		buildCroppedFile() {
			const img = this.$refs.reviewImg;
			const displayRect = this.$refs.cropWrap.getBoundingClientRect();
			const scaleX = img.naturalWidth / displayRect.width;
			const scaleY = img.naturalHeight / displayRect.height;
			const sx = this.crop.x * scaleX;
			const sy = this.crop.y * scaleY;
			const sw = this.crop.w * scaleX;
			const sh = this.crop.h * scaleY;

			const canvas = document.createElement('canvas');
			canvas.width = sw;
			canvas.height = sh;
			canvas.getContext('2d').drawImage(img, sx, sy, sw, sh, 0, 0, sw, sh);

			return new Promise((resolve) => {
				canvas.toBlob((blob) => resolve(new File([blob], 'signed-bol-cropped.jpg', { type: 'image/jpeg' })), 'image/jpeg', 0.85);
			});
		},
		async approveReview() {
			const order = this.reviewOrder;
			this.reviewApproving = true;
			this.reviewError = null;
			try {
				const formData = new FormData();
				if (this.hasCropSelection) {
					formData.append('file', await this.buildCroppedFile());
				}
				await axios.post('/json/shipping/' + order.id + '/approve', formData);
				this.closeReview();
				await this.fetchAll();
			} catch (e) {
				this.reviewError = e.response?.data?.message || 'Could not approve that BOL.';
			} finally {
				this.reviewApproving = false;
			}
		},
		async rejectReview() {
			const order = this.reviewOrder;
			this.reviewRejecting = true;
			this.reviewError = null;
			try {
				await axios.post('/json/shipping/' + order.id + '/reject', { reason: this.rejectReason });
				this.closeReview();
				await this.fetchAll();
			} catch (e) {
				this.reviewError = e.response?.data?.message || 'Could not reject that BOL.';
			} finally {
				this.reviewRejecting = false;
			}
		},
	},
	created() {
		this.fetchAll();
	},
};
</script>

<template>
	<Head title="Shipping" />
	<AuthenticatedLayout :breadcrumb="breadcrumb">
		<template #header></template>

		<div class="ship_container">
			<p v-if="error" class="ship_error">{{ error }}</p>
			<p v-if="loading">Loading...</p>

			<h2 class="ri_datatable_head">Filled — Ready to Assign a Driver ({{ filled.length }})</h2>
			<p class="ship_hint">Assigning a driver moves the order to Ready to Ship.</p>
			<div class="ship_tablewrap"><table class="ri_datatable" border="1">
				<thead>
					<tr><th>Order #</th><th>Partner</th><th>Lines</th><th>Qty</th><th>Driver</th><th></th></tr>
				</thead>
				<tbody>
					<tr v-for="order in filled" :key="order.id">
						<td>{{ order.id }}</td>
						<td>{{ order.person?.full_name || order.person?.organization || '(no partner)' }}</td>
						<td class="ship_num">{{ totals(order).lines }}</td>
						<td class="ship_num">{{ totals(order).qty }}</td>
						<td>
							<select v-model="assignDriverId[order.id]" class="ri_forminput">
								<option :value="undefined" disabled>Select a driver...</option>
								<option v-for="d in drivers" :key="d.id" :value="d.id">{{ d.name }}</option>
							</select>
						</td>
						<td>
							<button
								class="ri_defaultbutton"
								:disabled="!assignDriverId[order.id] || assigning[order.id]"
								@click="assignDriver(order)"
							>{{ assigning[order.id] ? 'Assigning...' : 'Assign' }}</button>
						</td>
					</tr>
					<tr v-if="!filled.length && !loading"><td colspan="6" class="ship_empty">No Filled orders waiting for a driver.</td></tr>
				</tbody>
			</table></div>

			<h2 class="ri_datatable_head ship_sectionhead">Ready to Ship ({{ readyToShip.length }})</h2>
			<p class="ship_hint">A driver is assigned. Mark Shipped once the truck actually leaves the dock.</p>
			<div class="ship_tablewrap"><table class="ri_datatable" border="1">
				<thead>
					<tr><th>Order #</th><th>Partner</th><th>Driver</th><th>Generated BOL</th><th></th></tr>
				</thead>
				<tbody>
					<tr v-for="order in readyToShip" :key="order.id">
						<td>{{ order.id }}</td>
						<td>{{ order.person?.full_name || order.person?.organization || '(no partner)' }}</td>
						<td>{{ order.driver?.name }}<span v-if="order.driver?.phone"> — {{ order.driver.phone }}</span></td>
						<td><a :href="'/report/bol/' + order.id + '.pdf'" target="_blank">View BOL</a></td>
						<td>
							<button
								class="ri_defaultbutton"
								:disabled="shipping[order.id]"
								@click="markShipped(order)"
							>{{ shipping[order.id] ? 'Marking...' : 'Mark Shipped' }}</button>
						</td>
					</tr>
					<tr v-if="!readyToShip.length && !loading"><td colspan="5" class="ship_empty">Nothing currently Ready to Ship.</td></tr>
				</tbody>
			</table></div>

			<h2 class="ri_datatable_head ship_sectionhead">Shipped ({{ shipped.length }})</h2>
			<p class="ship_hint">Left the dock. Waiting for the driver to upload a signed BOL through the Driver Portal.</p>
			<div class="ship_tablewrap"><table class="ri_datatable" border="1">
				<thead>
					<tr><th>Order #</th><th>Partner</th><th>Driver</th><th>Generated BOL</th></tr>
				</thead>
				<tbody>
					<tr v-for="order in shipped" :key="order.id">
						<td>{{ order.id }}</td>
						<td>{{ order.person?.full_name || order.person?.organization || '(no partner)' }}</td>
						<td>{{ order.driver?.name }}<span v-if="order.driver?.phone"> — {{ order.driver.phone }}</span></td>
						<td><a :href="'/report/bol/' + order.id + '.pdf'" target="_blank">View BOL</a></td>
					</tr>
					<tr v-if="!shipped.length && !loading"><td colspan="4" class="ship_empty">Nothing currently Shipped.</td></tr>
				</tbody>
			</table></div>

			<h2 class="ri_datatable_head ship_sectionhead">Delivered — Pending Review ({{ delivered.length }})</h2>
			<p class="ship_hint">Signed BOL uploaded by the driver. Review it to approve (moves to Completed) or reject (kicks it back to the driver).</p>
			<div class="ship_tablewrap"><table class="ri_datatable" border="1">
				<thead>
					<tr><th>Order #</th><th>Partner</th><th>Driver</th><th>Delivered</th><th></th></tr>
				</thead>
				<tbody>
					<tr v-for="order in delivered" :key="order.id">
						<td>{{ order.id }}</td>
						<td>{{ order.person?.full_name || order.person?.organization || '(no partner)' }}</td>
						<td>{{ order.driver?.name }}</td>
						<td>{{ order.status_changed_at ? new Date(order.status_changed_at).toLocaleDateString() : '' }}</td>
						<td><button class="ri_defaultbutton" @click="openReview(order)">Review</button></td>
					</tr>
					<tr v-if="!delivered.length && !loading"><td colspan="5" class="ship_empty">Nothing awaiting review.</td></tr>
				</tbody>
			</table></div>

			<h2 class="ri_datatable_head ship_sectionhead">Recently Completed ({{ completed.length }})</h2>
			<p class="ship_hint">Signed BOL approved — most recent 25.</p>
			<div class="ship_tablewrap"><table class="ri_datatable" border="1">
				<thead>
					<tr><th>Order #</th><th>Partner</th><th>Driver</th><th>Completed</th><th>Signed BOL</th></tr>
				</thead>
				<tbody>
					<tr v-for="order in completed" :key="order.id">
						<td>{{ order.id }}</td>
						<td>{{ order.person?.full_name || order.person?.organization || '(no partner)' }}</td>
						<td>{{ order.driver?.name }}</td>
						<td>{{ order.status_changed_at ? new Date(order.status_changed_at).toLocaleDateString() : '' }}</td>
						<td><a :href="'/json/shipping/' + order.id + '/signed-bol'" target="_blank">View Signed BOL</a></td>
					</tr>
					<tr v-if="!completed.length && !loading"><td colspan="5" class="ship_empty">Nothing completed yet.</td></tr>
				</tbody>
			</table></div>

			<Modal :show="!!reviewOrder" @close="closeReview" max-width="2xl">
				<div class="ship_reviewmodal" v-if="reviewOrder">
					<h3 class="ship_reviewtitle">Review Signed BOL — Order #{{ reviewOrder.id }}</h3>
					<p class="ship_hint">{{ reviewOrder.person?.full_name || reviewOrder.person?.organization }} — driver: {{ reviewOrder.driver?.name }}</p>

					<template v-if="!reviewImageFailed">
						<p class="ship_hint">Drag to select the area to keep, then Approve — or Approve as-is to skip cropping.</p>
						<div
							ref="cropWrap"
							class="ship_cropwrap"
							@pointerdown="startCrop"
							@pointermove="dragCrop"
							@pointerup="endCrop"
							@pointerleave="endCrop"
						>
							<img
								ref="reviewImg"
								:src="reviewObjectUrl"
								class="ship_reviewimg"
							/>
							<div v-if="crop.w > 0" class="ship_cropbox" :style="cropBoxStyle"></div>
						</div>
						<button v-if="hasCropSelection" class="ship_pinlink" @click="resetCrop">Clear selection</button>
					</template>
					<template v-else>
						<p class="ship_hint">Can't preview this file as an image (likely a PDF).
							<a :href="reviewImageUrl" target="_blank">Open it in a new tab</a> to review.
						</p>
					</template>

					<p v-if="reviewError" class="ship_error">{{ reviewError }}</p>

					<div v-if="showRejectForm" class="ship_rejectform">
						<textarea v-model="rejectReason" class="ri_forminput" rows="2" placeholder="What's wrong with it? (shown to the driver)"></textarea>
						<div class="ship_reviewactions">
							<button class="ship_pinlink" @click="showRejectForm = false">Cancel</button>
							<button class="ri_defaultbutton ship_rejectbutton" :disabled="reviewRejecting" @click="rejectReview">
								{{ reviewRejecting ? 'Rejecting...' : 'Confirm Reject' }}
							</button>
						</div>
					</div>
					<div v-else class="ship_reviewactions">
						<button class="ship_rejectbutton ri_defaultbutton" @click="showRejectForm = true">Reject</button>
						<button class="ri_defaultbutton" :disabled="reviewApproving" @click="approveReview">
							{{ reviewApproving ? 'Approving...' : (hasCropSelection ? 'Approve Cropped' : 'Approve') }}
						</button>
					</div>
				</div>
			</Modal>

			<h2 class="ri_datatable_head ship_sectionhead">
				Drivers
				<button class="ri_defaultbutton ship_titleaction" @click="showNewDriver = !showNewDriver">
					{{ showNewDriver ? 'Cancel' : '+ New Driver' }}
				</button>
			</h2>
			<div v-if="showNewDriver" class="ship_newdriver">
				<TextInput v-model="newDriver.name" placeholder="Name" class="ri_forminput" />
				<TextInput v-model="newDriver.phone" placeholder="Phone" class="ri_forminput" />
				<TextInput v-model="newDriver.carrier" placeholder="Carrier (if a trucking company)" class="ri_forminput" />
				<button class="ri_defaultbutton" :disabled="!newDriver.name || savingDriver" @click="saveNewDriver">
					{{ savingDriver ? 'Saving...' : 'Save Driver' }}
				</button>
				<p v-if="driverError" class="ship_error">{{ driverError }}</p>
			</div>
			<div class="ship_tablewrap"><table class="ri_datatable" border="1">
				<thead>
					<tr><th>Name</th><th>Phone</th><th>Carrier</th><th>Driver Portal PIN</th></tr>
				</thead>
				<tbody>
					<template v-for="d in drivers" :key="d.id">
						<tr>
							<td>{{ d.name }}</td>
							<td>{{ d.phone }}</td>
							<td>{{ d.carrier }}</td>
							<td>
								<span v-if="d.has_pin">Set</span>
								<span v-else class="ship_nopin">Not set</span>
								<button class="ship_pinlink" @click="openPinForm(d)">{{ d.has_pin ? 'Reset PIN' : 'Set PIN' }}</button>
							</td>
						</tr>
						<tr v-if="pinDriverId === d.id">
							<td colspan="4" class="ship_pinrow">
								<input type="password" v-model="pinForm.pin" placeholder="5-digit PIN" class="ri_forminput" maxlength="5" />
								<input type="password" v-model="pinForm.pin_confirmation" placeholder="Confirm PIN" class="ri_forminput" maxlength="5" />
								<button class="ri_defaultbutton" :disabled="savingPin" @click="savePin(d)">
									{{ savingPin ? 'Saving...' : 'Save PIN' }}
								</button>
								<button class="ship_pinlink" @click="pinDriverId = null">Cancel</button>
								<p v-if="pinError" class="ship_error">{{ pinError }}</p>
							</td>
						</tr>
					</template>
					<tr v-if="!drivers.length"><td colspan="4" class="ship_empty">No drivers in the directory yet.</td></tr>
				</tbody>
			</table></div>
			<p class="ship_hint">Give a driver their phone number + PIN so they can sign into <a href="/driver-portal">the Driver Portal</a> and upload a signed BOL for their own loads.</p>
		</div>
	</AuthenticatedLayout>
</template>

<style scoped>
.ship_container {
	max-width: 1200px;
	margin: 0 auto;
	padding: 16px;
}
.ship_sectionhead {
	margin-top: 28px;
	display: flex;
	align-items: center;
	justify-content: space-between;
}
.ship_hint {
	color: #666;
	font-size: 0.9rem;
	margin: 4px 0 12px 0;
}
.ship_tablewrap {
	overflow-x: auto;
}
.ship_num {
	text-align: right;
	font-variant-numeric: tabular-nums;
}
.ship_empty {
	text-align: center;
	color: #777;
	padding: 1em;
}
.ship_error {
	color: #b91c1c;
	margin: 6px 0;
}
.ship_titleaction {
	font-size: 0.85rem;
}
.ship_newdriver {
	display: flex;
	gap: 10px;
	align-items: center;
	flex-wrap: wrap;
	margin-bottom: 12px;
}
.ship_pinrow {
	background: #fafafa;
	display: flex;
	gap: 10px;
	align-items: center;
	flex-wrap: wrap;
	padding: 10px;
}
.ship_pinlink {
	background: none;
	border: none;
	color: #4338ca;
	text-decoration: underline;
	cursor: pointer;
	margin-left: 8px;
	font-size: 0.85rem;
}
.ship_nopin {
	color: #b91c1c;
}
.ship_reviewmodal {
	padding: 20px 24px;
}
.ship_reviewtitle {
	margin: 0 0 4px 0;
	font-size: 1.1rem;
}
.ship_cropwrap {
	position: relative;
	display: inline-block;
	max-width: 100%;
	margin: 10px 0;
	touch-action: none;
	cursor: crosshair;
}
.ship_reviewimg {
	display: block;
	max-width: 100%;
	max-height: 60vh;
	user-select: none;
}
.ship_cropbox {
	position: absolute;
	border: 2px dashed #4338ca;
	background: rgba(67, 56, 202, 0.15);
	pointer-events: none;
}
.ship_rejectform {
	margin-top: 12px;
}
.ship_rejectform textarea {
	width: 100%;
	resize: vertical;
}
.ship_reviewactions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 14px;
}
.ship_rejectbutton {
	background: #b91c1c;
}
</style>
