# Backups: schedule, tiers, and admin-panel integration

Backups are made by `scripts/update.sh` (every full update backs up first; the
`--backup-only` and `--scheduled` flags back up without updating) and land under
`/var/backups/reliefinventory/`:

```
hourly/<YYYYmmdd-HHMMSS>/    every backup, scheduled or on-demand  keep KEEP_HOURLY  (48)
daily/<stamp>/               that day's scheduled backup           keep KEEP_DAILY   (14)
monthly/<stamp>/             first daily promotion of the month    keep KEEP_MONTHLY (12)
yearly/<stamp>/              first daily promotion of the year     keep KEEP_YEARLY  (3)
```

`hourly/` is a flat, undeduplicated recent-backups tier — its job is to let you roll
back to whichever attempt preceded a bad one (e.g. three "Back Up Now" clicks or
update attempts in the same hour each get their own entry there, none of them
collapsed into "the hour's backup"). Only the once-a-day **scheduled** backup (never
an on-demand one — an update's pre-update backup, or a manual "Back Up Now") gets
promoted further into `daily/`, and only that promotion can, in turn, promote into
`monthly/`/`yearly/`. This is deliberate: an on-demand backup never enters
daily/monthly/yearly directly, so triggering several in one day can't crowd out
that day's (or an earlier day's) history — see `promote()`/`prune_tier()` in
`update.sh`.

The stamp is generated in `BACKUP_TZ`, not server-local time (`TZ="$BACKUP_TZ" date
...` in `update.sh`) — the admin panel displays it verbatim, so it has to already be
in the configured timezone or the panel would silently mislabel every backup time.

Each backup contains `db.sql.gz` (integrity-checked mysqldump),
`storage-app.tar.gz` (uploads), `env.backup`, and `git-sha.txt` (the code
revision the data matched). Daily/monthly/yearly copies are **hardlink
promotions** (`cp -al`) of the underlying hourly backup — promotion itself costs
no disk space; space is only held by however many distinct snapshots the tiers
retain.

## Schedule settings

Live settings file: `storage/app/backup-settings.conf` (copy
`scripts/backup-settings.conf.example` to start). Keys, defaults, and valid
values are documented in the example file; defaults are 2am
`America/Los_Angeles`, daily, keeping 48/14/12/3 (hourly/daily/monthly/yearly).

The systemd timer (`reliefinventory-backup.timer`) fires **hourly**, and each
firing runs `update.sh --scheduled`, which exits immediately unless a backup is
due per the settings file. Due means: at/past `BACKUP_HOUR` in `BACKUP_TZ`
(past, not exactly at — so downtime over the backup hour self-heals later the
same day, helped by `Persistent=true`), the right `BACKUP_DOW` in weekly mode,
and no scheduled backup recorded yet that day (tracked in
`/var/backups/reliefinventory/.last-scheduled`).

## Admin panel integration

The System Administration panel (`/setup/system`, permission `admin-system`,
`SystemController` + `SystemAdmin.vue`) manages all of this:

- **Schedule/retention changes**: the app rewrites
  `storage/app/backup-settings.conf`, which `www-data` owns. No root, no
  systemd interaction, takes effect at the next hourly check. The script
  **never sources** the settings file — each key is extracted and validated
  against a strict pattern with bad values falling back to defaults — so the
  file being web-writable does not give the web app shell access as root.
- **"Back up now"** starts `reliefinventory-backup-now.service` (plain
  `--backup-only`, no due-check) and **"Install Update"** starts
  `reliefinventory-update.service` (full update) — both via the sudoers rule
  in `scripts/systemd/reliefinventory-sudoers`, which allows exactly those
  two `systemctl start --no-block` commands and nothing else.
- **Status display**: the panel lists tier directory names (contents stay
  root-only; the names are enough), reads `.last-scheduled`, and follows a
  running update through `storage/app/system-update-status.json`, which
  `update.sh` writes at start/success/failure of full updates. During the
  update's maintenance window the panel's polls get 503s and it shows that
  as progress, not an error.

## One-time install (per server)

```bash
cd /var/www/reliefinventory-demo
runuser -u www-data -- cp scripts/backup-settings.conf.example storage/app/backup-settings.conf
cp scripts/systemd/reliefinventory-backup.service \
   scripts/systemd/reliefinventory-backup.timer \
   scripts/systemd/reliefinventory-update.service \
   scripts/systemd/reliefinventory-backup-now.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now reliefinventory-backup.timer
systemctl list-timers reliefinventory-backup.timer   # verify next firing

# Sudoers rule so the admin panel can trigger updates/backups (see file header)
cp scripts/systemd/reliefinventory-sudoers /etc/sudoers.d/reliefinventory
chmod 440 /etc/sudoers.d/reliefinventory
visudo -c
```

After changing the unit files in the repo, re-copy them and `systemctl
daemon-reload` (settings-file changes need nothing).

