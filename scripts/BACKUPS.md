# Backups: one format, one command, per-platform scheduling

Every backup in the app is a **zip with a manifest**, made by one command:

```bash
php artisan backup:create [--scope=database|full|storage] [--dest=DIR [--name=NAME]]
```

| scope | holds | used for |
|---|---|---|
| `database` (default) | the database dump | the bare-metal tiered backups, the Docker daily and pre-migration dumps, everyday backups |
| `full` | the dump **and** the uploaded files | "Database + uploaded files" on the admin page; `RI_BACKUP_SCOPE=full` in Docker |
| `storage` | the uploaded files only | disaster recovery — a good copy of the files to pair with a database zip. CLI only; the admin page doesn't offer it |

```
manifest.json         format (2), scope, app commit, migration list, size + SHA-256 of every entry
database/db.sql       plain mariadb-dump         (database, full)
files/private/...     storage/app/private        (full, storage)
files/public/...      storage/app/public         (full, storage)
```

`DatabaseTools` (`app/Services`) is the one place the dump/load command lines (and their flags: `--single-transaction
--no-tablespaces --routines --triggers`, `--skip-ssl` for the MariaDB client) are built; `BackupService` is the one
place a zip is written. Nothing else — not `update.sh`, not the Docker scripts — carries its own `mysqldump`.

- **A truncated dump is never archived.** `mariadb-dump` ends a finished dump with `-- Dump completed on <time>`
  (`DatabaseTools::dumpIsComplete()`). `BackupService` checks the exit status, a non-empty file *and* that marker before
  checksumming, and a restore checks it again before wiping anything — a cut-off dump checksums perfectly well, so the
  manifest alone couldn't catch it.
- **Never in a zip:** the application key (`APP_KEY`, `.env`, `storage/app/.app_key` — `BackupService` refuses those
  names even inside an included directory). What depends on `APP_KEY`: values encrypted in the database (the mail
  passwords and **this instance's federation private key** — without
  it the instance has no identity until a new key is generated in the Trust Console, and every peer must re-approve it)
  and login/remember cookies. Passwords and PINs are bcrypt, unaffected.
  Admins with `view-app-key` can reveal it on the admin page (password re-entry, throttled, logged) to write it down.
  A restore without the key blanks anything encrypted with the old one. *(The tiered backups used to keep a copy of
  `.env` beside each dump; they no longer do — keep the key somewhere separate.)*
- **Uploaded files** = `storage/app/private` and `storage/app/public`, minus `config('backup.exclude_dirs')`:
  `print-jobs` (regenerated), `device-releases` and `device-uploads` (firmware — gigabytes, re-uploadable).
- **`--dest=DIR`** writes the zip into `DIR` with **no database record and no expiry** (the caller owns retention) and
  works with the database down — use it for scripts and disaster recovery. `--name` is the file name without `.zip`.
  Without `--dest` the zip goes to `config('backup.dir')` (`RI_BACKUP_DIR`, default `storage/backups`; `/backups` in
  Docker) with a `backups` row so the admin page lists it and it expires. If the row can't be written (database down, or
  a pre-migration backup before the table has this version's columns) the file is written anyway, unlisted.
  `backup.dir` is deliberately never under `storage/app`, so a backup can't contain earlier backups.
- **How the admin page runs one:** the request starts `php artisan backup:run <id>` detached (`setsid -f`), so it
  outlives the request and needs no queue worker. One at a time: the `start()` row check plus an `flock` on
  `<dir>/.backup.lock`, released by the kernel if the process dies; a row whose process vanished is marked failed on
  the next page load. Needs `setsid` (util-linux), PHP's zip support, and `mariadb-dump`/`mysqldump`.
