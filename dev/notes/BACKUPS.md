# Backups: one format, one command, per-platform scheduling

Every backup in the app is a **zip with a manifest**, made by one command:

```bash
php artisan backup:create [--scope=database|full|storage] [--dest=DIR [--name=NAME]]
```

| scope | holds | used for |
|---|---|---|
| `database` (default) | the database dump | scheduled snapshots, the pre-update and pre-migration backups, everyday backups |
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
| Software update | `admin-system` | check for / install updates (`update.sh`, deprecated) | version only; upgrade by pulling an image |
| Offline mode | `admin-system` | ✔ | ✔ |
| Create a backup + Backups list | `manage-backups` | ✔ | ✔ |
| Snapshot schedule + roll back | `manage-backups` (roll back: `admin-system`) | ✔ the same | ✔ |
| Application key | `view-app-key` | ✔ | ✔ |

The systemd-only update endpoints (`/json/system/update`, `check-updates`) answer **409** in a container, so hiding them in the page
isn't the only guard. (Backups are the same on both: the page has no per-platform backup section.) The page opens for `admin-system` **or** `manage-backups`;
the nav entry is still gated on `admin-system`.

## Bare metal (systemd): the same scheduler, started by a timer

A bare-metal install has **no backup logic of its own any more**. It uses the schedule, tiers, retention, form and roll-back described in
the next section; the only bare-metal piece is the thing that asks every hour:

