//! One backup run against one server: ask for a fresh backup, wait for it, download it (resuming if the connection
//! drops), check the SHA-256 the server reports, keep it as a read-only file and delete copies beyond the retention
//! count. Everything the server does goes through [`BackupApi`], so the whole sequence is tested against a scripted fake.
//!
//! A file only appears under its real name after its checksum matched, so a folder never holds a half-written
//! backup that looks finished; the partial download sits beside it as `<name>.part` and is picked up again next time.

use std::path::{Path, PathBuf};
use std::time::Duration;

use serde::Serialize;

use super::client::{BackupApi, BackupInfo, RemoteError};
use crate::download::hash_file;

#[derive(Debug, Clone)]
pub struct RunOptions {
    /// How often to ask the server whether its backup is finished.
    pub poll: Duration,
    /// Give up waiting for the server to finish a backup after this long.
    pub timeout: Duration,
    /// Pause before resuming an interrupted download.
    pub retry_pause: Duration,
}

impl Default for RunOptions {
    fn default() -> Self {
        RunOptions { poll: Duration::from_secs(4), timeout: Duration::from_secs(3 * 3600), retry_pause: Duration::from_secs(5) }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Outcome {
    pub path: PathBuf,
    pub size: u64,
    /// The same file was already here (a run that was cut off after the download), so nothing was fetched.
    pub reused: bool,
    pub pruned: usize,
}

/// `(step, detail, fraction)` for whoever shows progress.
pub type Report = dyn Fn(&str, &str, Option<f32>) + Send + Sync;

const NAME_PREFIX: &str = "relief-backup-";
/// A whole-instance backup's file name starts with this instead (a server paired as `ServerKind::Instance`).
const INSTANCE_PREFIX: &str = "relief-instance-";
const DOWNLOAD_ATTEMPTS: u32 = 6;
/// Consecutive failed status checks tolerated while the server works; it keeps going without us.
const POLL_FAILURES: u32 = 20;

/// The server's file name if it is a plain `relief-backup-….zip` (or `relief-instance-….zip`), else one of ours. The name came over
/// the network and becomes a path on this computer, so nothing but a bare, safe file name is accepted.
pub fn local_name(server_name: Option<&str>, id: i64) -> String {
    let ok = |n: &str| {
        n.len() <= 120
            && (n.starts_with(NAME_PREFIX) || n.starts_with(INSTANCE_PREFIX))
            && n.ends_with(".zip")
            && n.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '-' | '_'))
            && !n.contains("..")
    };
    match server_name {
        Some(n) if ok(n) => n.to_string(),
        _ => format!("{NAME_PREFIX}{id}.zip"),
    }
}

