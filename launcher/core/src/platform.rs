use async_trait::async_trait;
use std::path::Path;
use std::sync::Arc;

/// Port the app is published on *inside* the Linux layer. WSL and Lima forward it to the host's
/// 127.0.0.1 only; the proxy then owns the public port (8080 by default) so other devices on the LAN can
/// reach it — and a chosen public port can never collide with the forwarded one.
pub const INTERNAL_PORT: u16 = 18080;

#[derive(Debug, thiserror::Error)]
pub enum PlatformError {
    #[error("{0}")]
    Failed(String),
}

pub type Result<T> = std::result::Result<T, PlatformError>;

pub fn fail<T>(msg: impl Into<String>) -> Result<T> {
    Err(PlatformError::Failed(msg.into()))
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Preflight {
    Ok,
    /// Plain-language problem and what the person should do about it.
    Blocked(String),
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum InstallOutcome {
    Done,
    /// A Windows feature was just switched on; setup continues (idempotently) after the computer restarts.
    NeedsReboot,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, serde::Serialize)]
#[serde(rename_all = "snake_case")]
pub enum State {
    NotInstalled,
    Stopped,
    Running,
}

#[derive(Debug, Clone, Default, PartialEq)]
pub struct Progress {
    pub step: String,
    pub detail: String,
    /// 0.0–1.0 when known (downloads); None for steps with no measurable progress.
    pub fraction: Option<f32>,
}

impl Progress {
    pub fn step(step: &str, detail: &str) -> Progress {
        Progress { step: step.into(), detail: detail.into(), fraction: None }
    }
}

pub type ProgressFn = Arc<dyn Fn(Progress) + Send + Sync>;

/// What the Linux layer must provide. `wsl::WslBackend` implements it for Windows and `lima::LimaBackend`
/// for macOS; tests use a fake.
/// Every method is safe to call again after a failure or a reboot.
#[async_trait]
pub trait Platform: Send + Sync {
    async fn preflight(&self) -> Result<Preflight>;
    /// Bring the machine from nothing to "Docker Engine and the images are in place". Idempotent.
    async fn install(&self, compose: &str, progress: ProgressFn) -> Result<InstallOutcome>;
    async fn state(&self) -> Result<State>;
    /// Start the layer, keep it alive, write `compose` and bring the stack up.
    async fn start(&self, compose: &str) -> Result<()>;
    async fn stop(&self) -> Result<()>;
    /// Pull newer images and recreate the stack.
    async fn update(&self, compose: &str) -> Result<()>;
    /// `keep_data` leaves the Linux layer (and the database inside it) so a reinstall finds it again.
    async fn uninstall(&self, keep_data: bool) -> Result<()>;
    /// Run a command inside the Linux layer; an error if it exits non-zero. Returns stdout.
    async fn exec(&self, args: &[&str]) -> Result<String>;
    /// Copy a file out of the Linux layer to `local` (only while it is running). Any file, whoever owns it.
    async fn pull(&self, remote: &str, local: &Path) -> Result<()>;
    /// Copy `local` into the Linux layer at `remote` (only while it is running), replacing what is there.
    async fn push(&self, local: &Path, remote: &str) -> Result<()>;
    /// Open a terminal window on this computer in the folder with the compose file, where `docker compose …` works.
    /// Returns once the window has been asked for.
    async fn open_terminal(&self) -> Result<()> {
        fail("RI Launcher does not support this operating system yet.")
    }
    /// How this backend's compose file differs from the shared one (see `StackOptions`).
    fn stack_options(&self) -> crate::compose::StackOptions {
        crate::compose::StackOptions::default()
    }
}

/// Stand-in on operating systems with no backend (Linux): everything that needs the
/// Linux layer says so plainly, while settings and links still work.
pub struct Unsupported;

#[async_trait]
impl Platform for Unsupported {
    async fn preflight(&self) -> Result<Preflight> {
        Ok(Preflight::Blocked("RI Launcher does not support this operating system yet.".into()))
    }
    async fn install(&self, _c: &str, _p: ProgressFn) -> Result<InstallOutcome> {
        fail("RI Launcher does not support this operating system yet.")
    }
    async fn state(&self) -> Result<State> {
        Ok(State::NotInstalled)
    }
    async fn start(&self, _c: &str) -> Result<()> {
        fail("RI Launcher does not support this operating system yet.")
    }
    async fn stop(&self) -> Result<()> {
        Ok(())
    }
    async fn update(&self, _c: &str) -> Result<()> {
        fail("RI Launcher does not support this operating system yet.")
    }
    async fn uninstall(&self, _k: bool) -> Result<()> {
        Ok(())
    }
    async fn exec(&self, _a: &[&str]) -> Result<String> {
        fail("RI Launcher does not support this operating system yet.")
    }
    async fn pull(&self, _r: &str, _l: &Path) -> Result<()> {
        fail("RI Launcher does not support this operating system yet.")
    }
    async fn push(&self, _l: &Path, _r: &str) -> Result<()> {
        fail("RI Launcher does not support this operating system yet.")
    }
}
