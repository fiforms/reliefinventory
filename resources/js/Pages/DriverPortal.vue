<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- DriverPortal.vue

	Driver-facing, no login required: a driver signs in with phone + PIN
	(set by staff on the Shipping page) to see their own Ready to Ship /
	Shipped loads and upload the signed BOL once a delivery is done — that
	upload moves the order straight to Delivered. The exact same URL also
	works for staff (manage-orders): logged in with no driver session, they
	get a read-only view of every load currently out, across all drivers —
	see DriverPortalController's doc comment for the reasoning.

	Mirrors VolunteerKiosk.vue's dual-layout pattern: AuthenticatedLayout
	only when someone is actually logged in as staff, a bare div otherwise
	(a driver has no account at all).
-->

<script>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';

export default {
	components: { AuthenticatedLayout, Head },
	props: {
		breadcrumb: { type: Array },
		isStaffViewer: { type: Boolean, default: false },
		driverName: { type: String, default: null },
	},
	data() {
		return {
			signedInAs: this.driverName,
			loginForm: { phone: '', pin: '' },
			loggingIn: false,
			loginError: null,

			current: [],
			delivered: [],
			loading: false,
			loadError: null,

			uploadingId: null,
			uploadError: {},

			expandedIds: {},
			copiedId: null,
		};
	},
	computed: {
		isAuthenticated() {
			return !!this.$page.props.auth.user;
		},
		signedIn() {
			return this.isStaffViewer || !!this.signedInAs;
		},
	},
	methods: {
		totals(order) {
			const lines = order.order_lines || [];
			return { lines: lines.length, qty: lines.reduce((sum, l) => sum + (l.qty_requested || 0), 0) };
		},
		toggleDetails(order) {
			this.expandedIds = { ...this.expandedIds, [order.id]: !this.expandedIds[order.id] };
		},
		// Delivery destination isn't its own field on the order — it's the
		// partner org's address, or the specific shipment contact's address
		// when one was chosen at order entry (contact_person_id).
		deliveryAddress(order) {
			const source = order.contact_person || order.person;
			if (!source) return null;
			const line2 = [source.city, source.state, source.zip].filter(Boolean).join(', ');
			const lines = [source.address, line2].filter(Boolean);
			return lines.length ? lines : null;
		},
		mapsUrl(order) {
			const lines = this.deliveryAddress(order);
			if (!lines) return null;
			return 'https://maps.google.com/?q=' + encodeURIComponent(lines.join(', '));
		},
		async copyAddress(order) {
			const lines = this.deliveryAddress(order);
			if (!lines) return;
			try {
				await navigator.clipboard.writeText(lines.join(', '));
				this.copiedId = order.id;
				setTimeout(() => {
					if (this.copiedId === order.id) this.copiedId = null;
				}, 2000);
			} catch (e) {
				// clipboard access can be denied/unavailable — nothing more to do
			}
		},
		contactName(order) {
			return order.contact_name || order.contact_person?.full_name || order.person?.full_name || order.person?.organization || null;
		},
		contactPhone(order) {
			return order.contact_phone || order.contact_person?.phone || order.person?.phone || null;
		},
		// Only meaningful for fulfillment_method=delivery — pickup orders
		// don't carry a driver-relevant window (see OrderController::
		// complete(), which force-clears both fields for pickup).
		deliveryWindow(order) {
			if (order.fulfillment_method !== 'delivery') return null;
			const days = order.delivery_days || [];
			const dayLabel = days.length === 7 ? 'Any Day' : days.length ? days.join(', ') : null;
			return [dayLabel, order.preferred_time].filter(Boolean).join(' — ') || null;
		},
		async login() {
			this.loggingIn = true;
			this.loginError = null;
			try {
				const response = await axios.post('/driver-portal/login', this.loginForm);
				this.signedInAs = response.data.driverName;
				this.loginForm = { phone: '', pin: '' };
				await this.fetchLoads();
			} catch (e) {
				this.loginError = e.response?.data?.message || 'Could not sign in.';
			} finally {
				this.loggingIn = false;
			}
		},
		async logout() {
			await axios.post('/driver-portal/logout');
			this.signedInAs = null;
			this.current = [];
			this.delivered = [];
		},
		async fetchLoads() {
			this.loading = true;
			this.loadError = null;
			try {
				const response = await axios.get('/driver-portal/loads');
				this.current = response.data.current || [];
				this.delivered = response.data.delivered || [];
			} catch (e) {
				this.loadError = 'Could not load your assigned loads.';
			} finally {
				this.loading = false;
			}
		},
		triggerUpload(order) {
			// Vue 3 collects refs used inside v-for into an array, even
			// when the ref name is a unique per-item string like this one.
			this.$refs['fileInput' + order.id]?.[0]?.click();
		},
		async onFileChosen(order, event) {
			const file = event.target.files?.[0];
			event.target.value = '';
			if (!file) return;

			this.uploadingId = order.id;
			this.uploadError = { ...this.uploadError, [order.id]: null };
			const formData = new FormData();
			formData.append('file', file);
			try {
				await axios.post('/driver-portal/loads/' + order.id + '/bol', formData);
				await this.fetchLoads();
			} catch (e) {
				this.uploadError = { ...this.uploadError, [order.id]: e.response?.data?.message || 'Could not upload that file.' };
			} finally {
				this.uploadingId = null;
			}
		},
	},
	created() {
		if (this.signedIn) this.fetchLoads();
	},
};
</script>

