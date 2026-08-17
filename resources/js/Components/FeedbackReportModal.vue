<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- FeedbackReportModal.vue

	"Report an Issue" — opened from the profile menu (AuthenticatedLayout.vue)
	or from the feedback banner (Banner.vue). Captures page context
	automatically (current URL/title, browser) and, with the reporter's
	explicit opt-in via the checkbox, a DOM screenshot of the current page
	(html2canvas — client-side render, no OS-level screen-share prompt).
	Submits to POST /json/feedback-reports; FeedbackReportController emails
	the developer notification list and stores the report for triage on
	/setup/feedback.

	Screenshot timing: captured the moment `show` turns true, BEFORE the
	dialog itself becomes visible (a short delay lets the profile dropdown's
	close animation finish first) — the dialog only opens (dialogVisible)
	once capture is done. Capturing after the dialog is open would photograph
	the dialog's own gray backdrop instead of the page the reporter is
	actually reporting on.
-->

<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import axios from 'axios';

const props = defineProps({
	show: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const page = usePage();

const dialogVisible = ref(false);
const type = ref('bug');
const message = ref('');
const urgent = ref(false);
const includeScreenshot = ref(true);
const screenshotBlob = ref(null);
const capturingScreenshot = ref(false);
const submitting = ref(false);
const error = ref(null);
const submitted = ref(false);
const pageTitle = ref('');

watch(() => props.show, async (show) => {
	if (show) {
		type.value = 'bug';
		message.value = '';
		urgent.value = false;
		includeScreenshot.value = true;
		screenshotBlob.value = null;
		error.value = null;
		submitted.value = false;
		pageTitle.value = document.title;

		capturingScreenshot.value = true;
		screenshotBlob.value = await captureScreenshot();
		capturingScreenshot.value = false;

		dialogVisible.value = true;
	} else {
		dialogVisible.value = false;
	}
});

function close() {
	if (!submitting.value) {
		emit('close');
	}
}

async function submit() {
	if (!message.value.trim()) {
		error.value = 'Please describe the issue or idea.';
		return;
	}

	submitting.value = true;
	error.value = null;

	try {
		const formData = new FormData();
		formData.append('type', type.value);
		formData.append('message', message.value);
		formData.append('urgent', urgent.value ? '1' : '0');
		formData.append('page_url', window.location.href);
		formData.append('page_title', document.title || '');

		if (includeScreenshot.value && screenshotBlob.value) {
			formData.append('screenshot', screenshotBlob.value, 'screenshot.png');
		}

		await axios.post('/json/feedback-reports', formData);
		submitted.value = true;
	} catch (e) {
		error.value = e.response?.data?.message || 'Could not submit your report — please try again.';
	} finally {
		submitting.value = false;
	}
}

// A short delay lets the profile dropdown's own close animation finish so
// it doesn't end up in the captured image, before we snapshot the page
// exactly as the reporter is actually seeing it (dialog not open yet).
function wait(ms) {
	return new Promise((resolve) => setTimeout(resolve, ms));
}

async function captureScreenshot() {
	try {
		await wait(150);
		const { default: html2canvas } = await import('html2canvas');
		const canvas = await html2canvas(document.body, { logging: false, useCORS: true });

		return await new Promise((resolve) => canvas.toBlob((blob) => resolve(blob), 'image/png'));
	} catch (e) {
		// Screenshot is a nice-to-have — never block submission on it.
		console.error('Screenshot capture failed', e);

		return null;
	}
}
</script>

<template>
	<Modal :show="dialogVisible" @close="close" max-width="lg">
		<div class="p-6 space-y-4">
			<h2 class="text-lg font-semibold">Report an Issue</h2>

			<template v-if="submitted">
				<div class="rounded bg-green-100 border border-green-400 text-green-800 px-4 py-3 text-sm">
					Thanks — your report was submitted. We'll email you at
					{{ page.props.auth.user.email }} when there's an update.
				</div>
				<div class="flex justify-end">
					<SecondaryButton @click="close">Close</SecondaryButton>
				</div>
			</template>

			<template v-else>
				<div class="flex gap-4 text-sm">
					<label class="flex items-center gap-2">
						<input type="radio" value="bug" v-model="type" />
						Bug report
					</label>
					<label class="flex items-center gap-2">
						<input type="radio" value="feature" v-model="type" />
						Feature idea
					</label>
				</div>

				<textarea
					v-model="message"
					rows="5"
					class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
					placeholder="What happened, or what would you like to see?"
				></textarea>

				<label class="flex items-center gap-2 text-sm text-gray-700">
					<input type="checkbox" v-model="urgent" />
					Urgent — this is blocking work right now
				</label>

				<label class="flex items-center gap-2 text-sm text-gray-700">
					<input type="checkbox" v-model="includeScreenshot" :disabled="capturingScreenshot || !screenshotBlob" />
					<span v-if="capturingScreenshot">Capturing screenshot…</span>
					<span v-else-if="screenshotBlob">Attach a screenshot of this page</span>
					<span v-else>Screenshot unavailable</span>
				</label>

				<p class="text-xs text-gray-500">
					We automatically include the page you're on ({{ pageTitle }})
					and your browser info to help us track this down.
				</p>

				<div v-if="error" class="rounded bg-red-100 border border-red-400 text-red-800 px-4 py-3 text-sm">
					{{ error }}
				</div>

				<div class="flex justify-end gap-3">
					<SecondaryButton :disabled="submitting" @click="close">Cancel</SecondaryButton>
					<PrimaryButton :disabled="submitting" @click="submit">
						{{ submitting ? 'Submitting…' : 'Submit Report' }}
					</PrimaryButton>
				</div>
			</template>
		</div>
	</Modal>
</template>
