<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';
import MultiSelect from '@/Components/MultiSelect.vue';
import RIForm from '@/Components/RIForm.vue';
import TextArea from '@/Components/TextArea.vue';
import axios from 'axios';

defineProps({
    breadcrumb: {
        type: Array,
    },
});

// Every permission key, for the per-person override table. Loaded once —
// the same list is offered on every record being edited.
const allPermissions = ref([]);
onMounted(() => {
	axios.get('/json/permissions').then((response) => {
		allPermissions.value = response.data.records || [];
	});
});

// A person's effective access is their roles' default grants, with these
// per-person overrides layered on top in either direction — see
// granular-permissions-model.md. "default" means no override row at all.
function overrideState(record, permissionId) {
	const existing = (record.person_permissions || []).find((o) => o.permission_id === permissionId);
	if (!existing) return 'default';
	return existing.granted ? 'grant' : 'revoke';
}
function setOverrideState(record, permissionId, state) {
	const overrides = (record.person_permissions || []).filter((o) => o.permission_id !== permissionId);
	if (state === 'grant') overrides.push({ permission_id: permissionId, granted: true });
	if (state === 'revoke') overrides.push({ permission_id: permissionId, granted: false });
	record.person_permissions = overrides;
}
</script>

<template>
  <Head title="People Entry" />
  <AuthenticatedLayout :breadcrumb="breadcrumb">
    <template #header>
    </template>

    <RIForm 
      title="People Entry Form" 
      datasource="/json/people">
      
      <template #thead>
        <th>First Name</th>
        <th>Last Name</th>
        <th>Organization</th>
        <th>Phone</th>
        <th>Email</th>
        <th>Roles</th>
      </template>

      <template #tbody="{ record }"> 
        <td>{{ record.first_name }}</td>
        <td>{{ record.last_name }}</td>
        <td>{{ record.organization || 'N/A' }}</td>
        <td>{{ record.phone || 'N/A' }}</td>
        <td>{{ record.email || 'N/A' }}</td>
        <td class="comma-separated"><span v-for="sub in record.roles" > {{ sub.name }} </span></td>
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

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Address:</div>
            <TextArea
              v-model="record.address"
              :enabled="editing"
            /> 
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">City:</div>
            <TextInput
              v-model="record.city"
              :enabled="editing"
            /> 
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">State:</div>
            <TextInput
              v-model="record.state"
              maxlength="2"
              :enabled="editing"
            /> 
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">ZIP Code:</div>
            <TextInput
              v-model="record.zip"
              maxlength="10"
              :enabled="editing"
            /> 
          </div>

		  <div class="ri_fieldset">
		    <div class="ri_fieldlabel">County:</div>
		    <ComboBox
				v-model:keyValue="record.county_id"
				v-model:updates="record.county_id"
				optionsource="/json/counties"
				display="county"
			  	:enabled="editing"
		    /> 
		  </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Roles:</div>
            <MultiSelect
				v-model:records="record.people_roles"
				:template="templates.people_roles" 
				optionsource="/json/roles"
                :enabled="editing"
				fk_field="role_id"
				display="name"
            />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Comments:</div>
            <TextArea
              v-model="record.comments"
              :enabled="editing"
            />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Permission Overrides:</div>
            <p class="ri_hint">
                Only set these to give this specific person a capability their roles don't already
                grant, or to take one away without changing their role. Leave "Default" otherwise.
            </p>
            <table class="ri_datatable" border="1">
              <thead>
                <tr><th>Permission</th><th>Default (role)</th><th>Always Grant</th><th>Always Revoke</th></tr>
              </thead>
              <tbody>
                <tr v-for="permission in allPermissions" :key="permission.id">
                  <td>{{ permission.key }}</td>
                  <td v-for="state in ['default', 'grant', 'revoke']" :key="state" style="text-align:center;">
                    <input
                      type="radio"
                      :name="'perm-' + record.id + '-' + permission.id"
                      :checked="overrideState(record, permission.id) === state"
                      :disabled="!editing"
                      @change="setOverrideState(record, permission.id, state)"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </RIForm>
  </AuthenticatedLayout>
</template>

<style scoped>

td.comma-separated span:not(:last-child)::after {
  content: ', ';
}
</style>
