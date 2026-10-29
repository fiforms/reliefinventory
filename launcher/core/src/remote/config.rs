//! What this launcher knows about remote servers it is paired with, and what happened on the last runs.
//! Two files in the data dir, kept apart on purpose: `remote-servers.json` is what a person set (written by
//! the window or `ri-launcher remote …`), `remote-state.json` is what the backup runs wrote. A backup finishing
//! can never overwrite a setting, and deleting the state just means the next schedule check runs a backup.

use std::collections::BTreeMap;
use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

use super::client::ServerKind;
use super::identity::write_private;
use super::schedule::{RunRecord, Schedule};

/// How many copies a new server keeps.
pub const DEFAULT_KEEP: u32 = 14;

/// A server this launcher is paired with (it holds our public key as a `backup_client` trust, or, for [`ServerKind::Instance`], as an instance trust).
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct RemoteServer {
    /// Local id, used in folder names and commands (the server's own id isn't known until paired).
    pub id: String,
    pub name: String,
    pub url: String,
    pub server_uuid: String,
    /// What its backups are of: one warehouse (every pairing made before instance backups existed) or the whole instance.
    #[serde(default)]
    pub kind: ServerKind,
    /// The key the server presented when pairing, pinned for display (the fingerprint people compare).
    pub server_public_key: String,
    pub paired_at: i64,
    pub schedule: Schedule,
    /// `database` or `full` (database plus uploaded files).
    pub scope: String,
    /// Copies kept (newest first); older ones are deleted after a good download.
    pub keep: u32,
    /// Where copies are kept. `None` = `<data dir>/remote-backups/<id>`.
    pub dest_dir: Option<String>,
}

#[derive(Debug, Clone, Default, PartialEq, Serialize, Deserialize)]
#[serde(default)]
pub struct RemoteConfig {
    pub servers: Vec<RemoteServer>,
}

pub fn config_path(dir: &Path) -> PathBuf {
    dir.join("remote-servers.json")
}

impl RemoteConfig {
    /// Missing or unreadable = nothing paired, so a corrupt file never blocks the launcher.
    pub fn load(dir: &Path) -> RemoteConfig {
        std::fs::read(config_path(dir)).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
    }

    pub fn save(&self, dir: &Path) -> std::io::Result<()> {
        write_private(&config_path(dir), &serde_json::to_vec_pretty(self)?)
    }

    pub fn get(&self, id: &str) -> Option<&RemoteServer> {
        self.servers.iter().find(|s| s.id == id)
    }

    pub fn get_mut(&mut self, id: &str) -> Option<&mut RemoteServer> {
        self.servers.iter_mut().find(|s| s.id == id)
    }
}

pub fn validate_scope(scope: &str) -> Result<(), String> {
    match scope {
        "database" | "full" => Ok(()),
        _ => Err("Choose database, or database and uploaded files.".into()),
    }
}

pub fn validate_keep(keep: u32) -> Result<(), String> {
    if (1..=999).contains(&keep) {
        Ok(())
    } else {
        Err("Keep between 1 and 999 backups.".into())
    }
}

impl RemoteServer {
    /// Where this server's copies live.
    pub fn backup_dir(&self, data_dir: &Path) -> PathBuf {
        match &self.dest_dir {
            Some(d) if !d.trim().is_empty() => PathBuf::from(d.trim()),
            _ => data_dir.join("remote-backups").join(&self.id),
        }
    }
}

/// A backup in progress, for the window to show.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct Running {
    pub step: String,
    pub detail: String,
    pub fraction: Option<f32>,
    pub started_at: i64,
}

#[derive(Debug, Clone, Default, PartialEq, Serialize, Deserialize)]
#[serde(default)]
pub struct ServerState {
    #[serde(flatten)]
    pub record: RunRecord,
    pub last_error: Option<String>,
    /// The file the last good run saved.
    pub last_file: Option<String>,
    /// The server refused this computer's key (un-paired there): nothing is retried until it is paired again.
    pub needs_pairing: bool,
    pub running: Option<Running>,
}

#[derive(Debug, Clone, Default, PartialEq, Serialize, Deserialize)]
pub struct StateFile {
    pub servers: BTreeMap<String, ServerState>,
}

pub fn state_path(dir: &Path) -> PathBuf {
    dir.join("remote-state.json")
}

impl StateFile {
    pub fn load(dir: &Path) -> StateFile {
        std::fs::read(state_path(dir)).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
    }

    pub fn save(&self, dir: &Path) -> std::io::Result<()> {
        write_private(&state_path(dir), &serde_json::to_vec_pretty(self)?)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::remote::schedule::Frequency;

    pub(crate) fn server(id: &str) -> RemoteServer {
        RemoteServer {
            id: id.into(),
            name: "Statesville".into(),
            url: "https://ri.example.org".into(),
            server_uuid: "u".into(),
            kind: ServerKind::Warehouse,
            server_public_key: "k".into(),
            paired_at: 1,
            schedule: Schedule { frequency: Frequency::Daily, ..Schedule::default() },
            scope: "database".into(),
            keep: DEFAULT_KEEP,
            dest_dir: None,
        }
    }

    #[test]
    fn config_and_state_round_trip_and_survive_corruption() {
        let d = tempfile::tempdir().unwrap();
        assert_eq!(RemoteConfig::load(d.path()), RemoteConfig::default());
        let c = RemoteConfig { servers: vec![server("a1")] };
        c.save(d.path()).unwrap();
        assert_eq!(RemoteConfig::load(d.path()), c);

        let mut st = StateFile::default();
        st.servers.insert("a1".into(), ServerState { last_error: Some("x".into()), ..Default::default() });
        st.save(d.path()).unwrap();
        assert_eq!(StateFile::load(d.path()), st);

        std::fs::write(config_path(d.path()), b"{nope").unwrap();
        std::fs::write(state_path(d.path()), b"[1").unwrap();
        assert_eq!(RemoteConfig::load(d.path()), RemoteConfig::default());
        assert_eq!(StateFile::load(d.path()), StateFile::default());
    }

    #[test]
    fn backup_dir_defaults_under_the_data_dir_and_can_be_moved() {
        let mut s = server("a1");
        assert_eq!(s.backup_dir(Path::new("/data")), PathBuf::from("/data/remote-backups/a1"));
        s.dest_dir = Some(" /mnt/usb/ri ".into());
        assert_eq!(s.backup_dir(Path::new("/data")), PathBuf::from("/mnt/usb/ri"));
        s.dest_dir = Some("  ".into());
        assert_eq!(s.backup_dir(Path::new("/data")), PathBuf::from("/data/remote-backups/a1"));
    }

    #[test]
    fn a_server_saved_before_kinds_existed_is_a_warehouse() {
        let old = r#"{"servers":[{"id":"a1","name":"Old","url":"https://ri.example.org","server_uuid":"u","server_public_key":"k","paired_at":1,
            "schedule":{"frequency":"daily","hour":2,"minute":0,"weekday":6},"scope":"database","keep":14,"dest_dir":null}]}"#;
        let cfg: RemoteConfig = serde_json::from_str(old).unwrap();
        assert_eq!(cfg.servers[0].kind, ServerKind::Warehouse);
    }

    #[test]
    fn option_validation() {
        assert!(validate_scope("full").is_ok() && validate_scope("storage").is_err());
        assert!(validate_keep(1).is_ok() && validate_keep(0).is_err() && validate_keep(1000).is_err());
    }
}
