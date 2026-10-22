<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- PrintMenu.vue

	The single "Print" control for every printable document. One button opens a lightbox
	listing what can be done with the document — "Download PDF" and each printer at a site
	that can print this document's paper size (a label printer is never offered for a
	letter-size report: the server filters by `media`) — as selectable options. Choosing a
	printer also shows "Number of copies"; the Print button then renders the document
	server-side and puts it in the print queue (POST /json/print) for a print server to
	pick up. See rstation/PRINT_SERVER.md.

	A printer whose print server is offline or in error is still listed, flagged — a job
	sent to it waits in the queue until the server is back. The printer used last (per
	paper size) is preselected. Users without the print-documents permission just get the
	download: the button downloads straight away, with no dialog.

	Keyboard: Up/Down move through the options, Enter confirms (also from the copies box),
	Escape closes (without closing a dialog the menu itself was opened from).

	Usage:
		<PrintMenu document-key="pallet-label" media="label_large"
			:params="{ id: pallet.id }" download-href="/report/pallet/12" />

	Props:
		documentKey   Print-document key (App\Services\Printing\DocumentRegistry).
		media         Paper size key (config/printing.php) — filters printers.
		params        Parameters for the document (e.g. { id: 12 }).
		downloadHref  URL for "Download PDF"; omit to hide that option.
		prepare       Optional async () => ({ params, downloadHref }) | null, run
		              once the choice is confirmed, for documents that need a server step
		              first (pick sheets lock their orders). Return null to cancel.
		label         Button text (default "Print"); also the dialog's heading.
		variant       'button' (default), 'form' (ri_formbutton), 'link', or 'plain'
		              (no base class — style it yourself via extraClass).
		copies        Copies the box starts at (default 1; 1-99).
		disabled      Disable the button.

	extraClass    Extra CSS class(es) for the button.
	placement     Ignored — kept so existing callers still work. The dialog is a lightbox
	              in the browser's top layer, so it is never clipped by its container.

	Emits `sent` (with the queued job's JSON) after a successful send, and
	`downloaded` after "Download PDF" opens. The success/error message itself
	is shown by PrintToast (mounted once in AuthenticatedLayout) via a window
	event, so it survives this menu being unmounted — e.g. by a modal that
	closes on `sent`.
-->

<script>
import Modal from '@/Components/Modal.vue';
import { onPrintEvent } from '@/realtime';

// Printer lists are small and shared by every menu on a page, so cache them
// briefly per media type rather than refetching on every open.
const CACHE_MS = 30000;
const printerCache = {};

export default {
	components: { Modal },
	props: {
		documentKey: { type: String, required: true },
		media: { type: String, required: true },
		params: { type: Object, default: () => ({}) },
		downloadHref: { type: String, default: null },
		prepare: { type: Function, default: null },
		label: { type: String, default: 'Print' },
		variant: { type: String, default: 'button' },
		copies: { type: Number, default: 1 },
		disabled: { type: Boolean, default: false },
		extraClass: { type: String, default: '' },
		placement: { type: String, default: 'down' }, // unused; see the doc comment
	},
	emits: ['sent', 'downloaded'],
	data() {
		return {
			open: false,
			loading: false,
			loadError: false,
			printers: [],
			busy: false,
			lastUsedId: null,
			choice: null, // 'download' or a printer id
			copiesText: String(this.copies),
		};
	},
	computed: {
		canPrint() {
			return !!this.$page.props.printing?.canPrint;
		},
		buttonClass() {
			return { button: 'ri_defaultbutton', form: 'ri_formbutton', link: 'pm_link', plain: '' }[this.variant] ?? 'ri_defaultbutton';
		},
		hasDownload() {
			return !!(this.downloadHref || this.prepare);
		},
		// Last-used printer first, then the server's site/name order.
		orderedPrinters() {
			return [...this.printers].sort(
				(a, b) => (b.id === this.lastUsedId) - (a.id === this.lastUsedId)
			);
		},
		showSites() {
			return new Set(this.printers.map((p) => p.site_id)).size > 1;
		},
		// Everything the dialog offers, in the order shown (and arrowed through).
		options() {
			return [
				...(this.hasDownload ? [{ key: 'download' }] : []),
				...this.orderedPrinters.map((p) => ({ key: p.id, printer: p })),
			];
		},
		selectedPrinter() {
			return this.printers.find((p) => p.id === this.choice) || null;
		},
		copiesCount() {
			const n = Number(this.copiesText);
			return Number.isInteger(n) && n >= 1 && n <= 99 ? n : null;
		},
		canConfirm() {
			if (this.busy || this.choice === null) return false;
			return this.choice === 'download' || (!!this.selectedPrinter && this.copiesCount !== null);
		},
	},
	watch: {
		open(value) {
			// Capture phase, so Escape closes this dialog and not also one the menu sits inside.
			if (value) window.addEventListener('keydown', this.onWindowKeydown, true);
			else window.removeEventListener('keydown', this.onWindowKeydown, true);
		},
	},
	mounted() {
		// A print server's status changed: drop the cached list (and refresh it if the dialog is open).
		this.offPrinters = onPrintEvent('printers.changed', () => {
			delete printerCache[this.media];
			if (this.open) this.loadPrinters();
		});
		try {
			const saved = localStorage.getItem(this.storageKey());
			this.lastUsedId = saved ? parseInt(saved, 10) : null;
		} catch (e) {
			// storage blocked: just no "last used" ordering
		}
	},
	beforeUnmount() {
		window.removeEventListener('keydown', this.onWindowKeydown, true);
		this.offPrinters?.();
	},
	methods: {
		storageKey() {
			return 'ri_print_last_' + this.media;
		},
		async openDialog() {
			if (this.busy) return;
			// No print permission: the button is simply the download.
			if (!this.canPrint) {
				return this.download();
			}
			this.copiesText = String(this.copies);
			this.choice = null;
			this.open = true;
			await this.loadPrinters();
			this.chooseDefault();
		},
		close() {
			this.open = false;
		},
		// Keep a still-valid choice (a refresh while open); else the last-used printer, else the download, else the first printer.
		chooseDefault() {
			if (this.options.some((o) => o.key === this.choice)) return;
			const last = this.printers.find((p) => p.id === this.lastUsedId);
			this.choice = last ? last.id : this.hasDownload ? 'download' : (this.printers[0]?.id ?? null);
			this.$nextTick(() => this.focusChoice());
		},
		focusChoice() {
			this.$refs.dialogBody?.querySelector('[role="radio"][aria-checked="true"]')?.focus();
		},
		async loadPrinters() {
			const cached = printerCache[this.media];
			if (cached && Date.now() - cached.at < CACHE_MS) {
				this.printers = cached.printers;
				this.loadError = false;
				return;
			}
			this.loading = true;
			this.loadError = false;
			try {
				const { data } = await axios.get('/json/printers', { params: { media: this.media } });
				printerCache[this.media] = { at: Date.now(), printers: data.printers };
				this.printers = data.printers;
			} catch (e) {
				this.loadError = true;
			} finally {
				this.loading = false;
			}
			if (this.open) this.chooseDefault();
		},
		onWindowKeydown(event) {
			if (event.key !== 'Escape') return;
			event.preventDefault();
			event.stopPropagation();
			this.close();
		},
		// Up/Down move the selection; Enter on an option or the copies box confirms.
		onDialogKeydown(event) {
			const onOption = event.target?.getAttribute?.('role') === 'radio';
			if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && onOption) {
				event.preventDefault();
				const keys = this.options.map((o) => o.key);
				const next = keys[(keys.indexOf(this.choice) + (event.key === 'ArrowDown' ? 1 : -1) + keys.length) % keys.length];
				this.choice = next;
				this.$nextTick(() => this.focusChoice());
			} else if (event.key === 'Enter' && (onOption || event.target?.dataset?.pmCopies !== undefined)) {
				event.preventDefault();
				this.confirm();
			}
		},
		confirm() {
			if (!this.canConfirm) return;
			const choice = this.choice;
			const printer = this.selectedPrinter;
			const copies = this.copiesCount;
			this.close();
			if (choice === 'download') this.download();
			else this.send(printer, copies);
		},
		// What to print/download, after any server-side preparation step.
		async resolve() {
			if (this.prepare) {
				return (await this.prepare()) || null;
			}
			return { params: this.params, downloadHref: this.downloadHref };
		},
		async download() {
			// With a prepare step, open the tab now (inside the click) so the
			// popup blocker allows it, then point it at the PDF once ready.
			const tab = this.prepare ? window.open('', '_blank') : null;
			try {
				const resolved = await this.resolve();
				const href = resolved?.downloadHref;
				if (!href) {
					tab?.close();
					return;
				}
				if (tab) {
					tab.location = href;
				} else {
					window.open(href, '_blank');
				}
				this.$emit('downloaded');
			} catch (e) {
				tab?.close();
				this.showNotice('error', this.errorText(e, 'Could not prepare the document.'));
			}
		},
		async send(printer, copies) {
			this.busy = true;
			try {
				const resolved = await this.resolve();
				if (!resolved) return;
				const { data } = await axios.post('/json/print', {
					document: this.documentKey,
					params: resolved.params,
					printer_id: printer.id,
					copies,
				});
				this.lastUsedId = printer.id;
				try {
					localStorage.setItem(this.storageKey(), String(printer.id));
				} catch (e) {
					// ignore
				}
				// A queued job to an unready printer changes what the dialog should show.
				delete printerCache[this.media];
				this.showNotice(data.printer?.ready ? 'ok' : 'warn', data.message);
				this.$emit('sent', data);
			} catch (e) {
				this.showNotice('error', this.errorText(e, 'Could not send to the printer.'));
			} finally {
				this.busy = false;
			}
		},
		errorText(e, fallback) {
			const data = e.response?.data;
			return data?.errors?.printer_id?.[0]
				|| (data?.errors && Object.values(data.errors)[0]?.[0])
				|| data?.message
				|| fallback;
		},
		showNotice(type, text) {
			window.dispatchEvent(new CustomEvent('ri:print-notice', { detail: { type, text } }));
		},
		dotClass(printer) {
			return {
				pm_dot_ok: printer.status === 'idle' || printer.status === 'printing',
				pm_dot_error: printer.status === 'error',
				pm_dot_down: printer.status === 'offline' || printer.status === 'unavailable',
			};
		},
		statusText(printer) {
			if (printer.ready) return printer.status === 'printing' ? 'printing' : '';
			return printer.status === 'unavailable'
				? 'offline — will print when back'
				: (printer.status_message || printer.status) + ' — will queue';
		},
	},
};
</script>

