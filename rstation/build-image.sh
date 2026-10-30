#!/bin/bash
# Builds the R Station image: the offline-server bundle, then the platform's image build. The Docker image
# source is required so a stale or unintended bundle is never baked in by accident:
#
#   npm run build:rstation -- latest    # pull ghcr.io/fiforms/reliefinventory:latest and rebuild the bundle
#   npm run build:rstation -- edge      # the same, from :edge
#   npm run build:rstation -- cached    # keep the bundle already in product/image/debs/ (no pull)
#
# See build-offline-bundle.sh for what the bundle is.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"

case "${1:-}" in
    latest|edge)
        npm --prefix "$ROOT" run test:rstation
        APP_IMAGE="ghcr.io/fiforms/reliefinventory:$1" "$HERE/build-offline-bundle.sh"
        ;;
    cached)
        npm --prefix "$ROOT" run test:rstation
        ls "$HERE"/product/image/debs/rstation-offline-server_*.deb >/dev/null 2>&1 \
            || { echo "No cached bundle in rstation/product/image/debs/; run with 'latest' or 'edge' first." >&2; exit 1; }
        ;;
    *)
        echo "usage: npm run build:rstation -- latest|edge|cached" >&2
        exit 2
        ;;
esac

PRODUCT_ROOT="$HERE/product" PRODUCT=rstation "$HERE/platform/image-builder/build.sh" --virt
