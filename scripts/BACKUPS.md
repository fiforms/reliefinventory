# Backups: schedule, tiers, and admin-panel integration

Backups are made by `scripts/update.sh` (every full update backs up first; the
`--backup-only` and `--scheduled` flags back up without updating) and land under
`/var/backups/reliefinventory/`:

```
daily/<YYYYmmdd-HHMMSS>/     every backup           keep KEEP_DAILY   (14)
monthly/<stamp>/             first backup of month  keep KEEP_MONTHLY (12)
yearly/<stamp>/              first backup of year   keep KEEP_YEARLY  (3)
```

The stamp is generated in `BACKUP_TZ`, not server-local time (`TZ="$BACKUP_TZ" date
...` in `update.sh`) — the admin panel displays it verbatim, so it has to already be
in the configured timezone or the panel would silently mislabel every backup time.

Each backup contains `db.sql.gz` (integrity-checked mysqldump),
`storage-app.tar.gz` (uploads), `env.backup`, and `git-sha.txt` (the code
revision the data matched). Monthly/yearly copies are **hardlink promotions**
(`cp -al`) of that day's daily backup — promotion itself costs no disk space;
space is only held by however many distinct snapshots the tiers retain
(worst case 29 with the defaults).

## Schedule settings

Live settings file: `storage/app/backup-settings.conf` (copy
`scripts/backup-settings.conf.example` to start). Keys, defaults, and valid
values are documented in the example file; defaults are 2am
`America/Los_Angeles`, daily, keeping 14/12/3.

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
bash scripts/update.sh --backup-only          # back up right now
journalctl -u reliefinventory-backup.service  # scheduled-run history
```

Restore is manual and deliberate: `gunzip < db.sql.gz | mysql <db>` for data,
untar `storage-app.tar.gz` from the app root for uploads, and `git reset
--hard $(cat git-sha.txt)` + rebuild if the code must match the data.
