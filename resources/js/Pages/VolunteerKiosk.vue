<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- VolunteerKiosk.vue

	The facility sign-in kiosk (PROJECT_ANALYSIS.md Part 5). Touch-first,
	not built on RIForm — closer in spirit to DonationSorting.vue's custom
	session flow than to an admin CRUD form.

	Redesigned 2026-08-23 per the phone-Claude handoff (see
	volunteer-kiosk-phone-design-handoff-2026-08-23 memory) to be
	type-ahead-first rather than browse-first: default view is just a
	search box, auto-focused, with no tile grid until something's typed —
	deliberately no tap-from-a-full-list. Matching against every person
	(not just the active roster) so a deactivated regular or a first-time
	walk-in who already has a Person record can still be found. Guest and
	New Volunteer are separate always-visible buttons alongside the search
	box, not folded into the results.

	One smart tile, same tap target for sign-in and sign-out: a tile shows
	"signed in since ..." when the person has an open (or
	pending_confirmation) sign-in, tapping it opens a sign-out confirm
	screen instead of a fresh sign-in form. Agency/title_function/
	work_site/description_of_work are per-sign-in, not stored on Person
	(an agency can change visit to visit) — the confirm screen prefills
	them as a *suggestion* from the person's most recent closed sign-in
	(last_sign_in), never a stored fact. New Volunteer and Guest skip this
	confirm screen entirely — they log immediately on their own quick
	form's submit, per the handoff doc.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import TextArea from '@/Components/TextArea.vue';
import InputLabel from '@/Components/InputLabel.vue';

defineProps({
	breadcrumb: {
		type: Array,
	},
});
</script>

