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
import KioskEnableConfirmModal from '@/Components/KioskEnableConfirmModal.vue';

defineProps({
	breadcrumb: {
		type: Array,
	},
	// This device's assigned KioskLocation (see KioskLocation/
	// TrustedDevice::kiosk_location_id) — null if none is resolved yet
	// (e.g. more than one active location exists and this device hasn't
	// had kiosk mode enabled on it).
	kioskLocationId: {
		type: Number,
		default: null,
	},
	kioskLocationName: {
		type: String,
		default: null,
	},
	// That location's optional banner line — shown only when non-blank.
	kioskWelcomeMessage: {
		type: String,
		default: null,
	},
	// Minutes of inactivity before the kiosk resets to this view — null
	// means never (see KioskSetting::idle_reset_minutes).
	idleResetMinutes: {
		type: Number,
		default: null,
	},
	// Set when login/PIN-unlock just cleared kiosk lock on this device
	// (?closeout=1) — surfaces "Confirm Building Empty" as a suggestion.
	showCloseoutPrompt: {
		type: Boolean,
		default: false,
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
		<div class="vk_page" :class="{ vk_page_bare: !isAuthenticated }">
			<div v-if="!isAuthenticated" class="vk_stafflogin">
				<a href="/login">Staff Login</a>
			</div>

			<div class="vk_shell">
				<header v-if="view === 'grid'" class="vk_header">
					<img src="/img/welcome.webp" alt="" class="vk_header_badge" />
					<h1 class="vk_header_title">Facility Sign-In/Sign-Out</h1>
					<p v-if="kioskLocationName" class="vk_header_location">{{ kioskLocationName }}</p>
					<p v-if="kioskWelcomeMessage" class="vk_header_tagline">{{ kioskWelcomeMessage }}</p>
				</header>

				<div v-if="isAuthenticated && view === 'grid'" class="vk_kioskmode_bar">
					<button type="button" class="ri_formbutton" @click="showKioskConfirmModal = true">
						Enable Kiosk Mode
					</button>
					<span class="vk_hint vk_hint_inline">Locks this device to sign-in only, no login required, until someone logs in again.</span>
				</div>

				<!-- ---------------- default (type-ahead) view ---------------- -->
				<template v-if="view === 'grid'">
					<button
						type="button"
						class="ri_formbutton vk_firsttimebtn"
						:class="{ vk_firsttimebtn_open: showFirstTimeOptions }"
						@click="showFirstTimeOptions = !showFirstTimeOptions"
					>
						First Time Here?
						<span class="vk_firsttimebtn_chevron" :class="{ vk_firsttimebtn_chevron_open: showFirstTimeOptions }" aria-hidden="true">▾</span>
					</button>
					<div v-if="showFirstTimeOptions" class="vk_secondarybar">
						<button type="button" class="ri_formbutton vk_secondarybtn" @click="openGuest">Guest</button>
						<button type="button" class="ri_formbutton vk_secondarybtn" @click="openAddNew">New Volunteer</button>
					</div>

					<p class="vk_instruction">If you've been here before, start typing your name and select your name when it appears</p>
					<div class="vk_searchbar">
						<span class="vk_searchicon" aria-hidden="true">🔍</span>
						<!-- This is the only free-text field on the kiosk's front
						     screen, so browsers/password managers default to
						     treating it as a login field and offer to fill/save a
						     password into it. autocomplete="off" alone is
						     routinely ignored by Chromium's heuristics, so this
						     also renames the field away from anything
						     name/login-shaped, opts out the major extension
						     managers via their vendor-specific ignore attributes,
						     and starts the input readonly — removed on first
						     focus — since Chrome's native save-password prompt
						     specifically skips fields that were readonly when the
						     page loaded. -->
						<input
							ref="searchInput"
							v-model="searchQuery"
							type="search"
							name="vk-lookup-not-a-login-field"
							class="ri_forminput vk_searchinput"
							placeholder="Start typing your name"
							autocomplete="off"
							autocapitalize="words"
							autocorrect="off"
							spellcheck="false"
							data-lpignore="true"
							data-1p-ignore
							data-bwignore
							data-form-type="other"
							readonly
							@focus="$event.target.removeAttribute('readonly')"
							autofocus
						/>
					</div>

					<p v-if="searchQuery.trim() && tiles.length === 0" class="vk_hint vk_hint_center">
						No match for "{{ searchQuery }}".
					</p>

					<div v-if="tiles.length" class="vk_grid">
						<button
							v-for="person in tiles"
							:key="person.id"
							type="button"
							class="vk_tile"
							:class="{ vk_tile_active: !!person.current_sign_in, vk_tile_forgotten: !!person.forgotten_sign_in }"
							@click="selectTile(person)"
						>
							<span class="vk_tile_name">{{ personName(person) }}</span>
							<span v-if="person.current_sign_in" class="vk_tile_status vk_tile_status_in">
								<span class="vk_tile_dot" aria-hidden="true"></span>
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

			<!-- ---------------- sign-in thank-you (existing person) ---------------- -->
			<template v-else-if="view === 'signin-thanks'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">Thank you, {{ greetingName(selected) }}!</h2>
					<p class="vk_hint">Please remember to sign out! Have a great day!</p>
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
					<div class="ri_formcontrol">
						<InputLabel value="Guest Type" />
						<select v-model="newGuest.other_category_id" class="ri_forminput">
							<option :value="null">— Choose or type below —</option>
							<option v-for="cat in otherCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
						</select>
					</div>
					<div class="ri_formcontrol">
						<InputLabel value="Or describe (free text)" />
						<TextInput v-model="newGuest.other_category_text" placeholder="e.g. State Representative" />
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
						<input v-model="form.agency" list="vk_agency_suggestions" class="ri_forminput" placeholder="e.g. American Red Cross" />
						<datalist id="vk_agency_suggestions">
							<option v-for="s in agencySuggestions" :key="s.id" :value="s.value" />
						</datalist>
					</div>

					<div class="ri_formcontrol">
						<InputLabel value="Title / Function (optional — professional services only)" />
						<input v-model="form.title_function" list="vk_task_suggestions" class="ri_forminput" />
						<datalist id="vk_task_suggestions">
							<option v-for="s in taskSuggestions" :key="s.id" :value="s.value" />
						</datalist>
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

			<!-- ---------------- sign-out thank-you ---------------- -->
			<template v-else-if="view === 'signout-thanks'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">See you next time, {{ greetingName(selected) }}!</h2>
					<p class="vk_hint">You're signed out. Thanks for coming!</p>
					<div class="ri_formactions">
						<button class="ri_defaultbutton" @click="cancelConfirm">Done</button>
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

			<!-- ---------------- emergency list (no login, no PIN) ---------------- -->
			<template v-else-if="view === 'emergency-list'">
				<div class="vk_confirm">
					<div class="ri_formactions" style="margin-bottom: 1em;">
						<button class="ri_formbutton" @click="closeEmergencyList">Back</button>
					</div>
					<h2 class="vk_confirm_title">Emergency Roster</h2>
				<p class="vk_hint">Everyone currently signed in to the building, for use during an evacuation or emergency sweep.</p>

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

			<!-- ---------------- end-of-day closeout: decision ---------------- -->
			<template v-else-if="view === 'closeout-decision'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">Welcome Back</h2>
					<p class="vk_hint">
						This device was in kiosk mode. Is this an end-of-day closeout?
					</p>
					<div class="ri_formactions">
						<button class="ri_defaultbutton" @click="openCloseoutReview">Yes — Walk Through Closeout</button>
						<button class="ri_formbutton" @click="dismissCloseoutPrompt">Not Now</button>
					</div>
				</div>
			</template>

			<!-- ---------------- end-of-day closeout: review ---------------- -->
			<template v-else-if="view === 'closeout-review'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">End-of-Day Closeout</h2>
					<p class="vk_hint">
						Everyone currently signed in — sign out anyone who's leaving now, then confirm the building is
						empty whenever you're ready.
					</p>

					<p v-if="closeoutRosterLoading" class="vk_hint">Loading…</p>
					<p v-else-if="closeoutRosterError" class="vk_error">{{ closeoutRosterError }}</p>
					<p v-else-if="closeoutRoster.length === 0" class="vk_hint">Nobody is currently signed in.</p>
					<ul v-else class="vk_closeoutroster">
						<li v-for="occupant in closeoutRoster" :key="occupant.id" class="vk_closeoutroster_row">
							<div>
								<strong>{{ occupant.name }}</strong>
								<span class="vk_hint"> — signed in {{ formatTime(occupant.signed_in_at) }}</span>
							</div>
							<button
								type="button"
								class="ri_formbutton"
								:disabled="signingOutId === occupant.id"
								@click="signOutFromCloseout(occupant)"
							>{{ signingOutId === occupant.id ? 'Signing Out…' : 'Sign Out' }}</button>
						</li>
					</ul>

					<!-- Informational, never blocking — the roster can be stale
					     or the person closing may already know better; the
					     Confirm button below stays enabled either way. -->
					<p v-if="!closeoutRosterLoading && closeoutRoster.length > 0" class="vk_closeout_warning">
						{{ closeoutRoster.length }} {{ closeoutRoster.length === 1 ? 'person is' : 'people are' }} still
						listed as in the building. Sign them out above, or confirm the building is empty anyway if
						that's not accurate.
					</p>

					<p v-if="closeoutConfirmError" class="vk_error">{{ closeoutConfirmError }}</p>

					<div class="ri_formactions">
						<button class="ri_defaultbutton" :disabled="closeoutConfirmSaving" @click="confirmBuildingEmpty">
							{{ closeoutConfirmSaving ? 'Confirming…' : 'Confirm Building Empty' }}
						</button>
						<button class="ri_formbutton" @click="view = 'grid'">Back</button>
					</div>
				</div>
			</template>

			<!-- Footer — occupancy count + the emergency roster, present on
			     every screen that isn't already a modal-style confirm/form
			     (kept off the emergency-list screen itself so it doesn't
			     compete with its own back button). No login needed: a
			     firefighter sweeping the building can't be expected to know
			     anyone's PIN. Closeout stays behind staff login, off the
			     front screen, since it's a routine end-of-day action, not
			     an emergency one — routes into the same roster-review
			     screen as the ?closeout=1 prompt rather than confirming
			     straight from this one tap. -->
			<div v-if="view === 'grid'" class="vk_footerbar">
				<span class="vk_footerbar_count">
					<span class="vk_footerbar_dot" aria-hidden="true"></span>
					{{ occupancyCount === null ? '…' : occupancyCount }} currently in the building
				</span>
				<div class="vk_footerbar_actions">
					<button type="button" class="ri_formbutton vk_footerbar_btn vk_footerbar_btn_emergency" @click="openEmergencyList">
						Emergency Roster
					</button>
					<button v-if="isAuthenticated" type="button" class="ri_formbutton vk_footerbar_btn" @click="openCloseoutReview">Confirm Building Empty</button>
				</div>
			</div>
			</div>
		</div>
	</component>

	<!-- Same shared confirm modal the Setup menu tile uses (Dashboard.vue) —
	     this is the on-page "Enable Kiosk Mode" button's entry point into it,
	     for someone already here rather than tapping the tile. -->
	<KioskEnableConfirmModal
		:show="showKioskConfirmModal"
		@close="showKioskConfirmModal = false"
		@enabled="() => (window.location.href = '/volunteers/kiosk')"
	/>
</template>

<script>
import axios from 'axios';

export default {
	data() {
		return {
			view: 'grid', // 'grid' | 'confirm-in' | 'signin-thanks' | 'confirm-out' | 'signout-thanks' | 'forgotten-sign-out' | 'add-new' | 'new-volunteer-thanks' | 'guest' | 'guest-thanks' | 'emergency-list' | 'closeout-decision' | 'closeout-review'

			showKioskConfirmModal: false,

			closeoutRoster: [],
			closeoutRosterLoading: false,
			closeoutRosterError: null,
			signingOutId: null,

			occupancyCount: null,
			occupancyPollTimer: null,
			closeoutConfirmSaving: false,
			closeoutConfirmError: null,

			showFirstTimeOptions: false,
			confirmationTimer: null,
			idleTimer: null,

			searchQuery: '',
			searchResults: [],
			searchTimer: null,

			otherCategories: [],
			agencySuggestions: [],
			taskSuggestions: [],

			selected: null,
			form: this.blankForm(),
			saving: false,
			saveError: null,

			newPerson: { first_name: '', last_name: '' },
			addingPerson: false,
			addPersonError: null,

			newGuest: this.blankGuest(),
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
		blankGuest() {
			return { first_name: '', last_name: '', other_category_id: null, other_category_text: '' };
		},
		personName(person) {
			if (!person) return '';
			const name = [person.first_name, person.last_name].filter(Boolean).join(' ');
			return name || person.organization || 'Unknown';
		},
		// For the "Thank you, ___!" greeting — a first name reads friendlier
		// than a full name, but falls back to personName() for an org-only
		// person with no first_name.
		greetingName(person) {
			if (!person) return '';
			return person.first_name || this.personName(person);
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
			const response = await axios.get('/json/sign-in-categories', {
				params: { kiosk_location_id: this.kioskLocationId || undefined },
			});
			this.otherCategories = response.data.records;
		},
		async loadSuggestions() {
			const [agencyResponse, taskResponse] = await Promise.all([
				axios.get('/json/kiosk-suggestions', { params: { kind: 'agency' } }),
				axios.get('/json/kiosk-suggestions', { params: { kind: 'task' } }),
			]);
			this.agencySuggestions = agencyResponse.data.records;
			this.taskSuggestions = taskResponse.data.records;
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
			clearTimeout(this.confirmationTimer);
			this.view = 'grid';
			this.selected = null;
			this.saveError = null;
			this.addPersonError = null;
			this.newPerson = { first_name: '', last_name: '' };
			this.guestError = null;
			this.newGuest = this.blankGuest();
			this.searchQuery = '';
			this.searchResults = [];
			this.showFirstTimeOptions = false;
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
			this.newGuest = this.blankGuest();
			this.guestError = null;
			this.view = 'guest';
		},
		async submitGuest() {
			this.addingGuest = true;
			this.guestError = null;
			try {
				const { first_name, last_name, other_category_id, other_category_text } = this.newGuest;
				const personResponse = await axios.post('/json/volunteer-sign-ins/guests', { first_name, last_name });
				this.selected = personResponse.data.record;
				await axios.post('/json/volunteer-sign-ins', {
					person_id: this.selected.id,
					category: 'other',
					other_category_id,
					// Falls back to a plain "Guest" only if the visitor picked/typed
					// nothing at all — keeps prior behavior when no guest types are
					// configured yet for this location.
					other_category_text: other_category_id || other_category_text ? other_category_text : 'Guest',
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
				this.view = 'signin-thanks';
				this.confirmationTimer = setTimeout(() => this.cancelConfirm(), 5000);
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
				this.view = 'signout-thanks';
				this.confirmationTimer = setTimeout(() => this.cancelConfirm(), 5000);
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
		// "Confirm Building Empty" is only ever reachable once someone's
		// already logged in (isAuthenticated gates the footer button, and
		// the ?closeout=1 prompt below requires it too), so this identifies
		// the actor from the session server-side (Auth::id() in
		// BuildingSafetyController::closeout) rather than asking for a
		// name+PIN that would just re-confirm who's already signed in.
		// Same landing spot as "Not Now" (dismissCloseoutPrompt) once done —
		// Setup is where whoever's closing up came from.
		async confirmBuildingEmpty() {
			this.closeoutConfirmSaving = true;
			this.closeoutConfirmError = null;
			try {
				await axios.post('/json/building-safety/closeout', {});
				window.location.href = '/dashboard#setup';
			} catch (e) {
				this.closeoutConfirmError = e.response?.data?.message || 'Could not complete this action.';
				this.closeoutConfirmSaving = false;
			}
		},
		// ---------------- end-of-day closeout review (?closeout=1) ----------------
		// A dedicated screen, not a dismissable banner — walks whoever's
		// closing up through what's actually needed: see who's still
		// listed in, sign people out right here (handy when a group is
		// leaving together), and only then decide to confirm the building
		// empty. Never blocks that confirm on the roster being empty —
		// the warning is informational, the choice stays with the person
		// closing (they may know the roster's stale, or be confirming
		// deliberately with people still shown).
		openCloseoutReview() {
			this.view = 'closeout-review';
			this.loadCloseoutRoster();
		},
		dismissCloseoutPrompt() {
			// Landing here at all only happens because a staff login/PIN-
			// unlock just cleared kiosk lock (see mounted()) — "Not Now"
			// means they're not doing closeout right now either, so send
			// them on to Setup (where the Volunteer Kiosk tile that got
			// them into kiosk mode in the first place lives) rather than
			// leaving them sitting on the kiosk sign-in/out grid itself.
			window.location.href = '/dashboard#setup';
		},
		async loadCloseoutRoster() {
			this.closeoutRosterLoading = true;
			this.closeoutRosterError = null;
			try {
				const response = await axios.get('/json/building-safety/emergency-occupancy-list');
				this.closeoutRoster = response.data.records;
			} catch (e) {
				this.closeoutRosterError = 'Could not load who is currently in the building.';
			} finally {
				this.closeoutRosterLoading = false;
			}
		},
		async signOutFromCloseout(occupant) {
			this.signingOutId = occupant.id;
			this.closeoutRosterError = null;
			try {
				await axios.post(`/json/volunteer-sign-ins/${occupant.id}/sign-out`);
				await this.loadCloseoutRoster();
				this.loadOccupancyCount();
			} catch (e) {
				this.closeoutRosterError = e.response?.data?.message || 'Could not sign that person out.';
			} finally {
				this.signingOutId = null;
			}
		},
		// Any tap/keystroke pushes the idle-reset deadline back out — bound
		// to document in mounted() so it fires regardless of which
		// screen/element is active.
		resetIdleTimer() {
			if (!this.idleResetMinutes) return;
			clearTimeout(this.idleTimer);
			this.idleTimer = setTimeout(() => {
				if (this.view !== 'grid') this.cancelConfirm();
			}, this.idleResetMinutes * 60000);
		},
	},
	mounted() {
		this.loadCategories();
		this.loadSuggestions();
		this.loadOccupancyCount();
		// Sign-in/out already re-fetches the count directly; this poll only
		// exists to catch sign-ins/outs happening on another kiosk device
		// pointed at the same building.
		this.occupancyPollTimer = setInterval(this.loadOccupancyCount, 180000);

		// A staff login/PIN-unlock just cleared kiosk lock on this device
		// (?closeout=1) — open straight onto the decision screen instead of
		// the plain grid, see openCloseoutReview()/dismissCloseoutPrompt().
		if (this.showCloseoutPrompt && this.isAuthenticated) {
			this.view = 'closeout-decision';
		}

		if (this.idleResetMinutes) {
			document.addEventListener('click', this.resetIdleTimer);
			document.addEventListener('keydown', this.resetIdleTimer);
			document.addEventListener('touchstart', this.resetIdleTimer);
			this.resetIdleTimer();
		}
	},
	beforeUnmount() {
		if (this.occupancyPollTimer) clearInterval(this.occupancyPollTimer);
		clearTimeout(this.confirmationTimer);
		clearTimeout(this.idleTimer);
		document.removeEventListener('click', this.resetIdleTimer);
		document.removeEventListener('keydown', this.resetIdleTimer);
		document.removeEventListener('touchstart', this.resetIdleTimer);
	},
};
</script>

<style scoped>
.vk_page {
	--vk_accent: #2563eb;
	--vk_accent_dark: #1d4ed8;
	min-height: 100vh;
	/* Extra bottom padding reserves room for the fixed .vk_footerbar so it
	   never overlaps the last row of tiles. */
	padding: 1.5em 1em 7em;
	background: linear-gradient(160deg, #eef2ff 0%, #f8fafc 45%, #f0fdf4 100%);
}
/* Kiosk-lock mode (EnsureKioskAccess, no logged-in staff) fills the whole
   browser viewport with no app chrome around it, so the gradient should
   read as the device's own background, not a boxed-in page. */
.vk_page_bare {
	min-height: 100vh;
}
.vk_stafflogin {
	max-width: 720px;
	margin: 0 auto 0.5em;
	text-align: right;
}
.vk_stafflogin a {
	font-size: 0.75rem;
	color: #9ca3af;
}
.vk_shell {
	max-width: 720px;
	margin: 0 auto;
}
.vk_header {
	text-align: center;
	margin-bottom: 1.5em;
}
.vk_header_badge {
	/* Tailwind's preflight sets img { display: block }, which takes it out
	   of the text-align: center on .vk_header above — needs its own
	   centering. */
	display: block;
	margin: 0 auto 0.4em;
	width: 6.5rem;
	height: 6.5rem;
	border-radius: 22px;
	box-shadow: 0 4px 14px rgba(15, 23, 42, 0.15);
}
.vk_header_title {
	font-size: 2rem;
	font-weight: 800;
	color: #111827;
	letter-spacing: -0.01em;
}
.vk_header_location {
	color: #374151;
	font-size: 1.15rem;
	font-weight: 700;
	margin-top: 0.3em;
}
.vk_header_tagline {
	color: #6b7280;
	font-size: 1.05rem;
	font-weight: 600;
	margin-top: 0.25em;
}
.vk_kioskmode_bar {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 0.75em;
	margin-bottom: 0.75em;
}
.vk_closeoutroster {
	list-style: none;
	margin: 0 0 1em;
	padding: 0;
	text-align: left;
	max-height: 45vh;
	overflow-y: auto;
	border: 1px solid #e5e7eb;
	border-radius: 0.5em;
}
.vk_closeoutroster_row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.75em;
	padding: 0.7em 0.9em;
	border-bottom: 1px solid #f1f5f9;
}
.vk_closeoutroster_row:last-child {
	border-bottom: none;
}
.vk_closeout_warning {
	background: #fffbeb;
	border: 1px solid #fde68a;
	border-radius: 0.5em;
	color: #92400e;
	font-size: 0.85rem;
	padding: 0.75em 1em;
	margin: 0 0 1em;
	text-align: left;
}
/* Pinned to the bottom of the viewport (not just the end of the page
   content) so occupancy count + the emergency roster are always reachable
   without scrolling, even once the tile grid grows past one screen. */
.vk_footerbar {
	position: fixed;
	left: 50%;
	bottom: 1em;
	transform: translateX(-50%);
	width: calc(100% - 2em);
	max-width: 720px;
	z-index: 20;
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 0.75em 1em;
	padding: 0.85em 1.1em;
	background: white;
	border: 1px solid #e5e7eb;
	border-radius: 16px;
	box-shadow: 0 4px 16px rgba(15, 23, 42, 0.12);
}
.vk_footerbar_count {
	display: flex;
	align-items: center;
	gap: 0.5em;
	font-weight: 700;
	color: #111827;
}
.vk_footerbar_dot {
	width: 0.6em;
	height: 0.6em;
	border-radius: 50%;
	background: #10b981;
	box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
	flex-shrink: 0;
}
.vk_footerbar_actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5em;
}
.vk_footerbar_btn {
	font-size: 0.85rem;
	padding: 0.5em 1em;
}
.vk_footerbar_btn_emergency {
	background-color: #dc2626;
}
.vk_footerbar_btn_emergency:hover {
	background-color: #b91c1c;
}
/* Card that holds every non-grid screen (confirm, add-new, guest,
   safety, emergency list) — gives the kiosk a consistent "panel" feel
   instead of form fields floating loose on the gradient background. */
.vk_confirm {
	max-width: 480px;
	margin: 0 auto;
	background: white;
	border: 1px solid #e5e7eb;
	border-radius: 16px;
	padding: 1.75em;
	box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}
.vk_searchbar {
	position: relative;
	margin-bottom: 1em;
}
.vk_searchicon {
	position: absolute;
	left: 1em;
	top: 50%;
	transform: translateY(-50%);
	font-size: 1.2rem;
	opacity: 0.6;
	pointer-events: none;
}
.vk_searchinput {
	width: 100%;
	font-size: 1.35rem;
	padding: 0.9em 1em 0.9em 2.75em;
	border-radius: 14px;
	border: 2px solid #e2e8f0;
	background: white;
	box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
	transition: border-color 0.15s ease, box-shadow 0.15s ease;
	/* type="search" pulls in the browser's own search-field chrome (a
	   round-rect appearance on WebKit, a built-in clear button) that
	   fights with our own icon/border styling above. */
	-webkit-appearance: none;
	appearance: none;
}
.vk_searchinput::-webkit-search-cancel-button {
	-webkit-appearance: none;
	appearance: none;
}
.vk_searchinput:focus {
	outline: none;
	border-color: var(--vk_accent);
	box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
}
.vk_firsttimebtn {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.4em;
	width: 100%;
	font-size: 0.95rem;
	font-weight: 700;
	color: #374151;
	background: white;
	border: 2px dashed #cbd5e1;
	margin-bottom: 0.75em;
}
.vk_firsttimebtn:hover {
	background: #f8fafc;
	border-color: #94a3b8;
}
.vk_firsttimebtn_open {
	border-style: solid;
	border-color: var(--vk_accent);
	color: var(--vk_accent);
}
.vk_firsttimebtn_chevron {
	display: inline-block;
	transition: transform 0.15s ease;
}
.vk_firsttimebtn_chevron_open {
	transform: rotate(180deg);
}
.vk_instruction {
	font-size: 0.95rem;
	font-weight: 600;
	color: #374151;
	margin-bottom: 0.5em;
}
.vk_secondarybar {
	display: flex;
	gap: 0.6em;
	margin-bottom: 1.75em;
}
.vk_secondarybtn {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.4em;
	font-size: 1rem;
	font-weight: 800;
	padding: 0.85em 1em;
	border-radius: 12px;
	background-color: var(--vk_accent);
}
.vk_secondarybtn:hover {
	background-color: var(--vk_accent_dark);
}
.vk_hint {
	color: #6b7280;
}
.vk_hint_inline {
	font-size: 0.8rem;
}
.vk_hint_center {
	text-align: center;
	margin: 1.5em 0;
}
.vk_error {
	color: #b91c1c;
	background: #fef2f2;
	border: 1px solid #fecaca;
	border-radius: 8px;
	padding: 0.6em 0.9em;
	margin: 0.75em 0;
}
.vk_grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
	gap: 0.85em;
}
.vk_tile {
	min-height: 104px;
	display: flex;
	flex-direction: column;
	justify-content: center;
	align-items: flex-start;
	gap: 0.4em;
	border: 1px solid #e2e8f0;
	border-radius: 14px;
	background: white;
	padding: 1.1em;
	text-align: left;
	cursor: pointer;
	box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
	transition: transform 0.1s ease, box-shadow 0.1s ease, border-color 0.1s ease;
}
.vk_tile:hover {
	border-color: #cbd5e1;
	box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
	transform: translateY(-1px);
}
.vk_tile:active {
	transform: translateY(0);
	box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
}
.vk_tile_active {
	background: #ecfdf5;
	border-color: #10b981;
}
.vk_tile_forgotten {
	background: #fffbeb;
	border-color: #f59e0b;
}
.vk_tile_name {
	font-size: 1.15rem;
	font-weight: 700;
	color: #111827;
}
.vk_tile_status {
	font-size: 0.85rem;
	color: #6b7280;
}
.vk_tile_status_in {
	display: flex;
	align-items: center;
	gap: 0.4em;
	color: #047857;
	font-weight: 600;
}
.vk_tile_dot {
	width: 0.5em;
	height: 0.5em;
	border-radius: 50%;
	background: #10b981;
	flex-shrink: 0;
}
.vk_tile_status_forgotten {
	color: #b45309;
	font-weight: 600;
}
.vk_confirm_title {
	font-size: 1.4rem;
	font-weight: 800;
	color: #111827;
	margin-bottom: 0.6em;
}
.vk_category_choice {
	display: flex;
	gap: 0.5em;
}
.vk_category_choice .ri_formbutton {
	flex: 1;
}
.vk_choice_active {
	background: var(--vk_accent);
	color: white;
}
.vk_emergencylist {
	max-width: 640px;
	list-style: none;
	padding: 0;
	margin: 0 0 1em;
}
.vk_emergencylist li {
	padding: 0.6em 0;
	border-bottom: 1px solid #e5e7eb;
	font-size: 1.1rem;
}
.vk_emergencylist_tag {
	margin-left: 0.6em;
	padding: 0.15em 0.65em;
	border-radius: 999px;
	font-size: 0.75rem;
	font-weight: 700;
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

@media (max-width: 480px) {
	.vk_page {
		/* Stacked bar (below) is taller than the single-row desktop one,
		   so it needs more reserved clearance underneath the grid. */
		padding-bottom: 10em;
	}
	.vk_header_title {
		font-size: 1.6rem;
	}
	.vk_confirm {
		padding: 1.25em;
		border-radius: 12px;
	}
	.vk_footerbar {
		width: calc(100% - 1.5em);
		border-radius: 12px;
		flex-direction: column;
		align-items: stretch;
	}
	.vk_footerbar_actions {
		justify-content: stretch;
	}
	.vk_footerbar_btn {
		flex: 1;
	}
}
</style>
