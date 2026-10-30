# RI Launcher

Installs and runs Relief Inventory on Windows, macOS and Linux **without Docker Desktop** — Docker Engine runs
inside a small Linux layer the launcher creates (a private Alpine WSL 2 distro on Windows; a private Alpine Lima VM on
macOS), or, on Linux, is simply the Docker the person already has. A window with buttons to install / start / stop / update, a **Run as a LAN service** switch, a **Copy
shareable link** button, an editable computer name, and backup tools. A separate program from `print-client/`, same
repo and the same Rust + Tauri v2 + Vue approach.

**Status (2026-10-25):** the core, CLI and Windows backend are built and were run end to end on a real Windows 11
machine (clean install through reboot to a LAN-reachable app, backup list/create/export/restore, local ↔ LAN switch,
host-name edit). The Tauri window (`src-tauri/`, `ui/`) compiles and its UI builds, but **has not been run yet** — the
first real run is the CI build (`.github/workflows/launcher-windows.yml`, manual, unsigned installer).

**macOS (2026-10-25):** the Lima backend (`lima.rs`) is built and was run end to end through the CLI on an Apple-silicon
MacBook Pro (macOS 26.6): clean install, start/stop, LAN and local-only, backups, update, uninstall with and without
keeping data (see "Proven on macOS"). A universal (arm64 + x86_64) unsigned `.dmg` builds locally;
`.github/workflows/launcher-macos.yml` (manual) builds the same in CI but has not been run yet. **The Mac window has not been run yet**, and nothing has run on an Intel Mac.
Plan, decisions and open questions: [`MAC_VERSION.md`](MAC_VERSION.md).

**Linux** (2026-10-25, `core/src/linux.rs`) is built and was run for real against Docker 29 on Ubuntu through the CLI (install, start,
LAN/local switch, backups create/export/restore, stop, update, uninstall keep/remove data); the window compiles there but has
not been run (`.github/workflows/launcher-linux.yml`, manual, builds the `.deb` and AppImage). See "Linux" below.

## Layout
- `core/` (`ri-launcher-core`, plain crate, `cargo test` runs everywhere) — `platform.rs` (the `Platform` trait: what a
  Linux layer must do), `wsl.rs` (Windows backend), `lima.rs` (macOS backend), `layer.rs` (what runs *inside* the Linux
  layer, shared by both: the Docker install, `/opt/ri`, compose commands), `service.rs` (`Launcher`: settings + compose + proxy + backend),
  `settings.rs`, `compose.rs`, `network.rs`, `proxy.rs`, `backups.rs`, `status.rs`, `download.rs`, `runner.rs`,
  `elevate.rs`, `linux.rs` (Linux backend). `bin/ri-launcher.rs` is a full command-line front end (`ri-launcher --help`) — useful for testing and
  for driving a remote machine.
- `src-tauri/` (Tauri shell: commands, tray, start-at-login, single instance, the elevated `--install` pass) and
  `ui/` (Vue). Not a workspace member (own `Cargo.lock`), like print-client.
