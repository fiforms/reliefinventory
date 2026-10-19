# Docker distribution plan (x64 + arm64)

Status: **built and tested on amd64 (2026-10-19), branch `docker-image`**; arm64 build verified under QEMU
emulation (see below); not yet run on real arm64 hardware or published. Files: `Dockerfile`, `.dockerignore`,
`docker/` (compose.yaml, README.md, entrypoint, scheduler, Caddyfile),
`.github/workflows/docker-amd64.yml` + `docker-arm64.yml` (manual-dispatch, one per architecture; no combined multi-arch manifest yet). User-facing docs: `docker/README.md`.

## What was actually built (differs slightly from the plan below)

- **Web server: FrankenPHP** (`dunglas/frankenphp`, Caddy + PHP in one process, classic mode) instead of
  php-fpm + nginx — one process, no supervisor. Plain HTTP on 8080; TLS is a reverse proxy's job.
- **PDF rendering: distro `weasyprint` from Debian trixie apt**, identical on both arches — the only PDF engine.
  The image originally also carried distro `chromium`, Node and puppeteer's `node_modules` (for Browsershot, driving
  the pallet labels, sitrep and help guide, via a `docker/chromium-wrapper.sh` that added `--no-sandbox
  --disable-dev-shm-usage --disable-gpu`); those documents were verified under WeasyPrint on 2026-10-19 and all of it
  was removed (Chromium ~380 MB + Node ~120 MB; measured total saving ~1.2 GB), along with the `pdf-node`/`node-bin` build stages.
- **One image, roles by first argument** (`web|queue|scheduler|reverb|artisan`); only `web` migrates, seeds,
  generates `APP_KEY` (kept in the storage volume if not supplied) and runs `admin:bootstrap`
  (new non-interactive command; `RI_ADMIN_EMAIL`/`RI_ADMIN_PASSWORD`, random password logged once if blank).
- **One config file, no secrets to invent**: operators edit only the settings block at the top of
  `docker/compose.yaml` (`APP_URL`, `RI_ADMIN_EMAIL`; the rest optional). A one-shot `init` service generates random
  DB passwords into a `secrets` volume that `db` (via `MARIADB_*_FILE`) and the app (via `DB_PASSWORD_FILE`) read;
  `secrets` must live and die with `db-data`.
- **Scheduler container** replaces the systemd timers: hourly `volunteers:sync-active-windows`, daily gzip'd
  `mariadb-dump` into `./backups` with retention.
- Image was ~2.9 GB uncompressed with Chromium (380 MB) and Node (120 MB); both are now gone: the amd64 image measures ~1.7 GB (rebuilt 2026-10-19; a PDF rendered inside the container). Python/WeasyPrint is 255 MB; the rest is fonts and FrankenPHP.

## Known gaps

- **System Administration panel**: "Install Update" / "Backup Now" / backup schedule shell out to systemd and git;
  they don't work in the container, and its version panel relies on `RI_GIT_COMMIT` rather than git. Needs a
  "managed by Docker" mode that hides them.
- Reverb (`--profile realtime`) is wired but untested in the container.
- Not yet run on real arm64 hardware (Pi) or in CI.
- Backups cover the database only; the `storage` volume (photos, print PDFs) is the operator's to back up.
- Print servers/R Stations pointing at a containerised instance untested end to end.


