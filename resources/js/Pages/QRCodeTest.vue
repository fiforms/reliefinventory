<script setup>
import { ref } from 'vue';
import QrScanner from '@/Components/QrScanner.vue';

const scannedCode = ref('');

const handleScan = (code) => {
  scannedCode.value = code;
};
</script>

<template>
  <div>
    <h2>Scan Item QR Code</h2>
    <QrScanner @scanned="handleScan" />
    <p>Scanned Code: {{ scannedCode }}</p>
  </div>
</template>