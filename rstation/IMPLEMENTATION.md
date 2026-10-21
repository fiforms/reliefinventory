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

## 1. "Escape hatch" key daemon

**Built (2026-10-18), in the platform core rather than the product** (it is useful to any product
with a remote main screen): `platform/docs/ESCAPE_HATCH.md`. An evdev daemon (not a labwc
keybinding — it sees keys even when Chromium is hung) redirects the browser to `/settings` on
**Ctrl+Alt+Home**, **Ctrl+Alt+S** (keyboards without a Home key) or **holding the remote's Home
for 3 s**, via the reusable `slide-announcer-kiosk-navigate` (DevTools Protocol). If the browser
doesn't answer it restarts the kiosk and the launcher (`product/frontend/Main.vue`) opens
Settings once instead of going back to the site. The product opts in by listing
`slide-announcer-escape-hatch.service` in `product/image/enable`. Confirmed working on a real Pi
(2026-10-18). Still to do from the original idea: the automatic watchdog (no page heartbeat → bounce
to the local page), and a Chromium URL allow-list.

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
- **Kiosk URL follows the site** (built 2026-10-21, branch `device-session`): `Main.vue` asks the local backend
  for a browser ticket (`POST /api/local/browser-ticket`, which calls the site's `POST /api/devices/session-ticket`
  with the bearer token) and opens `{server}/device-session/{ticket}`; the server sets a device-session cookie and
  redirects to the page for the device's Function (`config/devices.php`: Sitrep display, Warehouse stats display,
  Volunteer check-in). No Function page, unpaired, or any failure → `{server}/login` as before. The page is chosen
  at launch, so a Function change shows after the next launcher run (reboot or escape-hatch return). Falling back to
  the local settings home when the site is unreachable is still to do (ties into §1).

## 3. Heartbeat extensions the product needs

- Report CUPS queues and their state (`print_printers`) — see PRINT_SERVER.md.
- Optionally report the currently shown page/health, to support the watchdog in §1 and give
  `/setup/devices` something better than "online/offline" to show.
- Act on `kiosk_function` / `kiosk_path` from the response via the product's
  `on_heartbeat_response`.

## 4. A local, dockerized copy of the whole app (built 2026-10-19, branch `rstation-local-server`)

Built as an **opt-in, standalone** mode — not a sync target: Docker plus the Relief Inventory images
(web app, queue, scheduler, MariaDB) ride in the image and run on the device from Settings > Local
server, for a site with no Internet. Design, rules and known gaps: [LOCAL_SERVER.md](LOCAL_SERVER.md).
Still open from the original idea: syncing a local site's data back to a central one (no merge story
exists in the app; export/import is the realistic first step), updating the containers independently of
the OS image, and making the device a first-class "its own site" in pairing and fleet management.

## Open questions

1. Does the platform let a product add labwc keybindings (an `rc.xml` overlay), or does that
   need an upstream hook?
2. Where does a runtime-chosen `server_url` live so the platform's backend honors it without a
   reflash — and does that need an upstream change?
3. Which physical key(s) on the target keyboards/remotes should be the escape hatch, and should
   there be an automatic watchdog as well?
4. How does a kiosk-mode browser authenticate against the remote site's pages (today
   `/volunteers/kiosk` and `/donation-sorting` need a login or a kiosk-mode trusted device)?
