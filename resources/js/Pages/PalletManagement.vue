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

const kindOptions = [
	{ id: 'R', name: 'Receiving' },
	{ id: 'W', name: 'Warehouse' },
	{ id: 'S', name: 'Shipping' },
	{ id: 'H', name: 'Hold' },
	{ id: 'Q', name: 'Quarantine' },
];
</script>

<template>
  <Head title="Pallet Management" />
  <AuthenticatedLayout :breadcrumb="breadcrumb">
    <RIForm
      title="Pallet Management"
      datasource="/json/pallets"
	  :precreate="false"
	  >

      <template #thead>
		<th>Tag</th>
        <th>Kind</th>
        <th>Packed Date</th>
        <th>Location</th>
        <th>Status</th>
      </template>

      <template #tbody="{ record, index }">
		<td><span style="font-weight: bold; font-size: 14pt;"> {{ record.kind }}{{ record.id.toString().padStart(8, "0") }}</span></td>
        <td>{{ record.kind }}</td>
        <td>{{ record.datepacked }}</td>
        <td v-if="record.location">{{ record.location.code }}</td>
        <td v-else>Unknown</td>
        <td>{{ record.status }}</td>
      </template>

      <template #default="{ record, editing, templates }">

		<div class="ri_formtable">
		  <div class="ri_fieldset" v-if="record.id">
		    <div class="ri_fieldlabel"> Pallet Tag </div>
		    <span style="font-weight: bold; font-size: 14pt;"> {{ record.kind }}{{ record.id.toString().padStart(8, "0") }}</span>
		  </div>
		</div>
		  <div class="ri_fieldset" v-if="record.id">
			<div class="ri_fieldlabel">  </div>
			<p>
			<button @click="printLabel(record.id)" class="ri_defaultbutton">Print Pallet Label</button>
			</p>
		  </div>
		  <div class="ri_formtable">
		    <div class="ri_fieldset">
		      <div class="ri_fieldlabel">Kind:</div>
			  <!-- Fixed forever once labeled (new-label-per-trip rule) -->
			  <ComboBox
			  	v-model:keyValue="record.kind"
			  	:options="kindOptions"
			  	:enabled="editing && !record.id"
			  />
		    </div>

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
              v-model:keyValue="record.location_id"
              v-model:updates="record.location"
              optionsource="/json/locations"
              :enabled="editing"
			  display="code"
            />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Status:</div>
            <TextInput
              v-model="record.status"
              placeholder="e.g. received, sorting, empty, missing..."
              :enabled="editing"
           />
          </div>

		  <div class="ri_fieldset">
			<div class="ri_fieldlabel">Container Type:</div>
			<ComboBox
			  v-model:keyValue="record.container_type"
			  :options="[{ id: 'pallet', name: 'Pallet' }, { id: 'gaylord', name: 'Gaylord' }]"
			  :enabled="editing"
			/>
		  </div>

		  <div class="ri_fieldset" v-if="record.kind === 'R'">
			<div class="ri_fieldlabel">Donor:</div>
			<ComboBox
			  v-model:keyValue="record.donor_person_id"
			  v-model:updates="record.donor"
			  optionsource="/json/people"
			  display="last_name"
			  :enabled="editing"
			/>
		  </div>

		  <div class="ri_fieldset" v-if="record.kind === 'H'">
			<div class="ri_fieldlabel">Destination:</div>
			<ComboBox
			  v-model:keyValue="record.destination_person_id"
			  v-model:updates="record.destination"
			  optionsource="/json/people"
			  display="last_name"
			  :enabled="editing"
			/>
		  </div>

		  <div class="ri_fieldset" v-if="record.kind === 'W'">
			<div class="ri_fieldlabel">Earliest Expiry:</div>
			<TextInput
			  type="date"
			  v-model="record.earliest_expiry"
			  :enabled="editing"
			/>
		  </div>

		  <div class="ri_fieldset" v-if="record.condition">
			<div class="ri_fieldlabel">Condition:</div>
			<ComboBox
			  v-model:keyValue="record.condition"
			  :options="[{ id: 'pending', name: 'Pending QC' }, { id: 'good', name: 'Good' }, { id: 'condemned', name: 'Condemned' }]"
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
			<th>Notes</th>
          </template>

          <template #tbody="{ subrecord, index }">
            <td>{{ subrecord.created_at }}</td>
            <td>{{ subrecord.location ? subrecord.location.code : '' }}</td>
            <td>{{ subrecord.status }}</td>
			<td>{{ subrecord.notes }}</td>
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
