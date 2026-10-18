<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- PrintToast.vue

	Shows the result of a "send to printer" (see PrintMenu.vue). Mounted once
	in AuthenticatedLayout and driven by a window event, so the message
	outlives whatever menu/modal triggered it. Event: 'ri:print-notice' with
	detail { type: 'ok' | 'warn' | 'error', text }.

	Also, when realtime (Reverb) is on, announces when one of *my* jobs
	finishes: "printed on Office" or "failed: out of labels". This is also the
	one place the page-wide realtime subscription for printing is started.
-->

<script>
import { onPrintEvent, startPrintRealtime } from '@/realtime';

export default {
	data() {
		return { notice: null, timer: null };
	},
	computed: {
		// The queue page is open to anyone who can print (their own jobs).
		canViewQueue() {
			return !!this.$page.props.printing?.canPrint;
		},
	},
	mounted() {
		window.addEventListener('ri:print-notice', this.onNotice);
		const userId = this.$page.props.auth?.user?.id;
		if (this.$page.props.printing?.canPrint && userId) {
			startPrintRealtime(userId);
			this.offJob = onPrintEvent('print-job.changed', this.onJobChanged);
		}
	},
	beforeUnmount() {
		window.removeEventListener('ri:print-notice', this.onNotice);
		this.offJob?.();
		clearTimeout(this.timer);
	},
	methods: {
		// Managers also receive everyone's jobs on the queue channel; only announce mine.
		onJobChanged(job) {
			if (job.requested_by !== this.$page.props.auth?.user?.id) return;
			const where = job.printer_name ? ` on ${job.printer_name}` : '';
			if (job.status === 'printed') {
				this.onNotice({ detail: { type: 'ok', text: `“${job.title}” printed${where}.` } });
			} else if (job.status === 'failed') {
				this.onNotice({ detail: { type: 'error', text: `“${job.title}” failed${where}${job.error_message ? ': ' + job.error_message : '.'}` } });
			}
		},
		onNotice(event) {
			this.notice = event.detail;
			clearTimeout(this.timer);
			this.timer = setTimeout(() => (this.notice = null), 9000);
		},
	},
};
</script>

<template>
	<div v-if="notice" class="pt_toast" :class="'pt_' + notice.type" role="status">
		<span>{{ notice.text }}</span>
		<a v-if="canViewQueue && notice.type !== 'error'" href="/print-queue" class="pt_link">View queue</a>
		<button type="button" class="pt_close" aria-label="Dismiss" @click="notice = null">&times;</button>
	</div>
</template>

<style scoped>
.pt_toast {
	position: fixed;
	right: 16px;
	bottom: 16px;
	z-index: 60;
	display: flex;
	align-items: center;
	gap: 10px;
	max-width: min(380px, calc(100vw - 32px));
	padding: 10px 14px;
	border-radius: 6px;
	font-size: 0.9rem;
	box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
}
.pt_ok {
	background: #dcfce7;
	color: #14532d;
}
.pt_warn {
	background: #fef3c7;
	color: #78350f;
}
.pt_error {
	background: #fee2e2;
	color: #7f1d1d;
}
.pt_link {
	text-decoration: underline;
	white-space: nowrap;
}
.pt_close {
	background: none;
	border: 0;
	font-size: 1.2rem;
	line-height: 1;
	cursor: pointer;
	color: inherit;
}
</style>
