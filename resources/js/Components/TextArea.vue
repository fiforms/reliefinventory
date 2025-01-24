<script setup>

const model = defineModel({
    type: [String, null],
    required: true,
});

const props = defineProps({
  enabled: {
    type: Boolean,
    default: true, // Input is enabled by default
  },
});


</script>

<template>
  <div class="ri_formcontrol">
    <textarea
	    v-if="enabled"
        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 ri_forminput"
        v-model="model"
    ></textarea>
	<span v-else class="ir_disabled_input">{{ model }}</span>
  </div>
</template>