- **Expiry** (the `backups`-table zips and anything else named `relief-backup-*.zip` in `backup.dir`): `RI_BACKUP_KEEP_DAYS`
  (default 14) after creation, deleted lazily when the admin page loads or a backup runs, hourly by the Docker scheduler
  (`backup:prune`), or individually by an admin. Files with that name and no row (pre-migration backups, leftovers after
  a restore replaced the table) go once they are that old; nothing else in the directory is touched.

> **The key is the one thing a backup does not carry.** The admin page shows a red notice above every backup section telling
> the administrator to write down the application key (shown in the panel at the bottom, to `view-app-key` holders) and keep it
> somewhere safe, separate from the backups: it is required to restore the encrypted parts of a backup.

## Updates & Backups — one admin page (`/setup/system`)

`SystemAdmin.vue` (`SystemController` + `BackupController`) replaces the old separate "Updates & Backups" and "Backup
Files" pages (`/setup/backups` redirects here). Each section needs its own permission, and only what the platform can do
is shown (`config('system.runtime')`: `systemd` or `docker`; the Dockerfile sets `RI_RUNTIME=docker`, `/.dockerenv` is the
fallback):

| section | permission | systemd (bare metal) | docker (incl. R Station local server) |
|---|---|---|---|
| Software update | `admin-system` | check for / install updates (`update.sh`) | version only; upgrade by pulling an image |
| Offline mode | `admin-system` | ✔ | ✔ |
| Create a backup + Backups list | `manage-backups` | ✔ | ✔ |
| Scheduled backups | `admin-system` | tiers, "Run a Scheduled Backup Now", schedule + retention form | the snapshot schedule (`manage-backups`, below) |
| Application key | `view-app-key` | ✔ | ✔ |

The systemd-only endpoints (`/json/system/update`, `check-updates`, `backup`, `backup-settings`) answer **409** in a
container, so hiding them in the page isn't the only guard. The page opens for `admin-system` **or** `manage-backups`;
the nav entry is still gated on `admin-system`.

## Bare metal (systemd): tiered scheduled backups

Made by `scripts/update.sh` (every full update backs up first; `--backup-only` and `--scheduled` back up without
updating). It runs `backup:create --scope=database --dest=<staging> --name=<stamp>` as the app user (so no root-owned files
appear under `storage/`), moves the zip beside the tiers (same filesystem — a rename) and files it under
`/var/backups/reliefinventory/`. The zips are `root:<app user>` mode `640` (and the tier directories `750`), re-applied on every
run: the **web user can read them**, which is what lets the admin page offer them for download
(`GET /json/backups/tier/{tier}/{stamp}/download`, permission `manage-backups`, logged — the same gate as an on-demand zip;
tier and stamp are matched against fixed patterns). Nobody else on the box can. A zip holds the data but never the application key:

```
hourly/<YYYYmmdd-HHMMSS>.zip   every backup: hourly, scheduled, or on-demand   keep KEEP_HOURLY (48)
daily/<stamp>.zip              that day's scheduled backup                     keep KEEP_DAILY   (14)
monthly/<stamp>.zip            first daily promotion of the month              keep KEEP_MONTHLY (12)
yearly/<stamp>.zip             first daily promotion of the year               keep KEEP_YEARLY  (3)
```

(Backups made before the zip format are `<stamp>/` directories holding `db.sql.gz` + `storage-app.tar.gz` + `env.backup`;
they stay listed and age out through the same tiers. Restore one by hand: `gunzip < db.sql.gz | mysql <db>`.)

`hourly/` is a flat, undeduplicated recent-backups tier — its job is to let you roll back to whichever attempt preceded a
bad one (three "Run a Scheduled Backup Now" clicks or update attempts in the same hour each get their own entry). Only the
once-a-day **scheduled** backup (never an on-demand one) is promoted into `daily/` — as a **hard link**, so promotion costs
no disk space — and only that promotion can promote into `monthly/`/`yearly/`. An on-demand backup can therefore never
crowd out the daily/monthly/yearly history (`promote()`/`prune_tier()` in `update.sh`; pruning is by file mtime, not name).

