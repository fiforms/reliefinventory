# Scanning interface notes

Lessons from making the Donation Sorting page (`resources/js/Pages/DonationSorting.vue`) work almost entirely from a
barcode scanner (Oct 2026, PRs #10 and #11). Read this before building or changing any scan-driven screen — Order Filling
(pickers), Receiving, Inventory Movement — so the next one starts where this one ended.

**The goal:** an operator can work a whole pallet with the scanner in one hand, rarely touching the keyboard or mouse,
often not looking at the screen. The page must be foolproof: a stray scan, a click in the wrong place or a slow network
must never lose work or corrupt a field. Sound tells them what happened; focus is always where the next scan should go.

## How the scanner behaves (the root of most bugs)

- It is a **keyboard wedge**: it types the digits and then Enter, as if a very fast typist did. In practice ~4–5 ms between
  characters (measure yours; the logging trick below shows it). There is no "scan" event — only keystrokes in whatever has focus.
- So **a scan goes wherever focus is**, including fields meant for something else (Qty, comments, a modal). Every field
  that can hold focus during scanning needs a deliberate answer to "what if a barcode lands here?".
- A person typing is >100 ms between keys; a scan is <10 ms. We treat digits <80 ms apart as one burst (`onQtyKeydown`).
  A *pasted* barcode has no keystrokes and can't be told from typing — fall back gracefully.
- Barcodes are 8, 12, 13 or 14 digits (EAN-8, UPC-A, EAN-13, GTIN-14). A real quantity is never 8+ digits, so "8+ digits
  in a quantity box" means a scan (`/^\d{8,}$/`).

## Audio feedback (`resources/js/scanSounds.js`)

The operator may not be looking. One distinct sound per outcome, all synthesized with the Web Audio API (no files to ship,
works offline, can't 404):

| Outcome | Sound |
|---|---|
| Matched a single item | one short high beep |
| Matched a carton/case | two quick rising beeps |
| Not in the catalog (a form is opening) | one lower, longer tone — repeated if they scan again while the form is still open |
| Misscan / failed lookup | two low square tones |

- **Play the sound first, then do the slow thing.** An unknown scan sounds the instant our own database says "miss", and
  the form opens immediately; the online lookup (hundreds of ms to seconds) fills in later. Waiting on the network made the
  sound hesitate and felt broken.
- Browsers only allow audio after a user gesture; a scan's keystrokes count. Create the `AudioContext` lazily and `resume()` it.
- Always swallow audio errors — sound is a nicety and must never break a scan.
- Provide a **mute toggle** (speaker emoji in the top bar, remembered per browser in `localStorage`); warehouses differ.
- Reuse the same vocabulary on every scan screen so the sounds mean the same thing everywhere.

## Focus: keep the scan target ready, and never steal typing

- **Refocus after any click** (`refocusScanTarget`): clicking a disposition button or blank space shouldn't leave the
  operator hunting for the box. Rules: never take focus from a text field they're typing in (Qty, comments, pallet tag,
  donor search); never while a modal is open; if an item is already chosen and waiting on a quantity, focus **Qty** (a scan
  there means "save this, next item"), otherwise the item box.
- **Park focus in a modal that opens from a scan.** The modal's own box gets `tabindex="-1"` and focus, and swallows digits +
  Enter typed at it (`onModalStrayKey`), replaying the "not found" tone. Without this the next scan appended to the old
  barcode in the box behind the modal and the form blanked itself. Tab and Escape still work.
- A dialog that *wants* a scan (the carton dialog: "scan a single item inside") focuses its own scan box instead.
- After async work (lookup returns, item created), move focus deliberately — and re-select the field's text (below).

## Fields that can accidentally become scan targets (Qty is the classic)

- **Use `type="text" inputmode="numeric"`, not `type="number"`.** Vue's `v-model` on a number input rewrites `"012…"` to `12…`
  in the DOM, dropping a UPC's leading zero; the lookup then fails "sometimes" (only codes starting with 0). Found by logging
  keystrokes — see below.
- **Select the field's contents on focus**, so a scan *replaces* the number instead of appending to it. And **re-select
  after any programmatic value write** (`prefillQty`): setting the value clears the selection, which made the next scan
  produce `30` + barcode.
- **Remember the value from before the scan.** The scan overwrites the box, so capture the field's value when a keystroke burst
  starts (`qtyBurst.startValue`) — that is the running quantity. A different barcode saves the current item with that
  quantity; the same barcode (compare 14-digit-normalised codes) counts up instead (+1, or + a carton's count).
- **Default sensibly:** picking an item prefills Qty with 1 (selected), so Enter saves one, `3` + Enter saves three, and
  scanning the next item saves and moves on. Scan, scan, scan = one of each.
- Read the **raw DOM value** when it matters (`$refs.qtyInput.value`), not the v-model, which may have been coerced.

## Slow things must not block the scan loop

- Split resolution in two: a fast local answer (`GET /json/upc/resolve?online=0`) and an optional slow suggestion
  (`GET /json/upc/suggest`). React to the first at once; merge the second in when it arrives, but only if the form is
  still open for the same code, and never overwrite what the operator has started typing.
- Per-line autosave (existing pattern): each line POSTs as entered. Retry on reconnect. Don't batch to a final Save.
- Rate-limit anything that calls an external service (config/upc.php) and degrade to "enter it by hand" — never block.

## Search boxes beside a scanner (`SearchSelect.vue`)

- `smartOpen`: only show the list for 2–7 digits or 2+ characters containing a letter. A scan (8+ digits) and an empty box
  show nothing; **Enter must never pick a row the operator can't see.**
- `debounce` (~150 ms): a scan's burst otherwise re-filters thousands of items per digit and flashes the list. Enter
  flushes the pending text first so it never acts on a stale list.
- `openOnFocus=false` on scan boxes. The dropdown is `position: fixed`, placed from the input's rect and flipped upward
  when short of room, so a scrolling modal can't clip it.
- `scanDigits` emits `scan` for barcode-shaped Enter; an exact match on a loaded option emits `scanmatch` (a local hit that
  never reaches the server — it still needs the sound and any case-quantity prefill).

## Barcode data (details in CLAUDE.md "Barcode scan → item")

- Normalise every code to 14 digits (`Gtin::normalize`) so UPC-A / EAN-13 / GTIN-14 spellings are one key; check the check digit
  before trusting a code (a bad one is a misscan, not "unknown").
- A single item's UPC lives on its master-list row (`items.upc`). A **carton** (GTIN-14, indicator 1–8) maps to the item inside
  it plus units per carton (`carton_codes`), so carton and single scans share one row and can be combined.
- GS1's "carton = same item number under a different indicator digit" is only a guess: it held for 10845561000871 → 845561000874
  and failed for 10842515008167 vs 842515006715 (same manufacturer prefix only). Offer same-manufacturer candidates and let a
  person confirm; never auto-map. **Nothing from an online service is stored until a person confirms it.**

## Never lose work

- Anything that leaves the screen (Complete, All Sessions, menu, Back) must first save the item being entered, wait for
  in-flight saves, and only then proceed (`flushPendingWork`); only failed saves justify a "leave anyway?" prompt.
- Navigation guards: Inertia `router.on('before')` for links/menu (return `false` to cancel), `beforeunload` for
  refresh/close, and the Navigation API `navigate` event for browser Back (Chromium only — Firefox/Safari won't prompt;
  Inertia's own popstate handler can't be vetoed). An in-page view switch ("All Sessions") bypasses all of these — handle it yourself.
- Destructive/irreversible batch actions (Combine duplicate lines) take a two-step inline confirm, not `window.confirm`.

## Debugging scan problems

Don't guess — log every keystroke and focus change with timestamps (temporary, then remove):

```js
window.addEventListener('keydown', e => console.log('key', e.key, 'in', e.target.tagName, 'value=', e.target.value), true);
window.addEventListener('focusin', e => console.log('FOCUS ->', e.target.tagName, e.target.placeholder), true);
```

That showed focus never moved mid-scan (our first theory) and that the Qty value lost its leading `0` after the second digit
(the real bug). Scanning into Notepad being perfect while the page fails means the page is mangling the input, not the scanner.

## Checklist for a new scan-driven screen (e.g. picking)

1. Decide the **resting scan target** and make every click/async path return focus there (or to the quantity box when an item is pending).
2. Every other focusable field: what happens if a barcode is typed into it? Text-type, select-on-focus, detect 8+ digits.
3. One sound per outcome from `scanSounds.js`; play it before slow work; mute toggle available.
4. Local answer first, network suggestion later; never block on an external service.
5. Modals opened by a scan park focus and swallow stray scans; modals that want a scan focus the scan box.
6. Save the pending item before any exit; guard navigation; keep per-line autosave.
7. Test with a real scanner and a barcode starting with 0, a carton, an unknown code, the same item ten times, and a scan while a modal is open.

## Where the code is

`resources/js/Pages/DonationSorting.vue` (flow), `resources/js/scanSounds.js`, `resources/js/Components/SearchSelect.vue`
(`smartOpen`/`debounce`/`scanDigits`/`scanmatch`), `app/Helpers/Gtin.php`, `app/Services/Upc/*`, `app/Http/Controllers/UpcController.php`,
Setup > Carton Barcodes (`resources/js/Pages/CartonCodes.vue`). Tests: `tests/Feature/UpcLookupTest.php`, `SortingConsolidateTest.php`.
Open follow-ups are in TODO.md item 15.
