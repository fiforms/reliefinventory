# Cutting a release

One release version (`1.4.0`, tag `v1.4.0`) covers the web app and its Docker image, the RI Launcher, RI Print and the R Station
image. The Docker image is built by GitHub when the release is published; the desktop apps and the R Station image are built by hand
and attached afterwards. Do the steps in this order: the R Station build pulls the release's Docker image, so it has to exist first.

Never commit to `master`: steps 1–4 happen on a branch and go in through a PR (`CLAUDE.md`, "Committing").

## 1. Check the code

```bash
vendor/bin/pint --test <files changed since the last release>   # the repo isn't Pint-clean; don't reformat the rest
vendor/bin/pest --parallel                                      # ~3 min; read dev/TESTING.md first if the test DB isn't set up
npm run test:rstation                                           # R Station backend and update-script tests
(cd launcher && cargo test -p ri-launcher-core)
(cd print-client && cargo test -p ri-print-core)             
dev/scripts/schema-doc.sh                                       # only if a migration changed; commit dev/SCHEMA.md
```

## 2. Update CHANGELOG.md

Move `[Unreleased]` under a new `## [1.4.0] - YYYY-MM-DD` heading and start a fresh empty `[Unreleased]`. Build the list from the commits,
not from memory: `git log v<previous>..HEAD --oneline`. Group by Added / Changed / Fixed / Removed, and call out anything that needs
an administrator to act (database migration, devices that must be paired again, new `compose.yaml` settings).

## 3. Set the version in the sub-projects

Each has its own number, but give them all the release's version so a bug report can be matched to a release. In each file change
`version`:

| Project | Files |
|---|---|
| RI Launcher | `launcher/src-tauri/Cargo.toml`, `launcher/src-tauri/tauri.conf.json`, `launcher/package.json`, `launcher/core/Cargo.toml` |
| RI Print | `print-client/src-tauri/Cargo.toml`, `print-client/src-tauri/tauri.conf.json`, `print-client/package.json`, `print-client/core/Cargo.toml` |
| R Station | `rstation/product/VERSION` (bump it when the product changed; it feeds the image and app-package version strings) |

Then refresh the lock files (`cargo update -p ri-launcher-desktop` and the print-client equivalent, or just `cargo check` in each) and commit
`Cargo.lock` with them. The web app and Docker image need no edit: the version comes from the release tag (`RI_VERSION`, `config/app.php`).

Commit all of this to the release branch, open the PR, merge it. Everything below is done from the merged `master` commit.

## 4. Draft the release notes

GitHub release notes are what administrators read before upgrading. Start from the changelog section and make it plain: what's new,
whether the upgrade migrates the database (and so can't be rolled back without a restore: `docker/README.md`, "Rolling back"), and what to
do after upgrading. Keep it in a scratch file until step 5; GitHub's "Generate release notes" can add the PR list underneath.

## 5. Tag and publish on GitHub: this builds the Docker image

On GitHub, Releases → Draft a new release: choose **Create new tag** `v1.4.0` on `master`, paste the notes, and publish. Tick
**pre-release** for a candidate: it publishes `:1.4.0-rc1` only, and `:latest` (what every install follows) doesn't move.

Publishing triggers `.github/workflows/docker.yml`: amd64 and arm64 images, tagged `:1.4.0` and `:latest`, with `RI_VERSION` stamped in.
Wait for it to go green (Actions tab), then check `docker pull ghcr.io/fiforms/reliefinventory:1.4.0`. Nothing below works until it exists.

## 6. Build the desktop apps and attach them to the release

The launcher and RI Print workflows are manual: Actions → *RI Launcher (Linux / macOS / Windows)* and *Print client (Linux / macOS / Windows)* →
**Run workflow**, and choose the **tag** `v1.4.0` (not a branch) as the ref. The launcher then shows the tag as its version; a branch
build shows the commit hash and an installer version of `<Cargo version>+<hash>`. Details: `launcher/README.md`, "Version shown in the window".

Download each run's artifacts and attach them to the release:

```bash
gh release upload v1.4.0 <the .deb, .AppImage, .dmg and installer files>
```

The macOS build is unsigned until we have an Apple Developer ID; say so in the release notes (right-click → Open the first time).
To build locally instead: `npm run build:launcher`, `npm run build:print-client`.

## 7. Build the R Station image and upload it to the CDN

On the arm64-capable build host (setup: `rstation/platform/docs/BUILDING.md`: pi-gen, RAUC signing key, ~10 GB free):

```bash
npm run build:rstation -- latest            # tests, pulls :latest into the offline-server .deb, then builds the image (needs the step 5 image)
```

`latest` is the release you just published in step 5. For a pre-release candidate `:latest` hasn't moved, so use `edge` or build the bundle
by hand (`npm run build:offline-bundle -- 1.4.0-rc1`) and then `-- cached`.

Output is in `rstation/platform/image-builder/deploy/`: the flashable `.img.xz` (new SD cards), the signed `.raucb` (over-the-air
updates for devices already in the field) and a `-virt.img.xz` for QEMU. Test the image on a Pi, or the virt image in QEMU, before publishing.

Upload the `.img.xz` and `.raucb` to the CDN: **TODO: write down the CDN, folder and upload method here.** Keep the file names
unique per build. Then copy the public https URLs of the `.img.xz` and the `.raucb` for step 8.

## 8. Publish the manifest the RI Launcher reads

The launcher's "R Station" tab finds the SD card image through `rstation-image.json`, an asset of the fixed GitHub release named
`rstation-image`. It's separate from the version release, so a new image needs no launcher release. After the upload in step 7:

```bash
npm run publish:rstation-image -- <https url of the .img.xz> --version 1.4.0 --notes "what changed" \\
    --rauc <https url of the .raucb> [--min-launcher 1.4.0]
# add --dry-run first to see the manifest; needs `gh` logged in with write access
```

It streams each file once to get its SHA-256 and size, then uploads the manifest. The `.raucb` goes in as a `rauc` object (filename, size,
sha256, urls: the same shape as the image) so a server or device can fetch the update bundle; the launcher ignores it. `--min-launcher` hides the image from launchers older than
the given version (use it if the image needs something new from the launcher), and `--url` adds mirrors. To try a manifest before
publishing, point a launcher at it with `RI_IMAGE_MANIFEST_URL`. Then open the launcher's R Station tab and check it offers the new image.

## After the release

- Install the published `:latest` on a test host (`docker compose pull && docker compose up -d`) and open Updates & Backups: it should report the new version.
- Make sure `[Unreleased]` in `CHANGELOG.md` is empty and ready.
