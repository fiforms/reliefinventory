<script setup>
// The one camera-scan button for every scan-driven screen. Tapping it opens a full-screen camera view that closes by
// itself on the first good read; the code is then typed into the page's scan field exactly as a keyboard-wedge scanner
// would (scanInject.js), so the page's normal scan logic, sounds and focus rules all apply with no camera code of its own.
//
//   <ScanButton :target="() => scanInput" />
//
// `target`: optional function returning the element the scan should go to (an <input>/<textarea>, or a component's
// exposed input). When it returns nothing usable, the last text field that had focus is used.
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import QrScanner from '@/Components/QrScanner.vue';
import { injectScan, isTextField } from '@/scanInject';

const props = defineProps({
  target: { type: Function, default: null },
  label: { type: String, default: 'Scan' },
  // Icon only, squared off on the left so it sits flush against the text box to its left.
  compact: { type: Boolean, default: false },
});

const open = ref(false);
const error = ref(null);
const secure = typeof window !== 'undefined' && window.isSecureContext;
const hasCamera = typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia;

// Remember the last text field they were in; clicking the button moves focus onto the button itself.
let lastField = null;
function trackFocus(e) { if (isTextField(e.target)) lastField = e.target; }
onMounted(() => document.addEventListener('focusin', trackFocus));
onBeforeUnmount(() => document.removeEventListener('focusin', trackFocus));

function begin() {
  error.value = null;
  if (!hasCamera || !secure) {
    error.value = !secure
      ? 'The camera only works over a secure (https) connection.'
      : 'This browser has no camera access.';
    return;
  }
  open.value = true;
}

async function onScanned(code) {
  open.value = false;
  await nextTick();
  let el = null;
  try { el = props.target ? props.target() : null; } catch (e) { el = null; }
  el = el?.$el?.querySelector?.('input') ?? el; // a component instance → its input
  if (!isTextField(el)) el = lastField && document.contains(lastField) ? lastField : null;
  if (!injectScan(code, el)) error.value = 'Scanned ' + code + ', but there is no scan field to put it in.';
}

function onCameraError(message) {
  open.value = false;
  error.value = message;
}
</script>

<template>
  <span class="scanbtn">
    <button type="button" class="ri_formbutton" :class="{ scanbtn_compact: compact }" :title="compact ? 'Scan with camera' : null" :aria-label="compact ? 'Scan with camera' : null" @click="begin">📷<template v-if="!compact"> {{ label }}</template></button>
    <span v-if="error" class="scanbtn_error" role="alert">{{ error }}</span>
    <Teleport to="body">
      <div v-if="open" class="scanbtn_overlay" @click.self="open = false">
        <div class="scanbtn_panel">
          <QrScanner @scanned="onScanned" @error="onCameraError" />
          <button type="button" class="ri_formbutton scanbtn_cancel" @click="open = false">Cancel</button>
        </div>
      </div>
    </Teleport>
  </span>
</template>

<style scoped>
.scanbtn { display: inline-block; }
.scanbtn_compact { height: 100%; margin-left: -1px; border-top-left-radius: 0; border-bottom-left-radius: 0; }
.scanbtn_error { margin-left: 0.5rem; color: #b00020; font-size: 0.85rem; }
.scanbtn_overlay {
  position: fixed; inset: 0; z-index: 10000; background: rgba(0, 0, 0, 0.85);
  display: flex; align-items: center; justify-content: center; padding: 1rem;
}
.scanbtn_panel { width: 100%; max-width: 680px; background: #fff; border-radius: 8px; padding: 1rem; text-align: center; }
.scanbtn_cancel { margin-top: 0.75rem; }
</style>
