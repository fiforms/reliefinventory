<script setup>
// Camera barcode reader: shows live video, emits `scanned` once with the first decoded code, then stops the camera.
// Used through ScanButton.vue (which types the code into the page's scan field like a keyboard-wedge scanner would);
// don't wire a page-specific handler to this directly. Emits `error` with a readable message if the camera can't start.
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Html5Qrcode, Html5QrcodeSupportedFormats as F } from 'html5-qrcode';

const emit = defineEmits(['scanned', 'error']);

const readerId = 'ri-camera-reader-' + Math.random().toString(36).slice(2, 8);
const starting = ref(true);
const torchAvailable = ref(false);
const torchOn = ref(false);
let scanner = null;
let finished = false;
let unmounted = false;

// Retail barcodes (item UPC/EAN), shipping cartons (ITF-14 / Code 128) and our own QR/Code 128 labels.
const FORMATS = [
  F.UPC_A, F.UPC_E, F.EAN_13, F.EAN_8, F.ITF, F.CODE_128, F.CODE_39, F.QR_CODE,
];

async function stopScanner() {
  const s = scanner;
  scanner = null;
  if (!s) return;
  try { if (s.isScanning) await s.stop(); } catch (e) { /* already stopped */ }
  try { s.clear(); } catch (e) { /* element gone */ }
}

function cameraErrorMessage(e) {
  const text = String(e?.message || e || '');
  if (/permission|denied|NotAllowed/i.test(text)) return 'Camera permission was denied. Allow camera access for this site and try again.';
  if (/NotFound|no camera|requested device/i.test(text)) return 'No camera was found on this device.';
  if (/NotReadable|in use/i.test(text)) return 'The camera is in use by another app.';
  return 'Could not start the camera' + (text ? ': ' + text : '.');
}

async function start() {
  try {
    scanner = new Html5Qrcode(readerId, { formatsToSupport: FORMATS, verbose: false });
    await scanner.start(
      { facingMode: 'environment' },
      {
        fps: 12,
        // A wide box: 1-D barcodes are read along their length.
        qrbox: (w, h) => ({ width: Math.floor(Math.min(w, 600) * 0.9), height: Math.floor(Math.min(h, 400) * 0.55) }),
      },
      (text) => {
        if (finished || !text) return;
        finished = true;
        try { navigator.vibrate?.(60); } catch (e) { /* not supported */ }
        stopScanner().finally(() => emit('scanned', String(text).trim()));
      },
      () => { /* no code in this frame */ },
    );
    if (unmounted) { await stopScanner(); return; }
    try { torchAvailable.value = !!scanner.getRunningTrackCameraCapabilities().torchFeature().isSupported(); }
    catch (e) { torchAvailable.value = false; }
  } catch (e) {
    await stopScanner();
    if (!unmounted) emit('error', cameraErrorMessage(e));
  } finally {
    starting.value = false;
  }
}

async function toggleTorch() {
  try {
    const next = !torchOn.value;
    await scanner.getRunningTrackCameraCapabilities().torchFeature().apply(next);
    torchOn.value = next;
  } catch (e) { torchAvailable.value = false; }
}

onMounted(start);
onBeforeUnmount(() => { unmounted = true; stopScanner(); });
</script>

<template>
  <div class="qrs_wrap">
    <div :id="readerId" class="qrs_reader"></div>
    <p v-if="starting" class="qrs_hint">Starting camera…</p>
    <p v-else class="qrs_hint">Point the camera at the barcode.</p>
    <button v-if="torchAvailable" type="button" class="qrs_torch" @click="toggleTorch">
      {{ torchOn ? 'Light off' : 'Light on' }}
    </button>
  </div>
</template>

<style scoped>
.qrs_wrap { width: 100%; max-width: 640px; margin: 0 auto; text-align: center; }
.qrs_reader { width: 100%; min-height: 240px; background: #000; border-radius: 6px; overflow: hidden; }
.qrs_hint { margin: 0.5rem 0; color: #666; font-size: 0.9rem; }
.qrs_torch { padding: 0.35rem 0.9rem; border: 1px solid #999; border-radius: 4px; background: #fff; }
</style>
