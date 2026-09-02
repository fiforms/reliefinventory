<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- Users.vue

	User Administration (permission: manage-users; TODO.md item 1) —
	create/promote/deactivate login-capable accounts (anyone with an
	email). Distinct from /setup/people: that page manages party-tracking
	roles (Partner/Donor) plus the is_volunteer flag, with no permission
	overrides; this page manages the login-capable roles (Administrator,
	Sorting and Inventory, Office, Partner) and per-person permission
	overrides. Roles here are mutually exclusive presets, not a role
	hierarchy — see selectRole() below. Whether someone is a volunteer
	is tracked separately (is_volunteer) since it's a fact about the
	person, not their permission role — a volunteer can be the office
	manager or an administrator.

	Built to the admin-page-style conventions (see SystemAdmin.vue), not
	RIForm — this is an account-administration panel (create/promote/
	deactivate actions, not a plain field-editing CRUD form), so it needs
	its own list/detail flow rather than RIForm's generic one.

	Warehouse Users / Partners is a display-only filter over the same
	list+datasource — not a workflow split.
-->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Checkbox from '@/Components/Checkbox.vue';
import axios from 'axios';

defineProps({
    breadcrumb: {
        type: Array,
    },
});

const STAFF_ROLE_NAMES = ['Administrator', 'Sorting and Inventory', 'Office'];

const users = ref([]);
const allPermissions = ref([]);
// Each role's own permission bundle (id + key only) — a role is just a
// named preset over the flat permission list below, not a live grant
// mechanism of its own. Fetched with ?context=users so this page only
// ever offers the login-capable roles (Administrator, Partner,
// etc.), same restriction the roles picker used before.
const roles = ref([]);
const tab = ref('warehouse'); // 'warehouse' | 'partners'
const selected = ref(null); // the record being created/edited, or null for the list view
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const accountActionBusy = ref(false);
const confirmingDeactivate = ref(false);

function fetchUsers() {
    loading.value = true;
    return axios.get('/json/users')
        .then((response) => {
            users.value = response.data.records || [];
        })
        .finally(() => {
            loading.value = false;
        });
}

onMounted(() => {
    fetchUsers();
    axios.get('/json/permissions').then((response) => {
        allPermissions.value = response.data.records || [];
    });
    axios.get('/json/roles?context=users').then((response) => {
        roles.value = response.data.records || [];
    });
});

const filteredUsers = computed(() => users.value.filter((record) => {
    const roleNames = (record.roles || []).map((r) => r.name);
    const isStaff = roleNames.some((name) => STAFF_ROLE_NAMES.includes(name));
    return tab.value === 'warehouse' ? isStaff : !isStaff;
}));

function statusLabel(record) {
    if (record.disabled_at) {
        // Self-registration leaves an account disabled until email
        // verification (or this admin override) clears it — distinct from
        // an admin explicitly deactivating an already-verified account.
        return record.email_verified_at ? 'Deactivated' : 'Pending — email not verified';
    }
    if (!record.has_password) return 'Invited — pending setup';
    return 'Active';
}

// The permission ids granted by default just from the record's currently
// assigned roles — a role's own permission bundle, unioned. This is what
// "no override" (below) falls back to; it's also what a role preset
// button applies in one tap.
function roleDefaultPermissionIds(record) {
    const assignedRoleIds = new Set((record.people_roles || []).map((r) => r.role_id));
    const ids = new Set();
    roles.value
        .filter((role) => assignedRoleIds.has(role.id))
        .forEach((role) => (role.permissions || []).forEach((p) => ids.add(p.id)));
    return ids;
}

// Effective grant = an explicit override if one exists, else whatever the
// assigned roles' default bundle says. Mirrors HasPermissions::
// effectivePermissionKeys() on the backend so the checklist always shows
// what the person can *actually* do, not just what's been explicitly set.
function isGranted(record, permissionId) {
    const override = (record.person_permissions || []).find((o) => o.permission_id === permissionId);
    if (override) return override.granted;
    return roleDefaultPermissionIds(record).has(permissionId);
}

// Only stores an explicit override when the desired state differs from
// what the role default would already give — keeps person_permissions
// minimal (a role's bundle changing later still propagates to anyone who
// never customized that specific permission) instead of stamping every
// permission explicit on every save.
function setPermissionState(record, permissionId, desired) {
    const roleDefault = roleDefaultPermissionIds(record).has(permissionId);
    const overrides = (record.person_permissions || []).filter((o) => o.permission_id !== permissionId);
    if (desired !== roleDefault) overrides.push({ permission_id: permissionId, granted: desired });
    record.person_permissions = overrides;
}

function togglePermission(record, permissionId) {
    setPermissionState(record, permissionId, !isGranted(record, permissionId));
}

function grantAll(record) {
    allPermissions.value.forEach((p) => setPermissionState(record, p.id, true));
}
function revokeAll(record) {
    allPermissions.value.forEach((p) => setPermissionState(record, p.id, false));
}