The stamp is generated in `BACKUP_TZ` (`TZ="$BACKUP_TZ" date ...`), not server-local time — the panel displays it verbatim.
Every backup is a full copy of the database, not incremental: simple to restore from any single file, which matters more here than disk space.
**The tiers hold the database only — uploaded files are not in them** (an update never touches uploads, so a rollback point
doesn't need them). Protect the files with a "Database + uploaded files" backup from the admin page (or
`backup:create --scope=full` from a cron/script), or back up `storage/app` at the filesystem level.

**Hourly backups (optional):** with `HOURLY_BACKUPS=1` each firing of the hourly timer also takes a backup if none has been
taken that hour (`.last-hourly`). These are ordinary `hourly/` entries, never promoted; `KEEP_HOURLY` decides how far back
they reach (48 = two days). Off by default.

**Schedule settings:** `storage/app/backup-settings.conf` (copy `scripts/backup-settings.conf.example` to start; keys and
valid values are documented there). Defaults: 2am `America/Los_Angeles`, daily, keeping 48/14/12/3. The timer
(`reliefinventory-backup.timer`) fires **hourly**; each firing runs `update.sh --scheduled`, which exits at once unless a
backup is due: at/past `BACKUP_HOUR` in `BACKUP_TZ` (past, not exactly at — downtime over the hour self-heals the same
day, helped by `Persistent=true`), the right `BACKUP_DOW` in weekly mode, and none recorded yet that day (`.last-scheduled`).
The panel rewrites the file (`www-data` owns it; no root, no systemd) and `update.sh` **never sources** it — each key is
extracted and checked against a strict pattern, bad values fall back to defaults.

"Run a Scheduled Backup Now" and "Install Update" start `reliefinventory-backup-now.service` / `reliefinventory-update.service`
through the sudoers rule in `scripts/systemd/reliefinventory-sudoers`, which allows exactly those two
`systemctl start --no-block` commands. The panel follows a running update through `storage/app/system-update-status.json`
(written by `update.sh`); during the maintenance window its polls get 503s and it shows that as progress, not an error.
Only one update or backup runs at a time on a server, across all its instances (`/var/lock/reliefinventory-update.lock`):
a run that finds it taken — e.g. updating two instances at once, or an update during another instance's hourly
backup — waits its turn (up to `LOCK_WAIT_SECONDS`, default 1 hour) and shows "Waiting for another update or backup on
this server to finish" in the panel meanwhile. Logs are `/var/log/reliefinventory/update-<instance>-<stamp>.log`.

### One-time install (per server)

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

After changing the unit files in the repo, re-copy them and `systemctl daemon-reload` (settings-file changes need nothing).
`journalctl -u reliefinventory-backup.service` shows scheduled-run history.

## Docker / R Station local server: per-warehouse snapshot tiers

The `scheduler` container (`docker/scheduler.sh` → `docker/db-backup.sh` → `warehouse:each "backup:scheduled"`) asks **every active warehouse
every hour** for a snapshot, and each warehouse's own schedule decides whether one is due (`App\Models\BackupSchedule::due()`). The schedule is the
single-row `backup_schedule` table, edited by `manage-backups` holders on **Warehouse Backups → Snapshot Schedule**:

| Tier | Made | Kept |
|---|---|---|
| hourly | any hour with none in the last ~50 minutes (off by default) | newest `hourly_keep` (24) |
| daily | first check after `daily_hour` each day | newest `daily_keep` (14) |
| weekly | on `weekly_dow`, or the next check once the last is over a week old (off by default) | newest `weekly_keep` (8) |
| monthly | first check of the month after `daily_hour` (off by default) | newest `monthly_keep` (12) |

