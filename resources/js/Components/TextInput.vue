<script setup>
import { onMounted, ref, computed } from 'vue';

const model = defineModel({
    type: [String, Number, null],
    required: true,
});

const props = defineProps({
  enabled: {
    type: Boolean,
    default: true, // Input is enabled by default
  },
  type: {
	type: String,
  },
});

const input = ref(null);

onMounted(() => {
    if (input && input.value && input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
	    v-if="enabled"
        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        v-model="model"
        ref="input"
		:type="type"
    />
	<span v-else class="ir_disabled_input">{{ model }}</span>

</template>
