# CUPS backport

The R Station image ships **CUPS 2.4.20**, backported from Debian sid, instead of trixie's 2.4.10 — 2.4.10 has
upstream bug #998 (garbled "Connection" field on the Add Printer page, fixed in 2.4.11) and lacks the
2.4.17–2.4.20 security fixes.

- **Build scripts and releases live in their own repo:** https://github.com/fiforms/cups-backport
  (Dockerfile, arm64 build via buildx/qemu, `publish.sh`). Releases are named `cups-<ver>-rstation<N>`.
- **Consuming it:** run `rstation/fetch-cups-debs.sh` before the platform's image `build.sh`. It downloads the
  release named by `CUPS_TAG` in that script, verifies checksums, and puts the `.deb`s in
  `rstation/product/image/debs/` (gitignored). The platform's `00-run.sh` installs everything in that folder
  after the `packages` list (see `platform/docs/PRODUCTS.md`, `debs/`).
- **Updating:** build + publish a new release in the backport repo, then bump `CUPS_TAG` here. Debian won't
  update these automatically — rebase on new sid releases when CUPS advisories land, and delete all of this once
  trixie carries 2.4.11+.
- **Status:** arm64 packages install cleanly over trixie's in an emulated container and `cupsd` accepts its
  config; a full image build and the Add Printer page on a real Pi are not yet verified.