<template>
	<Head title="Driver Portal" />
	<component :is="isAuthenticated ? 'AuthenticatedLayout' : 'div'" :breadcrumb="isAuthenticated ? breadcrumb : undefined">
		<div class="dp_page" :class="{ dp_page_bare: !isAuthenticated, dp_page_login: !signedIn && !isAuthenticated }">
			<a v-if="!signedIn && !isAuthenticated" href="/" class="dp_homelink">&larr; Home</a>
			<div class="dp_brand" :class="{ dp_title_centered: !signedIn && !isAuthenticated }">Relief Inventory</div>
			<h1 class="dp_title" :class="{ dp_title_centered: !signedIn && !isAuthenticated }">Driver Portal</h1>

			<template v-if="!signedIn">
				<div class="dp_loginwrap">
					<p class="dp_hint">Enter the phone number and PIN your warehouse contact gave you.</p>
					<div class="dp_loginform">
						<input type="tel" v-model="loginForm.phone" placeholder="Phone Number" class="ri_forminput dp_input" />
						<input type="password" inputmode="numeric" pattern="[0-9]*" v-model="loginForm.pin" placeholder="PIN" class="ri_forminput dp_input" maxlength="5" />
						<button class="ri_defaultbutton dp_loginbutton" :disabled="loggingIn || !loginForm.phone || !loginForm.pin" @click="login">
							{{ loggingIn ? 'Signing In...' : 'Sign In' }}
						</button>
						<p v-if="loginError" class="dp_error">{{ loginError }}</p>
					</div>
				</div>
			</template>

			<template v-else>
				<p v-if="signedInAs" class="dp_hint">
					Signed in as <strong>{{ signedInAs }}</strong>.
					<button class="dp_logoutlink" @click="logout">Sign Out</button>
				</p>
				<p v-else-if="isStaffViewer" class="dp_hint">Staff view — every load currently Ready to Ship or Shipped, across all drivers.</p>

				<p v-if="loadError" class="dp_error">{{ loadError }}</p>
				<p v-if="loading">Loading...</p>

				<h2 class="dp_sectionhead">Current Loads ({{ current.length }})</h2>
				<div v-for="order in current" :key="order.id" class="dp_card">
					<div class="dp_cardhead">
						<span class="dp_ordernum">Order #{{ order.id }}</span>
						<span class="dp_status_badge">{{ order.status?.name }}</span>
					</div>
					<p class="dp_partner">{{ order.person?.full_name || order.person?.organization || '(no partner)' }}</p>
					<p class="dp_meta">
						{{ totals(order).lines }} line(s), {{ totals(order).qty }} item(s)<span v-if="order.pallet_count"> — {{ order.pallet_count }} pallet{{ order.pallet_count === 1 ? '' : 's' }}</span><span v-if="isStaffViewer && order.driver"> — driver: {{ order.driver.name }}</span>
					</p>
					<p v-if="order.special_instructions" class="dp_instructions">{{ order.special_instructions }}</p>
					<p v-if="order.bol_rejection_reason" class="dp_rejection">
						<strong>The last upload was rejected:</strong> {{ order.bol_rejection_reason }}
					</p>

					<div v-if="expandedIds[order.id]" class="dp_details">
						<div v-if="deliveryAddress(order)" class="dp_detailrow">
							<span class="dp_detaillabel">Address</span>
							<a :href="mapsUrl(order)" target="_blank" rel="noopener" class="dp_addresslink">
								<span v-for="(line, i) in deliveryAddress(order)" :key="i">{{ line }}<br v-if="i < deliveryAddress(order).length - 1" /></span>
							</a>
							<button class="dp_copybutton" @click="copyAddress(order)">{{ copiedId === order.id ? 'Copied!' : 'Copy' }}</button>
						</div>
						<div v-if="contactName(order) || contactPhone(order)" class="dp_detailrow">
							<span class="dp_detaillabel">Contact</span>
							<span>{{ contactName(order) }}</span>
							<a v-if="contactPhone(order)" :href="'tel:' + contactPhone(order)" class="dp_phonelink">{{ contactPhone(order) }}</a>
						</div>
						<div v-if="deliveryWindow(order)" class="dp_detailrow">
							<span class="dp_detaillabel">Window</span>
							<span>{{ deliveryWindow(order) }}</span>
						</div>
						<p v-if="!deliveryAddress(order) && !contactName(order) && !contactPhone(order) && !deliveryWindow(order)" class="dp_hint">No delivery address or contact on file for this order.</p>
					</div>

					<div class="dp_cardactions">
						<button class="ri_formbutton dp_detailstoggle" @click="toggleDetails(order)">
							{{ expandedIds[order.id] ? 'Hide Details ▲' : 'Delivery Details ▼' }}
						</button>
						<template v-if="!isStaffViewer">
							<input
								type="file"
								accept="image/*,.pdf"
								class="dp_hiddenfile"
								:ref="'fileInput' + order.id"
								@change="onFileChosen(order, $event)"
							/>
							<button class="ri_defaultbutton" :disabled="uploadingId === order.id" @click="triggerUpload(order)">
								{{ uploadingId === order.id ? 'Uploading...' : 'Upload Signed BOL' }}
							</button>
						</template>
					</div>
					<p v-if="!isStaffViewer && uploadError[order.id]" class="dp_error">{{ uploadError[order.id] }}</p>
				</div>
				<p v-if="!current.length && !loading" class="dp_empty">No loads currently out.</p>

				<h2 class="dp_sectionhead">Recently Delivered</h2>
				<div v-for="order in delivered" :key="order.id" class="dp_card dp_card_muted">
					<div class="dp_cardhead">
						<span class="dp_ordernum">Order #{{ order.id }}</span>
						<span class="dp_status_badge dp_status_badge_done">Delivered</span>
					</div>
					<p class="dp_partner">{{ order.person?.full_name || order.person?.organization || '(no partner)' }}</p>
				</div>
				<p v-if="!delivered.length && !loading" class="dp_empty">Nothing delivered yet.</p>
			</template>
		</div>
	</component>
