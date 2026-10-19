#!/bin/bash
# Downloads the published CUPS backport .debs into ../product/image/debs/ (gitignored) for the image build,
# verifying them against SHA256SUMS. Run this (from anywhere) before the platform's image build.sh.
set -euo pipefail
CUPS_TAG="${CUPS_TAG:-cups-2.4.20-1-rstation1}"
REPO="${CUPS_BACKPORT_REPO:-fiforms/cups-backport}"  # the cups-backport repo (submodule at ./cups-backport)
DEST="$(cd "$(dirname "$0")" && pwd)/product/image/debs"
rm -rf "$DEST"; mkdir -p "$DEST"
gh release download "$CUPS_TAG" -R "$REPO" -D "$DEST"
( cd "$DEST" && sha256sum -c SHA256SUMS && rm SHA256SUMS )
# Skip the -dev packages (the image has no use for them); everything else must be installed together, since
# the distro's other cups-* packages (e.g. cups-ipp-utils) depend on the exact libcups2t64 version.
cd "$DEST" && rm -f libcups2-dev_* libcupsimage2-dev_*
ls "$DEST"
