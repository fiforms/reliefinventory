//! Persistent identity: `server_url`, a `device_uuid` generated once and kept forever (the server
//! recognises the same machine across re-pairing by it), and the bearer token.
//!
//! The token lives in `config.json` inside the per-user app-data directory, written 0600 on Unix
//! (Windows app-data is per-user ACL'd). It is never logged: `Config` has a hand-written `Debug`.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

#[derive(Serialize, Deserialize, Clone, Default)]
pub struct Config {
    #[serde(default)]
    pub server_url: Option<String>,
    pub device_uuid: String,
    #[serde(default)]
    pub device_name: Option<String>,
    #[serde(default)]
    pub token: Option<String>,
}

impl std::fmt::Debug for Config {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Config")
            .field("server_url", &self.server_url)
            .field("device_uuid", &self.device_uuid)
            .field("device_name", &self.device_name)
            .field("token", &self.token.as_ref().map(|_| "<redacted>"))
            .finish()
    }
}

impl Config {
    pub fn path(dir: &Path) -> PathBuf {
        dir.join("config.json")
    }

    /// Load, or create with a fresh `device_uuid` on first run.
    pub fn load_or_create(dir: &Path) -> std::io::Result<Config> {
        std::fs::create_dir_all(dir)?;
        let p = Self::path(dir);
        if let Ok(text) = std::fs::read_to_string(&p) {
            if let Ok(c) = serde_json::from_str::<Config>(&text) {
                return Ok(c);
            }
            // Unreadable config: keep it for inspection but never reuse a half-written identity.
            let _ = std::fs::rename(&p, dir.join("config.json.corrupt"));
        }
        let c = Config { device_uuid: uuid::Uuid::new_v4().to_string(), ..Default::default() };
        c.save(dir)?;
        Ok(c)
    }

    pub fn save(&self, dir: &Path) -> std::io::Result<()> {
        write_private(&Self::path(dir), serde_json::to_vec_pretty(self)?.as_slice())
    }

    pub fn is_paired(&self) -> bool {
        self.token.is_some() && self.server_url.is_some()
    }
}

/// Atomic write (tmp + rename), owner-only on Unix.
pub fn write_private(path: &Path, bytes: &[u8]) -> std::io::Result<()> {
    use std::io::Write;
    let tmp = path.with_extension("tmp");
    {
        let mut opts = std::fs::OpenOptions::new();
        opts.write(true).create(true).truncate(true);
        #[cfg(unix)]
        {
            use std::os::unix::fs::OpenOptionsExt;
            opts.mode(0o600);
        }
        let mut f = opts.open(&tmp)?;
        f.write_all(bytes)?;
        f.sync_all()?;
    }
    std::fs::rename(&tmp, path)
}

/// Default per-user data directory for the app.
pub fn default_data_dir() -> PathBuf {
    dirs::data_dir().unwrap_or_else(|| PathBuf::from(".")).join("RI Print")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn uuid_is_stable_and_token_redacted() {
        let d = tempfile::tempdir().unwrap();
        let a = Config::load_or_create(d.path()).unwrap();
        let mut b = Config::load_or_create(d.path()).unwrap();
        assert_eq!(a.device_uuid, b.device_uuid);
        b.token = Some("secret-token".into());
        assert!(!format!("{b:?}").contains("secret-token"));
        b.save(d.path()).unwrap();
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            let mode = std::fs::metadata(Config::path(d.path())).unwrap().permissions().mode();
            assert_eq!(mode & 0o077, 0, "config must not be group/world readable");
        }
    }

    #[test]
    fn corrupt_config_gets_a_new_identity_but_is_kept() {
        let d = tempfile::tempdir().unwrap();
        std::fs::write(Config::path(d.path()), "{not json").unwrap();
        let c = Config::load_or_create(d.path()).unwrap();
        assert!(!c.device_uuid.is_empty());
        assert!(d.path().join("config.json.corrupt").exists());
    }
}