</template>

<style scoped>
.dp_page {
	max-width: 640px;
	margin: 0 auto;
	padding: 16px;
}
.dp_page_bare {
	padding-top: 48px;
}
/* The login screen only (bare device, nobody signed in yet) — top-aligned
   like the rest of the bare layout (not vertically centered: centering
   looked fine at rest, but the on-screen keyboard shrinking the viewport
   to enter phone/PIN made it jump around). Extra top padding just clears
   the Home link, which sits in normal flow above the title. */
.dp_page_login {
	padding-top: 48px;
}
.dp_homelink {
	display: inline-block;
	margin-bottom: 16px;
	color: #4338ca;
	text-decoration: none;
	font-weight: 600;
}
.dp_homelink:hover {
	text-decoration: underline;
}
.dp_loginwrap {
	text-align: center;
}
/* Identifies this as part of Relief Inventory — a driver has no account
   and no site nav to otherwise tell them what app they're using. Matches
   the Welcome page header's title styling (text-xl sm:text-2xl font-bold
   text-blue-600) for consistency across every entry point. */
.dp_brand {
	font-size: 1.25rem;
	font-weight: 700;
	color: #2563eb;
	margin-bottom: 4px;
}
@media (min-width: 640px) {
	.dp_brand {
		font-size: 1.5rem;
	}
}
.dp_title {
	font-size: 1.4rem;
	margin: 0 0 12px 0;
}
.dp_title_centered {
	text-align: center;
}
.dp_hint {
	color: #666;
	margin-bottom: 16px;
}
.dp_loginform {
	display: flex;
	flex-direction: column;
	gap: 10px;
	max-width: 320px;
	margin: 0 auto;
}
.dp_input {
	font-size: 1.1rem;
	padding: 10px;
}
.dp_loginbutton {
	font-size: 1.1rem;
	padding: 10px;
}
.dp_logoutlink {
	background: none;
	border: none;
	color: #4338ca;
	text-decoration: underline;
	cursor: pointer;
	margin-left: 8px;
}
.dp_sectionhead {
	font-size: 1rem;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: #666;
	border-bottom: 1px solid #ccc;
	padding-bottom: 4px;
	margin: 24px 0 10px 0;
}
.dp_card {
	border: 1px solid #ddd;
	border-radius: 8px;
	padding: 12px 14px;
	margin-bottom: 10px;
}
.dp_card_muted {
	opacity: 0.75;
}
.dp_cardhead {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 4px;
}
.dp_ordernum {
	font-weight: bold;
}
.dp_status_badge {
	background: #fef3c7;
	color: #92400e;
	font-size: 0.7rem;
	font-weight: bold;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	padding: 2px 6px;
	border-radius: 8px;
}
.dp_status_badge_done {
	background: #dcfce7;
	color: #166534;
}
.dp_partner {
	margin: 2px 0;
}
.dp_meta {
	color: #666;
	font-size: 0.9rem;
	margin: 2px 0 8px 0;
}
.dp_instructions {
	background: #fffbeb;
	border: 1px solid #fde68a;
	border-radius: 4px;
	padding: 6px 8px;
	font-size: 0.9rem;
	margin-bottom: 8px;
}
.dp_rejection {
	background: #fef2f2;
	border: 1px solid #fecaca;
	color: #991b1b;
	border-radius: 4px;
	padding: 6px 8px;
	font-size: 0.9rem;
	margin-bottom: 8px;
}
.dp_cardactions {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 4px;
}
.dp_detailstoggle {
	font-size: 0.85rem;
}
.dp_details {
	background: #f9fafb;
	border: 1px solid #e5e7eb;
	border-radius: 4px;
	padding: 8px 10px;
	margin-bottom: 10px;
	font-size: 0.9rem;
}
.dp_detailrow {
	display: flex;
	align-items: baseline;
	flex-wrap: wrap;
	gap: 8px;
	padding: 4px 0;
}
.dp_detailrow + .dp_detailrow {
	border-top: 1px solid #e5e7eb;
}
.dp_detaillabel {
	font-weight: bold;
	color: #666;
	font-size: 0.75rem;
	text-transform: uppercase;
	letter-spacing: 0.03em;
	min-width: 60px;
}
.dp_addresslink {
	color: #1d4ed8;
}
.dp_phonelink {
	color: #1d4ed8;
	font-weight: bold;
}
.dp_copybutton {
	background: #eef2ff;
	border: 1px solid #c7d2fe;
	color: #4338ca;
	border-radius: 4px;
	padding: 2px 8px;
	font-size: 0.8rem;
	cursor: pointer;
}
.dp_hiddenfile {
	display: none;
}
.dp_empty {
	color: #777;
	padding: 0.5em 0;
}
.dp_error {
	color: #b91c1c;
	margin: 6px 0;
}
</style>