function roleAssigned(record, roleId) {
    return (record.people_roles || []).some((r) => r.role_id === roleId);
}

// Roles here are mutually exclusive presets, not additive tags — tapping
// one is a full reset to exactly that role's bundle (matching Admin then
// Partner should leave nothing granted, not the union of both), the
// same "Any Day" pattern OrderEntry.vue's delivery-day chips use for a
// one-tap bulk-select, just applied as a replace instead of a merge.
// Tapping the already-active role clears the selection entirely (also a
// full reset, to nothing).
function selectRole(record, role) {
    const wasActive = roleAssigned(record, role.id);
    record.people_roles = wasActive ? [] : [{ role_id: role.id }];

    const grantedIds = new Set(wasActive ? [] : (role.permissions || []).map((p) => p.id));
    allPermissions.value.forEach((p) => setPermissionState(record, p.id, grantedIds.has(p.id)));
}

function newUser() {
    error.value = '';
    notice.value = '';
    selected.value = {
        first_name: '', last_name: '', email: '', is_volunteer: false,
        people_roles: [], person_permissions: [],
    };
}

function selectUser(record) {
    error.value = '';
    notice.value = '';
    confirmingDeactivate.value = false;
    selected.value = JSON.parse(JSON.stringify(record));
}

function cancelEdit() {
    selected.value = null;
    error.value = '';
    confirmingDeactivate.value = false;
}

function describeSaveError(err) {
    const errors = err.response?.data?.errors;
    if (errors) return Object.values(errors).flat().join(' ');
    return err.response?.data?.message || 'Could not save. Please try again.';
}

function saveUser() {
    error.value = '';
    notice.value = '';
    saving.value = true;
    const request = selected.value.id
        ? axios.put(`/json/users/${selected.value.id}`, selected.value)
        : axios.post('/json/users', selected.value);

    request
        .then((response) => {
            notice.value = response.data.message || 'Saved.';
            cancelEdit();
            fetchUsers();
        })
        .catch((err) => {
            error.value = describeSaveError(err);
        })
        .finally(() => {
            saving.value = false;
        });
}

function runAccountAction(url, successPatch) {
    error.value = '';
    accountActionBusy.value = true;
    return axios.post(`/json/users/${selected.value.id}/${url}`)
        .then((response) => {
            notice.value = response.data.message || 'Done.';
            Object.assign(selected.value, successPatch);
            fetchUsers();
        })
        .catch((err) => {
            error.value = err.response?.data?.message || 'Could not complete that action.';
        })
        .finally(() => {
            accountActionBusy.value = false;
        });
}

function deactivate() {
    if (!confirmingDeactivate.value) {
        confirmingDeactivate.value = true;
        return;
    }
    confirmingDeactivate.value = false;
    runAccountAction('deactivate', { disabled_at: new Date().toISOString() });
}

function reactivate() {
    runAccountAction('reactivate', { disabled_at: null });
}

function resendInvite() {
    runAccountAction('resend-invite', {});
}
</script>

