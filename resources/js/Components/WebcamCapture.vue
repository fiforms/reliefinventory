<!-- This file is part of the Relief Inventory Project (https://reliefinventory.app)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- WebcamCapture.vue
     Live in-app webcam preview with a Capture button, for desktops where
     <input capture> just opens a file picker. Emits `captured` with a JPEG File
     (same shape an <input type=file> would give), and `close`. getUserMedia needs
     HTTPS or localhost; the parent should check `webcamSupported()` first. -->

<script>
// True when this browser can open a camera at all.
export function webcamSupported() {
	return typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia && window.isSecureContext;
}
</script>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

const emit = defineEmits(['captured', 'close']);

const video = ref(null);
const error = ref(null);
const ready = ref(false);
let stream = null;

onMounted(async () => {
	try {
		stream = await navigator.mediaDevices.getUserMedia({
			video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } },
			audio: false,
		});
		video.value.srcObject = stream;
		await video.value.play();
		ready.value = true;
	} catch (e) {
		error.value =
			e?.name === 'NotAllowedError'
				? 'Camera access was blocked. Allow it in the browser address bar, or use Choose File.'
				: e?.name === 'NotFoundError'
					? 'No camera found. Use Choose File instead.'
					: 'Could not start the camera. Use Choose File instead.';
	}
});

function stop() {
	stream?.getTracks().forEach((t) => t.stop());
	stream = null;
}

onBeforeUnmount(stop);

function capture() {
	const v = video.value;
	if (!v?.videoWidth) return;
	const canvas = document.createElement('canvas');
	canvas.width = v.videoWidth;
	canvas.height = v.videoHeight;
	canvas.getContext('2d').drawImage(v, 0, 0);
	canvas.toBlob(
		(blob) => {
			if (!blob) {
				error.value = 'Could not capture the photo.';
				return;
			}
			stop();
			emit('captured', new File([blob], 'shipment-' + Date.now() + '.jpg', { type: 'image/jpeg' }));
		},
		'image/jpeg',
		0.9,
	);
}

function close() {
	stop();
	emit('close');
}
</script>

<template>
	<div class="wc_box">
		<video v-show="!error" ref="video" class="wc_video" playsinline muted></video>
		<p v-if="error" class="ri_error">{{ error }}</p>
		<div class="wc_actions">
			<button v-if="!error" type="button" class="ri_defaultbutton" :disabled="!ready" @click="capture">
				Capture
			</button>
			<button type="button" class="ri_formbutton" @click="close">Cancel</button>
		</div>
	</div>
</template>

<style scoped>
.wc_box {
	margin: 0.5em 0;
	max-width: 40em;
}
.wc_video {
	width: 100%;
	background: #000;
	border-radius: 4px;
}
.wc_actions {
	display: flex;
	gap: 0.5em;
	margin-top: 0.5em;
}
</style>
