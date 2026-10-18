# R Station implementation notes

Working notes (started 2026-10-08) on what the Relief Inventory R Station product needs beyond the
stock R Station platform. Companion to [PRINT_SERVER.md](PRINT_SERVER.md). Everything here lives
in `rstation/product` (or the web app), not in the `rstation/platform` submodule — except where a
note says the platform itself would need a change, which then goes upstream. Items are ideas and
decisions, not built, unless marked otherwise.

## Principle: the web app can't see the device, and the device can't trust the web app

Two facts shape most of this:

1. **The web app can't reach or see features on the local kiosk** — printers, the local settings
   shell, CUPS, Wi-Fi state. The device is behind NAT, and the server never pushes. Anything the
   web app needs to know about the device arrives via the heartbeat; anything it wants the device
   to do is picked up on the next heartbeat or polled from a queue (see the print queue design).
2. **The remote site can hang, or be down.** The kiosk's browser is pointed at a remote site
   (e.g. `/volunteers/kiosk`) and a stalled page, captive portal, or server outage must never
   strand the screen with no way back. The device has to be able to recover to a local page
   without help from the site it's showing.

## 1. "Escape hatch" key daemon (labwc)

A small daemon that plugs into the labwc compositor, listens for one or several designated
"special" keys, and returns the kiosk's browser to a local `http://localhost/` page (the
on-device settings shell/home) regardless of what the remote page is doing.

- **Why a compositor-level hook, not page JS:** a hung or crashed remote page can't be trusted to
  handle a key itself. labwc sees the key first.
- **Mechanism (to investigate):** labwc supports global keybindings in its `rc.xml`
  (`<keyboard><keybind key="…"><action name="Execute" command="…"/>`), which can run a script on
  the chosen key. That script navigates the already-running Chromium back to localhost — the
  platform already has a precedent for driving Chromium out-of-band over the DevTools protocol
  (`cdp_navigate()` in the `slide-announcer-revelation-peer` hotfix script) — or restarts it
  against the local URL if the browser itself is wedged. A separate daemon is only needed if
  `rc.xml` keybindings prove too limited (e.g. for chords, long-press, or reading raw evdev
  keys); otherwise the keybind + a small script is enough.
- **Which keys:** configurable list. The platform's remote already reports Home as
  `BrowserHome` (keyCode 172) and has Back/Compose buttons (see `local-app/frontend/src/remoteNav.js`);
  a physical-keyboard fallback (e.g. a rarely-hit combo like Ctrl+Alt+Home) is needed for
  kiosks without the remote. The platform's Settings → Key Debug screen is the tool for finding
  what a given key actually reports.
- **Also consider:** an automatic watchdog (no page heartbeat for N seconds → bounce to the
  local page), in addition to the manual key, so an unattended device self-recovers.
- **Where it ships:** product `image/` seam (labwc config + script + any unit), since labwc's
  session is assembled by the platform's `kiosk-start.sh`. If labwc's `rc.xml` is platform-owned,
  the product may need an overlay hook from upstream — check `docs/PRODUCTS.md` first.

## 2. First-run wizard: choose the site URL before pairing

Today the platform reads `server_url` from `/boot/firmware/slideannouncer.yaml`
(`local-app/backend/pairing.py: read_server_url()`), and a blank/missing value is a hard failure
for pairing, heartbeat and updates. For Relief Inventory we want one kiosk image to work for any
of several sites, chosen at setup time on the device itself.

- **Built (2026-10-18):** a "Server" wizard step (`product/frontend/SetupServer.vue`, between
  Network and Name, supplied through the platform's new `setupSteps` seam) pre-fills the boot
  yaml's `server_url` if there is one, checks the address with `{server_url}/up` (with a "use it
  anyway" option when the site is merely down), and saves it to `/data/status/server-url`. The
  platform's `read_server_url()` and the update CLI prefer that file, so pairing, heartbeat, OTA
  and print jobs all follow it. Design, rules and what changed in the platform:
  `platform/docs/SERVER_URL.md` (platform branch `server-url-first-run`).
- **No switching, by decision:** once the device is paired or set up the address is fixed;
  moving to another site is a factory reset (which wipes `/data` and re-runs the wizard,
  offering the yaml's value again). The yaml's comment says editing it does not move a set-up
  device. An unpair or revocation keeps the chosen address. (Dropped: an in-place "change site"
  and a remembered list of recent sites.)
- **Nothing baked into the image:** `product.env` no longer sets `KIOSK_URL`, so Chromium
  always starts on the local app (which runs the wizard on a fresh device and then hands the
  browser to the site — `product/frontend/Main.vue`); the build seeds the default only into the
  boot partition's yaml, not the rootfs.
- **Pairing is per site:** the 6-digit code comes from that site's `/setup/devices` page, so the
  URL has to be set first; the device's `device_uuid` stays the same across sites (a different
  site just sees a new device).
- **Kiosk URL follows the site:** the browser's target page is `server_url` + the heartbeat's
  `kiosk_path` (see `config/devices.php`). Today `Main.vue` navigates to `server_url` + `/login`;
  reading the heartbeat-cached `kiosk_path` instead, and falling back to the local settings home
  when the site is unreachable, is still to do (ties into §1).

## 3. Heartbeat extensions the product needs

- Report CUPS queues and their state (`print_printers`) — see PRINT_SERVER.md.
- Optionally report the currently shown page/health, to support the watchdog in §1 and give
  `/setup/devices` something better than "online/offline" to show.
- Act on `kiosk_function` / `kiosk_path` from the response via the product's
  `on_heartbeat_response`.

## Idea (not planned): a local, dockerized copy of the whole app

A possible future direction: package the entire Relief Inventory web app (Laravel + MariaDB +
queue worker) as a Docker image/compose stack that can run **locally on the Pi** itself, so a
site with poor or no connectivity runs fully offline, with the kiosk pointed at `localhost`
instead of a remote server.

Open points if this is ever pursued: the Pi's resource limits (RAM/SD wear for MariaDB); how
the local database syncs back with a central site (the app has no sync/merge story today — a
large design problem, not a packaging one); how updates/migrations ship (the platform's OTA
tiers are OS image and local app, not containers); and backups. It would also change the device's
role from "thin client of a remote site" to "its own site," which touches pairing and the
fleet-management model. Noted here only so the idea isn't lost.

## Open questions

1. Does the platform let a product add labwc keybindings (an `rc.xml` overlay), or does that
   need an upstream hook?
2. Where does a runtime-chosen `server_url` live so the platform's backend honors it without a
   reflash — and does that need an upstream change?
3. Which physical key(s) on the target keyboards/remotes should be the escape hatch, and should
   there be an automatic watchdog as well?
4. How does a kiosk-mode browser authenticate against the remote site's pages (today
   `/volunteers/kiosk` and `/donation-sorting` need a login or a kiosk-mode trusted device)?
