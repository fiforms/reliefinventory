<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';
import RIForm from '@/Components/RIForm.vue';
import RISubform from '@/Components/RISubform.vue';
</script>

<template>
  <Head title="Pallet Management" />
  <AuthenticatedLayout>
    <RIForm 
      title="Pallet Management"
      datasource="/json/pallets">
      
      <template #thead>
        <th>Packed Date</th>
        <th>Last Location</th>
        <th>Status</th>
      </template>

      <template #tbody="{ record, index }">
        <td>{{ record.datepacked }}</td>
        <td v-if="record.last_location">{{ record.last_location.code }}</td>
        <td v-else>Unknown</td>
        <td>{{ record.last_status }}</td>
      </template>

      <template #default="{ record, editing, templates }">
        <div class="ri_formtable">
          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Date Packed:</div>
            <TextInput
              id="datepacked"
              type="date"
              v-model="record.datepacked"
              required
              autofocus
              :enabled="editing"
            />
          </div>
          
          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Location:</div>
            <ComboBox
              v-model:keyValue="record.last_location_id"
              v-model:updates="record.last_location"
              optionsource="/json/locations"
              :enabled="editing"
            />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Status:</div>
            <ComboBox
              v-model:keyValue="record.last_status"
              :options="[{ id: 'created', name: 'Newly Created' }, { id: 'wrapped', name: 'Wrapped' }, { id: 'moved', name: 'Moved' },{ id: 'unwrapped', name: 'Unwrapped' },{ id: 'archived', name: 'Archived' },]"
              :enabled="editing"
            />
          </div>
        </div>

        <RISubform
          title="Pallet Status History"
          v-model:records="record.pallet_status"
          :template="templates.pallet_status"
          :enabled="false">
          
          <template #thead>
            <th>Date</th>
            <th>Location</th>
            <th>Status</th>
          </template>

          <template #tbody="{ subrecord, index }">
            <td>{{ subrecord.created_at }}</td>
            <td>{{ subrecord.location.code }}</td>
            <td>{{ subrecord.status }}</td>
          </template>

          <template #default="{ subrecord, index }">
            <td>
            </td>
          </template>
        </RISubform>
      </template>
    </RIForm>
  </AuthenticatedLayout>
</template>
