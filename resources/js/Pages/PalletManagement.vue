<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';
import RIForm from '@/Components/RIForm.vue';
import RISubform from '@/Components/RISubform.vue';

defineProps({
    breadcrumb: {
        type: Array,
    },
});
</script>

<template>
  <Head title="Pallet Management" />
  <AuthenticatedLayout :breadcrumb="breadcrumb">
    <RIForm 
      title="Pallet Management"
      datasource="/json/pallets"
	  :precreate="true"
	  >
      
      <template #thead>
		<th>#</th>
        <th>Packed Date</th>
        <th>Last Location</th>
        <th>Status</th>
      </template>

      <template #tbody="{ record, index }">
		<td><span style="font-weight: bold; font-size: 14pt;"> {{ record.id.toString().padStart(8, "0").substring(6,8) }}</span>
			&nbsp; &nbsp;  (P{{ record.id.toString().padStart(8, "0") }})</td>
        <td>{{ record.datepacked }}</td>
        <td v-if="record.last_location">{{ record.last_location.code }}</td>
        <td v-else>Unknown</td>
        <td>{{ record.last_status }}</td>
      </template>

      <template #default="{ record, editing, templates }">
		
		<div class="ri_formtable">
		  <div class="ri_fieldset"  v-if="record.id">
		    <div class="ri_fieldlabel"> Pallet # </div>
		    			<span style="font-weight: bold; font-size: 14pt;"> {{ record.id.toString().padStart(8, "0").substring(6,8) }}</span>
						&nbsp; &nbsp;  (P{{ record.id.toString().padStart(8, "0") }})  
		  </div></div>
		  <div class="ri_fieldset">
			<div class="ri_fieldlabel">  </div>
			<p>
			<button @click="printLabel(record.id)" class="ri_defaultbutton">Print Pallet Label</button>
			</p>
		  </div>  
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
			  display="code"
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
          v-model:records="record.statuses"
          :template="[]"
          :enabled="false">
          
          <template #thead>
            <th>Date</th>
            <th>Location</th>
            <th>Status</th>
          </template>

          <template #tbody="{ subrecord, index }">
            <td>{{ subrecord.created_at }}</td>
            <td>{{ subrecord.location ? subrecord.location.code : '' }}</td>
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

<script>

export default {
	
	methods: {
		printLabel(palletId) {
		    window.open("/report/pallet/" + palletId,"_blank");
		},
    }
}
</script>