One check makes **one zip**, tagged (`backups.tiers`, e.g. `hourly,daily,weekly`) with every tier it satisfies, so the Sunday zip is also the daily and
the weekly one. `BackupService::prune()` keeps a tiered zip while it is among the newest N of *any* tier it carries (N ≥ 1), so a weekly outlives the
daily window; turning a tier off never deletes what it made. Each tier can include the uploaded files (`*_with_files`; not hourly); a zip is `full` if any
tier it carries asks for files, otherwise database only. A failed attempt leaves its tiers due, so the next hour retries. Training warehouses and
warehouses mid-restore get none. Hand-made zips and the pre-migration backups keep the flat `RI_BACKUP_KEEP_DAYS` rule.

`RI_BACKUP_ENABLED=false` still stops every scheduled snapshot on the instance. `RI_BACKUP_HOUR`, `RI_BACKUP_SCOPE` and `RI_BACKUP_KEEP_DAYS` now
only seed a warehouse's schedule row when its table is created (an existing install keeps behaving as before: one daily backup); after that the
warehouse admin owns them. The `app` container still makes a `…-premigrate` zip before applying pending migrations (`RI_PREMIGRATE_BACKUP`; it
refuses to migrate if that fails).

**Rolling back** (`Roll back to this` on a row of the Backups list; `admin-system` + password, `BackupController::restoreSnapshot`,
`RestoreService::runSnapshot`): a detached `restore:run --snapshot=<file>` first saves a database zip of the *current* data in the same folder
(`…-before-rollback.zip`; if that fails nothing is touched), then restores the snapshot through the same engine as `/setup/restore` (lineage checked,
accounts relinked untrusted, files replaced when the snapshot has them, the person who ran it stays Administrator). Only a zip in this warehouse's own
backup folder can be named. The snapshot is never consumed. The restore replaces the `backups` table with the snapshot's, so the safety copy and any
newer zips are no longer *listed*; they stay on disk and `backup:prune` removes `relief-backup-*.zip` files with no record after `RI_BACKUP_KEEP_DAYS`.
Snapshots sit on the same machine: they guard against mistakes, not hardware loss — see the Launcher section for off-box copies.

### Whole-instance backups (Docker): schedule, list, back up now

**Instance Admin → Full Instance Backups** (`/setup/instance`, instance administrators; `InstanceBackupController`) is the schedule, the list, *Back up now*
and download for the one-zip backup of the control + accounts + every warehouse schema (`InstanceBackupService`, MULTI_WAREHOUSE.md §11). Only the
`provisioner` container holds the database administrator's password, so the web container never makes the zip: it writes a **fixed** request into
`provisioning_commands` (`backup-now`, `backup-now-files`; the scheduler writes `backup-sched`) — no warehouse, no parameters, no keys — and the provisioner
decides what to do (`Provisioner::run`). The schedule is the control table `instance_backup_schedule` (one row, **off until turned on**):

| | Made | Kept |
|---|---|---|
| daily | first check after `daily_hour`, once a day (database only unless `daily_with_files`) | newest `daily_keep` (7) |
| weekly | on `weekly_dow`, or the next check once the last is over a week old (off by default; `weekly_with_files`) | newest `weekly_keep` (4) |