<template>
	<Head title="Volunteer Kiosk" />
	<!-- Guest kiosk mode (nobody logged in on this device, see
	     EnsureKioskAccess) skips AuthenticatedLayout entirely — that layout
	     assumes $page.props.auth.user exists (nav, profile menu), which is
	     null here. A bare wrapper with a deliberately unobtrusive "Staff
	     Login" link is the only way back to the full app. -->
	<component :is="isAuthenticated ? AuthenticatedLayout : 'div'" :breadcrumb="isAuthenticated ? breadcrumb : undefined">
		<div class="vk_page">
			<div v-if="!isAuthenticated" class="vk_stafflogin">
				<a href="/login">Staff Login</a>
			</div>
			<div v-if="isAuthenticated && view === 'grid'" class="vk_kioskmode_bar">
				<button type="button" class="ri_formbutton" :disabled="enablingKioskMode" @click="enableKioskMode">
					Enable Kiosk Mode
				</button>
				<span class="vk_hint">Locks this device to sign-in only, no login required, until someone logs in again.</span>
			</div>

			<div v-if="view === 'grid' || view === 'safety' || view === 'emergency-list'" class="vk_safetybar">
				<span>{{ occupancyCount === null ? '…' : occupancyCount }} in the building</span>
				<button type="button" class="ri_formbutton" @click="openEmergencyList">Emergency List — Current Building Occupancy</button>
				<!-- No login needed above: a firefighter sweeping the building
				     can't be expected to know anyone's PIN. Closeout stays
				     behind staff login, off the front screen, since it's a
				     routine end-of-day action, not an emergency one. -->
				<button v-if="isAuthenticated" type="button" class="ri_formbutton" @click="openSafety('closeout')">Confirm Building Empty</button>
			</div>

			<!-- ---------------- default (type-ahead) view ---------------- -->
			<template v-if="view === 'grid'">
				<div class="vk_searchbar">
					<input
						ref="searchInput"
						v-model="searchQuery"
						type="text"
						class="ri_forminput vk_searchinput"
						placeholder="Start typing your name"
						autocomplete="off"
						autocapitalize="words"
						autofocus
					/>
				</div>

				<div class="vk_secondarybar">
					<button type="button" class="ri_formbutton" @click="openGuest">Guest</button>
					<button type="button" class="ri_formbutton" @click="openAddNew">New Volunteer</button>
				</div>

				<p v-if="searchQuery.trim() && tiles.length === 0" class="vk_hint">
					No match for "{{ searchQuery }}".
				</p>
				<p v-else-if="!searchQuery.trim()" class="vk_hint">Type your name above to sign in or out.</p>

				<div v-if="tiles.length" class="vk_grid">
					<button
						v-for="person in tiles"
						:key="person.id"
						type="button"
						class="vk_tile"
						:class="{ vk_tile_active: !!person.current_sign_in }"
						@click="selectTile(person)"
					>
						<span class="vk_tile_name">{{ personName(person) }}</span>
						<span v-if="person.current_sign_in" class="vk_tile_status vk_tile_status_in">
							Signed in since {{ formatTime(person.current_sign_in.signed_in_at) }}
						</span>
						<span v-else-if="person.forgotten_sign_in" class="vk_tile_status vk_tile_status_forgotten">
							Tap to record when you left
						</span>
						<span v-else-if="person.last_sign_in" class="vk_tile_status">
							{{ [person.last_sign_in.agency, person.last_sign_in.description_of_work].filter(Boolean).join(' — ') || 'Tap to sign in' }}
						</span>
						<span v-else class="vk_tile_status">Tap to sign in</span>
					</button>
				</div>
			</template>

			<!-- ---------------- new volunteer (first-time) ---------------- -->
			<template v-else-if="view === 'add-new'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">New Volunteer</h2>
					<p class="vk_hint">First time here? Enter your name below to sign in.</p>

					<div class="ri_formcontrol">
						<InputLabel value="First Name" />
						<TextInput v-model="newPerson.first_name" autocomplete="off" />
					</div>
					<div class="ri_formcontrol">
						<InputLabel value="Last Name" />
						<TextInput v-model="newPerson.last_name" autocomplete="off" />
					</div>
					<p v-if="addPersonError" class="vk_error">{{ addPersonError }}</p>

					<div class="ri_formactions">
						<button class="ri_defaultbutton" :disabled="addingPerson" @click="submitAddNew">Sign In</button>
						<button class="ri_formbutton" @click="cancelConfirm">Cancel</button>
					</div>
				</div>
			</template>

			<!-- ---------------- new volunteer thank-you ---------------- -->
			<template v-else-if="view === 'new-volunteer-thanks'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">Thanks for coming, {{ personName(selected) }}!</h2>
					<p class="vk_hint">You're signed in. Please check in at the office to complete your first-time sign-in.</p>
					<div class="ri_formactions">
						<button class="ri_defaultbutton" @click="cancelConfirm">Done</button>
					</div>
				</div>
			</template>

			<!-- ---------------- guest ---------------- -->
			<template v-else-if="view === 'guest'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">Guest Sign-In</h2>
					<p class="vk_hint">For visitors, inspectors, and other one-off guests.</p>

					<div class="ri_formcontrol">
						<InputLabel value="First Name" />
						<TextInput v-model="newGuest.first_name" autocomplete="off" />
					</div>
					<div class="ri_formcontrol">
						<InputLabel value="Last Name" />
						<TextInput v-model="newGuest.last_name" autocomplete="off" />
					</div>
					<p v-if="guestError" class="vk_error">{{ guestError }}</p>

					<div class="ri_formactions">
						<button class="ri_defaultbutton" :disabled="addingGuest" @click="submitGuest">Sign In</button>
						<button class="ri_formbutton" @click="cancelConfirm">Cancel</button>
					</div>
				</div>
			</template>

			<!-- ---------------- guest thank-you ---------------- -->
			<template v-else-if="view === 'guest-thanks'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">Thanks, {{ personName(selected) }}!</h2>
					<p class="vk_hint">You're signed in as a guest.</p>
					<div class="ri_formactions">
						<button class="ri_defaultbutton" @click="cancelConfirm">Done</button>
					</div>
				</div>
			</template>

			<!-- ---------------- sign-in confirm ---------------- -->
			<template v-else-if="view === 'confirm-in'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">Sign In — {{ personName(selected) }}</h2>

					<div class="ri_formcontrol">
						<InputLabel value="Category" />
						<div class="vk_category_choice">
							<button
								type="button"
								class="ri_formbutton"
								:class="{ vk_choice_active: form.category === 'volunteer' }"
								@click="form.category = 'volunteer'"
							>Volunteer</button>
							<button
								type="button"
								class="ri_formbutton"
								:class="{ vk_choice_active: form.category === 'other' }"
								@click="form.category = 'other'"
							>Other</button>
						</div>
					</div>

					<template v-if="form.category === 'other'">
						<div class="ri_formcontrol">
							<InputLabel value="Other category" />
							<select v-model="form.other_category_id" class="ri_forminput">
								<option :value="null">— Choose or type below —</option>
								<option v-for="cat in otherCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
							</select>
						</div>
						<div class="ri_formcontrol">
							<InputLabel value="Or describe (free text)" />
							<TextInput v-model="form.other_category_text" placeholder="e.g. State Representative" />
						</div>
					</template>

					<div class="ri_formcontrol">
						<InputLabel value="Agency" />
						<TextInput v-model="form.agency" placeholder="e.g. American Red Cross" />
					</div>

					<div class="ri_formcontrol">
						<InputLabel value="Title / Function (optional — professional services only)" />
						<TextInput v-model="form.title_function" />
					</div>

					<div class="ri_formcontrol">
						<InputLabel value="Work Site" />
						<TextInput v-model="form.work_site" />
					</div>

					<div class="ri_formcontrol">
						<InputLabel value="Description of Work" />
						<TextArea v-model="form.description_of_work" />
					</div>

					<div v-if="form.category === 'other'" class="ri_formcontrol">
						<InputLabel value="Expected departure (optional)" />
						<input v-model="form.expected_departure_at" type="datetime-local" class="ri_forminput" />
					</div>

					<p v-if="saveError" class="vk_error">{{ saveError }}</p>

					<div class="ri_formactions">
						<button class="ri_defaultbutton" :disabled="saving" @click="submitSignIn">Confirm Sign-In</button>
						<button class="ri_formbutton" @click="cancelConfirm">Cancel</button>
					</div>
				</div>
			</template>

			<!-- ---------------- sign-out confirm ---------------- -->
			<template v-else-if="view === 'confirm-out'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">Sign Out — {{ personName(selected) }}</h2>
					<p class="vk_hint">
						Signed in since {{ formatTime(selected.current_sign_in.signed_in_at) }}
						<span v-if="selected.current_sign_in.status === 'pending_confirmation'">
							— this looks like a forgotten sign-out from a previous day. Confirming below will close it out now.
						</span>
					</p>

					<p v-if="saveError" class="vk_error">{{ saveError }}</p>

					<div class="ri_formactions">
						<button class="ri_defaultbutton" :disabled="saving" @click="submitSignOut">Confirm Sign-Out</button>
						<button class="ri_formbutton" @click="cancelConfirm">Cancel</button>
					</div>
				</div>
			</template>

			<!-- ---------------- forgotten sign-out (building was cleared) ---------------- -->
			<template v-else-if="view === 'forgotten-sign-out'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">Welcome back, {{ personName(selected) }}</h2>
					<p class="vk_hint">
						Looks like you forgot to sign out — you were signed in since
						{{ formatTime(selected.forgotten_sign_in.signed_in_at) }}, and the building's been
						confirmed empty since then. What time did you leave?
					</p>

					<div class="ri_formcontrol">
						<InputLabel value="Time You Left" />
						<input v-model="forgottenSignOutAt" type="datetime-local" class="ri_forminput" />
					</div>

					<p v-if="saveError" class="vk_error">{{ saveError }}</p>

					<div class="ri_formactions">
						<button
							class="ri_defaultbutton"
							:disabled="saving || !forgottenSignOutAt"
							@click="submitForgottenSignOut"
						>Save &amp; Continue to Sign In</button>
						<button class="ri_formbutton" @click="cancelConfirm">Cancel</button>
					</div>
				</div>
			</template>

			<!-- ---------------- building safety (PIN-gated) ---------------- -->
			<template v-else-if="view === 'safety'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">{{ safetyTitle }}</h2>
					<p class="vk_hint">Enter your PIN to confirm — this requires the building-safety permission.</p>

					<div class="ri_formcontrol">
						<InputLabel value="Your Name" />
						<input v-model="safetyQuery" type="text" class="ri_forminput" placeholder="Start typing your name" />
						<div v-if="safetyCandidates.length" class="vk_grid" style="margin-top: 0.5em;">
							<button
								v-for="candidate in safetyCandidates"
								:key="candidate.id"
								type="button"
								class="vk_tile"
								:class="{ vk_tile_active: safetyOperator?.id === candidate.id }"
								@click="safetyOperator = candidate"
							>
								<span class="vk_tile_name">{{ personName(candidate) }}</span>
							</button>
						</div>
					</div>

					<div v-if="safetyOperator" class="ri_formcontrol">
						<InputLabel value="PIN" />
						<input v-model="safetyPin" type="password" inputmode="numeric" maxlength="5" class="ri_forminput" />
					</div>

					<p v-if="safetyError" class="vk_error">{{ safetyError }}</p>

					<div class="ri_formactions">
						<button
							class="ri_defaultbutton"
							:disabled="safetySaving || !safetyOperator || safetyPin.length !== 5"
							@click="submitSafetyAction"
						>Confirm</button>
						<button class="ri_formbutton" @click="cancelSafety">Cancel</button>
					</div>
				</div>
			</template>

			<!-- ---------------- emergency list (no login, no PIN) ---------------- -->
			<template v-else-if="view === 'emergency-list'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">Emergency List — Current Building Occupancy</h2>

					<p v-if="emergencyListLoading" class="vk_hint">Loading…</p>
					<p v-else-if="emergencyListError" class="vk_error">{{ emergencyListError }}</p>
					<p v-else-if="emergencyList.length === 0" class="vk_hint">Nobody is currently signed in.</p>
					<ul v-else class="vk_emergencylist">
						<li v-for="occupant in emergencyList" :key="occupant.id">
							<strong>{{ occupant.name }}</strong>
							<span
								class="vk_emergencylist_tag"
								:class="occupant.category === 'volunteer' ? 'vk_emergencylist_tag_volunteer' : 'vk_emergencylist_tag_guest'"
							>{{ occupant.why }}</span>
							<span class="vk_hint"> — signed in {{ formatTime(occupant.signed_in_at) }}</span>
						</li>
					</ul>

					<div class="ri_formactions">
						<button class="ri_formbutton" @click="closeEmergencyList">Back</button>
					</div>
				</div>
			</template>
		</div>
	</component>
