<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import RIForm from '@/Components/RIForm.vue';
</script>

<template>
  <Head title="Item Category Entry" />
  <AuthenticatedLayout>
    <template #header>
    </template>
    
    <RIForm 
      title="Item Category Management" 
      datasource="/json/categories">
      <template #thead>
        <th>Category Name</th>
      </template>
      <template #tbody="{ record, index }"> 
        <td>{{ record.name }}</td>
      </template>
      <template #default="{ record, editing, templates }">
        <div class="ri_formtable">
          <div class="ri_fieldset">
            <div class="ri_fieldlabel">Category Name:</div>
            <TextInput
              v-model="record.name"
              required
              autofocus
              :enabled="editing"
            /> 
          </div>
        </div>
      </template>
    </RIForm>
  </AuthenticatedLayout>
</template>
