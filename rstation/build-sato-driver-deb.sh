#!/bin/bash
# Builds the rstation-sato-driver .deb (SATO label printer filter + PPD, see sato-driver/README.md) into
# product/image/debs/ (gitignored) for the image build. Architecture-independent, so build it anywhere with
# dpkg-deb. Run it, like fetch-cups-debs.sh, before the platform's image build.sh.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="$HERE/sato-driver"
DEST="$HERE/product/image/debs"
VERSION="$(cat "$SRC/VERSION")"
STAGE="$(mktemp -d)"; trap 'rm -rf "$STAGE"' EXIT

install -D -m 0755 "$SRC/pdftosbpl"    "$STAGE/usr/lib/cups/filter/pdftosbpl"
install -D -m 0644 "$SRC/sato-sbpl.ppd" "$STAGE/usr/share/cups/model/rstation/sato-sbpl.ppd"
# Backends run as root when not group/world accessible; this one is root-only like CUPS's own.
install -D -m 0700 "$SRC/rstationlp"   "$STAGE/usr/lib/cups/backend/rstationlp"
install -D -m 0644 "$SRC/rstation-sato.usb-quirks" "$STAGE/usr/share/cups/usb/rstation-sato.usb-quirks"
install -D -m 0644 "$SRC/README.md"     "$STAGE/usr/share/doc/rstation-sato-driver/README.md"
mkdir -p "$STAGE/DEBIAN"
cat > "$STAGE/DEBIAN/control" <<EOF
Package: rstation-sato-driver
Version: $VERSION
Architecture: all
Maintainer: Relief Inventory <noreply@reliefinventory.invalid>
Depends: python3, ghostscript
Recommends: cups-daemon
Section: utils
Priority: optional
Description: CUPS filter and PPD for SATO SBPL label printers
 Turns the PDFs CUPS receives into SATO's SBPL bitmap jobs, one label per page, so a SATO label
 printer works on ARM (SATO's own driver is x86-only). Choose "SATO label printer - SBPL" when
 adding the queue. Also installs the rstationlp backend, which prints to the WS408 through the
 kernel's usblp node (the stock usb backend can't identify it on a Pi), and tells the stock backend
 to ignore that printer.
EOF
mkdir -p "$DEST"
dpkg-deb --root-owner-group --build "$STAGE" "$DEST/rstation-sato-driver_${VERSION}_all.deb"
ls -l "$DEST"/rstation-sato-driver_*.deb
