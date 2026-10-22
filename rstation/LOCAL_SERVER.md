# Local server — a whole Relief Inventory site on the R Station

Built 2026-10-19 (branch `rstation-local-server`). **Status: built, unit-tested, and proven end to end on a real Raspberry Pi (2026-10-20, first image build) — see "Verified on hardware".**
The scenario: a disaster zone with no Internet. Someone plugs the R Station into an Ethernet switch or
a Wi-Fi hotspot, switches it on, opens Settings > Local server, presses *Start*, and a few minutes later
everyone on that network can use a full Relief Inventory site from a browser. Nothing leaves the device.

This is the Docker stack from `docker/compose.yaml` (web, queue, scheduler, MariaDB — see
`scripts/DOCKER_PLAN.md`) with its images baked into the OS image instead of pulled.

## Verified on hardware (2026-10-20)

On a real Raspberry Pi, from an image built with `build-offline-bundle.sh` on the first attempt: the first-run
wizard with `http://localhost:8080` as the server and pairing skipped; installing the self-hosted server from
Settings > Local server (first start took a few minutes, as the page says, and was acceptable); completing the server's own first-run setup and
logging in; pairing the R Station with its own self-hosted server; and printing to a real printer through the
station's own print server, with the whole loop on one device and no Internet. That covers the Docker engine and
unit overrides, loading the baked-in images into `/data/docker`, the supervisor, the compose stack and the launcher.
After one reboot the stack came back quickly on its own and the kiosk went straight to the login page with no browser
error (a single try so far, so the launcher's wait-for-`/up` path under a slower boot is not yet proven).
(Reported by the project owner; not scripted.)

## What ships where

| Piece | Where | Notes |
|---|---|---|
| Container engine + Compose | `product/image/packages` (`docker.io`, `docker-compose`, `curl`) | Debian trixie 26.1 / 2.26. Installed, but **never started** unless the feature is on (below). |
| Images + compose file | `rstation-offline-server_*.deb` → `/opt/relief-inventory/` (`images.tar`, `compose.yaml`, `images.env`, `manifest.json`) | Built by `build-offline-bundle.sh`, dropped in the gitignored `product/image/debs/`. A .deb is the platform's only seam for arbitrary root-filesystem files, so no platform change was needed. |
| Supervisor | `image/system/scripts/slide-announcer-local-server` + `slide-announcer-local-server.service` | Root. Loads images, runs `docker compose up`, writes `/run/slide-announcer/local-server/status.json`, stops gracefully. |
| Three action units | `slide-announcer-local-server-{enable,disable,reset}.service` | The only things the Settings backend can start (polkit allows `slide-announcer-*` units), so nothing it sends is ever parsed by root code. |
| Docker unit overrides | `image/system/docker.service`, `docker.socket` | Debian's units + `ConditionPathExists=/data/relief-inventory/enabled` and `--data-root=/data/docker`. |
| Backend | `product/backend/local_server.py`, `local_server_api.py` | `/api/local/local-server[/enable|disable|reset]`, loopback only. |
| Settings page | `product/frontend/LocalServerSettings.vue` | Start/stop, open, URLs for other devices, first-run code, backups, erase. |
| Launcher | `product/frontend/Main.vue` | If the confirmed server is `localhost`, waits for `/up` before navigating (the server needs a minute or two at boot). |

Building an image **without** the .deb is fine: the page says the feature isn't included and nothing else changes.

## Building it

```bash
rstation/build-offline-bundle.sh          # pulls the arm64 images, `docker save`s them (arm64 only), builds the .deb
rstation/fetch-cups-debs.sh               # (leaves the offline and SATO .debs alone)
rstation/build-sato-driver-deb.sh         # the SATO label printer driver (sato-driver/README.md); build it every image, like the CUPS debs
PRODUCT_ROOT=…/rstation/product image-builder/build.sh
```

`APP_IMAGE=ghcr.io/fiforms/reliefinventory:<tag>` pins a build. `DB_IMAGE` must match `docker/compose.yaml`.

## Where the data lives

Everything that matters is on `/data` (the root is a read-only overlay), and survives OTA updates:

- `/data/docker` — Docker's image store and engine state (the *loaded* copy of the images, ~1.6 GB; the
  compressed copy stays in the root filesystem so a reload is possible after a factory reset).
- `/data/relief-inventory/{db,storage,secrets,backups,state}` — MariaDB files, uploads/logs, generated DB
  passwords, the scheduler's nightly dumps (14 days), and supervisor state (first-run code, loaded image id).
  Bind mounts rather than named volumes, so one folder is the thing to copy or erase.
- `/data/relief-inventory/enabled` — the start-at-boot flag. Present ⇒ Docker and the supervisor start at boot.

These paths are deliberately **not** in the product's `wipe_paths`: un-pairing from a central site must never
erase warehouse records. A factory reset does erase `/data`, and with it the data — the page says so.

## Behaviour worth knowing