fn is_backup_name(name: &str) -> bool {
    (name.starts_with(NAME_PREFIX) || name.starts_with(INSTANCE_PREFIX)) && name.ends_with(".zip")
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct LocalBackup {
    pub name: String,
    pub path: String,
    pub size: u64,
    /// Unix seconds.
    pub modified: i64,
}

/// The backups in `dir`, newest first. Anything that isn't one of ours (a folder shared with other files) is ignored.
pub fn list_local(dir: &Path) -> Vec<LocalBackup> {
    let mut v: Vec<LocalBackup> = std::fs::read_dir(dir)
        .into_iter()
        .flatten()
        .flatten()
        .filter_map(|e| {
            let name = e.file_name().to_string_lossy().into_owned();
            let meta = e.metadata().ok()?;
            if !meta.is_file() || !is_backup_name(&name) {
                return None;
            }
            let modified = meta.modified().ok()?.duration_since(std::time::UNIX_EPOCH).ok()?.as_secs() as i64;
            Some(LocalBackup { path: e.path().to_string_lossy().into_owned(), name, size: meta.len(), modified })
        })
        .collect();
    v.sort_by(|a, b| b.modified.cmp(&a.modified).then_with(|| b.name.cmp(&a.name)));
    v
}

/// Delete all but the newest `keep` backups, never `protect` (the one just saved). Returns how many went.
pub fn prune(dir: &Path, keep: u32, protect: &Path) -> usize {
    let mut removed = 0;
    for old in list_local(dir).into_iter().skip(keep.max(1) as usize) {
        let path = PathBuf::from(&old.path);
        if path == protect {
            continue;
        }
        let _ = set_readonly(&path, false); // our copies are read-only, which Windows won't delete
        if std::fs::remove_file(&path).is_ok() {
            removed += 1;
        }
    }
    removed
}

fn set_readonly(path: &Path, readonly: bool) -> std::io::Result<()> {
    let mut perm = std::fs::metadata(path)?.permissions();
    #[allow(clippy::permissions_set_readonly_false)]
    perm.set_readonly(readonly);
    std::fs::set_permissions(path, perm)
}

fn local(path: &Path, e: std::io::Error) -> RemoteError {
    RemoteError::Local(format!("{}: {e}", path.display()))
}

/// Hold a lock for the folder while a run is in progress, so two launcher processes (the window and a command)
/// never write the same `.part`. The operating system drops it if the process dies.
pub(crate) fn lock_folder(dir: &Path) -> Result<std::fs::File, RemoteError> {
    let path = dir.join(".ri-backup.lock");
    let file = std::fs::OpenOptions::new().create(true).truncate(false).write(true).open(&path).map_err(|e| local(&path, e))?;
    match file.try_lock() {
        Ok(()) => Ok(file),
        Err(std::fs::TryLockError::WouldBlock) => Err(RemoteError::Local("A backup to this folder is already running.".into())),
        Err(std::fs::TryLockError::Error(e)) => Err(local(&path, e)),
    }
}

fn active(info: &BackupInfo) -> bool {
    matches!(info.status.as_str(), "queued" | "running")
}

/// Poll until the backup is no longer queued or running.
async fn wait_until_finished(api: &dyn BackupApi, first: BackupInfo, opts: &RunOptions, report: &Report) -> Result<BackupInfo, RemoteError> {
    let started = std::time::Instant::now();
    let (mut info, mut failures) = (first, 0u32);
    while active(&info) {
        if started.elapsed() > opts.timeout {
            return Err(RemoteError::Local("The server is taking too long to make the backup.".into()));
        }
        report("The server is making the backup", info.progress.as_deref().unwrap_or("Waiting for it to start"), None);
        tokio::time::sleep(opts.poll).await;
        match api.get_backup(info.id).await {
            Ok(next) => {
                info = next;
                failures = 0;
            }
            Err(e) if e.is_transient() && failures < POLL_FAILURES => failures += 1, // the server keeps working without us
            Err(e) => return Err(e),
        }
    }
    Ok(info)
}

/// Run one backup. See the module docs for the sequence.
pub async fn run_backup(api: &dyn BackupApi, dest: &Path, scope: &str, keep: u32, opts: &RunOptions, report: &Report) -> Result<Outcome, RemoteError> {
    std::fs::create_dir_all(dest).map_err(|e| local(dest, e))?;
    let _lock = lock_folder(dest)?;

    report("Asking the server for a backup", "", None);
    let mut started = None;
    for _ in 0..3 {
        match api.create_backup(scope).await {
            Ok(info) => {
                started = Some(info);
                break;
            }
            // One at a time on the server. The same kind already running is as good as our own; another kind is waited out.
            Err(RemoteError::Busy(Some(running))) if running.scope == scope && active(&running) => {
                started = Some(running);
                break;
            }
            Err(RemoteError::Busy(Some(running))) => {
                wait_until_finished(api, running, opts, report).await?;
            }
            Err(e) => return Err(e),
        }
    }
    let started = started.ok_or_else(|| RemoteError::Local("The server stayed busy with other backups.".into()))?;

    let info = wait_until_finished(api, started, opts, report).await?;
    match info.status.as_str() {
        "complete" => {}
        "failed" => {
            return Err(RemoteError::Local(format!("The server could not make the backup: {}", info.error.as_deref().unwrap_or("no reason given"))))
        }
        other => return Err(RemoteError::Local(format!("The server's backup is not available ({other})."))),
    }
    // The hash comes from `show`, which the poll above used; if the list-style answer lacked it, ask once more.
    let info = if info.sha256.is_some() { info } else { api.get_backup(info.id).await? };
    let sha = info.sha256.clone().ok_or_else(|| RemoteError::Local("The server gave no checksum for the backup, so it can't be checked.".into()))?;

    let mut final_path = dest.join(local_name(info.filename.as_deref(), info.id));
    if final_path.exists() {
        if hash_file(&final_path).await.map(|h| h.eq_ignore_ascii_case(&sha)).unwrap_or(false) {
            let size = std::fs::metadata(&final_path).map(|m| m.len()).unwrap_or(0);
            return Ok(Outcome { pruned: prune(dest, keep, &final_path), path: final_path, size, reused: true });
        }
        // A different file with the same name (another server's, or damaged): keep it, save ours beside it.
        final_path = dest.join(format!("{NAME_PREFIX}{}-{}.zip", info.id, &sha[..8.min(sha.len())]));
    }
    let part = PathBuf::from(format!("{}.part", final_path.display()));

    let progress = |done: u64, total: Option<u64>| {
        let detail = match total {
            Some(t) => format!("{} of {} MB", done >> 20, t >> 20),
            None => format!("{} MB", done >> 20),
        };
        report("Downloading the backup", &detail, total.filter(|t| *t > 0).map(|t| (done as f32 / t as f32).min(1.0)));
    };
    let mut attempt = 0;
    loop {
        attempt += 1;
        match api.download_backup(info.id, &part, info.size_bytes, &progress).await {
            Ok(()) => break,
            // Resuming is cheap (the next attempt continues the .part file), so a dropped connection isn't a failure yet.
            Err(e) if e.is_transient() && attempt < DOWNLOAD_ATTEMPTS => {
                report("Download interrupted", "Trying to continue", None);
                tokio::time::sleep(opts.retry_pause).await;
            }
            Err(e) => return Err(e),
        }
    }

    report("Checking the download", "", None);
    let got = hash_file(&part).await.map_err(|e| local(&part, e))?;
    if !got.eq_ignore_ascii_case(&sha) {
        let _ = std::fs::remove_file(&part);
        return Err(RemoteError::Local("The downloaded backup did not match the server's checksum, so it was thrown away. The next run will download it again.".into()));
    }
    std::fs::rename(&part, &final_path).map_err(|e| local(&final_path, e))?;
    set_readonly(&final_path, true).map_err(|e| local(&final_path, e))?;
    let size = std::fs::metadata(&final_path).map(|m| m.len()).unwrap_or(0);
    let pruned = prune(dest, keep, &final_path);
    report("Done", "", Some(1.0));
    Ok(Outcome { path: final_path, size, reused: false, pruned })
}

#[cfg(test)]
pub(crate) mod tests {
    use super::*;
    use async_trait::async_trait;
    use sha2::{Digest, Sha256};
    use std::collections::VecDeque;
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::sync::Mutex;

    pub(crate) fn sha(data: &[u8]) -> String {
        crate::download::hex(&Sha256::digest(data))
    }

    pub(crate) fn info(id: i64, scope: &str, status: &str, data: &[u8]) -> BackupInfo {
        BackupInfo {
            id,
            scope: scope.into(),
            status: status.into(),
            filename: Some(format!("relief-backup-20261026-020000-{scope}-{id}.zip")),
            size_bytes: Some(data.len() as u64),
            sha256: (status == "complete").then(|| sha(data)),
            ..Default::default()
        }
    }

    /// A scripted server: `create` and `gets` are answered in order (the last `get` repeats).
    #[derive(Default)]
    pub(crate) struct Fake {
        pub create: Mutex<VecDeque<Result<BackupInfo, RemoteError>>>,
        pub gets: Mutex<VecDeque<Result<BackupInfo, RemoteError>>>,
        pub last_get: Mutex<Option<BackupInfo>>,
        pub data: Vec<u8>,
        pub downloads: AtomicUsize,
        /// The next n downloads write half of what is missing and then drop the connection.
        pub drop_connections: AtomicUsize,
        pub corrupt: bool,
    }

    #[async_trait]
    impl BackupApi for Fake {
        async fn create_backup(&self, _scope: &str) -> Result<BackupInfo, RemoteError> {
            self.create.lock().unwrap().pop_front().expect("unexpected create_backup")
        }
        async fn get_backup(&self, _id: i64) -> Result<BackupInfo, RemoteError> {
            let next = self.gets.lock().unwrap().pop_front();
            match next {
                Some(Ok(i)) => {
                    *self.last_get.lock().unwrap() = Some(i.clone());
                    Ok(i)
                }
                Some(Err(e)) => Err(e),
                None => Ok(self.last_get.lock().unwrap().clone().expect("no get scripted")),
            }
        }
        async fn download_backup(&self, _id: i64, part: &Path, _expected: Option<u64>, progress: &(dyn Fn(u64, Option<u64>) + Send + Sync)) -> Result<(), RemoteError> {
            self.downloads.fetch_add(1, Ordering::SeqCst);
            let have = std::fs::metadata(part).map(|m| m.len() as usize).unwrap_or(0);
            let mut bytes = self.data.clone();
            if self.corrupt {
                bytes[0] ^= 0xff;
            }
            let end = if self.drop_connections.load(Ordering::SeqCst) > 0 {
                self.drop_connections.fetch_sub(1, Ordering::SeqCst);
                have + (bytes.len() - have) / 2
            } else {
                bytes.len()
            };
            use std::io::Write;
            let mut f = std::fs::OpenOptions::new().create(true).append(true).open(part).unwrap();
            f.write_all(&bytes[have..end]).unwrap();
            progress(end as u64, Some(bytes.len() as u64));
            if end < bytes.len() {
                return Err(RemoteError::Network("the download was cut short".into()));
            }
            Ok(())
        }
    }

    fn fast() -> RunOptions {
        RunOptions { poll: Duration::from_millis(1), timeout: Duration::from_millis(400), retry_pause: Duration::from_millis(1) }
    }

    fn quiet() -> impl Fn(&str, &str, Option<f32>) + Send + Sync {
        |_, _, _| {}
    }

    fn payload() -> Vec<u8> {
        (0..5000u32).map(|n| (n % 251) as u8).collect()
    }

    #[tokio::test]
    async fn makes_downloads_verifies_and_keeps_a_read_only_copy() {
        let dir = tempfile::tempdir().unwrap();
        let data = payload();
        let fake = Fake {
            data: data.clone(),
            create: Mutex::new(VecDeque::from([Ok(info(7, "database", "queued", &data))])),
            gets: Mutex::new(VecDeque::from([Ok(info(7, "database", "running", &data)), Ok(info(7, "database", "complete", &data))])),
            ..Default::default()
        };
        let out = run_backup(&fake, dir.path(), "database", 5, &fast(), &quiet()).await.unwrap();

        assert_eq!(out.path.file_name().unwrap(), "relief-backup-20261026-020000-database-7.zip");
        assert_eq!(std::fs::read(&out.path).unwrap(), data);
        assert!(std::fs::metadata(&out.path).unwrap().permissions().readonly());
        assert!(!dir.path().join("relief-backup-20261026-020000-database-7.zip.part").exists());
        assert!(!out.reused && out.size == data.len() as u64);
    }

    #[tokio::test]
    async fn a_dropped_connection_resumes_the_partial_file() {
        let dir = tempfile::tempdir().unwrap();
        let data = payload();
        let fake = Fake {
            data: data.clone(),
            drop_connections: AtomicUsize::new(2),
            create: Mutex::new(VecDeque::from([Ok(info(1, "database", "complete", &data))])),
            ..Default::default()
        };
        let out = run_backup(&fake, dir.path(), "database", 5, &fast(), &quiet()).await.unwrap();
        assert_eq!(std::fs::read(&out.path).unwrap(), data);
        assert_eq!(fake.downloads.load(Ordering::SeqCst), 3);
    }

    #[tokio::test]
    async fn a_download_that_keeps_failing_gives_up_and_keeps_the_partial_for_next_time() {
        let dir = tempfile::tempdir().unwrap();
        let data = payload();
        let fake = Fake {
            data: data.clone(),
            drop_connections: AtomicUsize::new(100),
            create: Mutex::new(VecDeque::from([Ok(info(1, "database", "complete", &data))])),
            ..Default::default()
        };
        let err = run_backup(&fake, dir.path(), "database", 5, &fast(), &quiet()).await.unwrap_err();
        assert!(err.is_transient());
        assert_eq!(fake.downloads.load(Ordering::SeqCst), DOWNLOAD_ATTEMPTS as usize);
        assert!(list_local(dir.path()).is_empty(), "no finished-looking file");
        assert!(dir.path().join("relief-backup-20261026-020000-database-1.zip.part").exists());
    }

    #[tokio::test]
    async fn a_checksum_mismatch_throws_the_download_away() {
        let dir = tempfile::tempdir().unwrap();
        let data = payload();
        let fake = Fake {
            data: data.clone(),
            corrupt: true,
            create: Mutex::new(VecDeque::from([Ok(info(1, "database", "complete", &data))])),
            ..Default::default()
        };
        let err = run_backup(&fake, dir.path(), "database", 5, &fast(), &quiet()).await.unwrap_err();
        assert!(err.to_string().contains("checksum"), "{err}");
        assert!(std::fs::read_dir(dir.path()).unwrap().flatten().all(|e| e.file_name() == ".ri-backup.lock"));
    }

    #[tokio::test]
    async fn the_same_kind_already_running_is_adopted_and_another_kind_is_waited_out() {
        let data = payload();
        // adopt
        let dir = tempfile::tempdir().unwrap();
        let fake = Fake {
            data: data.clone(),
            create: Mutex::new(VecDeque::from([Err(RemoteError::Busy(Some(info(4, "database", "running", &data))))])),
            gets: Mutex::new(VecDeque::from([Ok(info(4, "database", "complete", &data))])),
            ..Default::default()
        };
        let out = run_backup(&fake, dir.path(), "database", 5, &fast(), &quiet()).await.unwrap();
        assert!(out.path.to_string_lossy().ends_with("database-4.zip"));

        // wait out a database backup, then make our full one
        let dir = tempfile::tempdir().unwrap();
        let fake = Fake {
            data: data.clone(),
            create: Mutex::new(VecDeque::from([
                Err(RemoteError::Busy(Some(info(4, "database", "running", &data)))),
                Ok(info(5, "full", "queued", &data)),
            ])),
            gets: Mutex::new(VecDeque::from([Ok(info(4, "database", "complete", &data)), Ok(info(5, "full", "complete", &data))])),
            ..Default::default()
        };
        let out = run_backup(&fake, dir.path(), "full", 5, &fast(), &quiet()).await.unwrap();
        assert!(out.path.to_string_lossy().ends_with("full-5.zip"));
    }

    #[tokio::test]
    async fn a_failed_server_backup_reports_its_reason() {
        let dir = tempfile::tempdir().unwrap();
        let data = payload();
        let mut failed = info(2, "database", "failed", &data);
        failed.error = Some("disk full".into());
        let fake = Fake { create: Mutex::new(VecDeque::from([Ok(failed)])), ..Default::default() };
        let err = run_backup(&fake, dir.path(), "database", 5, &fast(), &quiet()).await.unwrap_err();
        assert!(err.to_string().contains("disk full"), "{err}");
    }

    #[tokio::test]
    async fn a_missing_checksum_is_refused_rather_than_trusted() {
        let dir = tempfile::tempdir().unwrap();
        let data = payload();
        let mut nosum = info(2, "database", "complete", &data);
        nosum.sha256 = None;
        let fake = Fake { create: Mutex::new(VecDeque::from([Ok(nosum.clone())])), gets: Mutex::new(VecDeque::from([Ok(nosum)])), data, ..Default::default() };
        let err = run_backup(&fake, dir.path(), "database", 5, &fast(), &quiet()).await.unwrap_err();
        assert!(err.to_string().contains("checksum"), "{err}");
        assert_eq!(fake.downloads.load(Ordering::SeqCst), 0);
    }

    #[tokio::test]
    async fn a_server_that_never_finishes_times_out_and_poll_hiccups_are_tolerated() {
        let data = payload();
        let dir = tempfile::tempdir().unwrap();
        let fake = Fake {
            create: Mutex::new(VecDeque::from([Ok(info(2, "database", "queued", &data))])),
            gets: Mutex::new(VecDeque::from([Err(RemoteError::Network("blip".into())), Ok(info(2, "database", "running", &data))])),
            ..Default::default()
        };
        let err = run_backup(&fake, dir.path(), "database", 5, &fast(), &quiet()).await.unwrap_err();
        assert!(err.to_string().contains("too long"), "{err}");

        let fake = Fake {
            data: data.clone(),
            create: Mutex::new(VecDeque::from([Ok(info(3, "database", "queued", &data))])),
            gets: Mutex::new(VecDeque::from([Err(RemoteError::Network("blip".into())), Ok(info(3, "database", "complete", &data))])),
            ..Default::default()
        };
        assert!(run_backup(&fake, dir.path(), "database", 5, &fast(), &quiet()).await.is_ok());
    }

    #[tokio::test]
    async fn a_revoked_trust_stops_at_once() {
        let dir = tempfile::tempdir().unwrap();
        let fake = Fake { create: Mutex::new(VecDeque::from([Err(RemoteError::Revoked)])), ..Default::default() };
        assert_eq!(run_backup(&fake, dir.path(), "database", 5, &fast(), &quiet()).await.unwrap_err(), RemoteError::Revoked);
    }

    #[tokio::test]
    async fn a_file_already_here_with_the_right_hash_is_not_downloaded_again() {
        let dir = tempfile::tempdir().unwrap();
        let data = payload();
        std::fs::write(dir.path().join("relief-backup-20261026-020000-database-9.zip"), &data).unwrap();
        let fake = Fake { create: Mutex::new(VecDeque::from([Ok(info(9, "database", "complete", &data))])), ..Default::default() };
        let out = run_backup(&fake, dir.path(), "database", 5, &fast(), &quiet()).await.unwrap();
        assert!(out.reused);
        assert_eq!(fake.downloads.load(Ordering::SeqCst), 0);
    }

    #[tokio::test]
    async fn a_different_file_with_the_same_name_is_kept_and_ours_saved_beside_it() {
        let dir = tempfile::tempdir().unwrap();
        let data = payload();
        let theirs = dir.path().join("relief-backup-20261026-020000-database-9.zip");
        std::fs::write(&theirs, b"someone else's").unwrap();
        let fake = Fake { data: data.clone(), create: Mutex::new(VecDeque::from([Ok(info(9, "database", "complete", &data))])), ..Default::default() };
        let out = run_backup(&fake, dir.path(), "database", 9, &fast(), &quiet()).await.unwrap();
        assert_ne!(out.path, theirs);
        assert_eq!(std::fs::read(&theirs).unwrap(), b"someone else's");
        assert_eq!(std::fs::read(&out.path).unwrap(), data);
    }

    #[tokio::test]
    async fn two_runs_into_one_folder_cannot_overlap() {
        let dir = tempfile::tempdir().unwrap();
        let _held = lock_folder(dir.path()).unwrap();
        let fake = Fake::default();
        let err = run_backup(&fake, dir.path(), "database", 5, &fast(), &quiet()).await.unwrap_err();
        assert!(err.to_string().contains("already running"), "{err}");
    }

    #[test]
    fn file_names_from_the_network_are_never_trusted_as_paths() {
        assert_eq!(local_name(Some("relief-backup-20261026-database-3.zip"), 3), "relief-backup-20261026-database-3.zip");
        for bad in ["../../etc/passwd", "relief-backup-../x.zip", "evil.zip", "relief-backup-a/b.zip", "relief-backup-a\\b.zip", "relief-backup-x.exe", "", "relief-backup- space.zip"] {
            assert_eq!(local_name(Some(bad), 3), "relief-backup-3.zip", "{bad}");
        }
        assert_eq!(local_name(None, 8), "relief-backup-8.zip");
        // a whole-instance backup keeps its own name (and so its date), and the same safety rules apply to it
        assert_eq!(local_name(Some("relief-instance-20261029-020000-database-scheduled-daily.zip"), 20261029020000), "relief-instance-20261029-020000-database-scheduled-daily.zip");
        for bad in ["relief-instance-../x.zip", "relief-instance-a/b.zip", "relief-instance-x.exe", "relief-instances.exe"] {
            assert_eq!(local_name(Some(bad), 3), "relief-backup-3.zip", "{bad}");
        }
        assert_eq!(local_name(Some(&format!("relief-backup-{}.zip", "a".repeat(200))), 1), "relief-backup-1.zip");
    }

    #[test]
    fn retention_keeps_the_newest_never_the_protected_and_leaves_other_files_alone() {
        let dir = tempfile::tempdir().unwrap();
        let make = |name: &str, age: u64| {
            let p = dir.path().join(name);
            std::fs::write(&p, b"x").unwrap();
            let f = std::fs::File::options().write(true).open(&p).unwrap();
            f.set_modified(std::time::SystemTime::now() - Duration::from_secs(age)).unwrap();
            set_readonly(&p, true).unwrap();
            p
        };
        let newest = make("relief-backup-4.zip", 10);
        make("relief-backup-3.zip", 100);
        make("relief-backup-2.zip", 1000);
        let oldest = make("relief-backup-1.zip", 10_000);
        let theirs = make("holiday-photos.zip", 100_000);

        assert_eq!(prune(dir.path(), 2, &newest), 2);
        let left: Vec<_> = list_local(dir.path()).into_iter().map(|b| b.name).collect();
        assert_eq!(left, ["relief-backup-4.zip", "relief-backup-3.zip"]);
        assert!(theirs.exists() && !oldest.exists());

        // keep=0 is treated as 1, and the protected file survives even if it is old
        let old = make("relief-backup-0.zip", 999_999);
        assert_eq!(prune(dir.path(), 0, &old), 1);
        assert!(old.exists() && newest.exists());
    }

    #[test]
    fn whole_instance_copies_are_listed_and_pruned_like_any_other() {
        let dir = tempfile::tempdir().unwrap();
        let make = |name: &str, age: u64| {
            let p = dir.path().join(name);
            let f = std::fs::File::create(&p).unwrap();
            f.set_modified(std::time::SystemTime::now() - Duration::from_secs(age)).unwrap();
            p
        };
        let newest = make("relief-instance-20261029-030000-database-scheduled-daily.zip", 10);
        let older = make("relief-instance-20261028-030000-database-scheduled-daily.zip", 1000);
        let theirs = make("holiday-photos.zip", 100_000);

        assert_eq!(list_local(dir.path()).len(), 2);
        assert_eq!(prune(dir.path(), 1, &newest), 1);
        assert!(newest.exists() && !older.exists() && theirs.exists());
    }
}