<template>
	<span class="pm_container">
		<button
			type="button"
			:class="[buttonClass, extraClass]"
			:disabled="disabled || busy"
			@click.stop="openDialog"
		>
			{{ busy ? 'Sending…' : label }}
		</button>

		<Modal :show="open" max-width="md" @close="close">
			<div ref="dialogBody" class="pm_dialog" @keydown="onDialogKeydown" @click.stop>
				<h2 class="pm_title">{{ label }}</h2>

				<div v-if="loading" class="pm_hint">Loading printers…</div>
				<div v-else-if="loadError && !hasDownload" class="pm_hint pm_hint_error">Couldn't load printers.</div>
				<div v-else-if="!options.length" class="pm_hint">No printers are set up for this document.</div>

				<div v-if="options.length" role="radiogroup" :aria-label="'What to do with ' + label" class="pm_options">
					<button
						v-for="option in options"
						:key="option.key"
						type="button"
						role="radio"
						:aria-checked="choice === option.key"
						:tabindex="choice === option.key ? 0 : -1"
						class="pm_option"
						:class="{ pm_selected: choice === option.key }"
						:title="option.printer ? option.printer.status_message || option.printer.status : ''"
						@click="choice = option.key"
					>
						<span class="pm_radio" aria-hidden="true"></span>
						<template v-if="!option.printer">
							<span class="pm_name">Download PDF</span>
						</template>
						<template v-else>
							<span class="pm_dot" :class="dotClass(option.printer)"></span>
							<span class="pm_name">
								{{ option.printer.name }}<span v-if="showSites" class="pm_site"> · {{ option.printer.site }}</span>
							</span>
							<span v-if="statusText(option.printer)" class="pm_status">{{ statusText(option.printer) }}</span>
						</template>
					</button>
				</div>
				<div v-if="loadError && hasDownload" class="pm_hint pm_hint_error">Couldn't load printers — you can still download.</div>

				<div v-if="selectedPrinter" class="pm_copies">
					<label for="pm_copies_input">Number of copies</label>
					<input
						id="pm_copies_input"
						v-model="copiesText"
						type="number"
						min="1"
						max="99"
						step="1"
						inputmode="numeric"
						data-pm-copies
						class="pm_copies_input"
						:class="{ pm_invalid: copiesCount === null }"
						@focus="$event.target.select()"
					/>
					<span v-if="copiesCount === null" class="pm_hint_error pm_copies_error">Enter a number from 1 to 99.</span>
				</div>

				<div class="pm_actions">
					<button type="button" class="ri_formbutton" @click="close">Cancel</button>
					<button type="button" class="ri_defaultbutton" :disabled="!canConfirm" @click="confirm">
						{{ choice === 'download' ? 'Download' : 'Print' }}
					</button>
				</div>
			</div>
		</Modal>
	</span>
