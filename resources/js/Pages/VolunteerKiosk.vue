<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- VolunteerKiosk.vue

	The facility sign-in kiosk (PROJECT_ANALYSIS.md Part 5). Touch-first,
	not built on RIForm — closer in spirit to DonationSorting.vue's custom
	session flow than to an admin CRUD form.

	Default view is a scrollable, always-alphabetical tile grid of active
	volunteers (people.volunteer_active — see the active-window design in
	the volunteer-hours-tracking-design memory); typing in the search box
	is a filter layered on top, matching against every person (not just the
	active roster), so a deactivated regular or a first-time walk-in who
	already has a Person record can still be found.

	One smart tile, same tap target for sign-in and sign-out: a tile shows
	"signed in since ..." when the person has an open (or
	pending_confirmation) sign-in, tapping it opens a sign-out confirm
	screen instead of a fresh sign-in form. Agency/title_function/
	work_site/description_of_work are per-sign-in, not stored on Person
	(an agency can change visit to visit) — the confirm screen prefills
	them as a *suggestion* from the person's most recent closed sign-in
	(last_sign_in), never a stored fact.
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

			<div v-if="view === 'grid' || view === 'safety'" class="vk_safetybar">
				<span>{{ occupancyCount === null ? '…' : occupancyCount }} in the building</span>
				<button type="button" class="ri_formbutton" @click="openSafety('closeout')">Confirm Building Empty</button>
				<button type="button" class="ri_formbutton" @click="openSafety(activeRollCallId ? 'close-roll-call' : 'start-roll-call')">
					{{ activeRollCallId ? 'Close Roll Call' : 'Start Roll Call' }}
				</button>
			</div>

			<!-- ---------------- grid view ---------------- -->
			<template v-if="view === 'grid'">
				<div class="vk_searchbar">
					<input
						v-model="searchQuery"
						type="text"
						class="ri_forminput vk_searchinput"
						placeholder="Search by name — or just scroll to find your tile"
						autofocus
					/>
				</div>

				<p v-if="rosterError" class="vk_error">{{ rosterError }}</p>
				<p v-else-if="rosterLoading" class="vk_hint">Loading…</p>
				<p v-else-if="tiles.length === 0 && searchQuery.trim()" class="vk_hint">
					No match for "{{ searchQuery }}".
				</p>

				<div class="vk_grid">
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
						<span v-else-if="person.last_sign_in" class="vk_tile_status">
							{{ [person.last_sign_in.agency, person.last_sign_in.description_of_work].filter(Boolean).join(' — ') || 'Tap to sign in' }}
						</span>
						<span v-else class="vk_tile_status">Tap to sign in</span>
					</button>

					<button type="button" class="vk_tile vk_tile_addnew" @click="openAddNew">
						<span class="vk_tile_name">+ Add New</span>
						<span class="vk_tile_status">Not on the list?</span>
					</button>
				</div>
			</template>

			<!-- ---------------- add new person ---------------- -->
			<template v-else-if="view === 'add-new'">
				<div class="vk_confirm">
					<h2 class="vk_confirm_title">New Sign-In</h2>

					<div class="ri_formcontrol">
						<InputLabel value="First Name" />
						<TextInput v-model="newPerson.first_name" />
					</div>
					<div class="ri_formcontrol">
						<InputLabel value="Last Name" />
						<TextInput v-model="newPerson.last_name" />
					</div>
					<p v-if="addPersonError" class="vk_error">{{ addPersonError }}</p>

					<div class="ri_formactions">
						<button class="ri_defaultbutton" :disabled="addingPerson" @click="submitAddNew">Continue</button>
						<button class="ri_formbutton" @click="cancelConfirm">Cancel</button>
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
		</div>
	</component>
</template>

<script>
import axios from 'axios';

export default {
	data() {
		return {
			view: 'grid', // 'grid' | 'confirm-in' | 'confirm-out' | 'add-new' | 'safety'

			enablingKioskMode: false,

			occupancyCount: null,
			activeRollCallId: null,
			safetyAction: null, // 'closeout' | 'start-roll-call' | 'close-roll-call'
			safetyQuery: '',
			safetyCandidates: [],
			safetyOperator: null,
			safetyPin: '',
			safetySaving: false,
			safetyError: null,

			roster: [],
			rosterLoading: false,
			rosterError: null,

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
		};
	},
	computed: {
		isAuthenticated() {
			return !!this.$page.props.auth.user;
		},
		tiles() {
			return this.searchQuery.trim() ? this.searchResults : this.roster;
		},
		safetyTitle() {
			return {
				closeout: 'Confirm Building Empty',
				'start-roll-call': 'Start Roll Call',
				'close-roll-call': 'Close Roll Call',
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
		async loadRoster() {
			this.rosterLoading = true;
			this.rosterError = null;
			try {
				const response = await axios.get('/json/volunteer-sign-ins/roster');
				this.roster = response.data.records;
			} catch (e) {
				this.rosterError = 'Could not load the volunteer list.';
			} finally {
				this.rosterLoading = false;
			}
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
			this.searchQuery = '';
			this.loadRoster();
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
				const response = await axios.post('/json/volunteer-sign-ins/people', this.newPerson);
				this.selected = response.data.record;
				this.form = this.blankForm();
				this.view = 'confirm-in';
			} catch (e) {
				this.addPersonError = e.response?.data?.message || 'Could not add this person.';
			} finally {
				this.addingPerson = false;
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
				this.cancelConfirm();
			} catch (e) {
				this.saveError = e.response?.data?.message || 'Could not sign in.';
			} finally {
				this.saving = false;
			}
		},
		async submitSignOut() {
			this.saving = true;
			this.saveError = null;
			try {
				await axios.post(`/json/volunteer-sign-ins/${this.selected.current_sign_in.id}/sign-out`);
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
			this.activeRollCallId = response.data.active_roll_call_id;
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
				if (this.safetyAction === 'closeout') {
					await axios.post('/json/building-safety/closeout', payload);
				} else if (this.safetyAction === 'start-roll-call') {
					await axios.post('/json/building-safety/roll-calls', payload);
				} else if (this.safetyAction === 'close-roll-call') {
					await axios.post(`/json/building-safety/roll-calls/${this.activeRollCallId}/close`, payload);
				}
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
		this.loadRoster();
		this.loadCategories();
		this.loadOccupancyCount();
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
</style>
