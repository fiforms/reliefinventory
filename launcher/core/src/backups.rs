//! Backup tools. The app makes its own backups (database zips in `/opt/ri/backups` inside the Linux layer,
//! see docker/README.md); this module lists them, makes one on demand, copies one out to a normal folder
//! as a read-only snapshot, and restores one from a file.
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

pub async fn list<P: Platform>(p: &P) -> Result<Vec<BackupFile>> {
    let script = format!("find {REMOTE_DIR}/backups -name '*.zip' -exec stat -c '%n|%s|%Y' {{}} + 2>/dev/null; true");
    Ok(parse_list(&p.exec(&["sh", "-c", &script]).await?))
}

/// `scope`: "database" or "full" (database + uploaded files) — the same two the app's own page offers.
pub async fn create_now<P: Platform>(p: &P, scope: &str) -> Result<()> {
    if scope != "database" && scope != "full" {
        return fail("scope must be database or full");
    }
    p.exec(&["sh", "-c", &app(&format!("php artisan backup:create --scope={scope}"))]).await?;
    Ok(())
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

/// Restore a backup zip chosen from disk. A fresh database backup is made first and the restore refuses to
/// run if that fails, so a wrong file can always be undone. The app is unavailable during the restore.
pub async fn import<P: Platform>(p: &P, file: &Path) -> Result<()> {
    let is_zip = std::fs::read(file)
        .map(|b| b.starts_with(b"PK\x03\x04"))
        .map_err(|e| crate::platform::PlatformError::Failed(format!("{}: {e}", file.display())))?;
    if !is_zip {
        return fail("That file is not a Relief Inventory backup (it is not a zip file).");
    }
    create_now(p, "database").await.map_err(|e| {
        crate::platform::PlatformError::Failed(format!("Could not take a safety backup first, so nothing was changed: {e}"))
    })?;
    let remote = format!("{REMOTE_DIR}/backups/imported.zip");
    p.push(file, &remote)
        .await
        .map_err(|e| crate::platform::PlatformError::Failed(format!("could not copy the file in: {e}")))?;
    let result = p.exec(&["sh", "-c", &app("php artisan backup:restore /backups/imported.zip --force --no-interaction")]).await;
    // removed from inside the app's container: on a Linux host the folder belongs to the app's user
    let _ = p.exec(&["sh", "-c", &app("rm -f /backups/imported.zip")]).await;
    result.map(|_| ())
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
        (Fake { root: d.path().to_path_buf(), cmds: Mutex::new(vec![]), list_output: list_output.into(), fail_backup }, d)
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
    async fn import_takes_a_safety_backup_first_then_restores() {
        let (p, _d) = fake("", false);
        let f = tempfile::NamedTempFile::new().unwrap();
        std::fs::write(f.path(), b"PK\x03\x04zip").unwrap();
        import(&p, f.path()).await.unwrap();
        let cmds = p.cmds.lock().unwrap().clone();
        let at = |n: &str| cmds.iter().position(|c| c.contains(n)).unwrap();
        assert!(at("backup:create --scope=database") < at("backup:restore /backups/imported.zip --force --no-interaction"));
        assert!(cmds.last().unwrap().contains("rm -f /backups/imported.zip"));
    }

    #[tokio::test]
    async fn import_stops_when_the_safety_backup_fails_or_the_file_is_not_a_zip() {
        let (p, _d) = fake("", true);
        let f = tempfile::NamedTempFile::new().unwrap();
        std::fs::write(f.path(), b"PK\x03\x04zip").unwrap();
        let err = import(&p, f.path()).await.unwrap_err().to_string();
        assert!(err.contains("nothing was changed"));
        assert!(!p.cmds.lock().unwrap().iter().any(|c| c.contains("backup:restore")));
        let (p, _d) = fake("", false);
        std::fs::write(f.path(), b"not a zip").unwrap();
        assert!(import(&p, f.path()).await.unwrap_err().to_string().contains("not a zip"));
        assert!(p.cmds.lock().unwrap().is_empty());
    }

    #[tokio::test]
    async fn create_rejects_unknown_scope() {
        let (p, _d) = fake("", false);
        assert!(create_now(&p, "storage; rm -rf /").await.is_err());
        create_now(&p, "full").await.unwrap();
    }
}
