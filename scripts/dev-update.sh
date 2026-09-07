#!/usr/bin/env bash
#
# dev-update.sh — pull latest master, rebuild, and refresh dev.reliefinventory.app.
#
# Run as claude-agent (no sudo needed for the app steps; php-fpm reload uses sudo):
#
#   bash ~/reliefinventory/scripts/dev-update.sh
#
# This is the personal-dev-box counterpart to the production scripts/update.sh —
# deliberately without its backup tiering / maintenance-mode / health-check
# machinery, since this box has no real user traffic to protect. It just pulls,
# rebuilds what changed, migrates, and reloads.

set -Eeuo pipefail

APP_DIR="$HOME/reliefinventory"
PHP="php8.4"
BRANCH="${BRANCH:-master}"

cd "$APP_DIR"

echo "== dev-update: $(date -u +%FT%TZ) =="

OLD_SHA="$(git rev-parse HEAD)"
git fetch origin "$BRANCH"
NEW_SHA="$(git rev-parse "origin/$BRANCH")"

if [ "$OLD_SHA" = "$NEW_SHA" ]; then
    echo "-- Already up to date at $OLD_SHA"
else
    echo "-- Updating $OLD_SHA -> $NEW_SHA"
fi
git reset --hard "origin/$BRANCH"

CHANGED="$(git diff --name-only "$OLD_SHA" "$NEW_SHA")"

if echo "$CHANGED" | grep -qx 'composer.lock'; then
    echo "-- composer.lock changed; running composer install"
    composer install --no-interaction
else
    echo "-- composer.lock unchanged; skipping composer install"
fi

if echo "$CHANGED" | grep -qx 'package-lock.json'; then
    echo "-- package-lock.json changed; running npm ci"
    npm ci
else
    echo "-- package-lock.json unchanged; skipping npm ci"
fi

echo "-- Building assets"
npm run build

echo "-- Running migrations"
"$PHP" artisan migrate --force

echo "-- Clearing caches"
"$PHP" artisan config:clear
"$PHP" artisan route:clear
"$PHP" artisan view:clear

echo "-- Reloading php-fpm"
sudo systemctl reload php8.4-fpm

echo "== dev-update finished OK: now on $NEW_SHA =="
