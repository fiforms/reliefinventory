# RI Launcher

Installs and runs Relief Inventory on Windows (and, later, macOS) **without Docker Desktop** — Docker Engine runs
inside a small Linux layer the launcher creates (a private Alpine WSL 2 distro on Windows; a Lima VM is planned for
macOS). A window with buttons to install / start / stop / update, a **Run as a LAN service** switch, a **Copy
shareable link** button, an editable computer name, and backup tools. A separate program from `print-client/`, same
repo and the same Rust + Tauri v2 + Vue approach.

**Status (2026-10-25):** the core, CLI and Windows backend are built and were run end to end on a real Windows 11
machine (clean install through reboot to a LAN-reachable app, backup list/create/export/restore, local ↔ LAN switch,
host-name edit). The Tauri window (`src-tauri/`, `ui/`) compiles and its UI builds, but **has not been run yet** — the
first real run is the CI build (`.github/workflows/launcher-windows.yml`, manual, unsigned installer). **macOS has no
backend yet** (`platform::Unsupported`); see "Not built".

## Layout
- `core/` (`ri-launcher-core`, plain crate, `cargo test` runs everywhere) — `platform.rs` (the `Platform` trait: what a
  Linux layer must do), `wsl.rs` (Windows backend), `service.rs` (`Launcher`: settings + compose + proxy + backend),
  `settings.rs`, `compose.rs`, `network.rs`, `proxy.rs`, `backups.rs`, `status.rs`, `download.rs`, `runner.rs`,
  `elevate.rs`. `bin/ri-launcher.rs` is a full command-line front end (`ri-launcher --help`) — useful for testing and
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

## Proven by hand and in the code on Windows 11 (2026-10-25)
`wsl --install --no-distribution` (and `wsl --update`) **fail** on a machine with no WSL — the inbox stub only takes a bare
`--install`, which pulls Ubuntu — which is why the sequence above uses DISM + the MSI. Anything started from an SSH session
dies when the session ends (SSH quirk; use `Win32_Process.Create` when driving a test machine remotely). The RunOnce entry
did not fire with no one signed in at the console (expected). Not yet tested: Windows Firewall *on* (it was off for the Private
profile on the test laptop), Windows 10, Home vs Pro, sleep/hibernate, the real window, a standard (non-admin) account that
elevates as a *different* admin (the Linux layer is per Windows user, so that would install it for the admin — unsupported).

## Not built / possible future work
- **macOS backend** (Lima, `vmType: vz`, bundle `limactl`) — the `Platform` trait is the seam; `Unsupported` stands in.
- **Run as a Windows service** (LocalSystem, starts at boot, survives logout; the tray app becomes a controller). The
  distro is per-user, so it would be its own import under `C:\ProgramData\ReliefInventory`. Unproven: needs a test of WSL under
  SYSTEM with nobody logged in. Today's design is a per-user tray app with start-at-login.
- Offline install bundle (image tarball + rootfs + WSL MSI) beyond "restore from file"; loading an image tarball
  (`docker load`); code signing (installers are unsigned, so SmartScreen/Gatekeeper warn); the launcher's own auto-update;
  keeping the machine awake; download resume; a network-profile warning when the PC is on a "Public" network.
