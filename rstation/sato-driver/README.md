# SATO label printers (SBPL) on the R Station

SATO's published Linux driver is x86-only: its PPDs work anywhere, but its one binary (`rastertosbpl`) doesn't run on
the Pi. This directory replaces that binary and bundles a PPD, as the `rstation-sato-driver` package.

- `pdftosbpl` — a CUPS filter (Python + Ghostscript). PDF in, one SBPL bitmap job out per page. Read its docstring
  for the wire format; it was captured from SATO's own filter, then checked on a real printer, not taken from the
  SBPL manual (SATO gives that out on request only).
- `sato-sbpl.ppd` — "SATO label printer - SBPL": page sizes (4 x 6.5, 4 x 6, 4 x 4, 2.312 x 4, 3.5 x 1.125 in and custom),
  resolution (203/305/609 dpi) and a horizontal position shift. Shows up in Settings > Printers > "Find a specific
  driver" (search "sato").
- `rstationlp` — a CUPS backend (device URI `rstationlp://SATO/WS408?serial=<usb serial>`) that sends the job to the kernel's
  `/dev/usb/lp*` node instead of using CUPS's stock `usb` backend, and `rstation-sato.usb-quirks`, which tells the stock backend to
  ignore the WS408 (`blacklist`) so Settings > Printers lists it once. **Why:** the WS408 stops answering USB control requests
  (`GET_DEVICE_ID`) about 100 s after it enumerates on a Raspberry Pi 4 — on a laptop it never does — while still accepting print data.
  The stock backend identifies a printer through that request, so on the Pi the queue sticks on "Waiting for printer to become
  available" (or the printer shows as `usb://Unknown/Printer`). `rstationlp` identifies it from sysfs (vendor/product/serial are read at
  enumeration) and only uses the bulk-out path. Several printers work: each queue's URI carries its own serial, resolved to a node on
  every job. Costs: CUPS gets no paper-out/offline status for it, and a model must be added to `PRINTERS` in `rstationlp` *and* to the
  quirks file (the wire details and the investigation are in `../SATO_PRINTER_TROUBLESHOOTING.md`). `slide-announcer-printer-setup`
  allows the `rstationlp` scheme.
- `test_pdftosbpl.py`, `test_rstationlp.py` — `python3 -m pytest rstation/sato-driver` (the filter tests need `gs`).
- `../build-sato-driver-deb.sh` builds the `.deb` into `product/image/debs/`; run it before the image build (the
  image's `packages` file adds Ghostscript).

## In the image

The platform `apt-get install`s every `.deb` in `product/image/debs/` into the image's root filesystem, so this package
(and its Ghostscript and Python dependencies) is baked into the next image build. Run `rstation/build-sato-driver-deb.sh`
before the platform's `image-builder/build.sh`, in the same session as `fetch-cups-debs.sh` (which leaves it alone).
Bump `VERSION` when `pdftosbpl`, `rstationlp`, the quirks file or the PPD changes, and delete older `rstation-sato-driver_*.deb` files from `debs/` first. Image only: a hotfix bundle can't carry a package, so a Pi that was
flashed earlier needs the new image (or `dpkg -i` of the `.deb`, which doesn't survive the read-only root's next update).

## Verified

2026-10-22, WS408 over USB, 203 dpi, 2.312 x 4 in labels: a test frame, then a 2-page PDF with 2 copies (4 identical labels)
printed correctly with the default shift (`Left5`) using the filter's bytes and `lp -o raw`. The package was then installed
on a laptop, a CUPS queue created from this PPD, and a WeasyPrint label (text + a Code 128 barcode) printed through it with
plain `lp`; it looked right and the barcode scanned. 2026-10-22 evening, Raspberry Pi 4 (CUPS 2.4.20): with the printer's control endpoint dead, `rstationlp` printed every job; the package installed by `dpkg -i`, `lpinfo -v` listed the printer once, and a queue added with the same `lpadmin` call the Printers page makes printed. **Not yet tested:** a build of the image, two printers on one Pi, the Printers page itself (rather than `lpadmin`), USB label printers on the
Pi's Printers page, or a real Relief Inventory pallet label.

## Things to know

- **Horizontal position is empirical.** The label's position on the head depends on the stock; `Left5` was measured for
  2.312 x 4 in labels. Other widths may need another `Horizontal position` value (print a page with a border to see).
- **Printer must speak SBPL.** Some SATO models offer other languages on the front panel (Protocol/Emulation).
- Pages print at the PDF's own size (no scaling). The app's labels are 4 x 6.5 in; a smaller roll needs a PDF the same
  size as its label (a new label size in the app, not done yet).
- Only the models in SATO's PPD set that emit this `ESC GB` bitmap format are expected to work; WS408 is the one tested.
- Printer-side settings the SATO PPDs expose (darkness, speed, cutter, sensor type) are not sent; set them on the
  printer's panel.
