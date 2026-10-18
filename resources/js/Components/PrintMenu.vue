<!-- This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
     Licensed under the GNU GPL v. 3. See LICENSE.md for details -->

<!-- PrintMenu.vue

	The single "Print ▾" control for every printable document: one menu with
	"Download PDF" plus each printer at a site that can print this document's
	paper size (a label printer is never offered for a letter-size report —
	the server filters by `media`). Choosing a printer renders the document
	server-side and puts it in the print queue (POST /json/print); a print
	server then picks it up. See rstation/PRINT_SERVER.md.

	A printer whose print server is offline or in error is still listed,
	flagged — a job sent to it waits in the queue until the server is back.
	Users without the print-documents permission just get the download.

	Usage:
		<PrintMenu document-key="pallet-label" media="label_large"
			:params="{ id: pallet.id }" download-href="/report/pallet/12" />

	Props:
		documentKey   Print-document key (App\Services\Printing\DocumentRegistry).
		media         Paper size key (config/printing.php) — filters printers.
		params        Parameters for the document (e.g. { id: 12 }).
		downloadHref  URL for "Download PDF"; omit to hide that option.
		prepare       Optional async () => ({ params, downloadHref }) | null, run
		              on any choice, for documents that need a server step first
		              (pick sheets lock their orders). Return null to cancel.
		label         Button text (default "Print").
		variant       'button' (default), 'form' (ri_formbutton), 'link', or 'plain'
		              (no base class — style it yourself via extraClass).
		copies        Copies per job (default 1).
		disabled      Disable the button.

	extraClass    Extra CSS class(es) for the button.
	placement     'down' (default) or 'up' — which way the menu opens; use 'up' inside
	              a container that clips overflow, like a modal's bottom button row.

	Emits `sent` (with the queued job's JSON) after a successful send, and
	`downloaded` after "Download PDF" opens. The success/error message itself
	is shown by PrintToast (mounted once in AuthenticatedLayout) via a window
	event, so it survives this menu being unmounted — e.g. by a modal that
	closes on `sent`.
-->

<script>
// Printer lists are small and shared by every menu on a page, so cache them
// briefly per media type rather than refetching on every open.
const CACHE_MS = 30000;
const printerCache = {};

export default {
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
		placement: { type: String, default: 'down' },
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
		};
	},
	computed: {
		canPrint() {
			return !!this.$page.props.printing?.canPrint;
		},
		buttonClass() {
			return { button: 'ri_defaultbutton', form: 'ri_formbutton', link: 'pm_link', plain: '' }[this.variant] ?? 'ri_defaultbutton';
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
	},
	mounted() {
		document.addEventListener('click', this.onDocumentClick);
		try {
			const saved = localStorage.getItem(this.storageKey());
			this.lastUsedId = saved ? parseInt(saved, 10) : null;
		} catch (e) {
			// storage blocked: just no "last used" ordering
		}
	},
	beforeUnmount() {
		document.removeEventListener('click', this.onDocumentClick);
	},
	methods: {
		storageKey() {
			return 'ri_print_last_' + this.media;
		},
		onDocumentClick(event) {
			if (this.open && !this.$el.contains(event.target)) {
				this.open = false;
			}
		},
		async toggle() {
			if (this.busy) return;
			// No print permission: the button is simply the download.
			if (!this.canPrint) {
				return this.download();
			}
			this.open = !this.open;
			if (this.open) {
				this.loadPrinters();
			}
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
		},
		// What to print/download, after any server-side preparation step.
		async resolve() {
			if (this.prepare) {
				return (await this.prepare()) || null;
			}
			return { params: this.params, downloadHref: this.downloadHref };
		},
		async download() {
			this.open = false;
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
		async send(printer) {
			this.open = false;
			this.busy = true;
			try {
				const resolved = await this.resolve();
				if (!resolved) return;
				const { data } = await axios.post('/json/print', {
					document: this.documentKey,
					params: resolved.params,
					printer_id: printer.id,
					copies: this.copies,
				});
				this.lastUsedId = printer.id;
				try {
					localStorage.setItem(this.storageKey(), String(printer.id));
				} catch (e) {
					// ignore
				}
				// A queued job to an unready printer changes what the menu should show.
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
			@click.stop="toggle"
		>
			{{ busy ? 'Sending…' : label }}<template v-if="canPrint"> &#9662;</template>
		</button>

		<div v-if="open" class="pm_menu" :class="{ pm_menu_up: placement === 'up' }" @click.stop>
			<a v-if="downloadHref || prepare" href="#" class="pm_item" @click.prevent="download">
				Download PDF
			</a>

			<div v-if="loading" class="pm_hint">Loading printers…</div>
			<div v-else-if="loadError" class="pm_hint pm_hint_error">Couldn't load printers.</div>
			<div v-else-if="!printers.length" class="pm_hint">
				No printers are set up for this document.
			</div>
			<template v-else>
				<div class="pm_heading">Send to printer</div>
				<a
					v-for="printer in orderedPrinters"
					:key="printer.id"
					href="#"
					class="pm_item"
					:title="printer.status_message || printer.status"
					@click.prevent="send(printer)"
				>
					<span class="pm_dot" :class="dotClass(printer)"></span>
					<span class="pm_name">
						{{ printer.name }}<span v-if="showSites" class="pm_site"> · {{ printer.site }}</span>
					</span>
					<span v-if="statusText(printer)" class="pm_status">{{ statusText(printer) }}</span>
				</a>
			</template>
		</div>

	</span>
</template>

<style scoped>
.pm_container {
	position: relative;
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
.pm_menu {
	position: absolute;
	top: calc(100% + 4px);
	right: 0;
	background: white;
	border: 1px solid #ddd;
	border-radius: 5px;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
	min-width: 220px;
	z-index: 20;
	overflow: hidden;
	text-align: left;
}
.pm_menu_up {
	top: auto;
	bottom: calc(100% + 4px);
}
.pm_item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 14px;
	color: #333;
	text-decoration: none;
	font-size: 0.95rem;
	white-space: nowrap;
}
.pm_item:hover {
	background-color: #eef2ff;
}
.pm_heading {
	padding: 6px 14px 2px;
	border-top: 1px solid #eee;
	font-size: 0.75rem;
	text-transform: uppercase;
	letter-spacing: 0.03em;
	color: #777;
}
.pm_hint {
	padding: 8px 14px;
	font-size: 0.85rem;
	color: #777;
	border-top: 1px solid #eee;
}
.pm_hint_error {
	color: #b91c1c;
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
