<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->
	
<script setup>
import { onMounted, ref, computed } from 'vue';
import QrScanner from '@/Components/QrScanner.vue';

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
  }
});

const input = ref(null);
const showScanner = ref(false);

const onScan = () => {
  showScanner.value = true;
};

const handleScanned = (code) => {
	console.log(code);
  model.value = code; // Set the scanned code to the input
  showScanner.value = false; // Hide the scanner
};

onMounted(() => {
    if (input && input.value && input.value.hasAttribute('autofocus')) {
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
      />
      <button 
        v-if="allowscan" 
        class="scan-button" 
        @click="onScan">
        📷
      </button>
    </div>
    <span v-else class="ir_disabled_input">{{ model }}</span>
	
	<!-- QR Scanner Full-Screen Overlay -->
	<div v-if="showScanner" class="qr-overlay">
	  <QrScanner @scanned="handleScanned" />
	  <button class="close-scanner" @click="showScanner = false">✖</button>
	</div>
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

.scan-button {
  width: 35px;
  height: 35px;
  border: none;
  background-color: #ddd;
  cursor: pointer;
  border-radius: 5px;
  margin-left: 5px;
}

/* Full-Screen Scanner Overlay */
.qr-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.8);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 9999;
}

.close-scanner {
  position: absolute;
  top: 20px;
  right: 20px;
  background: red;
  color: white;
  border: none;
  padding: 10px;
  font-size: 16px;
  cursor: pointer;
  border-radius: 5px;
}
</style>
