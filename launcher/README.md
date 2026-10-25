# RI Launcher

Installs and runs Relief Inventory on Windows and Linux (macOS later) **without Docker Desktop** — on Windows, Docker Engine
runs inside a small Linux layer the launcher creates (a private Alpine WSL 2 distro); on Linux it simply uses the Docker
the person already has; a Lima VM is planned for macOS (`MAC_VERSION.md`). A window with buttons to install / start / stop / update, a **Run as a LAN service** switch, a **Copy
shareable link** button, an editable computer name, and backup tools. A separate program from `print-client/`, same
repo and the same Rust + Tauri v2 + Vue approach.

**Status (2026-10-25):** the core, CLI and Windows backend are built and were run end to end on a real Windows 11
machine (clean install through reboot to a LAN-reachable app, backup list/create/export/restore, local ↔ LAN switch,
host-name edit). The Tauri window (`src-tauri/`, `ui/`) compiles and its UI builds, but **has not been run yet** — the
first real run is the CI build (`.github/workflows/launcher-windows.yml`, manual, unsigned installer). **Linux** (2026-10-25, `core/src/linux.rs`) is built and was run for real against Docker 29 on Ubuntu through the CLI (install, start,
LAN/local switch, backups create/export/restore, stop, update, uninstall keep/remove data); the window compiles there but has
not been run. **macOS has no backend yet** (`platform::Unsupported`); see "Not built".

## Layout
- `core/` (`ri-launcher-core`, plain crate, `cargo test` runs everywhere) — `platform.rs` (the `Platform` trait: what a
  Linux layer must do), `wsl.rs` (Windows backend), `service.rs` (`Launcher`: settings + compose + proxy + backend),
  `settings.rs`, `compose.rs`, `network.rs`, `proxy.rs`, `backups.rs`, `status.rs`, `download.rs`, `runner.rs`,
  `elevate.rs`, `linux.rs` (Linux backend). `bin/ri-launcher.rs` is a full command-line front end (`ri-launcher --help`) — useful for testing and
  for driving a remote machine.
- `src-tauri/` (Tauri shell: commands, tray, start-at-login, single instance, the elevated `--install` pass) and
  `ui/` (Vue). Not a workspace member (own `Cargo.lock`), like print-client.
- Tests: `cd launcher && cargo test` (backend command sequences run against a fake `Runner`, no Windows needed).
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
  people type, the app's `8080:8080` mapping becomes `18080:8080`, an optional timezone is switched on. Nothing else
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
  (`php artisan backup:create`), **Save a copy** (copies through `\\wsl.localhost\ReliefInventory\…` to
  Documents\Relief Inventory Backups and marks the copy **read-only**), and **Restore from file** (refuses non-zips,
  takes a safety backup first and stops if that fails, then `backup:restore … --force --no-interaction`; the window's own
  confirmation replaces the command's prompt). The encryption key (`APP_KEY`) is not in a backup — see docker/README.md.

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

## Proven by hand and in the code on Windows 11 (2026-10-25)
`wsl --install --no-distribution` (and `wsl --update`) **fail** on a machine with no WSL — the inbox stub only takes a bare
`--install`, which pulls Ubuntu — which is why the sequence above uses DISM + the MSI. Anything started from an SSH session
dies when the session ends (SSH quirk; use `Win32_Process.Create` when driving a test machine remotely). The RunOnce entry
did not fire with no one signed in at the console (expected). Not yet tested: Windows Firewall *on* (it was off for the Private
profile on the test laptop), Windows 10, Home vs Pro, sleep/hibernate, the real window, a standard (non-admin) account that
elevates as a *different* admin (the Linux layer is per Windows user, so that would install it for the admin — unsupported).

## Not built / possible future work
- **Linux:** the window has not been run (only compiled); rootless Docker, Podman and Docker Desktop for Linux are untried; no check
  that the port is free before install. **macOS backend** (Lima, `vmType: vz`, bundle `limactl`) — the `Platform` trait is the seam; `Unsupported` stands in.
- **Run as a Windows service** (LocalSystem, starts at boot, survives logout; the tray app becomes a controller). The
  distro is per-user, so it would be its own import under `C:\ProgramData\ReliefInventory`. Unproven: needs a test of WSL under
  SYSTEM with nobody logged in. Today's design is a per-user tray app with start-at-login.
- Offline install bundle (image tarball + rootfs + WSL MSI) beyond "restore from file"; loading an image tarball
  (`docker load`); code signing (installers are unsigned, so SmartScreen/Gatekeeper warn); the launcher's own auto-update;
  keeping the machine awake; download resume; a network-profile warning when the PC is on a "Public" network.