- **Off by default.** `docker.socket`/`docker.service` have a condition on the flag, so a station that never
  enables this runs no container engine (containerd still idles at a few MB).
- **First start** unpacks the images into `/data/docker` (several minutes on an SD card), refuses to start
  with under ~3 GB free (exit 3: not retried), then `compose up`; MariaDB init + migrations take a few
  more minutes. Later starts skip the unpack — unless an OS update shipped a different build under the same
  tag (compared by image id), which reloads, and the app container migrates the existing database on start.
- **Shutdown** stops the stack with a 60 s grace for MariaDB, then Docker; the unit is ordered after
  `docker.service` so the stop happens in the right order at power-off. An abrupt power cut is survived by
  InnoDB recovery but can lose the last moments of entry — use a battery pack.
- **First-run protection.** The site is on a shared network, so anyone could open `/install` first. The
  supervisor generates a random `RI_SETUP_CODE`; the Settings page shows it only while `/install` is still
  open (it asks the server, over loopback).
- **Memory.** MariaDB is capped (`innodb_buffer_pool_size=96M`, no performance schema, 40 connections). The
  page warns under 3 GB RAM. Measured idle on an amd64 host (compose file run against the real image, 2026-10-19): ~460 MB for all four containers (app 207, db 166, queue 73, scheduler 13); expect more under load and on arm64.
- **Names and addresses.** Nothing here does name resolution: the page relies on the device's existing mDNS name
  (`<hostname>.local`, the one used for SSH). The supervisor reads `hostname` each time it starts and passes
  `http://<hostname>.local:8080` to the stack as `APP_URL`, so a rename takes effect at the next stop/start (a reboot
  does it); the page warns while a rename is pending. The app builds its own links from the request host, so
  `APP_URL` only matters for emails and console output. The page lists the name first and the LAN IP addresses
  after (Docker's bridges filtered out), each with a **QR code** button (client-side, the `qrcode` package the
  platform frontend already ships) for phones. Some Android phones can't resolve `.local` in a browser, so the
  numeric address is the fallback — it can change if DHCP gives the device a different lease.
- **Home site.** "Use the local server as this device's site" writes `http://localhost:8080` as the device's confirmed
  server (`/data/status/server-url`), remembering the previous value in `/data/status/server-url-before-local` so
  "Go back" restores it. This deliberately bypasses the platform's lock on that address (SERVER_URL.md), because the
  alternative is a factory reset, and is refused while the device is **paired** (the pairing token belongs to the old
  site). The same panel takes an **external site's address** (checked with `GET {url}/up` like the wizard does, with "use anyway" for a site that is merely down) for a device that skipped the wizard's Server step or wants to move. Both are the same rule: changeable from Settings only while the device is **not paired**; once paired, the address is locked until the device is unpaired (Settings > Pairing; unpairing keeps the address) or factory reset. Changes take effect the next time the launcher runs (reboot, or "Open Relief Inventory"). Entering
  `http://localhost:8080` in the first-run wizard's Server step does the same thing, and leaving that step empty
  shows "Skip — set up later" (pairing is skippable too), so an offline device can finish the wizard with no site
  and choose this later; until then the launcher offers Settings.
- **Plain HTTP** on port 8080 on every interface, no TLS. Fine for a private relief LAN; don't expose it to the Internet.

## Known gaps / not done

- **Still unverified on hardware:** the Settings > Local server extras beyond the happy path (QR codes, setting an
  external site later, the erase/reset button, stop/start cycles, and start-at-boot beyond the single reboot noted below), an OTA update that ships a
  different image build (reload by image id), abrupt power loss, `.local` name resolution from phones and other
  clients on a customer network, and measured memory use on the Pi (the ~460 MB figure is from an amd64 host). Unit tests use a fake `docker`/`systemctl` (`backend/test_local_server.py`).
- **Image size.** The .deb is ~475 MB; it sits in both root slots and so **every OTA
  bundle grows by that much**. If that proves painful: ship the local-server image only in a separate
  "offline" build, or move the tarball out of the rootfs onto the boot/data partition.
- **No way to get data out** except copying `/data/relief-inventory/backups` off the card. A "copy backup to
  USB" button and an import path into a central site (there is no sync/merge story in the app) are the
  natural next steps. Backups are database-only unless `RI_BACKUP_SCOPE=full` is set in the stack's environment; uploaded
  photos are in `storage/`. **Retention is flat** (`RI_BACKUP_KEEP_DAYS`, 14 by default): the daily/monthly/yearly tiers of the
  bare-metal install are not implemented for the local server (TODO.md item 14) — a long-running site should copy backups off the card.
- **Printing from the local server works** (verified, below): the device pairs with its own server and prints through
  its own print server. Home on its own only moves the kiosk's screen; pairing is a separate step.
- No automatic update of the containers apart from the OS image; no Reverb (realtime) in this stack.
- The Updates & Backups page hides its systemd/git-only sections inside the container and shows the compose backup settings read-only (see `scripts/DOCKER_PLAN.md`).