- `reliefinventory-backup.timer` fires **hourly** (`Persistent=true`, so downtime over the hour is caught up) and runs
  `update.sh --scheduled`. That takes the server-wide lock (`/var/lock/reliefinventory-update.lock`, so a snapshot never runs in the middle
  of an update, or another instance's), then runs `warehouse:each "backup:scheduled"` and `warehouse:each "backup:prune"` as the app user and
  exits. No log file per tick (it fires 24 times a day and usually does nothing; `journalctl -u reliefinventory-backup.service` has it).
  `backup:scheduled` decides whether a snapshot is due from the warehouse's `backup_schedule` row — exactly as in Docker.
- **A full update (`update.sh`) and `--backup-only`** take an ordinary recorded backup first: `backup:create --scope=database` as the app
  user, the same as the admin page's *Back Up Now*. It is the roll-back point the failure message names, is listed on **Warehouse Backups**, and
  expires after `RI_BACKUP_KEEP_DAYS` like any backup made by hand.
- **Where zips go:** `backup.dir` (`RI_BACKUP_DIR`; default `storage/backups` under the app), in the warehouse's own folder
  `<dir>/warehouses/<id>/`, written by the app user. Set `RI_BACKUP_DIR` in `.env` to put them elsewhere (a bigger disk, a mount) — the folder
  must be writable by the app user. *Unlike the old tiers (root-owned, which the web app could only read), these can be deleted by anything
  running as the web user*; the roll-back safety net is only as safe as the web app, so keep an off-box copy too (the RI Launcher pulls them).
- **Schedule:** the **Snapshot Schedule** on `/setup/system` (`manage-backups`): hourly / daily / weekly / monthly tiers, how many of each to
  keep, and whether a tier includes the uploaded files. Hours are in the server's timezone (`APP_TIMEZONE`; the form says which).
  `RI_BACKUP_ENABLED=false` in `.env` stops every scheduled snapshot on the installation.
- **On-demand backups** are the admin page's *Back Up Now* (a detached `backup:run` as the web user). There is no `backup-now` systemd unit or
  sudoers rule any more; only `reliefinventory-update.service` (deprecated) is still started by the web app.

### Moving an existing bare-metal install (demo, wa26, …)

1. **Update as usual.** `migrate` creates `backup_schedule` and fills it from the old `storage/app/backup-settings.conf` (`LegacyBackupSettings`):
   daily → the daily tier; weekly → the weekly tier on `BACKUP_DOW`; `HOURLY_BACKUPS=1` → the hourly tier; the monthly tier it always made →
   monthly; the keep counts carry over (clamped to what the form allows). `BACKUP_HOUR` was in `BACKUP_TZ`; it is converted once into the
   server's timezone (so it will not follow daylight saving; adjust it in the form if you want a fixed local hour). **`KEEP_YEARLY` has no
   equivalent** (there is no yearly tier): keep up to 36 monthlies instead, or copy a yearly zip off the box yourself. Check the result on
   `/setup/system`.
2. **The timer needs no change**: the unit already runs `update.sh --scheduled`, which now delegates. (Re-copying
   `scripts/systemd/reliefinventory-backup.{service,timer}` only updates their descriptions.)
3. **Decide where new zips go.** To keep them beside the old tiers, set `RI_BACKUP_DIR=/var/backups/reliefinventory` (wa26:
   `…-wa26`) in `.env` and `chown www-data` that folder; they land in `<dir>/warehouses/<id>/`, next to (not in) `hourly/ daily/ monthly/ yearly/`.
4. **The old tier folders stay as they are**: no longer listed on the page, no longer pruned, nothing deletes them. Delete them when you no
   longer need them (they are plain zips; `backup:restore` still restores any of them).
5. Tidy up, none of it urgent: `systemctl disable --now` and remove `reliefinventory[-<instance>]-backup-now.service` and its line in
   `/etc/sudoers.d/reliefinventory`; `SYSTEM_BACKUP_NOW_UNIT` and `SYSTEM_BACKUP_DIR` in `.env` are ignored now; `backup-settings.conf` is
   no longer read (delete it after the migration has run).

### One-time install (per server)

```bash
cd /var/www/reliefinventory-demo
cp scripts/systemd/reliefinventory-backup.service \
   scripts/systemd/reliefinventory-backup.timer \
   scripts/systemd/reliefinventory-update.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now reliefinventory-backup.timer
systemctl list-timers reliefinventory-backup.timer   # verify next firing

# Sudoers rule so the admin panel can start the (deprecated) update unit (see file header)
cp scripts/systemd/reliefinventory-sudoers /etc/sudoers.d/reliefinventory
chmod 440 /etc/sudoers.d/reliefinventory
visudo -c
```

After changing the unit files in the repo, re-copy them and `systemctl daemon-reload`. The service's `ExecStart` names the app directory
(`/var/www/reliefinventory-demo`); a second instance on the same server has its own copy of both units with its own path.
The panel follows a running update through `storage/app/system-update-status.json` (written by `update.sh`); during the maintenance window its
polls get 503s and it shows that as progress, not an error. Updates and backups of every instance on a server take turns (the lock above, up to
`LOCK_WAIT_SECONDS`, default 1 hour). Update logs are `/var/log/reliefinventory/update-<instance>-<stamp>.log`.

## Snapshot tiers, per warehouse (Docker, and bare metal through its timer)

In Docker the `scheduler` container (`docker/scheduler.sh` → `docker/db-backup.sh` → `warehouse:each "backup:scheduled"`), and on bare metal the
systemd timer (`update.sh --scheduled`, above), asks **every active warehouse every hour** for a snapshot, and each warehouse's own schedule decides whether one is due (`App\Models\BackupSchedule::due()`). The schedule is the
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

### Recovery key: how a disaster restore gets the warehouse keys back

A warehouse key encrypts that warehouse's secret values (mail passwords, the federation private key; `config/backup.php` `encrypted_columns`) and lives in the
`warehouse-secrets` volume, **never in a warehouse backup**. A whole-instance zip needs them too, or a restore onto a new machine comes back with those values
unreadable. They can't go in plainly and an unattended backup has nobody to type a passphrase, so the instance has a **recovery key pair**
(`App\Support\RecoveryKey`, libsodium):

- The **public** half is kept on the instance (control table `instance_recovery_key`; or `RI_RECOVERY_PUBLIC_KEY` from an installer that made the pair itself).
  Every whole-instance backup, scheduled ones included, seals all the warehouse keys to it (a sealed box, `keys/sealed.bin` in the zip; the manifest names the
  key's fingerprint). Nothing on the server can open that entry back up: the unattended backup can lock, only the private half can unlock, so a compromised
  server or a stolen zip gives away nothing it didn't already.
- The **private** half is shown **once** and kept nowhere on the server (not in the database, the audit log, a file or a log). It is 32 random bytes in a form made to be printed
  and typed back: `RK1-` and eleven groups of five letters and digits, with check characters, so a typing mistake is caught before anything is tried
  (`O`/`I`/`L` are read as `0`/`1`). It is a generated key, not a passphrase, so there is nothing to guess.

**Making it** (an instance administrator; once per instance):
- **Web:** until it exists, every instance administrator sees a red banner on every page; it opens **Instance Admin → Full Instance Backups → Recovery key → Make the recovery
  key** (password re-entered). The key appears in a dialog that has **Print**, **Download as a file** and **Copy**, says *"Print this key and store it somewhere
  safe"*, and cannot be closed until the person ticks that they have stored it. This is the first-run path for Docker, the RI Launcher and the R Station: the first
  account made by the setup wizard is an instance administrator, so the banner is the first thing they see.
- **CLI:** `php artisan instance:recovery-key generate` (in the app or provisioner container) prints it with the same instructions and asks for the confirmation.
  `generate --json` prints `{private_key, public_key, fingerprint}` for an installer or the Launcher to show a person and then `acknowledge`. `keypair` makes a pair and
  stores nothing, for an installer that keeps the private half itself and hands the instance only `adopt --public-key=…` / `RI_RECOVERY_PUBLIC_KEY`, so the private
  key never touches the server at all. `status`, `verify` (check a printout against the instance's key, now rather than in a disaster) and `acknowledge` are the rest.
- **Not made yet:** `instance:status` says so, the provisioner logs a reminder at start, and every scheduled/requested backup's answer says its zips cannot restore the
  encrypted settings. A key shown but never confirmed stays flagged (if the page was closed, the private half is gone and a new one must be made: *Make a new recovery key*).

**Restoring:** `restore:instance <zip> --recovery-key-file <file>`: the file can be the downloaded one as it is (the key is found among the text), or type it at the
prompt. The zip names the fingerprint it needs, a wrong or mistyped key is refused **before anything on the instance is touched**, and a sealed zip with no way to open it is refused
too, unless you say `--without-keys` (each warehouse's encrypted settings are then entered again) or give `--keys-dir` (a copy of the old `warehouse-secrets` volume). The
restored instance keeps sealing to the same key: its public half comes back with the control schema, so the same printout keeps working.

**Replacing it** (*Make a new recovery key*, or `generate --replace`) seals later backups to the new key; backups made before still need the **old** printout, so keep it until they
are gone. If the private key leaks, anyone holding an older zip can open that zip's sealed keys: replace the key, and treat the warehouse keys themselves as exposed for those zips.
**Keep the printout away from the server and from the backups**: someone holding both can read those settings, which is the one thing the separation is for. Bare-metal installs
have no instance backups, so no recovery key.

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
(`premigrate`, aged out after `RI_BACKUP_KEEP_DAYS`) or named by an operator are never pruned by the schedule. **Scheduled and requested zips hold the
warehouse keys only sealed to the recovery key** (next section; the passphrase route, `instance:backup --files --with-keys`, still works too). The provisioner works on one request at a time, so creating a
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
in `instance_audit_log`. The warehouse keys in these zips are sealed to the recovery key (see "Recovery key" above), so the Launcher's copies carry them without being able to read them.

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
