//! Backup tools. The app makes its own backups (database zips in `/opt/ri/backups` inside the Linux layer,
//! see docker/README.md); this module lists them, makes one on demand, copies one out to a normal folder
//! as a read-only snapshot.
use crate::platform::{fail, Platform, Result};
use crate::layer::REMOTE_DIR;
use serde::Serialize;
use std::path::{Path, PathBuf};

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct BackupFile {
    /// Path inside the Linux layer.
    pub path: String,
    pub name: String,
    pub size: u64,
    /// Unix seconds.
    pub modified: i64,
}

fn app(sub: &str) -> String {
    format!("cd {REMOTE_DIR} && docker compose exec -T app {sub} 2>&1")
}

/// `name|size|mtime` lines from `stat`, newest first. Busybox `find` has no -printf, hence `stat`.
fn parse_list(stdout: &str) -> Vec<BackupFile> {
    let mut v: Vec<BackupFile> = stdout
        .lines()
        .filter_map(|l| {
            let mut p = l.trim().rsplitn(3, '|');
            let modified = p.next()?.parse().ok()?;
            let size = p.next()?.parse().ok()?;
            let path = p.next()?.to_string();
            let name = path.rsplit('/').next()?.to_string();
            Some(BackupFile { path, name, size, modified })
        })
        .collect();
    v.sort_by(|a, b| b.modified.cmp(&a.modified));
    v
}

/// Listed from inside the app's container, as root: on a Docker host the zips belong to the app's user and the folders are private
/// to it (`0750`, the zips `0600`), so a `find` from this computer's own user quietly finds nothing. Paths come back as the
/// container sees them (`/backups/...`) and are mapped to where the rest of this module (and `Platform::pull`) expects them.
pub async fn list<P: Platform>(p: &P) -> Result<Vec<BackupFile>> {
    let find = "find /backups -name '*.zip' -exec stat -c '%n|%s|%Y' {} + 2>/dev/null; true";
    let script = format!("cd {REMOTE_DIR} && docker compose exec -T -u root app sh -c \"{find}\"");
    let mut files = parse_list(&p.exec(&["sh", "-c", &script]).await?);
    for f in &mut files {
        if let Some(rest) = f.path.strip_prefix("/backups/") {
            f.path = format!("{REMOTE_DIR}/backups/{rest}");
        }
    }
    Ok(files)
}

