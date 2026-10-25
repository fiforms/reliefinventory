use crate::platform::{Progress, ProgressFn};
use serde::{Deserialize, Serialize};
use std::path::{Path, PathBuf};
use std::sync::Arc;

/// Install progress, kept in a file because the install runs in an elevated copy of the program (Windows
/// feature changes need administrator rights) while the window that shows it is not.
#[derive(Debug, Clone, Default, PartialEq, Serialize, Deserialize)]
pub struct InstallStatus {
    pub running: bool,
    pub step: String,
    pub detail: String,
    pub fraction: Option<f32>,
    pub needs_reboot: bool,
    pub done: bool,
    pub error: Option<String>,
}

pub fn path(dir: &Path) -> PathBuf {
    dir.join("install-status.json")
}

pub fn write(dir: &Path, status: &InstallStatus) {
    let _ = std::fs::create_dir_all(dir);
    let tmp = dir.join("install-status.json.tmp");
    if std::fs::write(&tmp, serde_json::to_vec(status).unwrap_or_default()).is_ok() {
        let _ = std::fs::rename(tmp, path(dir));
    }
}

pub fn read(dir: &Path) -> Option<InstallStatus> {
    serde_json::from_slice(&std::fs::read(path(dir)).ok()?).ok()
}

pub fn clear(dir: &Path) {
    let _ = std::fs::remove_file(path(dir));
}

/// A `ProgressFn` that records each update as "running" in the status file.
pub fn writer(dir: &Path) -> ProgressFn {
    let dir = dir.to_path_buf();
    Arc::new(move |p: Progress| {
        write(
            &dir,
            &InstallStatus { running: true, step: p.step, detail: p.detail, fraction: p.fraction, ..Default::default() },
        )
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn writer_records_progress() {
        let d = tempfile::tempdir().unwrap();
        assert!(read(d.path()).is_none());
        writer(d.path())(Progress { step: "Download".into(), detail: "WSL".into(), fraction: Some(0.5) });
        let s = read(d.path()).unwrap();
        assert!(s.running && !s.done);
        assert_eq!((s.step.as_str(), s.fraction), ("Download", Some(0.5)));
        clear(d.path());
        assert!(read(d.path()).is_none());
    }
}
