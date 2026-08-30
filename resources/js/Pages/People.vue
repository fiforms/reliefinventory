<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';
import MultiSelect from '@/Components/MultiSelect.vue';
import Checkbox from '@/Components/Checkbox.vue';
import RIForm from '@/Components/RIForm.vue';
import TextArea from '@/Components/TextArea.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import AddressCorrectionCheck from '@/Components/AddressCorrectionCheck.vue';

defineProps({
    breadcrumb: {
        type: Array,
    },
});
</script>

<template>
  <Head title="People Entry" />
  <AuthenticatedLayout :breadcrumb="breadcrumb">
    <template #header>
    </template>

    <RIForm
      ref="riform"
      title="People Entry Form"
      datasource="/json/people"
      :filter="peopleFilter">

      <template #listactions>
        <input
          type="text"
          v-model="peopleSearch"
          class="ri_forminput people_search"
          placeholder="Search by name or organization..."
        />
      </template>

      <template #thead>
        <th>First Name</th>
        <th>Last Name</th>
        <th>Organization</th>
        <th>Category</th>
        <th>Parent Org</th>
        <th>Phone</th>
        <th>Email</th>
        <th>Roles</th>
        <th>Volunteer</th>
      </template>

      <template #tbody="{ record }">
        <td>{{ record.first_name }}</td>
        <td>{{ record.last_name }}</td>
        <td>{{ record.organization || 'N/A' }}<span v-if="record.is_organization" class="people_badge">org</span></td>
        <td>{{ record.category ? record.category.name : '' }}</td>
        <td>{{ record.parent ? record.parent.full_name : '' }}</td>
        <td>{{ record.phone || 'N/A' }}</td>
        <td>{{ record.email || 'N/A' }}</td>
        <td class="comma-separated"><span v-for="sub in record.roles" > {{ sub.name }} </span></td>
        <td>{{ record.is_volunteer ? 'Yes' : '' }}</td>
      </template>

      <template #default="{ record, editing, templates }">
        <div class="ri_formtable">
          <div class="ri_fieldset">
            <div class="ri_fieldlabel">First Name:</div>
            <TextInput
              v-model="record.first_name"
              :enabled="editing"
            />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Last Name:</div>
            <TextInput
              v-model="record.last_name"
              :enabled="editing"
            />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Organization:</div>
            <TextInput
              v-model="record.organization"
              :enabled="editing"
            />
          </div>
          <p class="ri_hint">Provide a name (first + last) and/or an organization &mdash; at least one is required.</p>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">This record is the organization itself:</div>
            <Checkbox
              v-model="record.is_organization"
              :enabled="editing"
            />
          </div>
          <p class="ri_hint">
            Check this for the org's own record (e.g. "Macedonia SDA Church") so individual contacts at
            that org can be linked to it below, instead of inferring org-vs-individual from which name
            fields happen to be filled in.
          </p>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Parent Organization:</div>
            <SearchSelect
              v-model="record.parent_person_id"
              optionsource="/json/people?is_organization=1"
              display="full_name"
              placeholder="Search organizations..."
              :enabled="editing"
            />
          </div>
          <p class="ri_hint">
            Set this when this person is a contact at an organization already recorded above (checked
            "This record is the organization itself"), rather than a standalone donor/recipient/contact.
          </p>

          <div class="ri_fieldset" v-if="record.parent_person_id">
            <div class="ri_fieldlabel">Contact Role:</div>
            <TextInput
              v-model="record.contact_role"
              placeholder="e.g. Primary, Delivery, Billing"
              :enabled="editing"
            />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Category:</div>
            <SearchSelect
              ref="categorySelect"
              v-model="record.category_id"
              optionsource="/json/person-categories"
              display="name"
              placeholder="e.g. Donor, Supplier, Warehouse Contact..."
              :allowcreate="true"
              :enabled="editing"
              @create="createCategory"
            />
          </div>
          <p v-if="categoryError" class="ri_error">{{ categoryError }}</p>
          <p class="ri_hint">
            An open-ended tag for filtering the People list (Donor, Supplier, Warehouse Contact, etc.) —
            type a new one to add it. Not tied to permissions and not required.
          </p>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Badge Code:</div>
            <TextInput
              v-model="record.badge_code"
              :enabled="editing"
            />
          </div>
          <p class="ri_hint">Scan or type the code from this person's physical badge, if issued &mdash; used for PIN-unlock badge scanning.</p>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Phone:</div>
            <TextInput
              v-model="record.phone"
              type="tel"
              :enabled="editing"
            /> 
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Email:</div>
            <TextInput
              v-model="record.email"
              type="email"
              :enabled="editing"
            /> 
          </div>

		  <!-- ZIP first, then street address: geocod.io resolves city/state/
		       county confidently from street+zip alone, so asking ZIP (quick
		       to state on a call) before the street lets the rest fill in
		       automatically once address is entered — the partner reciting
		       their full address afterward becomes a confirmation pass over
		       already-filled fields rather than the only source of them.
		       @blur.capture (not @blur) because it needs to catch focus
		       leaving TextInput/TextArea's inner <input>/<textarea> despite
		       those components' own wrapping div sitting between here and
		       the actual field — blur doesn't bubble, but capture-phase
		       listeners on an ancestor still see it on the way down. -->
		  <div class="people_address_fields" @blur.capture="maybeAutoLookupCounty(record)">
			  <div class="ri_fieldset">
				<div class="ri_fieldlabel">ZIP Code:</div>
				<TextInput
				  v-model="record.zip"
				  maxlength="10"
				  :enabled="editing"
				/>
			  </div>

			  <div class="ri_fieldset">
				<div class="ri_fieldlabel">Address:</div>
				<TextArea
				  v-model="record.address"
				  :enabled="editing"
				/>
			  </div>

			  <AddressCorrectionCheck
				:status="addressCheck.status" :casing-only="addressCheck.casingOnly"
				:entered="addressCheck.entered" :suggested="addressCheck.suggested"
				@accept="acceptAddressSuggestion" @keep="keepAddressAsEntered" />

			  <p v-if="editing && record.address && !(record.verified_address || record.address_verified_at)
				&& addressCheck.status === 'idle'" class="people_county_hint">
				<button type="button" class="ri_formbutton" :disabled="$page.props.offlineMode"
					@click="verifyAddress(record)">
					{{ $page.props.offlineMode ? "Can't verify address — offline" : 'Verify Address' }}
				</button>
			  </p>

			  <div class="ri_fieldset">
				<div class="ri_fieldlabel">City:</div>
				<TextInput
				  v-model="record.city"
				  :enabled="editing && addressCheck.status !== 'checking'"
				/>
			  </div>

			  <div class="ri_fieldset">
				<div class="ri_fieldlabel">State:</div>
				<TextInput
				  v-model="record.state"
				  maxlength="2"
				  :enabled="editing && addressCheck.status !== 'checking'"
				/>
			  </div>

			  <div class="ri_fieldset">
				<div class="ri_fieldlabel">County:</div>
				<ComboBox
					v-model:keyValue="record.county_id"
					v-model:updates="record.county_id"
					optionsource="/json/counties"
					display="county"
					secondaryDisplay="state"
					:filter="(c) => !record.state || c.state === record.state.toUpperCase()"
					:enabled="editing"
				/>
				<p v-if="countyLookupError" class="people_county_error">{{ countyLookupError }}</p>
				<p v-if="countyLookupHint" class="people_county_hint">{{ countyLookupHint }}</p>
			  </div>
		  </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Roles:</div>
            <MultiSelect
				v-model:records="record.people_roles"
				:template="templates.people_roles"
				optionsource="/json/roles?context=people"
                :enabled="editing"
				fk_field="role_id"
				display="name"
            />
          </div>
          <p class="ri_hint">
            Staff/login access (Administrator, Office, etc.) and permission overrides are
            managed from Setup &rarr; User Administration, not here.
          </p>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Volunteer:</div>
            <Checkbox
              v-model="record.is_volunteer"
              :enabled="editing"
            />
          </div>
          <p class="ri_hint">
            Whether this person is a volunteer is separate from their role &mdash; a volunteer
            can be an office manager or an administrator too. Feeds volunteer hours tracking.
          </p>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Comments:</div>
            <TextArea
              v-model="record.comments"
              :enabled="editing"
            />
          </div>
        </div>
      </template>
    </RIForm>
  </AuthenticatedLayout>