</template>

<script>
import axios from 'axios';

export default {
	data() {
		return {
			view: 'grid', // 'grid' | 'confirm-in' | 'confirm-out' | 'forgotten-sign-out' | 'add-new' | 'new-volunteer-thanks' | 'guest' | 'guest-thanks' | 'safety' | 'emergency-list'

			enablingKioskMode: false,

			occupancyCount: null,
			occupancyPollTimer: null,
			safetyAction: null, // 'closeout'
			safetyQuery: '',
			safetyCandidates: [],
			safetyOperator: null,
			safetyPin: '',
			safetySaving: false,
			safetyError: null,

			searchQuery: '',
			searchResults: [],
			searchTimer: null,

			otherCategories: [],

			selected: null,
			form: this.blankForm(),
			saving: false,
			saveError: null,

			newPerson: { first_name: '', last_name: '' },
			addingPerson: false,
			addPersonError: null,

			newGuest: { first_name: '', last_name: '' },
			addingGuest: false,
			guestError: null,

			emergencyList: [],
			emergencyListLoading: false,
			emergencyListError: null,

			forgottenSignOutAt: '',
		};
	},
	computed: {
		isAuthenticated() {
			return !!this.$page.props.auth.user;
		},
		tiles() {
			return this.searchQuery.trim() ? this.searchResults : [];
		},
		safetyTitle() {
			return {
				closeout: 'Confirm Building Empty',
			}[this.safetyAction] || '';
		},
	},
	watch: {
		searchQuery() {
			clearTimeout(this.searchTimer);
			const query = this.searchQuery.trim();
			if (!query) {
				this.searchResults = [];
				return;
			}
			this.searchTimer = setTimeout(() => this.runSearch(query), 250);
		},
		safetyQuery() {
			clearTimeout(this.safetySearchTimer);
			const query = this.safetyQuery.trim();
			this.safetyOperator = null;
			if (!query) {
				this.safetyCandidates = [];
				return;
			}
			this.safetySearchTimer = setTimeout(async () => {
				const response = await axios.get('/json/building-safety/kiosk-operators', { params: { q: query } });
				this.safetyCandidates = response.data.records;
			}, 250);
		},
	},
	methods: {
		blankForm() {
			return {
				category: 'volunteer',
				other_category_id: null,
				other_category_text: '',
				agency: '',
				title_function: '',
				work_site: '',
				description_of_work: '',
				expected_departure_at: '',
			};
		},
		personName(person) {
			if (!person) return '';
			const name = [person.first_name, person.last_name].filter(Boolean).join(' ');
			return name || person.organization || 'Unknown';
		},
		formatTime(value) {
			if (!value) return '';
			return new Date(value).toLocaleString(undefined, { hour: 'numeric', minute: '2-digit' });
		},
		toDatetimeLocal(date) {
			const pad = (n) => String(n).padStart(2, '0');
			return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
		},
		async loadCategories() {
			const response = await axios.get('/json/volunteer-sign-in-categories');
			this.otherCategories = response.data.records;
		},
		async runSearch(query) {
			const response = await axios.get('/json/volunteer-sign-ins/search', { params: { q: query } });
			this.searchResults = response.data.records;
		},
		selectTile(person) {
			this.selected = person;
			this.saveError = null;
			if (person.current_sign_in) {
				this.view = 'confirm-out';
				return;
			}
			if (person.forgotten_sign_in) {
				this.forgottenSignOutAt = this.toDatetimeLocal(new Date());
				this.view = 'forgotten-sign-out';
				return;
			}
			this.form = this.blankForm();
			// Prefill from the most recent closed sign-in — a suggestion, not
			// a stored fact, since agency/work can change visit to visit.
			if (person.last_sign_in) {
				this.form.agency = person.last_sign_in.agency || '';
				this.form.title_function = person.last_sign_in.title_function || '';
				this.form.work_site = person.last_sign_in.work_site || '';
				this.form.description_of_work = person.last_sign_in.description_of_work || '';
			}
			this.view = 'confirm-in';
		},
		cancelConfirm() {
			this.view = 'grid';
			this.selected = null;
			this.saveError = null;
			this.addPersonError = null;
			this.newPerson = { first_name: '', last_name: '' };
			this.guestError = null;
			this.newGuest = { first_name: '', last_name: '' };
			this.searchQuery = '';
			this.searchResults = [];
			this.$nextTick(() => this.$refs.searchInput?.focus());
		},
		openAddNew() {
			this.newPerson = { first_name: '', last_name: '' };
			this.addPersonError = null;
			this.view = 'add-new';
		},
		async submitAddNew() {
			this.addingPerson = true;
			this.addPersonError = null;
			try {
				const personResponse = await axios.post('/json/volunteer-sign-ins/people', this.newPerson);
				this.selected = personResponse.data.record;
				await axios.post('/json/volunteer-sign-ins', {
					person_id: this.selected.id,
					category: 'volunteer',
				});
				this.loadOccupancyCount();
				this.view = 'new-volunteer-thanks';
			} catch (e) {
				this.addPersonError = e.response?.data?.message || 'Could not sign in.';
			} finally {
				this.addingPerson = false;
			}
		},
		openGuest() {
			this.newGuest = { first_name: '', last_name: '' };
			this.guestError = null;
			this.view = 'guest';
		},
		async submitGuest() {
			this.addingGuest = true;
			this.guestError = null;
			try {
				const personResponse = await axios.post('/json/volunteer-sign-ins/guests', this.newGuest);
				this.selected = personResponse.data.record;
				await axios.post('/json/volunteer-sign-ins', {
					person_id: this.selected.id,
					category: 'other',
					other_category_text: 'Guest',
				});
				this.loadOccupancyCount();
				this.view = 'guest-thanks';
			} catch (e) {
				this.guestError = e.response?.data?.message || 'Could not sign in.';
			} finally {
				this.addingGuest = false;
			}
		},
		async submitSignIn() {
			this.saving = true;
			this.saveError = null;
			try {
				await axios.post('/json/volunteer-sign-ins', {
					person_id: this.selected.id,
					...this.form,
					expected_departure_at: this.form.expected_departure_at || null,
				});
				this.loadOccupancyCount();
				this.cancelConfirm();
			} catch (e) {
				this.saveError = e.response?.data?.message || 'Could not sign in.';
			} finally {
				this.saving = false;
			}
		},
		async submitForgottenSignOut() {
			this.saving = true;
			this.saveError = null;
			try {
				await axios.post(`/json/volunteer-sign-ins/${this.selected.forgotten_sign_in.id}/sign-out`, {
					signed_out_at: this.forgottenSignOutAt,
				});
				this.loadOccupancyCount();
				// They're at the kiosk tapping their own tile right now, so
				// go straight into a fresh sign-in rather than back to the
				// grid — the old row closing out is a correction, not the
				// thing they came here to do.
				this.form = this.blankForm();
				if (this.selected.last_sign_in) {
					this.form.agency = this.selected.last_sign_in.agency || '';
					this.form.title_function = this.selected.last_sign_in.title_function || '';
					this.form.work_site = this.selected.last_sign_in.work_site || '';
					this.form.description_of_work = this.selected.last_sign_in.description_of_work || '';
				}
				this.saveError = null;
				this.view = 'confirm-in';
			} catch (e) {
				this.saveError = e.response?.data?.message || 'Could not save.';
			} finally {
				this.saving = false;
			}
		},
		async submitSignOut() {
			this.saving = true;
			this.saveError = null;
			try {
				await axios.post(`/json/volunteer-sign-ins/${this.selected.current_sign_in.id}/sign-out`);
				this.loadOccupancyCount();
				this.cancelConfirm();
			} catch (e) {
				this.saveError = e.response?.data?.message || 'Could not sign out.';
			} finally {
				this.saving = false;
			}
		},

		async loadOccupancyCount() {
			const response = await axios.get('/json/building-safety/occupancy-count');
			this.occupancyCount = response.data.count;
		},
		async openEmergencyList() {
			this.view = 'emergency-list';
			this.emergencyListLoading = true;
			this.emergencyListError = null;
			try {
				const response = await axios.get('/json/building-safety/emergency-occupancy-list');
				this.emergencyList = response.data.records;
			} catch (e) {
				this.emergencyListError = 'Could not load the occupancy list.';
			} finally {
				this.emergencyListLoading = false;
			}
		},
		closeEmergencyList() {
			this.view = 'grid';
			this.emergencyList = [];
		},
		openSafety(action) {
			this.safetyAction = action;
			this.safetyQuery = '';
			this.safetyCandidates = [];
			this.safetyOperator = null;
			this.safetyPin = '';
			this.safetyError = null;
			this.view = 'safety';
		},
		cancelSafety() {
			this.view = 'grid';
			this.loadOccupancyCount();
		},
		async submitSafetyAction() {
			this.safetySaving = true;
			this.safetyError = null;
			const payload = { person_id: this.safetyOperator.id, pin: this.safetyPin };
			try {
				await axios.post('/json/building-safety/closeout', payload);
				this.cancelSafety();
			} catch (e) {
				this.safetyError = e.response?.data?.message || 'Could not complete this action.';
			} finally {
				this.safetySaving = false;
			}
		},

		async enableKioskMode() {
			if (!confirm('This will log you out and lock this device to sign-in only. Continue?')) {
				return;
			}
			this.enablingKioskMode = true;
			try {
				await axios.post('/json/volunteer-kiosk/enable-lock');
				window.location.href = '/volunteers/kiosk';
			} catch (e) {
				alert(e.response?.data?.message || 'Could not enable kiosk mode.');
				this.enablingKioskMode = false;
			}
		},
	},
	mounted() {
		this.loadCategories();
		this.loadOccupancyCount();
		// Sign-in/out already re-fetches the count directly; this poll only
		// exists to catch sign-ins/outs happening on another kiosk device
		// pointed at the same building.
		this.occupancyPollTimer = setInterval(this.loadOccupancyCount, 180000);
	},
	beforeUnmount() {
		if (this.occupancyPollTimer) clearInterval(this.occupancyPollTimer);
	},
};
</script>

