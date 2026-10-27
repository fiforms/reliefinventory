# Running Relief Inventory in Docker

One image (`linux/amd64` and `linux/arm64`) runs every role; the compose file wires them to MariaDB.
Nothing else is needed on the host — WeasyPrint (for PDF rendering), fonts and PHP are all in the image.

> **On a Windows PC or a Mac without Docker Desktop?** [RI Launcher](../launcher/README.md) installs and runs this same
> stack with no terminal: Docker Engine inside a small private Linux layer (WSL 2 on Windows, a Lima VM on macOS).

## Quick start

You only need `compose.yaml` (it is self-contained; grab it from this directory, or clone the repo).

1. **Set `APP_URL`** in the "YOUR SETTINGS" block at the top of `compose.yaml` — the address people will type into
   a browser. Everything else has a working default.
2. `docker compose up -d`
3. **Open `APP_URL` in a browser.** A first-run setup wizard asks for the administrator's name, email and password,
   and whether to load example data. Then you're signed in and ready to go.

On first start the container also generates the database passwords (random, kept in the `secrets` volume — you never
need them), creates the database, runs the migrations, seeds the essential lookup data (statuses, units, categories,
the item catalog, permissions) and generates the application key (kept in the `storage` volume). All of it is
skipped on later starts. The wizard closes for good once it has been used.

**Reachable from the internet before you've finished the wizard?** Whoever opens the page first can become the
administrator, as with any "first visitor sets it up" installer. Either finish setup before exposing the server,
or uncomment `RI_SETUP_CODE` in the settings block so completing the wizard also requires that code.

**Prefer no wizard (unattended installs)?** Uncomment `RI_ADMIN_EMAIL` (and optionally `RI_ADMIN_PASSWORD`) in the
settings block. The administrator is then created on first start; if you don't set a password, a random one is
printed once in the log: `docker compose logs app | grep -A1 -i "generated password"`.

Want to choose the database passwords yourself? Uncomment `DB_PASSWORD` / `DB_ROOT_PASSWORD` in the settings block
**before the first start only**.

**Bot check on sign-up (Cloudflare Turnstile).** The app protects `/register` and password reset with Cloudflare Turnstile,
which needs Internet access and free keys from Cloudflare. The settings block ships with `CLOUDFLARE_TURNSTILE_ENABLED:
"false"` so those pages work out of the box. To turn it on, set it to `"true"` and fill in `CLOUDFLARE_TURNSTILE_SITE_KEY`
and `CLOUDFLARE_TURNSTILE_SECRET_KEY`, then `docker compose up -d`. Enabled without both keys, those pages show a "this page
isn't set up yet" notice (HTTP 503) naming the settings to change, and the log records
`Cloudflare Turnstile is enabled but ... not both set`. Keep the values quoted. Turn it on before exposing `/register` to the internet.

To create more administrators or reset a password later, use the app's user administration, or:

```bash
docker compose exec app ri-entrypoint artisan user:update-password --email=you@example.com
docker compose exec app ri-entrypoint artisan user:promote --email=you@example.com
```

Example data can also be loaded later from the command line (**never** on a real instance):
`docker compose exec app ri-entrypoint artisan db:seed --class=WarehouseActivitySeeder`

## What runs

| Service     | Role                                                                                   |
|-------------|----------------------------------------------------------------------------------------|
| `init`      | One-shot: generates the database passwords the first time, then exits                  |
| `db`        | MariaDB 11, data in the `db-data` volume                                               |
| `app`       | Web server (FrankenPHP) on port 8080; migrates/seeds on start                          |
| `queue`     | Queue worker (same image)                                                              |
| `scheduler` | Hourly volunteer-window sync and a daily database dump to `./backups` (same image)     |
| `reverb`    | Optional realtime (`docker compose --profile realtime up -d`) — see below              |

## HTTPS

The container speaks plain HTTP on 8080 and expects a reverse proxy (Caddy, nginx, Traefik, your cloud's load
balancer) to provide TLS. Set `APP_URL: https://...` and `TRUSTED_PROXIES` to the proxy's address (or `*` if only
the proxy can reach the app port) so generated links and redirects use `https`.

## Data and backups

State lives in three named volumes:

- `db-data` — the database.
- `secrets` — the generated database passwords. **Keep it together with `db-data`**: the database was initialised
  with those values, so deleting one without the other leaves the app unable to connect (`docker compose down -v`
  removes all of them, i.e. a clean slate).
- `storage` — uploads (donation photos, feedback screenshots), print-queue PDFs, logs, and the generated app key.

The scheduler writes a backup **.zip** daily into `./backups` (next to `compose.yaml`), keeping
`RI_BACKUP_KEEP_DAYS` days. (The container takes ownership of that directory so it can write there.) It holds the
database only unless you set `RI_BACKUP_SCOPE: full`, which adds the uploaded files — **otherwise back up the `storage`
volume too** if you rely on the uploaded photos, and copy backups off the machine. They are the same zips an admin
makes on demand from **Updates & Backups** in the app (listed there, downloadable, never containing the application key —
see `scripts/BACKUPS.md`).

> **Retention is flat here.** Backups are kept `RI_BACKUP_KEEP_DAYS` days and then deleted. The daily / monthly / yearly
> tiers a standard (systemd) server install keeps are not implemented for containers or the R Station local server yet —
> copy a backup off the machine if you want to keep it longer.

Each warehouse has its own encryption key, kept in the `warehouse-secrets` volume (never in a backup). Keep that
volume with `db-data` and `storage`, and write the key down (Setup > Updates & Backups shows it): restoring a database
without its key makes its encrypted values unreadable. The instance's own `APP_KEY` (generated into the `storage`
volume unless you set it) protects cookies and account emails; losing it only logs everyone out. An install upgraded
from before warehouses had their own keys gives its warehouse the old `APP_KEY` and gets a new one, once (everyone logs
in again). If you set `APP_KEY` in this file, change it yourself afterwards; the app log says so.

