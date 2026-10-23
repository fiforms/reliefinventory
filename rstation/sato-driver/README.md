# SATO label printers (SBPL) on the R Station

SATO's published Linux driver is x86-only: its PPDs work anywhere, but its one binary (`rastertosbpl`) doesn't run on
the Pi. This directory replaces that binary and bundles a PPD, as the `rstation-sato-driver` package.

- `pdftosbpl` — a CUPS filter (Python + Ghostscript). PDF in, one SBPL bitmap job out per page. Read its docstring
  for the wire format; it was captured from SATO's own filter, then checked on a real printer, not taken from the
  SBPL manual (SATO gives that out on request only).
- `sato-sbpl.ppd` — "SATO label printer - SBPL": page sizes (4 x 6.5, 4 x 6, 4 x 4, 2.312 x 4, 3.5 x 1.125 in and custom),
  resolution (203/305/609 dpi) and a horizontal position shift. Shows up in Settings > Printers > "Find a specific
  driver" (search "sato").
- `test_pdftosbpl.py` — `python3 -m pytest rstation/sato-driver` (needs `gs`).
- `../build-sato-driver-deb.sh` builds the `.deb` into `product/image/debs/`; run it before the image build (the
  image's `packages` file adds Ghostscript).

## In the image

The platform `apt-get install`s every `.deb` in `product/image/debs/` into the image's root filesystem, so this package
(and its Ghostscript and Python dependencies) is baked into the next image build. Run `rstation/build-sato-driver-deb.sh`
before the platform's `image-builder/build.sh`, in the same session as `fetch-cups-debs.sh` (which leaves it alone).
Bump `VERSION` when `pdftosbpl` or the PPD changes. Image only: a hotfix bundle can't carry a package, so a Pi that was
flashed earlier needs the new image (or `dpkg -i` of the `.deb`, which doesn't survive the read-only root's next update).

## Verified

On a real Pi (2026-10-22): the driver came from an image build, the queue was added on the Printers page, and labels printed from the
web UI. The USB 3.0 port problem above was the one fault found. Still unconfirmed on the Pi: copies/multi-page and a real app pallet or item label.

Earlier, on a laptop:

2026-10-22, WS408 over USB, 203 dpi, 2.312 x 4 in labels: a test frame, then a 2-page PDF with 2 copies (4 identical labels)
printed correctly with the default shift (`Left5`) using the filter's bytes and `lp -o raw`. The package was then installed
on a laptop, a CUPS queue created from this PPD, and a WeasyPrint label (text + a Code 128 barcode) printed through it with
plain `lp`; it looked right. **Not yet tested:** scanning the barcode of any label printed on the small stock (pallet or item label).

## Things to know

- **On a Raspberry Pi, plug the printer into a USB 2.0 (black) port, not a USB 3.0 (blue) one.** Found the hard way on 2026-10-22
  (WS408, image built from this repo): on a USB 3.0 port CUPS's USB backend could not read the printer's IEEE 1284 device ID
  (it logged a garbage ID, listed the printer as `usb://Unknown/Printer?serial=0.0`, and a queue created under its real name sat on
  "Waiting for printer to become available" forever — the kernel's own `/sys/class/usbmisc/lp0/device/ieee1284_id` was empty too).
  Writing straight to `/dev/usb/lp0` worked throughout, so the printer, cable, power and SBPL stream were fine. On a USB 2.0 port the
  same backend reads `MFG:SATO;CMD:SBPL;MDL:WS408;…` and lists `usb://SATO/WS408?serial=<serial>`. Unloading `usblp` made no difference.
  If you must use a USB 3.0 port, `usb://Unknown/Printer?serial=0.0` as the queue's device URI printed (it matches any single printer
  whose ID can't be read, so it breaks with a second one). To diagnose: `sudo /usr/lib/cups/backend/usb` should list the printer under
  its real name; `lpstat -v` shows the URI the queue wants.
- **Horizontal position is empirical.** The label's position on the head depends on the stock; `Left5` was measured for
  2.312 x 4 in labels. Other widths may need another `Horizontal position` value (print a page with a border to see).
- **Printer must speak SBPL.** Some SATO models offer other languages on the front panel (Protocol/Emulation).
- Pages print at the PDF's own size (no scaling). The app's labels are 4 x 6.5 in; a smaller roll needs a PDF the same
  size as its label (a new label size in the app, not done yet).
- Only the models in SATO's PPD set that emit this `ESC GB` bitmap format are expected to work; WS408 is the one tested.
- Printer-side settings the SATO PPDs expose (darkness, speed, cutter, sensor type) are not sent; set them on the
  printer's panel.
