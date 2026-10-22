#!/bin/bash
# Builds the offline-server package for the R Station image: a .deb that carries the Relief Inventory
# Docker images (the web app and MariaDB, linux/arm64) and the compose file that runs them, installed
# at /opt/relief-inventory. Run it (from anywhere) before the platform's image build.sh, like
# fetch-cups-debs.sh; the platform installs every .deb in product/image/debs/. A .deb is the one seam
# that can put arbitrary files into the root filesystem without changing the platform.
#
#   rstation/build-offline-bundle.sh                       # ghcr.io/fiforms/reliefinventory:latest
#   rstation/build-offline-bundle.sh 0.1.0                 # ghcr.io/fiforms/reliefinventory:0.1.0 (a leading v is dropped)
#   APP_IMAGE=ghcr.io/fiforms/reliefinventory:1.2.3 rstation/build-offline-bundle.sh
#   npm run build:offline-bundle -- 0.1.0                  # the same, from the repo root
#
# Needs docker (pulling another architecture's image works without emulation) and dpkg-deb, and
# about 2 GB of scratch space. The result is ~460 MB and is NOT committed (debs/ is gitignored).
# Building an image without it just leaves the Settings > Local server page saying the feature isn't
# in this image; nothing else changes.
set -euo pipefail

# An optional first argument is the release version; an explicit APP_IMAGE still wins.
VERSION_ARG="${1:-}"
DEFAULT_TAG="${VERSION_ARG#v}"
APP_IMAGE="${APP_IMAGE:-ghcr.io/fiforms/reliefinventory:${DEFAULT_TAG:-latest}}"
DB_IMAGE="${DB_IMAGE:-mariadb:11}"        # keep in step with docker/compose.yaml
PLATFORM=linux/arm64
HERE="$(cd "$(dirname "$0")" && pwd)"
DEST="${HERE}/product/image/debs"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

command -v docker >/dev/null || { echo "docker is required" >&2; exit 1; }

# With Docker's containerd image store (the default from Docker 29) a tag can hold every platform that was ever
# pulled, and inspect/save default to the host's. Ask for the platform explicitly wherever the CLI can; on the classic
# store `pull --platform` replaces the tag with that platform's image and the plain forms are right.
inspect() { docker image inspect --platform "$PLATFORM" "$@" 2>/dev/null || docker image inspect "$@"; }
SAVE_ARGS=()
docker save --help 2>&1 | grep -q -- '--platform' && SAVE_ARGS=(--platform "$PLATFORM")

echo "==> Pulling $PLATFORM images"
docker pull --platform "$PLATFORM" "$APP_IMAGE"
docker pull --platform "$PLATFORM" "$DB_IMAGE"
for ref in "$APP_IMAGE" "$DB_IMAGE"; do
    arch="$(inspect --format '{{.Architecture}}' "$ref")"
    [ "$arch" = arm64 ] || { echo "$ref is '${arch}', not arm64 — is another platform's copy cached under that tag?" >&2; exit 1; }
done

# What the app was built from, for the Settings page and bug reports (the image carries RI_GIT_COMMIT).
APP_COMMIT="$(inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$APP_IMAGE" | sed -n 's/^RI_GIT_COMMIT=//p' | head -n1)"
APP_ID="$(inspect --format '{{.Id}}' "$APP_IMAGE")"

PKG="$WORK/pkg"
ROOT="$PKG/opt/relief-inventory"
mkdir -p "$ROOT" "$PKG/DEBIAN"

echo "==> Saving"
# One archive for both so `docker load` runs once. Not compressed again: the layers inside already are, and zstd
# measured no gain (~480 MB either way).
docker save "${SAVE_ARGS[@]}" "$APP_IMAGE" "$DB_IMAGE" -o "$ROOT/images.tar"

cp "$HERE/product/offline/compose.yaml" "$ROOT/compose.yaml"
cat > "$ROOT/images.env" <<ENV
RI_APP_IMAGE=$APP_IMAGE
RI_DB_IMAGE=$DB_IMAGE
ENV
cat > "$ROOT/manifest.json" <<JSON
{"app_image": "$APP_IMAGE", "db_image": "$DB_IMAGE", "app_commit": "${APP_COMMIT:-unknown}", "app_image_id": "$APP_ID", "built": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"}
JSON

VERSION="0.$(date -u +%Y%m%d).${APP_COMMIT:0:7}"
VERSION="${VERSION%.}"
cat > "$PKG/DEBIAN/control" <<CTL
Package: rstation-offline-server
Version: ${VERSION}
Architecture: arm64
Maintainer: Relief Inventory <noreply@invalid>
Section: misc
Priority: optional
Description: Relief Inventory Docker images for the R Station's offline local server
 The web app and MariaDB images as one 'docker save' archive, plus the compose file that runs them.
CTL

mkdir -p "$DEST"
# fetch-cups-debs.sh keeps this file when it refreshes the CUPS packages; replace any previous build.
rm -f "$DEST"/rstation-offline-server_*.deb
# The payload is already compressed, so don't spend minutes recompressing it.
dpkg-deb -Znone --root-owner-group --build "$PKG" "$DEST/rstation-offline-server_${VERSION}_arm64.deb"
ls -lh "$DEST"/rstation-offline-server_*.deb
