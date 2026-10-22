<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
	
<script setup>
import { onMounted, ref, computed } from 'vue';
import ScanButton from '@/Components/ScanButton.vue';

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
  placeholder: {
	type: String,
  },
  allowscan: {
	type: Boolean,
	default: false,
  },
  autocomplete: {
	type: String,
	default: undefined,
  },
  // Declared as a prop because an undeclared `autofocus` attribute falls through to the
  // wrapper div, not the <input>, so the old hasAttribute check on the input never matched.
  autofocus: {
	type: Boolean,
	default: false,
  },
});

const input = ref(null);
onMounted(() => {
    if (props.autofocus && input.value) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
  <div class="ri_formcontrol" :class="{ 'with-button': allowscan }">
    <div class="input-wrapper" v-if="enabled">
      <input
        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 ri_forminput"
        v-model="model"
        ref="input"
        :type="type"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
      />
      <ScanButton v-if="allowscan" :target="() => input" />
    </div>
    <span v-else class="ir_disabled_input">{{ model }}</span>
  </div>
</template>

<style scoped>
.ri_formcontrol {
  width: 100%;
}

.input-wrapper {
  display: flex;
  align-items: center;
  width: 100%;
  position: relative;
}

.ri_forminput {
  flex-grow: 1;
  width: calc(100% - 40px); /* Adjusts width when the button is present */
}

</style>