**Restoring** one onto a brand-new install: open the new server, choose **Restore from a backup** on the first-run
screen, and upload the zip. The new install must be the same version as the one that made the backup, or newer. If you
want the encrypted settings (the mail passwords, and the instance's federation key — without it every paired instance and client must approve this one again) to survive, set `APP_KEY` in `compose.yaml` to the old
warehouse's key *before* the first start (the first warehouse takes its key from there); otherwise they are cleared and
listed for you to re-enter. To recover a damaged
database, the restore screen also takes a second zip that supplies just the uploaded files (any database inside it is
ignored); on a system that is already set up, an administrator runs
`docker compose exec app php artisan backup:restore backups/<database zip> --storage=backups/<files zip> --force`.

## Upgrading

```bash
docker compose pull
docker compose up -d      # migrations run automatically when `app` starts
```

`compose.yaml` follows `:latest`, which is the newest **published release** (a GitHub Release), not whatever was
last merged — new work only reaches your server once it has been released. Release notes say what changed and whether
the upgrade migrates the database. Only the newest release is supported, so we recommend staying on `:latest`. You can
pin one release (`:1.4.0`) to delay an upgrade briefly, but not to stay on an old version long-term. `:edge` is an
unreleased development build for the test instance; don't point real data at it.

If the new image has pending migrations, the `app` container backs up the database to `backups/` first
(`relief-backup-<timestamp>-database-premigrate.zip`) and only then migrates. **If that backup fails, it does not migrate** —
the container exits with a message in `docker compose logs app` (usually the backup directory isn't writable). Fix that
and it retries on restart, or set `RI_PREMIGRATE_BACKUP: "false"` to migrate without a backup. A fresh install, or an
upgrade with no new migrations, takes no extra dump. Pre-migration backups are pruned with the daily ones
(`RI_BACKUP_KEEP_DAYS`). This covers the database only — see "Data and backups" for `storage`.

**Rolling back:** an old image can't run against a migrated schema (it doesn't contain the newer migrations' undo code).
Pin the previous version tag in `compose.yaml` (rather than `:latest`), restore the pre-migration dump (restore command
above), then `docker compose up -d`. Anything entered since the upgrade is lost with that restore.

**Downgrade guard:** if an older image is started on a database a newer one has already migrated (say, someone pins
`:1.3.0` after running `:1.4.0`), the `app` container refuses to start, changes nothing, and logs what to do (`docker compose
logs app`) — go back to `:latest`, or restore the pre-migration backup as above.

## Print servers and R Stations

They talk to the web app over HTTP exactly as they do on a bare-metal install; point them at `APP_URL`. They pair with a code from **Setup > Devices** and then authenticate with their own key (see `rstation/FEDERATION_AUTH.md`). The same goes for pairing this site with another one, in **Setup > Trust Console**: set the public address there first, since the other side records it.

## Realtime (optional)

Everything works without it (browsers and print servers poll). To enable Laravel Reverb, set
`BROADCAST_CONNECTION: reverb` and the `REVERB_*` values in the settings block (see `scripts/REVERB.md` for what
each means), start with `docker compose --profile realtime up -d`, and make your reverse proxy forward WebSocket
traffic to the `reverb` service (host port 8081). **Not yet tested in the container** — verify before relying on it.

## Testing with realistic data, and finding slow screens

To test the screens, reports and performance before you have real data, pick **Simulate a year of activity** in the
setup wizard (or, on an instance that is still empty, **Setup > Testing Tools > Start simulation**, or
`docker compose exec app php artisan simulate:warehouse`). It fills the instance with about a year of fictional
donations, sorting, requests and shipments (~1,200 requests, ~12,000 inventory movements, about a minute). It refuses to
run once the instance has any data, and simulated data can't be removed from the app — to start clean, remove the
volumes (`docker compose down -v`; this deletes everything). See `scripts/SIMULATOR.md`.

The stack also records what is slow. `RI_SLOW_QUERY_MS: 250` in the settings block makes the app log every database
query slower than that, and every page/endpoint that is slow, runs 60+ queries or sends 1 MB+; MariaDB's own slow log
(`--slow-query-log`, 0.25 s) is switched on in the `db` service and the app is granted read access to it at start-up.
Read both at **Setup > Testing Tools**, or `docker compose exec app php artisan slowlog:show` (`--requests` for whole
pages, `--recent` for newest first). The scheduler empties MariaDB's table when it passes 20,000 rows; the app's own log
is two files of at most 5 MB. Set `RI_SLOW_QUERY_MS: 0` to turn the app-side recording off.

## Not available in the container

The **System Administration** panel's *Install Update* and *Backup Now* buttons drive systemd on a bare-metal
install and do nothing useful here; upgrade by pulling a new image (above); the pre-migration and daily dumps are your backups.

## Building the image yourself

```bash
docker build -t reliefinventory .                                   # this machine's architecture
docker buildx build --platform linux/amd64,linux/arm64 -t reliefinventory .   # both (needs buildx)
docker compose -f docker/compose.yaml build                         # via compose
docker compose -f docker/compose.yaml up -d                         # (after a local build, edit `image:` or tag your build to match)
```

Environment variables of note (all optional): `RI_HTTP_PORT` (container port, default 8080),
`RI_DB_WAIT_SECONDS` (how long to wait for the database, default 120), `RI_DB_DUMP_ARGS` (extra `mariadb-dump`
flags; default `--skip-ssl` for the bundled database).
