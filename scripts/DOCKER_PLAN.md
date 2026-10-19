# Docker distribution plan (x64 + arm64)

Status: **planned, not started** (assessed 2026-10-19). There is no Dockerfile or compose file in the repo yet.

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
   - headless Chromium for Browsershot (pallet labels, sitrep);
   - the `weasyprint` binary with Pango/Cairo (inventory reports, order forms/BOL).

   Expect an image of roughly 1–1.5 GB. Chromium on arm64: use Debian's `chromium` package (the
   Puppeteer-bundled download doesn't ship for arm64) and set `LARAVEL_PDF_CHROME_PATH` to it. Set
   `LARAVEL_PDF_NO_SANDBOX=true` inside the container. This turns the manual provisioning steps in CLAUDE.md
   into part of the image.
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

1. Multi-stage Dockerfile (composer → npm build → runtime on `php:8.4-fpm` with Chromium + weasyprint) and a
   compose file, x64 first.
2. Entrypoint, plus a PDF smoke test that renders each of the two drivers.
3. buildx multi-arch; test on the Pi.
4. Scheduler sidecar, backup guidance, install README, CI publishing.

Work on a branch and propose it as a PR, per the repo convention for larger changes.

## Related

- `rstation/IMPLEMENTATION.md` mentions a parked "dockerized local copy of the app" idea; it would reuse this image.