The scheduler container runs `instance:backup --scheduled` every hour: it reads the row, and only when something is due asks the provisioner
(`SnapshotRules`, shared with the warehouse snapshots, decides). The provisioner re-checks, writes `relief-instance-<stamp>-<database|full>-scheduled-daily|weekly.zip`
into `<backup dir>/instance/`, records the outcome and the last *success* on the row (a failure leaves the backup due, so the next hour tries again), and prunes:
the newest `daily_keep` scheduled zips (a weekly one is also that day's daily) plus the newest `weekly_keep` weekly ones. Zips made by hand, before an upgrade
(`premigrate`, aged out after `RI_BACKUP_KEEP_DAYS`) or named by an operator are never pruned by the schedule. **Scheduled and requested zips never hold the
warehouse keys** (that needs a passphrase typed at the server: `instance:backup --files --with-keys`). The provisioner works on one request at a time, so creating a
warehouse waits while a big backup runs. The list is read from file names, so it works even when the web container can't read the zips (they are `0600`); the Download
button appears only for a zip it can read, and deleting one is done at the server. Every change on that page re-asks for the signed-in person's password and is
written to `instance_audit_log`.

## Off-site copies pulled by the RI Launcher (federation `backup_client`)

A desktop computer running the RI Launcher can keep its own scheduled copies of this server's backups, without anyone logging in to
download them. An administrator pairs it once in the **Trust Console** (`/setup/trust` → "Pair something new" → *Backup client (RI Launcher)*,
grant "Backup and reporting operator" = `operator.full_read`); the launcher then asks the server to make a backup
(`POST /api/federation/v1/backups`), downloads it resumably, checks its SHA-256 and keeps the newest N. Those backups are the same zips as the
admin page makes (and count as "one at a time" with them), they expire on the server after `RI_BACKUP_KEEP_DAYS` like any on-demand backup, and
every request and download is in the trust's audit log. Revoking the trust in the console stops it at once. Spec: `rstation/FEDERATION_AUTH.md`
("Backups"); the client: `launcher/README.md` ("Remote backups tab"). The zip never holds `APP_KEY`, but it does hold password hashes and
personal data, which is why the grant is rated high risk — pair only a computer you control.

**The whole instance** (every warehouse and the shared databases) is a separate pairing: an **instance administrator** makes a code on
**Instance Admin → Full Instance Backups → Pair a computer**, and the launcher pairs with it as *The whole instance* (`ri-launcher remote pair <address>
<code> --instance`). That is an **instance trust** in the control schema (`instance_trusts`, one permission `instance.backup`), independent of any
warehouse and of every warehouse's Trust Console; the same computer can hold both kinds, as separate entries with their own schedule and folder. The
endpoints are `/api/instance/v1/…` (`InstanceBackupApiController`, spec in `rstation/FEDERATION_AUTH.md`), and asking for a backup writes the fixed
`backup-now` request the provisioner answers. Pairings are listed and revoked on the same Instance Admin tab; every pairing, request and download is
in `instance_audit_log`. These zips never hold the warehouse keys, so keep those separately (see "Whole-instance backups" above).

## Restoring

**Onto a brand-new instance — the first-run wizard** (`/install` → "Restore from a backup"). Creating or emptying an instance
is deliberately not offered in the UI. The wizard uploads the zip in resumable chunks (the `ChunkedUpload` service the firmware
page also uses; `RI_RESTORE_CHUNK_BYTES`, `RI_RESTORE_MAX_BYTES`), then `POST /install/restore/launch` starts
`php artisan restore:run <id> [<files-id>]` as a detached process (`RestoreService`). It takes **one or two zips**:

- the **main** zip supplies the database (scope `database` or `full`) — and the files too, if it has them;
- an optional **files** zip (scope `storage` or `full`) supplies the files instead. If that zip also holds a database it is
  **ignored**, as are the main zip's files when a files zip is given. This is the recovery for a corrupted database: the latest
  good database zip plus the best copy of the files.

**On an instance that is already set up — the command line.** Same pipeline, zips on disk, nothing deleted from them:

```bash
php artisan backup:restore DATABASE.zip [--storage=FILES.zip] --force
php artisan backup:restore --storage=FILES.zip --force      # files only; the database is left alone
```

`--force` is required once the instance has been set up, and a database restore then **takes that warehouse out of service
first** (its status becomes `restoring`; the other warehouses keep serving, and Laravel's instance-wide maintenance mode is not used)
and puts it back after. If the database load fails, the warehouse is **left out of service** with a message: a wiped
database has no setup-complete record, so resetting it to a clean state (as the wizard does for a never-set-up instance)
would open the setup wizard to anyone. Run it as the app user (`runuser -u www-data -- php artisan …`) so files it writes
aren't root-owned. Files are laid over what is there; none are deleted.

### Starting over: `php artisan setup:reset`

If a forced restore fails and leaves the database empty or half-loaded (the warehouse is then out of service), or you simply
want a brand-new instance, `setup:reset` **erases the entire database** and rebuilds it empty (schema + essential lookup data, no user
accounts — only the built-in Unknown Donor placeholder — and no setup record). Uploaded files and backup zips on disk are not touched. Afterwards restore a backup with
`backup:restore` (no `--force` needed — the instance is new again) or set up from scratch in the browser.

It is built so it cannot be run by accident, from a script, or from something pasted off the Internet:

- it **only runs from a real, interactive terminal** — piped input, `--no-interaction`, cron, CI and `ssh host 'cmd'` without a
  TTY all stop at the first check, and there is **no `--force`/`--yes` option** to skip anything;
- it shows what is about to be lost (database name and host, row counts, the newest backup zips found) and then makes you
  **type `YES-DELETE-ALL-MY-DATA` yourself**, exactly (case-sensitive, nothing else accepted). Anything else changes nothing.

Afterwards, if the warehouse is still out of service, `php artisan warehouse:resume <id>` puts it back (`setup:reset` already does for the warehouse it reset) — and, if the server is reachable from the internet,
set `RI_SETUP_CODE` first, because the new instance's setup wizard is open to whoever reaches it. In Docker:
`docker compose exec app php artisan setup:reset` (`exec` gives it a terminal; `-T` or a redirected stdin will refuse).

The pipeline (`RestoreService`):

1. open every zip, read `manifest.json`, run `BackupCompatibility` (format, no app key, safe paths, zip == manifest,
   **migration list must not contain a migration this code lacks** — a backup from newer software is refused; an older one is
   fine, the restore ends by migrating up; skipped for a zip whose database isn't used), choose what comes from where, check
   disk space;
2. unpack only the entries needed to `<RI_BACKUP_DIR>/restore/<id>/work`, checking size and SHA-256 as it streams — **nothing
   on the instance has changed up to here**, so a damaged or hostile zip is harmless;
3. `db:wipe`, load `database/db.sql`, `migrate` (a never-set-up instance that fails here is reset to a clean migrated + seeded
   state so the wizard works again);
4. move `files/private` / `files/public` into the storage disks;
5. blank every value in `config('backup.encrypted_columns')` that this instance's `APP_KEY` can't decrypt;
6. drop rows that point at files this machine doesn't have (`backups` zips, `device_releases` firmware — re-upload — and
   unfinished `print_jobs`), then set the setup-complete latch if an Administrator exists (if the backup has none the wizard
   stays open to create one).

Progress lives in `<RI_BACKUP_DIR>/restore/status.json` (the database is being replaced, so it can't live there); the wizard
polls `GET /install/restore/status`, which deliberately skips the session/CSRF/setup middleware. There are no logins yet, so
the gates are the wizard's own: the instance must still need setup, `RI_SETUP_CODE` (if set) at the start, and the unguessable
upload id for the chunk calls. An internet-exposed fresh instance should set `RI_SETUP_CODE` — a restore can install any
administrator the backup holds. Adding an encrypted model cast? Register it in `config/backup.php` (`encrypted_columns`) — a
test enforces it.


### One folder per warehouse, one backup at a time

Each warehouse keeps its zips, its `backup.log` and its restore folder under `<backup dir>/warehouses/<id>/` (the existing warehouse's old zips move
there the first time `instance:migrate` runs). Backups on the instance run **one at a time**, whichever warehouse asked: a second waits for the first
(up to `RI_BACKUP_LOCK_WAIT` seconds, default an hour). Dumps keep the structure of the live-state tables (`sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches`: `config/backup.php`) but not their rows, and a restore empties them. In Docker the scheduler makes every active warehouse's daily backup
(`backup:scheduled`, once a day each); the pre-migration backup is still the default warehouse's (MULTI_WAREHOUSE.md §10).