- Tests: `cd launcher && cargo test` (both backends' command sequences run against a fake `Runner`, on any OS).
  `compose::tests::bundled_template_still_renders` fails if someone edits `docker/compose.yaml` in a way the launcher
  can no longer patch.

## How it works
- **Install (Windows)**, every step idempotent and safe to re-run: enable the `Microsoft-Windows-Subsystem-Linux` and
  `VirtualMachinePlatform` features with DISM (needs a restart → status "restart needed", a RunOnce entry opens the
  launcher after sign-in); install WSL from its own MSI (pinned version + SHA-256, `wsl.rs::pins`); `wsl --import
  ReliefInventory` from a pinned Alpine rootfs; `apk add docker docker-cli-compose`; add a Windows Firewall rule
  (private + domain) for the launcher program; write the compose file; `docker compose pull`. Needs administrator
  rights, so the window relaunches itself elevated with `--install` and **follows progress through
  `install-status.json`** in the data dir (`%LOCALAPPDATA%\ReliefInventory`).
- **Start:** `dockerd` is launched as a long-lived hidden `wsl.exe` — that process is the keep-alive (WSL stops an idle
  distro) — then the compose file is rewritten and `docker compose up -d`. **Stop:** `compose stop`, then
  `wsl --terminate`.
- **The compose file is regenerated from `settings.json` on every start** (`compose.rs`): `APP_URL` becomes the address
  people type, the app's `8080:8080` mapping becomes `18080:8080`, an optional timezone is switched on, and the app image tag follows the Software channel setting (`latest` = official releases, `edge` = unreleased builds for developers/testers; changing it pulls the new tag and restarts). Nothing else
  is edited, so the YAML inside the Linux layer is never the source of truth.
- **LAN service vs local only** (`Settings.mode`): the launcher runs a small TCP proxy (`proxy.rs`) that owns the public
  port (default 8080) and forwards to `127.0.0.1:18080`, where WSL forwards the app. LAN = listens on every interface;
  Local = 127.0.0.1 only. A busy port is reported at Start. The proxy lives in the launcher process, so **the launcher must
  stay running (tray) for others to connect**; reopening it while the app is up re-adopts the proxy (`Launcher::adopt`).
  Settings changed from another process (e.g. `ri-launcher set-mode`) are picked up by a running `ri-launcher run`
  (`follow_settings`).
- **Shareable link:** `<name>.local:<port>` — the name is the one set in the window, else this computer's name + `.local`
  (mDNS: Windows 10+, macOS, iOS, most Android, no router setup; verified to resolve from Linux too). The window also
  lists each LAN IP as a fallback. Virtual adapters (WSL, Docker, Hyper-V, VMs) are filtered out of that list.
  Local-only mode has no link (the button refuses rather than hand out one that can't work).
- **Backups** (`backups.rs`, same files the app makes in `/opt/ri/backups` inside the layer): list, "Back up now"
  (`php artisan backup:create`), **Save a copy** (`Platform::pull` — on Windows through `\\wsl.localhost\ReliefInventory\…`,
  on a Mac staged through the VM's `/tmp` and `limactl copy` — to Documents/Relief Inventory Backups, marking the copy
  **read-only**), and **Restore from file** (refuses non-zips,
  takes a safety backup first and stops if that fails, then `backup:restore … --force --no-interaction`; the window's own
  confirmation replaces the command's prompt). The encryption key (`APP_KEY`) is not in a backup — see docker/README.md.

## Remote backups tab (`core/src/remote/`, `ui/src/RemoteBackups.vue`) — 2026-10-26
Pairs this computer with *another* Relief Inventory server and keeps copies of its backups here, on a schedule. It uses the server's
federation trust system (`rstation/FEDERATION_AUTH.md`): an administrator makes a pairing code in the server's **Trust Console** (kind
**Backup client (RI Launcher)**, grant "Backup and reporting operator"), you type its address and the code here, and from then on this computer —
and nothing else — may ask that server to make a backup and download it. Nothing here needs Docker or a local Relief Inventory.
- **Warehouse or whole instance:** pairing asks which kind the code is for. *One warehouse* (the Trust Console's *Backup client*) backs up that warehouse;
  *The whole instance* (Instance Admin → Full Instance Backups → *Pair a computer*, `ri-launcher remote pair <address> <code> --instance`) backs up every
  warehouse and the shared databases (`/api/instance/v1`, `instance.backup`; it never includes warehouse keys). The same address can be both, as two entries
  with their own schedule and folder; instance copies are named `relief-instance-….zip`.
- **Schedule** per server: off, hourly, daily or weekly at a local time; what to copy (database, or database + uploaded files); how many copies to keep
  (older ones are deleted after each good backup); and which folder (default `remote-backups/<id>` in the data dir — pick a USB drive if you like).
  A backup runs as soon as a schedule is turned on; a computer that was off at the scheduled time catches up when it starts (once, not once per missed
  slot); a failure is retried after 10 min, 20, 40 … up to 2 h. It only runs while the launcher is running (tray); with a schedule active, closing the
  window doesn't quit it.
- **Each run:** ask the server for a new backup → wait → download (resumes after a dropped connection) → check the SHA-256 the server reports → save as a
  read-only `relief-backup-….zip` → prune. A file never appears under its real name until its checksum matched; the partial one is `<name>.part`.
- **Security:** the key pair is made on first use (`federation-identity.json`, 0600) and only the public half is sent. Requests are signed; plain `http://`
  is accepted only for this computer or its own network. If an administrator removes the trust (or the server regenerates its key), the next call is a 401
  and the server shows **Needs pairing again** — settings and copies are kept; pair again with a new code.
- **CLI:** `ri-launcher remote pair <address> <code>`, `list`, `set <id> --every daily --at 02:00 --keep 14 --scope full --folder /mnt/usb`, `backup <id>`,
  `files <id>`, `remove <id> [--delete-files]`, `remote run` (the schedule in the foreground; `ri-launcher run` also runs it).
- **Tests:** `cargo test` (schedule maths incl. a daylight-saving gap, the run sequence against a fake server, config), `core/tests/download_resume.rs`
  (the real client over HTTP: a cut connection resumes with `Range`, a server that ignores `Range`, a 416), and `launcher/e2e/run.sh` (the CLI against the real
  Laravel app on the disposable test database: a real dump downloaded and compared byte for byte, retention, revoke, re-pair). Browser mocks:
  `?mock=remote|remote-running|remote-empty|remote-unpaired`. The Tauri window itself has not been run.
- **Later:** reports over the same trust (`operator.full_read` already covers them), restoring a downloaded copy from this tab, tiered retention.

## R Station SD card tab (`core/src/sdimage.rs`, `ui/src/RStationImage.vue`) — 2026-10-25
A second tab downloads the R Station Raspberry Pi image and opens **Raspberry Pi Imager** with it chosen (`rpi-imager <file>`); the
launcher never writes to a drive itself. **Chain of trust:** the only address built in is `MANIFEST_URL`, the `rstation-image.json` asset
of the fixed `rstation-image` GitHub release; the manifest holds the image's size, SHA-256 and https URL(s) (Nextcloud), so a new image
needs no launcher release — publish with `npm run publish:rstation-image -- <url> --name <file>`. The manifest is untrusted until
`Manifest::parse` passes (safe file name, https only, 64-hex hash, sane size, `schema` ≤ 1; `min_launcher_version` gates old launchers).
The download resumes a `.part` file with a Range request (hashing the existing bytes first), falls back to a full restart if the server
ignores Range, stops at the manifest's size, discards on a hash mismatch and writes a `.verified` marker only after a match; old images
are deleted. Files live in `<data dir>/images`; the last manifest is cached there for offline use. CLI: `ri-launcher image info|download|verify|write`;
`RI_IMAGE_MANIFEST_URL` overrides the manifest address to test one before publishing. Browser mock states: `?mock=image-ready|image-offline|image-old|image-noimager|image-error`.
Run for real on Linux (real manifest, interrupted + resumed 1.15 GB download, verified); the window tab and launching Imager from it
have not been run on any OS, and nothing here has run on Windows or macOS (Imager's install paths there are unchecked).

## Linux (`linux.rs`)
No VM and no admin step: `LinuxBackend` drives the host's own Docker with `docker compose`, the same stack, proxy, share link and
backup tools. The launcher **never runs `sudo`**: if Docker is missing, not running, or the user isn't in the `docker` group, the
install card shows what to run for their distribution (`install_hint`, from `/etc/os-release`) and a **Check again** button
(`get_preflight`). Things that aren't obvious:
- **Its own compose project, `ri-launcher`** (written into the generated file's `name:` line and passed as `--project-name`). The shipped
  file says `name: reliefinventory`, which a developer's own checkout on the same Docker may already own; without this, a plain
  `docker compose exec` from our folder acted on *their* containers, and a full uninstall (`down --volumes`) would have deleted
  their data. Found the hard way in the first real run — don't remove it. `compose::StackOptions` carries it (Windows leaves the name alone, because
  changing it there would orphan existing installs' volumes).
- **The internal port is bound to `127.0.0.1`** (`127.0.0.1:18080:8080`): on a Linux host a bare `18080:8080` listens on every interface and would
  let anyone on the network bypass the proxy and the Local-only switch. Docker's published ports also bypass `ufw`.
- Files: `~/.local/share/ReliefInventory/stack/` holds `compose.yaml`, an `.installed` marker (written after the images are pulled) and
  `backups/`. That folder belongs to the app's container user, so backups are copied with `docker compose cp` (`Platform::pull`/`push`), never
  read from disk; after a restore upload the file is made readable from inside. The shared backup code speaks in `/opt/ri` paths, which
  `LinuxBackend::exec` translates both ways.
- Uninstall: *keep data* removes the containers (volumes stay; reinstall finds them); *remove everything* also deletes the volumes, the backups and
  the folder. It **never removes images** — Docker is shared, and another stack may use the same tag.
- Sharing: Linux needs the **Avahi** service for `<name>.local` to resolve from other machines (most desktop distributions run it; Arch
  doesn't by default), and a firewall (`ufw`/`firewalld`) may need the port opened — the window says both, and lists IP addresses as fallbacks.
- Start-at-login uses a `~/.config/autostart` entry; the tray needs an app-indicator on GNOME (same as the Print Client).
- CI: `.github/workflows/launcher-linux.yml` (manual) builds the `.deb` and AppImage.

## How it works on macOS
- **Install**, no administrator rights and no restart, so it runs in the window's own process (still reporting through
  `install-status.json`, so the UI is the same; a status left "running" by a quit mid-install becomes "Try again" at next
  launch): download a pinned Lima release (SHA-256, `lima.rs::pins`) and unpack it into the data dir
  (`~/Library/Application Support/ReliefInventory/lima`); download Lima's own pinned Alpine 3.23 cloud image; write
  `ri-instance.yaml` (`vmType: vz`, 2 CPUs, 4 GiB — 2 GiB on an 8 GB Mac —, sparse 60 GiB disk, `mounts: []`, only port
  18080 forwarded, to 127.0.0.1); `limactl create` + `start`; inside, the shared `apk add docker…`, then
  `rc-update add docker default`; write the compose file; `docker compose pull`.
- **Every `limactl` call runs as `env LIMA_HOME=~/.ri-lima limactl …`**, so a developer's own Lima/Colima is never touched.
  `LIMA_HOME` is in the home folder, not the data dir, because Lima's socket paths must be under 104 bytes.
- **Start:** `limactl start` if the VM isn't running (≈30 s; Lima's host agent is its own background process, so the VM
  keeps running after the launcher quits, like the Windows distro), wait for dockerd (an OpenRC service in the VM),
  write the compose file, `compose up -d`. **Stop:** `compose stop`, `limactl stop`. Commands inside run as
  `limactl shell --workdir / ri -- sudo …`.
- **Copying files in and out** (`pull`/`push`): `limactl copy` runs as the guest user, which can't read root's backups, so
  files are staged in the VM's `/tmp` with `sudo` and the staging copy is always removed.
- **Uninstall** `--keep-data` leaves the VM and Lima (a reinstall finds the database); a full uninstall runs
  `limactl delete` and removes `~/.ri-lima`, Lima and the downloads.
- **Share name:** `scutil --get LocalHostName` + `.local` (the name Bonjour answers to); the plain host name often already
  ends in `.local`. macOS's virtual interfaces (`utun*`, `bridge*` — the VM's is `bridge100` —, `awdl*`, `llw*`, `anpi*`,
  `ap*`) are left out of the address list.
- The window is a menu-bar app while hidden (no Dock icon), a normal app while open; the menu-bar icon opens its menu on click.

## Proven on macOS (2026-10-25, Apple-silicon MacBook Pro, macOS 26.6.2, Lima 2.2.1, via the CLI)
Clean install from nothing in 3½ minutes (no password, no terminal step); the first-run wizard at `localhost:8080`; the
- **First sign-in after setup:** the new instance's administrator sees a red banner, *Set up your recovery key* (Instance Admin → Full Instance Backups). The key it shows once is what lets an automatic or Launcher-pulled instance backup restore every warehouse's encrypted settings; print it and keep it away from this computer and the backups. (The Launcher itself never sees it.)
`.local` name and LAN IP from the share link answer; local-only stops LAN access and back, switched while `ri-launcher run`
was running; back up now, export (path with spaces, read-only, no overwrite), restore (safety backup first; a change made
after the backup was undone); data kept across stop/start; the VM outlives the launcher process; update; uninstall
`--keep-data` then reinstall (28 s, data still there); full uninstall leaves no process, listener or folder but
`settings.json`. Lima's release `limactl` is ad-hoc signed *with* the virtualization entitlement and a file we download
ourselves carries no quarantine flag, so it runs with no Gatekeeper prompt. **With the macOS firewall on, LAN connections
are accepted but hang (no error) until "Allow incoming connections" is answered** for the launcher. Not yet tested: the
window; the `.dmg` downloaded from the internet (Gatekeeper's "Open Anyway"); an Intel Mac; macOS 13/14; an 8 GB Mac;
sleep/wake; other devices on the network (only this Mac's own LAN address was tried); start at login.

## Proven by hand and in the code on Windows 11 (2026-10-25)
`wsl --install --no-distribution` (and `wsl --update`) **fail** on a machine with no WSL — the inbox stub only takes a bare
`--install`, which pulls Ubuntu — which is why the sequence above uses DISM + the MSI. Anything started from an SSH session
dies when the session ends (SSH quirk; use `Win32_Process.Create` when driving a test machine remotely). The RunOnce entry
did not fire with no one signed in at the console (expected). Not yet tested: Windows Firewall *on* (it was off for the Private
profile on the test laptop), Windows 10, Home vs Pro, sleep/hibernate, the real window, a standard (non-admin) account that
elevates as a *different* admin (the Linux layer is per Windows user, so that would install it for the admin — unsupported).

## Not built / possible future work
- **macOS:** code signing + notarization (needs an Apple Developer ID); keeping the Mac awake while sharing; VM size as
  a setting.
- **Linux:** the window has not been run (only compiled); rootless Docker, Podman and Docker Desktop for Linux are untried; no check
  that the port is free before install.
- **Run as a Windows service** (LocalSystem, starts at boot, survives logout; the tray app becomes a controller). The
  distro is per-user, so it would be its own import under `C:\ProgramData\ReliefInventory`. Unproven: needs a test of WSL under
  SYSTEM with nobody logged in. Today's design is a per-user tray app with start-at-login.
- Offline install bundle (image tarball + rootfs + WSL MSI) beyond "restore from file"; loading an image tarball
  (`docker load`); code signing (installers are unsigned, so SmartScreen/Gatekeeper warn); the launcher's own auto-update;
  keeping the machine awake; download resume; a network-profile warning when the PC is on a "Public" network.

## Update and backup requests from the app

`core/src/updater.rs` is the launcher's version of the Docker web host's `docker/updater/ri-updater.py`. The launcher generates
`RI_UPDATE_SECRET` once (`update-secret` next to `settings.json`) and writes it into the compose file it renders. While the app is
running, a task in the launcher (`Launcher::serve_update_requests`, started by the window and by `ri-launcher run`) polls
`GET /api/instance/update` on the internal port every 30 s and answers `check` (pull and compare), `update` (pull, and only if the image
is newer — on the edge channel, different — `down` then `up -d`, wait for `/up`) and `backup` (`instance:backup --requested`: the
provisioner's full-instance backup with files). Docker is reached only through `Platform::exec`, so it is the same code on Linux, Lima and WSL.
Requests are made in the app (`instance:update check|apply|backup`, or Instance Admin).

The window's **Update Relief Inventory** button (Local RI Server > Maintenance) uses the same rules (`Launcher::install_update`): it downloads the
newest image for the chosen channel and restarts only if that image is newer, saying "already running the newest version" otherwise. The
Backups tab's **Save all to this computer** copies every backup in the Linux layer — the app's own and the whole-instance zips under
`backups/instance/` — to Documents\Relief Inventory Backups (read-only, skipping ones already copied); **Open saved copies** opens that folder.