</template>

<style scoped>
.pm_container {
	display: inline-block;
}
.pm_link {
	background: none;
	border: 0;
	padding: 0;
	color: #2563eb;
	text-decoration: underline;
	cursor: pointer;
	font: inherit;
}
.pm_dialog {
	padding: 20px 24px;
	text-align: left;
}
.pm_title {
	margin: 0 0 12px;
	font-size: 1.15rem;
	font-weight: 600;
	color: #222;
}
.pm_options {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.pm_option {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	padding: 10px 12px;
	border: 1px solid #d1d5db;
	border-radius: 6px;
	background: white;
	color: #333;
	font-size: 0.95rem;
	text-align: left;
	cursor: pointer;
}
.pm_option:hover {
	background-color: #f5f7ff;
}
.pm_option:focus-visible {
	outline: 2px solid #2563eb;
	outline-offset: 1px;
}
.pm_selected {
	border-color: #2563eb;
	background-color: #eef2ff;
}
.pm_radio {
	width: 16px;
	height: 16px;
	flex: none;
	border: 2px solid #9ca3af;
	border-radius: 50%;
	box-sizing: border-box;
}
.pm_selected .pm_radio {
	border-color: #2563eb;
	background: radial-gradient(circle, #2563eb 45%, transparent 50%);
}
.pm_hint {
	padding: 8px 2px;
	font-size: 0.85rem;
	color: #777;
}
.pm_hint_error {
	color: #b91c1c;
}
.pm_copies {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 10px;
	margin-top: 14px;
	font-size: 0.95rem;
}
.pm_copies_input {
	width: 5rem;
	padding: 4px 8px;
	border: 1px solid #d1d5db;
	border-radius: 5px;
}
.pm_invalid {
	border-color: #dc2626;
}
.pm_copies_error {
	font-size: 0.8rem;
}
.pm_actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 18px;
}
.pm_dot {
	width: 9px;
	height: 9px;
	border-radius: 50%;
	flex: none;
	background: #9ca3af;
}
.pm_dot_ok {
	background: #16a34a;
}
.pm_dot_error {
	background: #dc2626;
}
.pm_dot_down {
	background: #f59e0b;
}
.pm_site {
	color: #777;
}
.pm_status {
	margin-left: auto;
	padding-left: 12px;
	font-size: 0.78rem;
	color: #92400e;
}
</style>