</template>

<script>
import axios from 'axios';
import { invalidateOptions } from '@/Components/SearchSelect.vue';

function normalizeAddr(s) {
  return (s || '').toString().trim().toLowerCase().replace(/\s+/g, ' ');
}
const IDLE_ADDRESS_CHECK = { status: 'idle', casingOnly: false, entered: {}, suggested: {} };

export default {
  data() {
    return {
      peopleSearch: '',
      categoryError: null,
      countyLookupError: null,
      countyLookupHint: null,
      // Drives AddressCorrectionCheck inline (see maybeAutoLookupCounty).
      addressCheck: { status: 'idle', casingOnly: false, entered: {}, suggested: {} },
    };
  },
  computed: {
    peopleFilter() {
      const query = this.peopleSearch.trim().toLowerCase();
      return (record) => {
        if (!query) return true;
        const haystack = [record.first_name, record.last_name, record.organization]
          .filter(Boolean)
          .join(' ')
          .toLowerCase();
        return haystack.includes(query);
      };
    },
  },
  methods: {
    // Category is a small open-ended tag list (Donor/Supplier/Warehouse
    // Contact/...) — quick-added inline via SearchSelect's allowcreate,
    // same idiom Receiving.vue uses for donors, but simpler (one field).
    async createCategory(name) {
      this.categoryError = null;
      const trimmed = (name || '').trim();
      if (!trimmed) return;
      try {
        const response = await axios.post('/json/person-categories', { name: trimmed });
        invalidateOptions('/json/person-categories');
        await this.$refs.categorySelect?.refresh(response.data.record.id);
      } catch (error) {
        this.categoryError = error.response?.data?.message || 'Could not save category.';
      }
    },
    // Looks up county/city/state/zip once there's enough to geocode —
    // fires on blur of any address field (see @blur.capture above), but
    // only actually calls out once address is non-blank and at least one
    // of zip/state is present (geocod.io resolves confidently from
    // street+zip alone even with city/state blank — tested at `rooftop`
    // accuracy). While the request is in flight, City/State are disabled
    // in the template (bound to `addressCheck.status === 'checking'`) —
    // editing a field mid-flight is exactly what caused the cursor-jump
    // race Mark found, since the response landing while still typing
    // collided with v-model. Only the ADDRESS text itself gets a review
    // step (AddressCorrectionCheck, rendered inline — no modal, no native
    // <dialog>), and only when geocod.io's version actually differs;
    // city/state/zip are simple enough to just fill in directly once
    // blank. County is applied directly too, since it's a separate,
    // already-editable picker widget.
    // `force` bypasses the dedup key — used by the manual "Verify Address"
    // button so it always re-checks even if this exact input was already
    // tried (e.g. tried once while offline and failed, or the record was
    // just loaded and nothing's been retyped).
    async maybeAutoLookupCounty(record, force = false) {
      if (this.$page.props.offlineMode) return; // no internet — don't even attempt it
      const address = (record.address || '').trim();
      const zip = (record.zip || '').trim();
      const state = (record.state || '').trim();
      if (!address || (!zip && !state)) return;

      const key = `${address}|${state}|${zip}`;
      if (!force && record.__geocodeKey === key) return; // already tried this exact input
      if (record.__geocodeKey !== key) record.verified_address = false; // input changed since any prior verification
      record.__geocodeKey = key;

      this.countyLookupError = null;
      this.countyLookupHint = null;
      this.addressCheck = { ...IDLE_ADDRESS_CHECK, status: 'checking' };
      try {
        const response = await axios.post('/json/geocode/county', {
          address: record.address, city: record.city, state: record.state, zip: record.zip,
        });
        const entered = { address: record.address, city: record.city, state: record.state, zip: record.zip };
        const suggested = {
          address: response.data.address, city: response.data.city,
          state: response.data.state, zip: response.data.zip,
        };
        if (!suggested.address || entered.address === suggested.address) {
          this.applyAddressFields(record, suggested);
          record.verified_address = true;
          this.addressCheck = { ...IDLE_ADDRESS_CHECK };
        } else {
          this.addressCheck = {
            status: 'ready',
            casingOnly: normalizeAddr(entered.address) === normalizeAddr(suggested.address),
            entered, suggested,
          };
        }
        this._addressCheckRecord = record;
        if (!record.county_id && response.data.county_id) {
          record.county_id = response.data.county_id;
        } else if (!record.county_id && response.data.county) {
          this.countyLookupHint = `Geocodio suggests "${response.data.county}, ${response.data.state}" `
            + `— that's not in the county list above yet.`;
        }
      } catch (error) {
        this.addressCheck = { ...IDLE_ADDRESS_CHECK };
        // 422: geocod.io just couldn't resolve this address — not worth an
        // alarming error. 503: lookup is unavailable (offline mode, or no
        // API key configured) — also expected, not a fault; address entry
        // works fine without it either way.
        if (![422, 503].includes(error.response?.status)) {
          this.countyLookupError = error.response?.data?.message || 'Could not look up that address.';
        }
      }
    },
    // Fills city/state/zip from a suggestion, but only where blank — never
    // overwrites something already typed.
    applyAddressFields(record, suggested) {
      if (!record.city) record.city = suggested.city || record.city;
      if (!record.state) record.state = suggested.state || record.state;
      if (!record.zip) record.zip = suggested.zip || record.zip;
    },
    acceptAddressSuggestion() {
      const record = this._addressCheckRecord;
      const suggested = this.addressCheck.suggested;
      if (record) {
        record.address = suggested.address;
        this.applyAddressFields(record, suggested);
        record.verified_address = true;
      }
      this.addressCheck = { ...IDLE_ADDRESS_CHECK };
    },
    keepAddressAsEntered() {
      const record = this._addressCheckRecord;
      const suggested = this.addressCheck.suggested;
      if (record) {
        this.applyAddressFields(record, suggested);
        // Geocod.io was still consulted and a human looked at both options
        // — that counts as "checked," even though the as-typed version won.
        record.verified_address = true;
      }
      this.addressCheck = { ...IDLE_ADDRESS_CHECK };
    },
    // Manual fallback for when the automatic blur-triggered lookup never
    // ran (offline mode was on at entry time, or this record predates the
    // whole feature) — same underlying check, just user-initiated and
    // forced past the dedup guard. Deliberately per-record and opt-in
    // rather than a batch job: geocod.io's free tier is a shared daily
    // quota, and there's no reason to spend it re-checking addresses
    // nobody's actively working with.
    verifyAddress(record) {
      this.maybeAutoLookupCounty(record, true);
    },
  },
};
</script>

<style scoped>

td.comma-separated span:not(:last-child)::after {
  content: ', ';
}

.people_badge {
  margin-left: 0.4em;
  font-size: 0.75em;
  color: #4338ca;
  border: 1px solid #c7d2fe;
  border-radius: 4px;
  padding: 0.05em 0.4em;
}

.people_search {
  max-width: 20rem;
}

.people_county_error {
  color: #b91c1c;
  font-size: 0.85em;
  margin-top: 0.3em;
}

.people_county_hint {
  color: #4338ca;
  font-size: 0.85em;
  margin-top: 0.3em;
}
</style>