<template>
  <Head title="User Administration" />
  <AuthenticatedLayout :breadcrumb="breadcrumb">
    <div class="max-w-4xl mx-auto p-4 space-y-6">
      <h1 class="text-2xl font-bold">User Administration</h1>

      <div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3">{{ error }}</div>
      <div v-if="notice" class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3">{{ notice }}</div>

      <!-- List view -->
      <section v-if="!selected" class="bg-white shadow rounded-lg p-6 space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold">Users</h2>
          <PrimaryButton @click="newUser">New User</PrimaryButton>
        </div>

        <div class="flex gap-2 text-sm">
          <button
            type="button"
            class="px-3 py-1 rounded-md border"
            :class="tab === 'warehouse' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
            @click="tab = 'warehouse'"
          >Warehouse Users</button>
          <button
            type="button"
            class="px-3 py-1 rounded-md border"
            :class="tab === 'partners' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
            @click="tab = 'partners'"
          >Partners</button>
        </div>

        <p v-if="loading" class="text-gray-500 text-sm">Loading users…</p>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-gray-600 border-b">
                <th class="py-1 pr-2">Name</th>
                <th class="py-1 pr-2">Email</th>
                <th class="py-1 pr-2">Roles</th>
                <th class="py-1 pr-2">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="record in filteredUsers"
                :key="record.id"
                class="border-b hover:bg-gray-50 cursor-pointer"
                @click="selectUser(record)"
              >
                <td class="py-1 pr-2">{{ record.first_name }} {{ record.last_name }}</td>
                <td class="py-1 pr-2">{{ record.email }}</td>
                <td class="py-1 pr-2">{{ (record.roles || []).map((r) => r.name).join(', ') }}</td>
                <td class="py-1 pr-2">{{ statusLabel(record) }}</td>
              </tr>
              <tr v-if="!filteredUsers.length">
                <td colspan="4" class="py-3 text-gray-400">No users in this view.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Detail view -->
      <section v-else class="bg-white shadow rounded-lg p-6 space-y-4">
        <h2 class="text-lg font-semibold">{{ selected.id ? 'Edit User' : 'New User' }}</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
          <label class="block">
            <span class="text-gray-700">First Name</span>
            <input v-model="selected.first_name" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
          </label>
          <label class="block">
            <span class="text-gray-700">Last Name</span>
            <input v-model="selected.last_name" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
          </label>
          <label class="block sm:col-span-2">
            <span class="text-gray-700">Email</span>
            <input v-model="selected.email" type="email" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" />
          </label>
        </div>
        <p v-if="!selected.id" class="text-xs text-gray-500">
          Creating a new user sends this address an email to set their own password.
        </p>

        <div class="ri_fieldset" style="display:flex; align-items:center; gap:0.5rem;">
          <Checkbox v-model="selected.is_volunteer" :enabled="true" />
          <span class="text-sm text-gray-700">Volunteer (unpaid) — independent of their role below</span>
        </div>

        <div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-gray-700">Role — pick one; it fully replaces the permission set below</span>
          </div>
          <div class="flex flex-wrap gap-2 mt-1">
            <button
              v-for="role in roles"
              :key="role.id"
              type="button"
              class="role-chip"
              :class="{ 'role-chip-active': roleAssigned(selected, role.id) }"
              :title="(role.permissions || []).map((p) => p.key).join(', ')"
              @click="selectRole(selected, role)"
            >{{ role.name }} <span class="role-chip-count">({{ (role.permissions || []).length }})</span></button>
          </div>
          <p class="text-xs text-gray-500 mt-1">
            Partner accounts currently have ordering-scoped access pending — see
            TODO.md item 1. They can log in, but ordering permissions aren't wired up yet.
          </p>
        </div>

        <div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-gray-700">Permissions</span>
            <div class="flex gap-2">
              <SecondaryButton type="button" @click="grantAll(selected)">Grant All</SecondaryButton>
              <SecondaryButton type="button" @click="revokeAll(selected)">Revoke All</SecondaryButton>
            </div>
          </div>
          <p class="text-xs text-gray-500 mb-2">
            Checked = this person can do it. Tapping a role above sets these to exactly that
            role's bundle — check/uncheck individual ones afterward to make an exception without
            changing their role.
          </p>
          <div class="overflow-x-auto">
            <table class="w-full text-sm border">
              <tbody>
                <tr v-for="permission in allPermissions" :key="permission.id" class="border-b">
                  <td class="py-1 px-2">
                    <label class="flex items-center gap-2">
                      <input
                        type="checkbox"
                        :checked="isGranted(selected, permission.id)"
                        @change="togglePermission(selected, permission.id)"
                      />
                      {{ permission.key }}
                    </label>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="selected.id" class="border-t pt-4 space-y-2">
          <h3 class="text-sm font-semibold text-gray-700">Account — {{ statusLabel(selected) }}</h3>
          <div class="flex flex-wrap gap-2">
            <SecondaryButton
              v-if="!selected.disabled_at"
              :disabled="accountActionBusy"
              @click="resendInvite"
            >{{ selected.has_password ? 'Send Password Reset' : 'Resend Invite Email' }}</SecondaryButton>

            <button
              v-if="!selected.disabled_at"
              type="button"
              class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs uppercase tracking-widest text-white"
              :class="confirmingDeactivate ? 'bg-red-600 hover:bg-red-500' : 'bg-amber-600 hover:bg-amber-500'"
              :disabled="accountActionBusy"
              @click="deactivate"
            >{{ confirmingDeactivate ? 'Confirm — this account will be blocked from logging in' : 'Deactivate' }}</button>
            <button
              v-if="confirmingDeactivate"
              type="button"
              class="text-sm text-gray-500 underline"
              @click="confirmingDeactivate = false"
            >Cancel</button>

            <PrimaryButton
              v-if="selected.disabled_at"
              :disabled="accountActionBusy"
              @click="reactivate"
            >Reactivate</PrimaryButton>
          </div>
        </div>

        <div class="flex gap-2 pt-2">
          <PrimaryButton :disabled="saving" @click="saveUser">{{ saving ? 'Saving…' : 'Save' }}</PrimaryButton>
          <button type="button" class="text-sm text-gray-500 underline" @click="cancelEdit">Cancel</button>
        </div>
      </section>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.role-chip {
  padding: 0.35rem 0.75rem;
  border: 1px solid #d1d5db;
  border-radius: 9999px;
  background: #fff;
  color: #374151;
  font-size: 0.875rem;
  cursor: pointer;
}

.role-chip-active {
  background: #4f46e5;
  color: #fff;
  border-color: #4f46e5;
}

.role-chip-count {
  opacity: 0.7;
  font-size: 0.75em;
}
</style>
