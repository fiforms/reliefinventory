# Frontend framework notes

## RIForm (`resources/js/Components/RIForm.vue`)
Generic list/detail form bound to a `/json/...` datasource. **Read its top doc comment before touching it** — it documents the slot contracts. `RISubform.vue` is the nested-collection equivalent (e.g. order lines).
- Fetches `{records, templates}`; list via `#thead`/`#tbody`, edit via `#default` (`{record, editing, templates}`). Save/cancel/delete use REST verbs inferred from `record.id`. Errors show inline (`saveError`); delete is a two-step inline confirm (no `window.confirm`). Buttons sit in `.ri_formactions` (`app.css`).
- Optional `filter` prop (`(record) => boolean`) + `#listactions` slot for filter UI (`Receiving.vue` is the reference). `#titleactions` renders in the title bar beside New Record, in a `.ri_titleactions` span. `#actions` (scoped: `editing, record, confirmingDelete, save, cancel, delete, keepRecord`) replaces the default button bar; `save(keepOpen)` with `true` stays on the saved record. Emits `select`, `new` (template default, or the server's record if `precreate`) and `saved`.
- No pagination: bound growing lists server-side (pallet history newest 300, pallets live-only unless kind/status or `?all=1`, offers open + 150 closed; `ListPayloadLimitsTest`). Do the same for any new history list. `selectRecord` selects by record object, not index.

## Per-line-autosave screens
`DonationSorting.vue` + `SortingSessionController`, `OrderEntry.vue` + `OrderController`, `OrderFilling.vue` + `OrderFillingController` create the session/order header the moment work starts and POST each line individually, so long sessions survive a crash. Follow this (not RIForm's save-at-end) for any long, line-heavy workflow. There is no session table: the `Transaction` row is the session, tracked by its status. `OrderEntry.vue` splits customer selection into its own screen to keep the line screen uncluttered.

## Scanner screens
Read `dev/notes/SCANNING_INTERFACE_NOTES.md` first (wedge-scanner behaviour, audio vocabulary in `scanSounds.js`, focus, Qty fields, `SearchSelect` options, never losing a pending line, new-screen checklist); `DonationSorting.vue` is the reference. Camera scanning is one `ScanButton.vue` per screen: it shows `QrScanner.vue` (`html5-qrcode`), closes on first read and types the code into the scan field like a wedge scanner (`scanInject.js`). Needs HTTPS.

## Help guides (`resources/js/Pages/Help/*.vue`, `Components/HelpPage.vue`)
Served by one `/help/{guide}` route; `/help` (`Help/Index.vue`, main-menu Help tile) lists every guide with Open/Print and **Print all guides** (`/report/help/all`, document `help-all`, `HelpReportController::allBuilder`). Adding a guide:
1. Add its slug to `HelpReportController::GUIDES` (the report route, `help-<slug>` print entry and button follow).
2. Put a `<HelpLink guide="<slug>">` on the page it describes (RIForm pages: `#listactions`; others beside the title). A test fails if a guide is unlinked.
3. Write `resources/views/reports/help/<slug>.blade.php` extending `reports.help.layout`.
4. Wrap the Vue page in `<HelpPage guide="<slug>">`.
The PDF is a hand-kept copy of the Vue page: edit both.
