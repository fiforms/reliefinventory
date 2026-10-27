# 0005 — Print pallet labels on whatever printer you have

- **Status:** Under discussion
- **Lane:** Shared (UX in `PrintMenu` and printing). The operational side, labelling pallets when supplies are short,
  is Mark's. No schema change is expected.
- **Author:** Mark
- **Idea:** none in ROADMAP.md. It is feature request #101 (in-app feedback), raised 2026-10-26.

## Problem

Pallet labels print on the label size chosen on `/setup/printers` (`LabelSetting::palletMedia()`: the large 4 x 6.5 in
label by default, or the small 2.312 x 4 in one). The Print dialog only offers printers loaded with that size. If there
isn't one, it says "No printers are set up for this document" and offers **Download PDF**, which is a label-size PDF a
letter printer can't print well.

Disasters aren't known for giving you everything you need. A site with only an office printer, or only a small-label
printer, is stalled on labelling pallets until the right stock arrives. Any label is better than no label.

## Proposed direction

### Only when the right printer is missing

If at least one printer is loaded with the configured pallet-label size, the dialog stays exactly as it is today.

If none is, the dialog itself shows a plain notice above the options. It is not a popup or a second dialog:

> No pallet label printers are set up. You can print on what you have:

Below the notice, the printers that *are* set up are offered, each with a layout made for its paper. Only paper some
printer actually has is shown:

| Paper available | Choice shown | Layout | Meant as |
|---|---|---|---|
| Letter (report printer) | **Letter paper**: tape it to the pallet | One big label per page: a huge pallet number, a large barcode and the details. A batch prints one page per pallet. | Lasting |
| Small label | **Small label, temporary** | The existing compact one-label layout, already used when the setting is "small". | Temporary: reprint full size when large labels come in |
| Small label | **Small labels, set of 3** | Three labels per pallet, each marked "1 of 3", "2 of 3", "3 of 3": (1) barcode, (2) big pallet number and kind, (3) contents, location and date. Every label also carries the full pallet tag, so a stray label still identifies its pallet. | Lasting: no reprint needed later |
| Large label (when the setting is small) | **Large label** | Today's normal large layout | Lasting |

A small-label printer therefore appears twice, once for each small-label choice. The descriptions make the difference
clear: one is a stopgap, the other is meant to stay.

### No printers at all

> No printers are set up in the app. Set up a printer, or download the PDF and print it yourself.

"Set up a printer" links to `/setup/printers` for people who can manage printers. Two downloads are offered: **Download
PDF** (label size, as today) and **Download PDF (letter paper)**, the version most people can print at home or in an
office.

### What stays the same

- The server still refuses a job whose paper doesn't match the printer. The chosen layout decides which paper that is,
  so a letter layout can only go to a letter printer, and so on.
- The labels' content is unchanged; only its arrangement on the paper changes.
- The last-used printer is still preselected when the right printer exists. In fallback mode, the first fallback choice
  is selected.

### Scope

The four pallet-label documents: a single pallet label, a donation's labels, pre-printed labels, and training pallet
labels. Training table-spot labels and inventory labels use different layouts and are left out. They could follow the
same pattern later.

## Rejected alternatives

- **Always offer every printer for every document.** A label printer would show up for a letter-size report, which is
  the clutter the media filter exists to prevent. Fallbacks appear only when the right printer is missing.
- **Small single label only.** Mark's point: a small single label is a stopgap he'd want to replace with a full-size one
  later. A set of 3 small labels can stay on the pallet for good. Both are offered.
- **Two letter labels per page.** This saves paper, but the labels are smaller and have to be cut apart. One per page is
  readable across the floor and quick to tape on.
- **A popup asking what to do.** The choice belongs in the printer picker the person is already looking at.

## Open questions

- **For Daniel:** is any of this already built, or a general way to print a document on a different paper size than
  its default? If so, this should use it.
- **Shape:**
  - *Layout parameter.* Is an optional `layout` parameter on a document (the registry declares a document's layouts and
    their paper, `POST /json/print` and the download routes accept it) the right shape?
  - *Fallback list.* Should the dialog learn the fallback layouts from a shared page prop, or should `/json/printers`
    return them?
- **Scan test:** the set-of-3 barcode label needs a scan test on a real WS408, like the compact label had.
- **Later:** should inventory labels and training spot labels get the same fallbacks?

## What it touches

- `PrintMenu.vue`: the fallback notice and choices, a second printer lookup when the first finds none, and passing the
  chosen layout.
- `DocumentRegistry` and `PrintController::store`: the paper check uses the chosen layout.
- `PalletReportController`: the builders and download routes take an optional layout.
- `resources/views/reports/partials/pallet_label*`: a letter size, and a new set-of-3 view.
- `HandleInertiaRequests`: the `printing` shared prop.
- The Receiving help guide (Vue page and PDF): a sentence on printing labels without a label printer.
