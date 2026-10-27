# RI Launcher for macOS — plan

Written 2026-10-25 for whoever builds the Mac side (first: Mark, on a MacBook, working with Claude Code). Read
[`README.md`](README.md) first — it explains the whole launcher and what was proven on Windows. This file is only about
what is *different* on a Mac and how to get there. Nothing here has been run on a Mac yet: **facts we proved** are
marked as such; everything about Lima/macOS below is a **plan or an expectation to verify**, and some of it will be wrong.

## Progress (2026-10-25, branch `launcher-macos`)

Steps 1–4 of "Suggested order of work" are done, and the `.dmg` builds (step 6, first half). **Proven** on an
Apple-silicon MacBook Pro, macOS 26.6.2, Lima 2.2.1 — details in README.md's "Proven on macOS"; the rest of this file is
the original plan, with the places where reality differed noted here rather than rewritten:

- **Built:** `core/src/lima.rs` (`LimaBackend`, fake-runner tests in the `wsl.rs` style), `core/src/layer.rs` (the shared
  in-VM script, B3), `Platform::pull`/`push` replacing `host_path` (B1), in-process install on macOS + stale-status
  recovery + `restart_computer` Windows-only (B2/B3), Mac wording in the UI (B4), `LocalHostName` and the Mac interface
  filter (B5 — this also fixed a real bug: `hostname` on a Mac is often `name.local`, which became `name-local.local`),
  the macOS arm of `default_backend` (B7), menu-bar behaviour (Accessory policy while hidden, menu on click, Dock reopen),
  and `.github/workflows/launcher-macos.yml` (universal `.dmg`, not yet run).