Goal: a multi-arch (linux/amd64 + linux/arm64) image and compose stack that someone else can run in a Docker
environment, without the manual server provisioning the app otherwise needs (see CLAUDE.md, "PDF / label
generation" and `scripts/REVERB.md`).

**Effort estimate:** roughly 2–4 days to a working multi-arch image and compose file; about another week to make
it something distributable to other people (entrypoint hardening, docs, CI publishing).

## Feasibility

Nothing in the app blocks this.

- Laravel 13 on PHP 8.4 with MariaDB. Queue, sessions and cache all use the `database` driver, so **no Redis is
  needed**. Broadcasting defaults to `log`; Reverb is optional.
- Everything is read from env via `config()`, so it is already `.env`-driven.
- The official `php` and `mariadb` images are multi-arch. PHP, Composer and Vite builds behave the same on both
  architectures, so `docker buildx build --platform linux/amd64,linux/arm64` on a multi-stage Dockerfile works.

## Recommended shape

A compose stack of separate containers, **not** one container holding both app and database (upgrades and backups
get much worse that way):

| Service   | What it is                                                                 |
|-----------|----------------------------------------------------------------------------|
| `app`     | php-fpm + nginx (or FrankenPHP/Caddy as a single process)                  |
| `queue`   | same image, running `queue:work`                                           |
| `db`      | official `mariadb` image, data on a volume                                 |
| `reverb`  | optional, behind a compose profile                                         |
| scheduler | small sidecar/loop container replacing the systemd timers (see below)      |

## The parts that take real work

1. **PDF generation (biggest item).** The image needs:
   - ~~headless Chromium for Browsershot (pallet labels, sitrep)~~ — dropped 2026-10-19, everything is WeasyPrint;
   - the `weasyprint` binary with Pango/Cairo (every PDF).

   (Original plan, for history: Chromium on arm64 needed Debian's `chromium` package, `LARAVEL_PDF_CHROME_PATH`
   and `LARAVEL_PDF_NO_SANDBOX=true`. None of that applies any more.) The image turns the manual provisioning
   step in CLAUDE.md into part of the image.
2. **First-run entrypoint.** Wait for the DB; generate `APP_KEY` if missing and persist it; run
   `migrate --force` and the essential seeders; `storage:link`. Provide a documented way to create the first
   admin (existing `php artisan user:create`).
3. **Persistence.** Volumes for the MariaDB data dir and `storage/app` (private: donation photos, feedback
   screenshots, print-queue PDFs).
4. **Scheduled work.** The backup timer (`scripts/BACKUPS.md`) and `volunteers:sync-active-windows`
   (`scripts/volunteer-sync.sh`) are systemd timers with no Docker equivalent. Replace with a scheduler
   sidecar or loop container; document backup guidance for the DB volume.
5. **Config hygiene.** A documented `.env`-driven compose file: secrets, `APP_URL`, trusted proxies.
6. **Distribution.** CI with buildx publishing to GHCR or Docker Hub. QEMU-emulated arm64 builds are slow;
   prefer native arm64 runners.

## Decisions / things to watch

- **Deploy model.** `scripts/update.sh` is git-pull based. In Docker, updates become "pull new image, restart,
  migrate on boot". Decide whether both workflows stay supported.
- **Reverb / print servers.** R Stations and print servers talk to the app over HTTP, which is fine. Realtime
  needs a WebSocket path through the recipient's reverse proxy — document it.
- **Frontend assets.** Build in a Node stage and copy `public/build` into the final image; no Node in runtime.
- **`.dockerignore`.** Exclude `node_modules`, `vendor`, `.git`, `rstation/` and `print-client/` (large and
  unrelated to the web image). Make sure no `.env` or demo data gets baked in.
- **Testing.** Pest can run in the build stage. PDF output on arm64 must be verified on real arm64 hardware at
  least once (the R Station Pi works for this).

## Suggested order

1. Multi-stage Dockerfile (composer → npm build → runtime on `php:8.4-fpm` with weasyprint) and a
   compose file, x64 first.
2. Entrypoint, plus a PDF smoke test that renders a document through weasyprint.
3. buildx multi-arch; test on the Pi.
4. Scheduler sidecar, backup guidance, install README, CI publishing.

Work on a branch and propose it as a PR, per the repo convention for larger changes.

## Related

- `rstation/IMPLEMENTATION.md` mentions a parked "dockerized local copy of the app" idea; it would reuse this image.