## Manual operations

```bash
bash scripts/update.sh --backup-only          # back up right now (lands in hourly/ only)
journalctl -u reliefinventory-backup.service  # scheduled-run history
```

Restore is manual and deliberate: `gunzip < db.sql.gz | mysql <db>` for data,
untar `storage-app.tar.gz` from the app root for uploads, and `git reset
--hard $(cat git-sha.txt)` + rebuild if the code must match the data.

## Backup files (on-demand zips) — `/setup/backups`

Separate from the tiered backups above (which stay as they are): a `manage-backups` holder
(Administrator by default) can create, download and delete full backup **zip files** from the
**Backup Files** page, and `php artisan backup:create [--uploads]` does the same from a shell.

```
manifest.json         format version, app commit, migration list, size + SHA-256 of every entry
database/db.sql       plain mariadb-dump
files/private/...     storage/app/private  (only with "include uploads")
files/public/...      storage/app/public   (only with "include uploads")
```

- **Where:** `config('backup.dir')` (`RI_BACKUP_DIR`, default `storage/backups`; `/backups` in Docker) —
  never under `storage/app`, which `update.sh` tars and a backup must not contain earlier backups.
  Excluded from uploads (`config('backup.exclude_dirs')`): `print-jobs`, `device-releases`, `device-uploads`.
- **How it runs:** the request starts `php artisan backup:run <id>` detached (`setsid -f`), so it outlives the
  web request and needs no queue worker (a worker's timeout/`retry_after` would kill or re-run a long job).
  One at a time: the `start()` row check plus an `flock` on `<dir>/.backup.lock`, which the kernel releases if
  the process dies; a row whose process vanished is marked failed on the next page load. Needs `setsid`
  (util-linux), `zip` support in PHP, and `mariadb-dump`/`mysqldump` on the server.
- **Expiry:** `RI_BACKUP_KEEP_DAYS` (default 14) after creation; deleted lazily when the page loads or a backup
  runs, hourly by the Docker scheduler (`backup:prune`), or individually by an admin. Bare-metal has no scheduler
  for it beyond the lazy path.
- **The application key is never in a zip** (not `.env`, not `storage/app/.app_key`; `BackupService` refuses
  those names even if they sit in an included directory). What depends on `APP_KEY`: values encrypted in the
  database (today only the feedback-aggregation shared secret) and login/remember cookies. Passwords and PINs
  are bcrypt, unaffected. Admins with `view-app-key` can reveal it on the Backup Files page (password re-entry,
  throttled, logged) to write it down. A restore without the key must blank anything encrypted with the old one.

### Restoring a backup zip — first-run wizard (`/install` → "Restore from a backup")

Only onto a **brand-new instance** (`FirstRunService::needsSetup()`); creating or emptying an instance is deliberately
not offered in the UI — start from a fresh database/volume. The wizard uploads the zip in resumable chunks (the same
`ChunkedUpload` service the firmware page uses; `RI_RESTORE_CHUNK_BYTES`, `RI_RESTORE_MAX_BYTES`), then
`php artisan restore:run <id>` takes over as a detached process (`RestoreService`):

1. read `manifest.json`, run `BackupCompatibility` (format, no app key, safe paths, zip == manifest, **migration list
   must not contain a migration this code lacks** — a backup from newer software is refused; an older one is
   fine, the restore ends by migrating up), check disk space;
2. unpack every entry to `<RI_BACKUP_DIR>/restore/<id>/work`, checking size and SHA-256 as it streams — **nothing
   on the instance has changed up to here**, so a damaged or hostile zip is harmless;
3. `db:wipe`, load `database/db.sql`, `migrate`. If this step fails the instance is reset to a clean migrated +
   seeded state so the wizard works again (re-upload and retry);
4. move `files/private` / `files/public` into the storage disks;
5. blank every value in `config('backup.encrypted_columns')` that this instance's `APP_KEY` can't decrypt (all of
   them, unless the operator set the same `APP_KEY` as the old system before restoring) and list them for the admin;
6. clear machine-local rows pointing at files a backup never carries (`backups`, `device_releases` — re-upload
   firmware — and unfinished `print_jobs`), then set the setup-complete latch if an Administrator exists (if the
   backup has none the wizard stays open to create one).

Progress lives in `<RI_BACKUP_DIR>/restore/status.json` (the database is being replaced, so it can't live there);
the wizard polls `GET /install/restore/status`, which deliberately skips the session/CSRF/setup middleware.
There are no logins yet, so the gates are the wizard's own: the instance must still need setup, `RI_SETUP_CODE`
(if set) at the start, and the unguessable upload id for the chunk calls. As with the wizard itself, an
internet-exposed fresh instance should set `RI_SETUP_CODE` — a restore can install any administrator the backup holds.
Adding an encrypted model cast? Register it in `config/backup.php` (`encrypted_columns`) — a test enforces it.
