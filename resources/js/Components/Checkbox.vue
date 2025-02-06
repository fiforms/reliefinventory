<script setup>
defineProps({
    modelValue: Boolean,
	enabled: Boolean,
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <input
	    v-if="enabled"
        type="checkbox"
        :checked="modelValue"
        @change="emit('update:modelValue', $event.target.checked)"
        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
    />
	<span v-else class="ir_disabled_input"> <span v-if="modelValue"> &#x2713; </span> </span>
</template>