<style scoped>
.vk_page {
	padding: 1em;
}
.vk_stafflogin {
	text-align: right;
	margin-bottom: 0.5em;
}
.vk_stafflogin a {
	font-size: 0.75rem;
	color: #999;
}
.vk_kioskmode_bar {
	display: flex;
	align-items: center;
	gap: 0.75em;
	margin-bottom: 0.5em;
}
.vk_safetybar {
	display: flex;
	align-items: center;
	gap: 1em;
	margin-bottom: 1em;
	padding: 0.5em 0.75em;
	background: #f9fafb;
	border: 1px solid #e5e7eb;
	border-radius: 8px;
	font-weight: 600;
}
.vk_searchbar {
	margin-bottom: 1em;
}
.vk_searchinput {
	width: 100%;
	font-size: 1.2rem;
	padding: 0.75em;
}
.vk_secondarybar {
	display: flex;
	gap: 0.5em;
	margin-bottom: 1em;
}
.vk_hint {
	color: #666;
	margin: 1em 0;
}
.vk_error {
	color: #b91c1c;
	margin: 0.5em 0;
}
.vk_grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: 0.75em;
}
.vk_tile {
	min-height: 96px;
	display: flex;
	flex-direction: column;
	justify-content: center;
	align-items: flex-start;
	gap: 0.35em;
	border: 1px solid #d1d5db;
	border-radius: 10px;
	background: white;
	padding: 1em;
	text-align: left;
	cursor: pointer;
}
.vk_tile:hover {
	background: #f3f4f6;
}
.vk_tile_active {
	background: #ecfdf5;
	border-color: #10b981;
}
.vk_tile_addnew {
	border-style: dashed;
	justify-content: center;
	align-items: center;
	text-align: center;
}
.vk_tile_name {
	font-size: 1.1rem;
	font-weight: bold;
}
.vk_tile_status {
	font-size: 0.85rem;
	color: #666;
}
.vk_tile_status_in {
	color: #047857;
	font-weight: 600;
}
.vk_tile_status_forgotten {
	color: #b45309;
	font-weight: 600;
}
.vk_confirm {
	max-width: 480px;
}
.vk_confirm_title {
	font-size: 1.3rem;
	font-weight: bold;
	margin-bottom: 0.75em;
}
.vk_category_choice {
	display: flex;
	gap: 0.5em;
}
.vk_choice_active {
	background: #1f2937;
	color: white;
}
.vk_emergencylist {
	max-width: 640px;
	list-style: none;
	padding: 0;
	margin: 0 0 1em;
}
.vk_emergencylist li {
	padding: 0.5em 0;
	border-bottom: 1px solid #e5e7eb;
	font-size: 1.1rem;
}
.vk_emergencylist_tag {
	margin-left: 0.6em;
	padding: 0.1em 0.6em;
	border-radius: 999px;
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
}
.vk_emergencylist_tag_volunteer {
	background: #d1fae5;
	color: #047857;
}
.vk_emergencylist_tag_guest {
	background: #fef3c7;
	color: #b45309;
}
</style>
