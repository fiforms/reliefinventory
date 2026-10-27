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
#
# BRANCH=master (the default) tracks origin, same as production: fetch +
# hard-reset to origin/master. Any other BRANCH is treated as a LOCAL-ONLY
# branch instead (e.g. an autofix/* dev-autofix branch) — this box has no
# push-capable git credentials by design (see scripts/AI_AGENT_ACCESS.md and
# .claude/skills/dev-autofix/SKILL.md), so such a branch never exists on
# origin to fetch. Previewing it just means checking out the local ref
# as-is, with no network fetch at all.
#
# IMPORTANT for callers: this does `git reset --hard` on whichever branch is
# selected. Commit your work on that branch FIRST — anything uncommitted in
# the working tree when this runs is discarded, not just left behind.

set -Eeuo pipefail

APP_DIR="$HOME/reliefinventory"
PHP="php8.4"
BRANCH="${BRANCH:-master}"

cd "$APP_DIR"

echo "== dev-update: $(date -u +%FT%TZ) =="

OLD_SHA="$(git rev-parse HEAD)"

if [ "$BRANCH" = "master" ]; then
    git fetch origin "$BRANCH"
    NEW_SHA="$(git rev-parse "origin/$BRANCH")"

    if [ "$OLD_SHA" = "$NEW_SHA" ]; then
        echo "-- Already up to date at $OLD_SHA"
    else
        echo "-- Updating $OLD_SHA -> $NEW_SHA"
    fi
    git checkout "$BRANCH"
    git reset --hard "origin/$BRANCH"
else
    if ! git show-ref --verify --quiet "refs/heads/$BRANCH"; then
        echo "-- No local branch named '$BRANCH', and this box never pushes to/fetches" >&2
        echo "   arbitrary branches from origin — nothing to preview. Check the branch" >&2
        echo "   name, or run with no BRANCH set to preview master instead." >&2
        exit 1
    fi

    NEW_SHA="$(git rev-parse "$BRANCH")"
    echo "-- Local-only branch '$BRANCH' at $NEW_SHA (not fetched from origin)"
    git checkout "$BRANCH"
    git reset --hard "$BRANCH"
fi

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
"$PHP" artisan instance:migrate --force

echo "-- Clearing caches"
"$PHP" artisan config:clear
"$PHP" artisan route:clear
"$PHP" artisan view:clear

echo "-- Reloading php-fpm"
sudo systemctl reload php8.4-fpm

echo "== dev-update finished OK: now on $NEW_SHA =="
