<script setup>
import { onMounted, ref, computed } from 'vue';

const model = defineModel({
    type: [String, Number],
    required: true,
});

const props = defineProps({
  enabled: {
    type: Boolean,
    default: true,
  },
  options: {
    type: Array,
    required: true,
    validator: (value) =>
      value.every(
        (item) => typeof item === 'object' && 'id' in item && 'name' in item
      ),
  },
});

const dropdownOpen = ref(false);

const selectedLabel = computed(() => {
  const selected = props.options.find((option) => option.id === model);
  return selected ? selected.name : '';
});

const toggleDropdown = () => {
  if (props.enabled) {
    dropdownOpen.value = !dropdownOpen.value;
  }
};

const selectOption = (id) => {
  model = id; // Update the model value
  dropdownOpen.value = false; // Close the dropdown
};
</script>

<template>
  <div class="relative">
    <!-- Display selected value -->
    <div
      v-if="props.enabled"
	  class="rounded-md border-gray-300 shadow-sm p-2 bg-white cursor-pointer"
      @click="toggleDropdown"
    >
      <span>{{ selectedLabel }}</span>
      <span class="float-right"> </span>
    </div>

    <!-- Dropdown options -->
    <ul
      v-if="dropdownOpen"
      class="absolute left-0 mt-2 rounded-md border border-gray-300 bg-white shadow-lg z-10 max-h-40 overflow-y-auto"
    >
      <li
        v-for="option in props.options"
        :key="option.id"
        @click="selectOption(option.id)"
        class="p-2 hover:bg-indigo-100 cursor-pointer"
      >
        {{ option.name }}
      </li>
    </ul>

    <!-- Display read-only text if disabled -->
    <span v-else-if="!props.enabled" class="ir_disabled_input">{{ selectedLabel }}</span>
  </div>
</template>
