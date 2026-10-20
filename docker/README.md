# Running Relief Inventory in Docker

One image (`linux/amd64` and `linux/arm64`) runs every role; the compose file wires them to MariaDB.
Nothing else is needed on the host — WeasyPrint (for PDF rendering), fonts and PHP are all in the image.

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

The scheduler writes a gzip'd SQL dump daily into `./backups` (next to `compose.yaml`), keeping
`RI_BACKUP_KEEP_DAYS` days. (The container takes ownership of that directory so it can write there.) **That covers the database only** — back up the `storage` volume too if you rely on the
uploaded photos, and copy dumps off the machine. Restore a dump with:

```bash
gunzip -c backups/reliefinventory-YYYYMMDD-HHMMSS.sql.gz | docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
```

If you set `APP_KEY` yourself, keep it with the backups: restoring a database without its key makes encrypted
values unreadable.

## Upgrading

```bash
docker compose pull
docker compose up -d      # migrations run automatically when `app` starts
```

If the new image has pending migrations, the `app` container dumps the database to `backups/` first
(`reliefinventory-premigrate-<timestamp>.sql.gz`) and only then migrates. **If that dump fails, it does not migrate** —
the container exits with a message in `docker compose logs app` (usually the backup directory isn't writable). Fix that
and it retries on restart, or set `RI_PREMIGRATE_BACKUP: "false"` to migrate without a backup. A fresh install, or an
upgrade with no new migrations, takes no extra dump. Pre-migration dumps are pruned with the daily ones
(`RI_BACKUP_KEEP_DAYS`). This covers the database only — see "Data and backups" for `storage`.

**Rolling back:** an old image can't run against a migrated schema. Pin the previous version tag in `compose.yaml`
(rather than `:latest`), restore the pre-migration dump (restore command above), then `docker compose up -d`.

## Print servers and R Stations

They talk to the web app over HTTP exactly as they do on a bare-metal install; point them at `APP_URL`.

## Realtime (optional)

Everything works without it (browsers and print servers poll). To enable Laravel Reverb, set
`BROADCAST_CONNECTION: reverb` and the `REVERB_*` values in the settings block (see `scripts/REVERB.md` for what
each means), start with `docker compose --profile realtime up -d`, and make your reverse proxy forward WebSocket
traffic to the `reverb` service (host port 8081). **Not yet tested in the container** — verify before relying on it.

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
