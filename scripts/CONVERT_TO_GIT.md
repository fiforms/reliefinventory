# One-time conversion: rsync deploys → git checkout

The live server was originally deployed by copying files (later rsync), so the app
root has no `.git` directory. `scripts/update.sh` requires a real git checkout.
This is the one-time conversion, run once per server. Current server: alias
`demolinode`, app root `/var/www/reliefinventory-demo`.

All commands below run **as root on the server** unless prefixed with the
`www-data` wrapper shown in step 4. Total downtime is roughly the length of one
normal deploy.

## 0. Prerequisite (local machine)

Make sure the commit you want live — including the `scripts/` directory itself —
is pushed to GitHub. The conversion pulls from GitHub, not from your Mac.

## 1. Manual backup (the script isn't on the box yet)

```bash
cd /var/www/reliefinventory-demo
mkdir -p /var/backups/reliefinventory/pre-git-conversion
DBPASS="$(sed -n 's/^DB_PASSWORD=//p' .env)"
MYSQL_PWD="$DBPASS" mysqldump --single-transaction --routines --triggers \
    -u "$(sed -n 's/^DB_USERNAME=//p' .env)" "$(sed -n 's/^DB_DATABASE=//p' .env)" \
    | gzip > /var/backups/reliefinventory/pre-git-conversion/db.sql.gz
tar -czf /var/backups/reliefinventory/pre-git-conversion/storage-app.tar.gz storage/app
cp -p .env /var/backups/reliefinventory/pre-git-conversion/env.backup
chmod -R go-rwx /var/backups/reliefinventory/pre-git-conversion
```

## 2. Maintenance mode + install git

```bash
runuser -u www-data -- php8.4 artisan down --retry=60
command -v git || apt-get update && apt-get install -y git
```

## 3. Fix ownership once, everywhere

Years of root-run rsyncs left mixed uid 501 / root ownership. Git will run as
`www-data`, so the whole tree must belong to it:

```bash
chown -R www-data:www-data /var/www/reliefinventory-demo
```

## 4. Turn the app root into a checkout

Run every git command as `www-data` (matching ownership means no
`safe.directory` complaints, and files git writes stay correctly owned):

```bash
cd /var/www/reliefinventory-demo
alias asapp='runuser -u www-data -- env HOME=/var/www'
asapp git init -b master
asapp git remote add origin https://github.com/fiforms/reliefinventory.git
asapp git fetch origin
asapp git reset --hard origin/master
asapp git branch --set-upstream-to=origin/master master
```

`reset --hard` overwrites tracked files with the pushed versions. `.env`,
`storage/` data, `vendor/`, and `node_modules/` are gitignored and untouched.

## 5. Sweep out rsync-era strays

rsync never deleted files, so files removed from the repo over time may still be
on disk. Dry-run first and **read the list** — it should only contain old app
files you recognize as deleted:

```bash
asapp git clean -nd     # dry run: prints what WOULD be deleted
asapp git clean -fd     # actually delete, only after reviewing the list
```

`clean -fd` ignores gitignored paths, so `.env`, `storage/`, `vendor/`, and
`node_modules/` are safe. **Never add `-x`** — that flag deletes gitignored
files too, which here means the live `.env` and uploads.

`asapp git status` should now report a clean working tree.

## 6. Finish with the update script

The checkout brought `scripts/update.sh` with it. It re-runs a backup (fine),
then does the rebuild, migrate, cache, service-restart, health-check, and
`artisan up` sequence:

```bash
bash scripts/update.sh
```

## Afterwards

- Every future deploy is just: push to GitHub, then `bash scripts/update.sh` on
  the box. No more rsync — the recurring storage-ownership breakage was caused
  by rsync itself and goes away with it.
- Scheduled backups: see `scripts/BACKUPS.md` for the tiered
  (daily/monthly/yearly) retention scheme and the systemd timer install.
- If a deploy changed permissions/roles, use `bash scripts/update.sh
  --seed-permissions`. Never run plain `db:seed` on the live box.
