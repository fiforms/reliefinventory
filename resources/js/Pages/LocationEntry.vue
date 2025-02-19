<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';
import RIForm from '@/Components/RIForm.vue';
</script>

<template>
  <Head title="Location Management" />
  <AuthenticatedLayout>
    <RIForm 
		title="Manage Locations" 
		datasource="/json/locations" 
		newrecordcaption="Add Location">
		
      <template #thead>
        <th>Code</th>
        <th>Route</th>
        <th>Block</th>
        <th>Aisle</th>
        <th>Lane</th>
        <th>Stack</th>
        <th>Use</th>
        <th>Status</th>
      </template>

      <template #tbody="{ record, index }">
        <td>{{ record.code }}</td>
        <td>{{ record.Route }}</td>
        <td>{{ record.Block }}</td>
        <td>{{ record.Aisle }}</td>
        <td>{{ record.Lane }}</td>
        <td>{{ record.Stack }}</td>
        <td>{{ record.use ? record.use.use : '' }}</td>
        <td>{{ record.status }}</td>
      </template>

      <template #default="{ record, editing }">
        <div class="ri_formtable">
          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Code:</div>
            <TextInput v-model="record.code" :enabled="editing" required />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Route:</div>
            <TextInput v-model="record.Route" :enabled="editing" />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Block:</div>
            <TextInput v-model="record.Block" :enabled="editing" />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Aisle:</div>
            <TextInput v-model="record.Aisle" :enabled="editing" />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Lane:</div>
            <TextInput v-model="record.Lane" :enabled="editing" />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Stack:</div>
            <TextInput v-model="record.Stack" :enabled="editing" />
          </div>

          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Use:</div>
            <ComboBox 
              v-model:keyValue="record.use_id"
              v-model:updates="record.use"
              optionsource="/json/uses"
              :enabled="editing"
			  display="use"
            />
          </div>
		  
		  <div class="ri_fieldset">
		     <div class="ri_fieldlabel">Pull Sequence</div>
		     <TextInput v-model="record.PullSequence" type="number" :enabled="editing" />
		   </div>


          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Status:</div>
            <ComboBox 
              v-model="record.status"
              :options="[{ id: 'active', name: 'Active' }, { id: 'archived', name: 'Archived' }]"
              :enabled="editing"
            />
          </div>
        </div>
      </template>
    </RIForm>
  </AuthenticatedLayout>
</template>
