<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';
import MultiSelect from '@/Components/MultiSelect.vue';
import RIForm from '@/Components/RIForm.vue';
import TextArea from '@/Components/TextArea.vue';

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
              required
              :enabled="editing"
            /> 
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Last Name:</div>
            <TextInput
              v-model="record.last_name"
              required
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
