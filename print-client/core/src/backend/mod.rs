//! The OS printing seam. Everything above this (pairing, heartbeat, job loop, ledger) is platform
//! independent; a backend only knows how to list queues, hand a PDF to the OS spooler, follow
//! that job, and cancel it. No spooler protocol code — thin wrappers over the tools each OS ships
//! (`lp`/`lpstat` on macOS and Linux; PowerShell + SumatraPDF on Windows).

use std::path::PathBuf;
use std::sync::Arc;
use std::time::Duration;

use async_trait::async_trait;
use thiserror::Error;

use crate::types::PrinterInfo;

pub mod unix;
pub mod windows;

/// A failure the OS reported, worded so it can be shown to warehouse staff as-is (it ends up in
/// the server's print-queue error column).
#[derive(Debug, Error, Clone, PartialEq, Eq)]
#[error("{0}")]
pub struct BackendError(pub String);

impl BackendError {
    pub fn new(s: impl Into<String>) -> Self {
        BackendError(s.into())
    }
}

pub struct SubmitRequest {
    pub queue_name: String,
    pub file: PathBuf,
    pub copies: u32,
    pub title: String,
    pub width_in: f64,
    pub height_in: f64,
}

/// Opaque OS job handle (a CUPS job id like `Zebra-12`; on Windows the spooler job ids).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct OsJob(pub String);

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum OsJobStatus {
    /// Still queued or being printed.
    Pending,
    /// Left the OS queue without error.
    Done,
    Failed(String),
}

#[async_trait]
pub trait PrintBackend: Send + Sync {
    /// The value for the heartbeat's `print_platform`.
    fn platform(&self) -> &'static str;
    /// Every queue the OS exposes (the *complete* list, including ones staff may not use).
    async fn list_printers(&self) -> Result<Vec<PrinterInfo>, BackendError>;
    /// Hand the PDF to the spooler, printing `copies` copies at actual size.
    async fn submit(&self, req: &SubmitRequest) -> Result<OsJob, BackendError>;
    async fn job_status(&self, queue_name: &str, job: &OsJob) -> Result<OsJobStatus, BackendError>;
    /// Best effort: remove a job we gave up on, or it prints later as a duplicate.
    async fn cancel(&self, queue_name: &str, job: &OsJob);
}

/// The backend for the OS we are running on. `sumatra` is the bundled SumatraPDF path (Windows).
pub fn default_backend(sumatra: Option<PathBuf>) -> Arc<dyn PrintBackend> {
    if cfg!(windows) {
        Arc::new(windows::WindowsBackend::new(sumatra))
    } else {
        Arc::new(unix::UnixBackend::default())
    }
}

/// Run a helper tool with a timeout, a stable (C) locale so output parses the same everywhere,
/// and no console window flashing up on Windows.
pub(crate) async fn run_tool(
    program: &str,
    args: &[&str],
    timeout: Duration,
) -> Result<(bool, String, String), BackendError> {
    let mut cmd = tokio::process::Command::new(program);
    cmd.args(args).env("LC_ALL", "C").env("LANG", "C").kill_on_drop(true);
    #[cfg(windows)]
    {
        cmd.creation_flags(0x0800_0000); // CREATE_NO_WINDOW
    }
    let out = match tokio::time::timeout(timeout, cmd.output()).await {
        Err(_) => return Err(BackendError::new(format!("{program} did not answer in time"))),
        Ok(Err(e)) if e.kind() == std::io::ErrorKind::NotFound => {
            return Err(BackendError::new(format!(
                "Printing tool '{program}' was not found on this computer."
            )))
        }
        Ok(Err(e)) => return Err(BackendError::new(format!("Could not run {program}: {e}"))),
        Ok(Ok(o)) => o,
    };
    Ok((
        out.status.success(),
        String::from_utf8_lossy(&out.stdout).into_owned(),
        String::from_utf8_lossy(&out.stderr).into_owned(),
    ))
}
