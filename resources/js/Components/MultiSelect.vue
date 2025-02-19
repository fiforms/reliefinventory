<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
	 
<!-- MultiSelect.vue -->

<template>
  <div v-if="enabled" class="ri_formcontrol">
    <label v-for="option in options" :key="option.id" class="ri_checkbox">
      <input
        type="checkbox"
        :checked="isSelected(option.id)"
        @change="toggleSelection(option.id)"
      />
      {{ option[display] }}
    </label>
  </div>
  <div v-else class="ri_formcontrol">
	<template v-for="option in options">
	  <p v-if="isSelected(option.id)"> 
		{{ option[display] }}
	  </p>
	</template>
  </div>
</template>

<script setup>
import { ref, onMounted, defineEmits } from "vue";
import axios from "axios";

const props = defineProps({
  records: Array, // Existing selected roles (people_roles)
  template: Object, // Template structure
  optionsource: String, // API URL for fetching roles
  enabled: Boolean, // Whether editing is allowed
  fk_field: String, // Field name of foreign key
  display: String, // Name of field to display
});

const emit = defineEmits(["update:records"]);
const options = ref([]); // Stores available options

// Fetch available roles from the API
onMounted(() => {
  axios
    .get(props.optionsource)
    .then((response) => {
      options.value = response.data.records || [];
    })
    .catch((error) => {
      console.error("Error fetching options:", error);
    });
});

// Check if option is already selected
const isSelected = (optionId) => {
  return props.records?.some((option) => option[props.fk_field] === optionId);
};

// Toggle role selection (add or remove from records)
const toggleSelection = (optionId) => {
  let updatedRecords = [...props.records];

  const index = updatedRecords.findIndex((option) => option[props.fk_field] === optionId);

  if (index === -1) {
    updatedRecords.push({ [props.fk_field]: optionId });
  } else {
    updatedRecords.splice(index, 1);
  }

  emit("update:records", updatedRecords);
};
</script>

<style scoped>
.ri_checkbox {
  display: flex;
  align-items: center;
  margin-bottom: 5px;
}

.ri_checkbox input {
  margin-right: 8px;
}
</style>
