<script setup>
import { onMounted, ref, defineEmits } from 'vue';
import { Html5QrcodeScanner } from 'html5-qrcode';

const emit = defineEmits(['scanned']);
const scannerRef = ref(null);

const startScanner = () => {
  const scanner = new Html5QrcodeScanner('reader', {
    fps: 10,
    // qrbox: { width: 500, height: 500 }
  });

  scanner.render((decodedText) => {
	scanner.clear();
	    emit('scanned', decodedText);
  });
};

onMounted(() => {
  startScanner();
});
</script>

<template>
  <div style="width: 90vw; height: 80vh;">
    <div id="reader" ref="scannerRef"></div>
  </div>
</template>
