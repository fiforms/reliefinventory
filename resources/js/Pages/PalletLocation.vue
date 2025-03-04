<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
     
<!-- PalletLocation.vue  
     This is an RIForm Vue File for tracking pallet movements in the warehouse -->

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';
import RIForm from '@/Components/RIForm.vue';
import TextArea from '@/Components/TextArea.vue';
</script>

<template>
  <Head title="Pallet Location Tracking" />
  <AuthenticatedLayout>
    <template #header>
    </template>
    
    <!-- RIForm Component for pallet location tracking -->
    <RIForm 
      title="Pallet Location Tracking" 
      datasource="/json/palletstatus"
      newrecordcaption="New Movement">
      
        <template #thead>
            <th>Date</th>
            <th>Pallet ID</th>
            <th>Location</th>
            <th>Status</th>
        </template>
        
        <template #tbody="{ record, index }"> 
            <td>{{ record.created_at }}</td>
            <td>{{ record.pallet_id }}</td>
            <td>{{ record.location ? record.location.code : 'Unknown' }}</td>
            <td>{{ record.status }}</td>
        </template>
        
        <template #default="{ record, editing, templates }">
        <div class="ri_formtable">
          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Pallet ID:</div>
            <TextInput
                id="pallet_id"
                v-model="record.pallet_id"
                required
                autofocus
                placeholder="Scan pallet barcode"
                :enabled="editing"
            /> 
          </div>
          
          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Location:</div>
            <ComboBox 
                v-model:keyValue="record.location_id"
                v-model:updates="record.location"
                optionsource="/json/locations"
                display="code"
                placeholder="Scan location barcode"
                :enabled="editing"
            />
          </div>
          
          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Status:</div>
            <ComboBox 
                v-model:keyValue="record.status"
                optionsource="/json/palletstatus/statuses"
                :enabled="editing"
            />
          </div>
          
          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Notes:</div>
            <TextArea
                v-model="record.notes"
                :enabled="editing"
            /> 
          </div>

          <div v-if="record.pallet_id && editing">
            <h3 class="mt-4">Pallet Information</h3>
            <div class="ri_fieldset">
              <div class="ri_fieldlabel">Date Packed:</div>
              {{ record.pallet ? record.pallet.datepacked : 'Unknown' }}
            </div>
            <div class="ri_fieldset">
              <div class="ri_fieldlabel">Current Status:</div>
              {{ record.pallet ? record.pallet.last_status : 'Unknown' }}
            </div>
          </div>
        </div>
        </template>
    </RIForm>
  </AuthenticatedLayout>
</template>