/// `scope`: "database" or "full" (database + uploaded files) — the same two the app's own page offers — for the app's own (default
/// warehouse) backup, or "instance": the whole instance, every warehouse and the shared databases with their files, asked of the
/// provisioner exactly as Instance Admin's "Backup now" does (`instance:backup --requested`, which waits for it).
pub async fn create_now<P: Platform>(p: &P, scope: &str) -> Result<()> {
    let command = match scope {
        "database" | "full" => format!("php artisan backup:create --scope={scope}"),
        "instance" => "php artisan instance:backup --requested --no-interaction".to_string(),
        _ => return fail("scope must be database, full or instance"),
    };
    p.exec(&["sh", "-c", &app(&command)]).await?;
    Ok(())
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct BackupMade {
    /// True for the whole-instance backup; false when only the app's own database was backed up (see `note`).
    pub instance: bool,
    pub note: Option<String>,
}

/// The "Backup now" button: a whole-instance backup if the app can make one (it needs the provisioner, which an older or
/// partly started stack may not have), otherwise the database backup of the app's own warehouse, saying why.
pub async fn backup_now<P: Platform>(p: &P) -> Result<BackupMade> {
    match create_now(p, "instance").await {
        Ok(()) => Ok(BackupMade { instance: true, note: None }),
        Err(why) => {
            create_now(p, "database").await?;
            Ok(BackupMade { instance: false, note: Some(why.to_string()) })
        }
    }
}

fn unique(dir: &Path, name: &str) -> PathBuf {
    let first = dir.join(name);
    if !first.exists() {
        return first;
    }
    let (stem, ext) = name.rsplit_once('.').unwrap_or((name, ""));
    (2..)
        .map(|n| dir.join(format!("{stem} ({n}).{ext}")))
        .find(|p| !p.exists())
        .unwrap()
}

/// Copy a backup out of the Linux layer into `dest_dir` and mark the copy read-only, so a snapshot sitting
/// in Documents can't be edited or overwritten by accident. Returns the copy's path.
pub async fn export<P: Platform>(p: &P, backup: &BackupFile, dest_dir: &Path) -> Result<PathBuf> {
    if !backup.path.starts_with(&format!("{REMOTE_DIR}/backups/")) || backup.path.contains("..") {
        return fail("that is not a backup file");
    }
    std::fs::create_dir_all(dest_dir).map_err(|e| crate::platform::PlatformError::Failed(e.to_string()))?;
    let dest = unique(dest_dir, &backup.name);
    p.pull(&backup.path, &dest)
        .await
        .map_err(|e| crate::platform::PlatformError::Failed(format!("could not copy the backup out: {e}")))?;
    let to = dest.clone();
    tokio::task::spawn_blocking(move || -> std::io::Result<()> {
        let mut perm = std::fs::metadata(&to)?.permissions();
        perm.set_readonly(true);
        std::fs::set_permissions(&to, perm)
    })
    .await
    .map_err(|e| crate::platform::PlatformError::Failed(e.to_string()))?
    .map_err(|e| crate::platform::PlatformError::Failed(format!("could not mark the copy read-only: {e}")))?;
    Ok(dest)
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct ExportAll {
    pub copied: usize,
    /// Already in the folder from an earlier time (same name and size).
    pub already_there: usize,
    pub failed: usize,
}

/// Copy every backup in the Linux layer (the app's own and the whole-instance ones) into `dest_dir`, read-only, skipping any
/// that an earlier run already copied. One failing file doesn't stop the rest.
pub async fn export_all<P: Platform>(p: &P, dest_dir: &Path) -> Result<ExportAll> {
    let mut out = ExportAll { copied: 0, already_there: 0, failed: 0 };
    for b in list(p).await? {
        if std::fs::metadata(dest_dir.join(&b.name)).map(|m| m.len() == b.size).unwrap_or(false) {
            out.already_there += 1;
        } else if export(p, &b, dest_dir).await.is_ok() {
            out.copied += 1;
        } else {
            out.failed += 1;
        }
    }
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::platform::*;
    use async_trait::async_trait;
    use std::sync::Mutex;

    struct Fake {
        root: PathBuf,
        cmds: Mutex<Vec<String>>,
        list_output: String,
        fail_backup: bool,
        fail_on: Mutex<Option<String>>,
    }

    #[async_trait]
    impl Platform for Fake {
        async fn preflight(&self) -> Result<Preflight> {
            Ok(Preflight::Ok)
        }
        async fn install(&self, _c: &str, _p: ProgressFn) -> Result<InstallOutcome> {
            Ok(InstallOutcome::Done)
        }
        async fn state(&self) -> Result<State> {
            Ok(State::Running)
        }
        async fn start(&self, _c: &str) -> Result<()> {
            Ok(())
        }
        async fn stop(&self) -> Result<()> {
            Ok(())
        }
        async fn update(&self, _c: &str) -> Result<()> {
            Ok(())
        }
        async fn uninstall(&self, _k: bool) -> Result<()> {
            Ok(())
        }
        async fn exec(&self, a: &[&str]) -> Result<String> {
            let line = a.join(" ");
            self.cmds.lock().unwrap().push(line.clone());
            if self.fail_on.lock().unwrap().as_deref().is_some_and(|f| line.contains(f)) {
                return fail("provisioner unavailable");
            }
            if line.contains("backup:create") && self.fail_backup {
                return fail("disk full");
            }
            Ok(if line.contains("find ") { self.list_output.clone() } else { String::new() })
        }
        async fn pull(&self, remote: &str, local: &Path) -> Result<()> {
            std::fs::copy(self.at(remote), local).map(|_| ()).map_err(|e| PlatformError::Failed(e.to_string()))
        }
        async fn push(&self, local: &Path, remote: &str) -> Result<()> {
            std::fs::copy(local, self.at(remote)).map(|_| ()).map_err(|e| PlatformError::Failed(e.to_string()))
        }
    }

    impl Fake {
        fn at(&self, remote: &str) -> PathBuf {
            self.root.join(remote.trim_start_matches('/'))
        }
    }

    fn fake(list_output: &str, fail_backup: bool) -> (Fake, tempfile::TempDir) {
        let d = tempfile::tempdir().unwrap();
        std::fs::create_dir_all(d.path().join("opt/ri/backups")).unwrap();
        (Fake { root: d.path().to_path_buf(), cmds: Mutex::new(vec![]), list_output: list_output.into(), fail_backup, fail_on: Mutex::new(None) }, d)
    }

    #[test]
    fn list_is_newest_first_and_skips_junk() {
        let v = parse_list("/opt/ri/backups/a.zip|10|100\ngarbage\n/opt/ri/backups/daily/b.zip|20|300\n");
        assert_eq!(v.iter().map(|b| b.name.as_str()).collect::<Vec<_>>(), ["b.zip", "a.zip"]);
        assert_eq!(v[0].size, 20);
    }

    #[tokio::test]
    async fn export_copies_read_only_and_never_overwrites() {
        let (p, _d) = fake("", false);
        std::fs::write(p.at("/opt/ri/backups/x.zip"), b"PK\x03\x04data").unwrap();
        let b = BackupFile { path: "/opt/ri/backups/x.zip".into(), name: "x.zip".into(), size: 8, modified: 1 };
        let out = tempfile::tempdir().unwrap();
        let first = export(&p, &b, out.path()).await.unwrap();
        assert!(std::fs::metadata(&first).unwrap().permissions().readonly());
        let second = export(&p, &b, out.path()).await.unwrap();
        assert_ne!(first, second);
        assert_eq!(second.file_name().unwrap(), "x (2).zip");
        // paths outside the backups folder are refused
        let bad = BackupFile { path: "/etc/passwd".into(), name: "passwd".into(), size: 0, modified: 0 };
        assert!(export(&p, &bad, out.path()).await.is_err());
        let sneaky = BackupFile { path: "/opt/ri/backups/../../etc/passwd".into(), name: "passwd".into(), size: 0, modified: 0 };
        assert!(export(&p, &sneaky, out.path()).await.is_err());
    }

    #[tokio::test]
    async fn list_asks_the_container_as_root_and_maps_its_paths() {
        let (p, _d) = fake("/backups/instance/i.zip|5|9\n/backups/warehouses/W1/w.zip|7|8\n", false);
        let files = list(&p).await.unwrap();
        assert_eq!(files.iter().map(|f| f.path.as_str()).collect::<Vec<_>>(), ["/opt/ri/backups/instance/i.zip", "/opt/ri/backups/warehouses/W1/w.zip"]);
        let cmd = p.cmds.lock().unwrap()[0].clone();
        assert!(cmd.contains("exec -T -u root app"), "{cmd}");
    }

    #[tokio::test]
    async fn export_all_copies_each_backup_once_including_the_instance_ones() {
        let listing = "/opt/ri/backups/a.zip|8|2\n/opt/ri/backups/instance/relief-instance-1-full.zip|8|1\n";
        let (p, _d) = fake(listing, false);
        std::fs::create_dir_all(p.at("/opt/ri/backups/instance")).unwrap();
        std::fs::write(p.at("/opt/ri/backups/a.zip"), b"PK\x03\x04data").unwrap();
        std::fs::write(p.at("/opt/ri/backups/instance/relief-instance-1-full.zip"), b"PK\x03\x04data").unwrap();
        let out = tempfile::tempdir().unwrap();
        let first = export_all(&p, out.path()).await.unwrap();
        assert_eq!(first, ExportAll { copied: 2, already_there: 0, failed: 0 });
        let again = export_all(&p, out.path()).await.unwrap();
        assert_eq!(again, ExportAll { copied: 0, already_there: 2, failed: 0 });
        assert!(out.path().join("relief-instance-1-full.zip").exists());
    }

    #[tokio::test]
    async fn create_rejects_unknown_scope() {
        let (p, _d) = fake("", false);
        assert!(create_now(&p, "storage; rm -rf /").await.is_err());
        create_now(&p, "full").await.unwrap();
    }

    #[tokio::test]
    async fn backup_now_takes_the_whole_instance_when_it_can() {
        let (p, _d) = fake("", false);
        assert_eq!(backup_now(&p).await.unwrap(), BackupMade { instance: true, note: None });
        let cmds = p.cmds.lock().unwrap().clone();
        assert_eq!(cmds.len(), 1);
        assert!(cmds[0].contains("exec -T app php artisan instance:backup --requested"));
    }

    #[tokio::test]
    async fn backup_now_falls_back_to_the_apps_own_database_and_says_why() {
        let (p, _d) = fake("", false);
        *p.fail_on.lock().unwrap() = Some("instance:backup".into());
        let made = backup_now(&p).await.unwrap();
        assert!(!made.instance && made.note.as_deref() == Some("provisioner unavailable"));
        assert!(p.cmds.lock().unwrap().iter().any(|c| c.contains("backup:create --scope=database")));
    }
}
