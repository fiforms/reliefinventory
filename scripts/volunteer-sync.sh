#!/usr/bin/env bash
#
# volunteer-sync.sh — hourly check that flips people.volunteer_active for
# anyone whose scheduled volunteer_window starts or ends today.
#
# This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
# Licensed under the GNU GPL v. 3. See LICENSE.md for details
#
# Run as root (drops to www-data internally), via
# reliefinventory-volunteer-sync.timer — same hourly-systemd-timer pattern as
# reliefinventory-backup.timer (see scripts/BACKUPS.md), reused here rather
# than introducing a new scheduling mechanism. Unlike the backup timer, this
# job is naturally idempotent (volunteers:sync-active-windows only touches
# rows exactly on their transition day) so it has no separate "is it due"
# check — it just runs every firing.

set -Eeuo pipefail

APP_DIR="${APP_DIR:-$(dirname "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)")}"
APP_USER="${APP_USER:-www-data}"
APP_USER_HOME="${APP_USER_HOME:-/var/www}"
PHP="${PHP:-php8.4}"

[ "$(id -u)" -eq 0 ] || { echo "Run as root (app steps drop to $APP_USER)." >&2; exit 2; }
[ -f "$APP_DIR/artisan" ] || { echo "No artisan found in $APP_DIR" >&2; exit 2; }

runuser -u "$APP_USER" -- env HOME="$APP_USER_HOME" "$PHP" "$APP_DIR/artisan" volunteers:sync-active-windows
