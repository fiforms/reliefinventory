//! RI Launcher core: everything except the window. `platform::Platform` is the seam to the Linux layer
//! that runs Docker (`wsl::WslBackend` on Windows, `lima::LimaBackend` on macOS, `linux::LinuxBackend` — the host's own
//! Docker — on Linux), `service::Launcher`
//! ties settings, compose editing, the LAN proxy and a backend together, and `backups` is the file side. `remote` is the other
//! direction: this computer paired (federation trust) with a *remote* Relief Inventory server, pulling its backups on a schedule.
pub mod backups;
pub mod compose;
pub mod download;
pub mod elevate;
pub mod layer;
pub mod lima;
pub mod linux;
pub mod network;
pub mod platform;
pub mod proxy;
pub mod remote;
pub mod runner;
pub mod sdimage;
pub mod service;
pub mod settings;
pub mod status;
pub mod wsl;

use std::path::{Path, PathBuf};

/// Where settings, downloads and the Linux layer live: the per-user app data folder, shared by the
/// command line and the window so they always see the same installation.
pub fn default_data_dir() -> PathBuf {
    dirs::data_local_dir().unwrap_or_else(|| PathBuf::from(".")).join("ReliefInventory")
}

#[cfg(windows)]
pub type DefaultBackend = wsl::WslBackend<runner::SystemRunner, download::HttpDownloader>;
#[cfg(target_os = "macos")]
pub type DefaultBackend = lima::LimaBackend<runner::SystemRunner, download::HttpDownloader>;
#[cfg(target_os = "linux")]
pub type DefaultBackend = linux::LinuxBackend<runner::SystemRunner>;
#[cfg(not(any(windows, target_os = "macos", target_os = "linux")))]
pub type DefaultBackend = platform::Unsupported;

/// The backend for this operating system. `exe` is the program the firewall rule allows (the one running
/// the proxy); `resume` is registered to run at next sign-in if setup is waiting on a restart. Both are
/// Windows-only: a Mac needs no firewall rule of ours and never restarts during setup.
#[cfg(windows)]
pub fn default_backend(dir: &Path, exe: PathBuf, resume: Option<String>) -> DefaultBackend {
    wsl::WslBackend::new(runner::SystemRunner, download::HttpDownloader, dir.to_path_buf(), exe, resume)
}

#[cfg(target_os = "macos")]
pub fn default_backend(dir: &Path, _exe: PathBuf, _resume: Option<String>) -> DefaultBackend {
    lima::LimaBackend::new(runner::SystemRunner, download::HttpDownloader, dir.to_path_buf())
}

#[cfg(target_os = "linux")]
pub fn default_backend(dir: &Path, _exe: PathBuf, _resume: Option<String>) -> DefaultBackend {
    linux::LinuxBackend::new(runner::SystemRunner, dir.to_path_buf())
}

#[cfg(not(any(windows, target_os = "macos", target_os = "linux")))]
pub fn default_backend(_dir: &Path, _exe: PathBuf, _resume: Option<String>) -> DefaultBackend {
    platform::Unsupported
}
