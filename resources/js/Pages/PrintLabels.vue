<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
	 

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import ComboBox from '@/Components/ComboBox.vue';

</script>
<template>
  <Head title="Master Item Catalog" />
  <AuthenticatedLayout>
	<template #header>
	</template>
	<div class="ri_fieldset">
			    <div class="ri_fieldlabel">Select Pallet Label</div>
	    <ComboBox v-model:keyValue="pallet_id"
				  v-model:updates="pallet_id"
			  	  optionsource="/json/pallets"
			  	  :enabled="editing" />
	</div>			
  </AuthenticatedLayout>
</template>