- **Different from the plan:** `LIMA_HOME` is **`~/.ri-lima`, not `<data dir>/lima`** — Lima refuses to create an instance
  whose socket path is 104 bytes or more, and `~/Library/Application Support/ReliefInventory/lima/ri/ssh.sock.<16 digits>`
  reaches that with a username of about ten characters. Lima's binaries and the downloads do stay in the data dir. Alpine is
  **3.23.4** (the image Lima's own template pins), not 3.24 like Windows: 3.24's cloud images changed their naming and Lima
  hasn't adopted them. `limactl copy` runs as the guest user and can't read root's files, so pull/push stage through
  `/tmp` with `sudo`. There is no keep-alive process: dockerd is an OpenRC service in the VM (`rc-update add docker default`).
  VM memory is 4 GiB, or 2 GiB on Macs with 8 GB (the stack idles at ~450 MB in the VM). Preflight requires macOS
  **13.3** (Lima's note for Alpine aarch64 under `vz`), 10 GB free, and `kern.hv_support`.
- **Answered "verify" items:** the downloaded `limactl` runs with no Gatekeeper prompt (ad-hoc signed with the
  `com.apple.security.virtualization` entitlement, no quarantine flag); the VM survives the launcher quitting; the
  forward is 127.0.0.1-only; nothing of ours lands in `~/Library/Caches/lima`. New: with the macOS firewall on, LAN
  connections are accepted and then **hang with no error** until "Allow incoming connections" is answered.
- **Still open:** run the window (`npm run tauri dev`, then the `.dmg`); Gatekeeper's "Open Anyway" on a *downloaded*
  `.dmg` (a local build has no quarantine flag); an Intel Mac; macOS 13/14; an 8 GB Mac; another device on the network
  opening the link; Sequoia's Local Network permission; sleep/wake (and guest clock); start at login; menu-bar icon
  legibility in light/dark; the CI workflow's first run. Then step 7's remaining docs once the window is proven.

## Goal

A Mac user downloads one installer, opens **RI Launcher**, presses **Install**, and gets Relief Inventory running with
no Docker Desktop (commercial licence) and no terminal. Same window and same features as Windows: Install / Start /
Stop / Update / Uninstall, the **Run as a LAN service** vs **local only** switch, **Copy shareable link**, an editable
computer name, and the backup tools (list, back up now, save a copy, restore from file).

Most of the program is already platform-neutral and will work unchanged: settings, compose-file rendering, the LAN
proxy, the share link, the CLI, the Vue UI, and the Tauri shell (tray, window, start-at-login, single instance). What
is missing is **one backend**, `LimaBackend`, implementing the `Platform` trait in `core/src/platform.rs`, plus a
handful of places where Windows wording or behaviour leaked into shared code (listed below).

## The approach: Lima

Docker needs a Linux kernel, so on a Mac it runs inside a small VM. Use **[Lima](https://lima-vm.io)** (`limactl`,
Apache-2.0, free) with `vmType: vz` (Apple's Virtualization.framework). Why:

- Free and open source; no licence question for non-profits (the whole reason this program exists).
- `vz` needs no admin rights, no kernel extension and no hypervisor install, and runs on both Apple silicon and Intel
  Macs (macOS 13+; `tauri.conf.json` already sets `minimumSystemVersion` 13.0).
- It forwards guest ports to the Mac's `127.0.0.1` automatically — exactly the shape the launcher already assumes on
  Windows (WSL forwards the app's internal port to localhost only, and our proxy owns the public port).
- Considered and rejected: Apple's own `container` tool (macOS 26 + Apple silicon only, no compose), Docker Desktop
  (the thing we're avoiding), Colima/Rancher Desktop (Lima wrappers — we'd depend on someone else's UI/CLI for no gain),
  UTM/QEMU (heavier, no automation story).

The Relief Inventory image (`ghcr.io/fiforms/reliefinventory`) is multi-arch (amd64 + arm64), so the VM must match the
Mac's CPU — **no emulation**. Pick the guest architecture from the host.

## How the Windows design maps over

| Concern | Windows (built, proven) | Mac (plan) |
|---|---|---|
| Linux layer | Private Alpine distro `ReliefInventory` in WSL 2 | One private Lima instance (name it `ri`) running Alpine, `vmType: vz` |
| Getting the tooling | DISM features → reboot → pinned WSL MSI (SHA-256) | **No reboot, no admin.** Download a pinned Lima release tarball (SHA-256 checked, `download.rs` already does this) into the data dir |
| Install steps | `wsl --import` → `apk add docker docker-cli-compose iptables ip6tables` → pull images | `limactl create/start` → the *same* `apk add` + `docker compose pull` script, run inside the VM |
| Keep-alive | A long-lived hidden `wsl.exe … dockerd` (WSL stops idle distros) | Lima's host agent already keeps the VM up after `limactl start`; confirm it survives the launcher quitting |
| Port forwarding | WSL → host `127.0.0.1:18080` | Lima → host `127.0.0.1:18080` (check the forward is localhost-only; set it explicitly in the Lima YAML if not) |
| LAN access | Launcher-owned Rust proxy on the public port | **Identical** — `proxy.rs` is unchanged |
| Data location | `%LOCALAPPDATA%\ReliefInventory` | `~/Library/Application Support/ReliefInventory` (`dirs::data_local_dir()` already resolves it) |
| Admin rights | Needed (DISM, MSI, firewall rule); the window relaunches elevated with `--install` | **None needed.** Install runs in-process; no `relaunch_elevated`, no `NeedsReboot` |
| Autostart | `tauri-plugin-autostart` | Already configured with `MacosLauncher::LaunchAgent` in `main.rs` |
| Distribution | Unsigned NSIS/MSI (SmartScreen warns) | Unsigned `.dmg`/`.app` (Gatekeeper warns) until we have an Apple Developer ID |

### Decisions to make early (my recommendations)

1. **Isolate our Lima from the user's own.** Set `LIMA_HOME=<data dir>/lima` for every `limactl` call, so we never see
   or touch a developer's existing Lima/Colima instances, and Uninstall can delete our directory safely.
2. **Download Lima at install time, don't bundle it** — same pattern as the pinned WSL MSI (`wsl.rs::pins`): small
   installer, one pinned version, SHA-256 verified, arch picked at runtime. Use the official release tarball, not a
   rebuild: Lima's release binaries are what carries the entitlement `vz` needs to create a VM. *Verify:* that a file we
   download ourselves (no quarantine attribute) runs without a Gatekeeper prompt, and that the shipped `limactl` works
   when invoked from our app's process. The Lima tarball also holds the guest-agent files `limactl` needs
   (`share/lima/…`) — unpack the whole thing, not just the binary.
3. **Alpine guest, same Docker install as Windows.** The in-VM script (`apk add docker docker-cli-compose …`, compose
   file written under `/opt/ri`, `docker compose pull/up`) should be **shared code**, not copied. Today it lives inside
   `wsl.rs`; extract it (e.g. `core/src/layer.rs`) so both backends call it and the compose/backup paths stay identical.
   If Alpine + vz misbehaves, fall back to Lima's Debian/Ubuntu cloud image and `apt`/get.docker — but try Alpine first
   for size and parity.
4. **Isolate the guest from the Mac's files.** Lima mounts the home folder into the VM by default; set `mounts: []`.
   The database and uploads live on the VM's own disk, and Backup export goes through explicit copy commands.
5. **VM sizing.** Unlike WSL (dynamic memory), a Lima VM gets a fixed CPU/RAM/disk. Start with 2 CPUs, 4 GiB RAM and a
   sparse 60 GiB disk; make them settings later. Measure what the app actually needs (MariaDB + web + queue + scheduler).

## Work to do

### A. `core` — the backend (the bulk of the work)

Create `core/src/lima.rs` with `LimaBackend<R: Runner, D: Downloader>` mirroring `WslBackend<R, D>` — **same testing
approach: a fake `Runner`, so the command sequence is unit-tested on any machine** (copy the style of the `wsl.rs`
tests: full install order, re-running install skips finished steps, start/stop/uninstall sequences, preflight blocks).
Every `Platform` method must stay **idempotent** — safe to call again after a failure or a quit mid-install.

- `preflight`: macOS ≥ 13; the Mac's architecture is supported (pins exist); enough free disk; virtualization available
  (`sysctl kern.hv_support` is 1 on every supported Mac, but check — and note it can be 0 when the Mac is itself a VM).
- `install`: download + verify + unpack Lima (progress through the same `ProgressFn`, writes `install-status.json` via
  `status.rs`) → write the Lima instance YAML (arch, `vmType: vz`, cpus/memory/disk, `mounts: []`, the forward of the
  app's internal port `INTERNAL_PORT = 18080`, Alpine image URL + SHA from a pin) → `limactl create` + `start` → run the
  shared in-VM install script → `docker compose pull`. Never returns `NeedsReboot`.
- `state`: NotInstalled (no instance dir) / Stopped (instance exists, VM not running) / Running (VM up **and** dockerd
  answering) — same three states the UI already understands.
- `start` / `stop` / `update` / `uninstall(keep_data)`: `limactl start` (+ dockerd up) then write the compose text and
  `compose up -d`; `compose stop` then `limactl stop`; `compose pull && up -d`; `uninstall` = stop and, unless
  `keep_data`, `limactl delete -f` and remove the downloads. `keep_data` leaves the instance so a reinstall finds the
  database again, exactly like the Windows distro.
- `exec`: `limactl shell ri -- <args>`. Writing the compose file uses `Runner::run_stdin` (as `write_compose` does today).
- Pins: add Lima (aarch64 + x86_64) and the Alpine cloud image (both archs) to a `pins()` table with SHA-256s, the way
  `wsl.rs` pins its MSI and rootfs. Put the checksums in the code with a comment saying where they came from.

### B. Small changes to shared code (so Mac isn't a second-class citizen)

These are the places Windows assumptions leaked into code Mac will also run. Fix them in the Windows PR's style: small,
with tests, and **don't break Windows**.

1. **`Platform::host_path` is a Windows-ism.** *(Done 2026-10-25 for the Linux backend: the trait now has `pull`/`push`, defaulting to the
   `host_path` copy so Windows is unchanged, and `backups.rs` uses them. `core/src/linux.rs` is a working example of overriding them.)* `backups.rs` reads/writes backups through
   `\\wsl.localhost\ReliefInventory\…`. Lima has no such path (and we're setting `mounts: []`). Replace it with two
   trait methods — `pull(remote, local)` and `push(local, remote)` — implemented on Windows by the existing UNC copy and on
   Mac by `limactl copy` (or streaming through `limactl shell cat`). Update `backups::export`/`import`/`list` and the
   Windows backend together; the backup tests are the safety net.
2. **Elevation.** `elevate.rs` and the `--install` relaunch in `src-tauri/src/main.rs` are Windows-only. On macOS `start_install`
   should just run the install in-process (spawn it like the elevated pass does, still reporting via the status file so
   the window's progress UI is unchanged). `elevate::relaunch_elevated` already returns "unsupported" off Windows — don't
   call it there.
3. **`restart_computer` / the "Restart needed" card** never appears on Mac (no `NeedsReboot`); make sure the command is
   `cfg(windows)` or returns a clear error, and nothing else is shown.
4. **Wording.** Some user-facing strings say "Windows" ("Approve the Windows prompt…", the reboot message in
   `service.rs`, the firewall note in the UI). Make them platform-neutral or switch on `cfg!`. The UI's firewall note needs a
   Mac version (below).
5. **Default share-link name.** `network::default_hostname()` uses the OS host name. On a Mac that can be a network-assigned
   or odd name; the name other devices actually resolve over Bonjour is `scutil --get LocalHostName` (+ `.local`). Use it
   on macOS, falling back to the current logic. Also extend the virtual-adapter filter in `network.rs` for Mac interface
   names (`utun*`, `bridge*` — Lima/other VMs sit on `bridge100` —, `awdl*`, `llw*`, `anpi*`, `vmenet*`); the share link
   should list `en*` addresses.
6. **`runner.rs`** has `CREATE_NO_WINDOW` and UTF-16 decoding for `wsl.exe`; neither hurts on Mac (the decoder detects
   UTF-16, `limactl` emits UTF-8). Nothing to do except confirm.
7. `lib.rs` — `DefaultBackend`/`default_backend` are `cfg(windows)` vs `Unsupported`; add a `cfg(target_os = "macos")`
   arm returning `LimaBackend<SystemRunner, HttpDownloader>`. The CLI and the window pick it up automatically.

### C. The Tauri shell and CI

- `src-tauri` already builds on macOS in principle (tray icon, autostart LaunchAgent, single-instance, dialog, opener are
  all cross-platform). First run: `cd launcher && npm ci && npm run tauri dev`, then `npm run tauri build`.
- Build a **universal** app (`--target universal-apple-darwin`; needs both Rust targets installed) or ship separate arm64
  and x86_64 builds — your call; universal is simpler for users.
- Add `.github/workflows/launcher-macos.yml` modelled on `launcher-windows.yml` (manual `workflow_dispatch`, `macos-latest`,
  `cargo test -p ri-launcher-core`, `npm ci`, `npm run tauri build`, upload the `.dmg`). **Unsigned**, like Windows.
- Icons are already generated for macOS (`icons/icon.icns`). The tray icons in `icons/tray/` are colour PNGs; macOS menu-bar
  icons are usually monochrome "template" images. Decide whether the coloured status dots read well in the menu bar
  (they should, but check light/dark menu bars) — if not, switch to a template icon plus a coloured badge.

> **Since this was written** the Linux backend landed (`core/src/linux.rs`, README "Linux"). It is the simplest worked example of a
> `Platform` implementation and of the shared-code changes above: `pull`/`push`, `compose::StackOptions` (a loopback-only port bind and a
> private compose project name — **read the README note on why the project name matters**; a Lima VM is isolated, but check
> the same collision can't happen), no elevation, and an in-process install in `src-tauri`.

## Mac-specific things that will bite (verify each)

- **Gatekeeper and unsigned apps.** Until there is an Apple Developer ID (and notarization) the `.app` is blocked on first
  open. As of macOS Sequoia the old right-click → Open trick is gone: the user opens the app, dismisses the warning, then
  goes to **System Settings → Privacy & Security → "Open Anyway"**. Document this prominently in the Mac install notes
  (and the README) — it is the single biggest first-run hurdle. Signing + notarizing later is a pipeline change, not a code change.
- **Incoming connections / firewall.** The macOS application firewall (if enabled) and first-listen prompts ask to
  "Allow incoming network connections" for a program that opens a listening socket — that's our proxy in LAN mode. For an
  unsigned app the choice may not stick across updates. Also test **Sequoia's "Local Network" privacy permission**, which
  gates apps talking to devices on the LAN; determine whether it affects *accepting* connections from other devices (it
  is known to affect outgoing local-network access) and add the right guidance to the UI.
- **Sleep.** A sleeping Mac stops the VM and the proxy, so other computers can't connect. Windows has the same gap
  (not built there either). For LAN service mode, consider holding a "no idle sleep" assertion (`caffeinate -i`, or the
  IOKit assertion API) while the stack is running. Worth doing on both platforms together.
- **The proxy lives in the launcher process.** Same rule as Windows: others connect only while the launcher (menu bar app)
  is running; closing the window must leave it in the menu bar; **Quit** ends sharing. Check that the window-close →
  hide behaviour and the dock icon behave sensibly on macOS (a menu-bar-only app may want
  `ActivationPolicy::Accessory` while hidden).
- **mDNS names.** `<LocalHostName>.local` should resolve from Windows 10+, iOS, most Android, and other Macs with no
  router setup. Verify from a Windows PC and a phone.
- **Disk and memory.** The VM's disk image is a sparse file; "delete" must actually free it. And 4 GiB of RAM is a lot on an
  8 GB MacBook Air — decide what we say about minimum hardware.
- **Guest clock drift after sleep/wake** can make time-sensitive things (sessions, backups' timestamps) odd; Lima handles
  most of it but check `date` inside the VM after a sleep.

## Suggested order of work (each step is demonstrable)

1. **Spike by hand, no launcher code.** On the Mac: download Lima, create an Alpine `vz` instance, `apk add docker
   docker-cli-compose`, start dockerd, run the app from `docker/compose.yaml` (with the `8080:8080` → `18080:8080` change
   the launcher makes — see `core/src/compose.rs`), and open `http://localhost:18080`. Note every command that worked; they
   become the fake-runner test script. Record findings in this file (like README's "Proven by hand" section).
2. **Refactor first, behaviour-neutral:** extract the shared in-VM script and replace `host_path` with `pull`/`push`
   (section B1), keeping the Windows tests green (`cargo test` runs on a Mac too: `wsl.rs` is not `cfg(windows)`-gated, only the
   choice of default backend is, so the WSL fake-runner tests run on any OS).
3. **`lima.rs` with fake-runner tests**, then wire `default_backend` for macOS.
4. **Run the CLI on the Mac:** `cargo run -p ri-launcher-core --bin ri-launcher -- install`, `start`, `link`, `backups create`,
   `backups export`, `stop`, `uninstall` — the same end-to-end path that was proven on Windows, driven without any window.
5. **LAN check:** from another device, open the shared link in LAN mode; flip to local-only and confirm it stops.
6. **The window:** `npm run tauri dev`, then CI build + install of the unsigned `.dmg` on a clean Mac account.
7. **Docs:** update `README.md` (status, layout, Mac how-it-works), the launcher section in the repo-root `CLAUDE.md`, and
   `docker/README.md` (point Mac users at the launcher).

## Definition of done

On a clean Mac (Apple silicon *and* Intel if you can get one): install the `.dmg`, open it past Gatekeeper, press
**Install** with no terminal, reach the Relief Inventory first-run wizard at `http://localhost:8080`; Stop/Start keeps the
data; **LAN service** lets a phone or Windows PC open the shared link; **Update** pulls a newer image; **Back up now**,
**Save a copy** and **Restore from file** work; **Uninstall** (with and without keeping data) leaves nothing running;
and the launcher survives quit + reopen (re-adopts the proxy) and starts at login.

## Working notes for Mark (and his Claude)

- Branch from `ri-launcher` (PR #63) or from `master` once it merges. The launcher is separate from the Laravel app: it has
  its own `Cargo.toml`, `package.json` and CI; nothing here touches the PHP side. Root `CLAUDE.md` has a short "RI Launcher"
  section and the repo's conventions (commit only when asked; manual-only GitHub workflows; document non-obvious things).
- Dev loop: `cd launcher && cargo test` (fast, no VM needed) — keep adding fake-runner tests as the Mac backend grows;
  `cargo run -p ri-launcher-core --bin ri-launcher -- --help` for the CLI; `npm run tauri dev` for the window. The UI also has
  mock modes (`?mock=notinstalled|installing|failed|stopped|local|running`) that need no backend at all.
- Lessons from Windows that probably have a Mac twin: make every install step **idempotent** and re-runnable; report progress
  through the status file rather than assuming one process lives through the whole install; a keep-alive process must not
  die with the thing that started it (processes started over SSH died with the session — if you test the Mac remotely, use
  `launchctl`/`nohup`/`setsid`-style detachment); settings changed from another process (CLI vs. window) must be picked up
  by the running proxy (`follow_settings`); a busy public port should produce a friendly message, not a stack trace.
- Don't claim things are verified that you only expect. When something is proven on the Mac, move it from "expectation"
  to a dated "Proven" note, as the README does for Windows.
