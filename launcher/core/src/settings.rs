use serde::{Deserialize, Serialize};
use std::path::{Path, PathBuf};

pub const DEFAULT_PORT: u16 = 8080;

/// `Lan`: other devices on the network can open the app (the proxy listens on every interface).
/// `Local`: only this computer can (the proxy listens on 127.0.0.1).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum Mode {
    Lan,
    Local,
}

/// Which image tag the stack follows. `Latest`: the newest published release. `Edge`: unreleased development
/// builds, for developers and testers (never for real data).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize, Default)]
#[serde(rename_all = "lowercase")]
pub enum Channel {
    #[default]
    Latest,
    Edge,
}

impl Channel {
    pub fn tag(self) -> &'static str {
        match self {
            Channel::Latest => "latest",
            Channel::Edge => "edge",
        }
    }
}

/// The few things the launcher lets a person change. The compose file is regenerated from these every
/// time the app starts, so this file (not the YAML inside the Linux layer) is the source of truth.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(default)]
pub struct Settings {
    pub mode: Mode,
    /// Name other devices type, e.g. `win11machine.local`. None = this computer's name + `.local`.
    pub hostname: Option<String>,
    pub port: u16,
    /// PHP timezone name for the app (None = the app's default, UTC).
    pub timezone: Option<String>,
    /// Which release channel the app image follows.
    pub channel: Channel,
}

impl Default for Settings {
    fn default() -> Self {
        Settings { mode: Mode::Lan, hostname: None, port: DEFAULT_PORT, timezone: None, channel: Channel::Latest }
    }
}

#[derive(Debug, thiserror::Error, PartialEq)]
pub enum SettingsError {
    #[error("{0}")]
    Invalid(String),
}

/// A DNS-style name (`win11machine.local`) or an IPv4 address. Returns it lowercased.
pub fn validate_hostname(input: &str) -> Result<String, SettingsError> {
    let h = input.trim().trim_end_matches('.').to_ascii_lowercase();
    let bad = |m: &str| Err(SettingsError::Invalid(m.to_string()));
    if h.is_empty() {
        return bad("Enter a computer name, like office-pc.local");
    }
    if h.len() > 253 {
        return bad("That name is too long");
    }
    if h.parse::<std::net::Ipv4Addr>().is_ok() {
        return Ok(h);
    }
    for label in h.split('.') {
        let ok = !label.is_empty()
            && label.len() <= 63
            && !label.starts_with('-')
            && !label.ends_with('-')
            && label.chars().all(|c| c.is_ascii_alphanumeric() || c == '-');
        if !ok {
            return bad("Use only letters, numbers and hyphens, with dots between parts (no spaces)");
        }
    }
    Ok(h)
}

pub fn validate_timezone(input: &str) -> Result<String, SettingsError> {
    let t = input.trim();
    let ok = !t.is_empty()
        && t.len() <= 64
        && t.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '/' | '_' | '-' | '+'));
    if ok {
        Ok(t.to_string())
    } else {
        Err(SettingsError::Invalid("Use a timezone name like America/New_York".into()))
    }
}

pub fn validate_port(port: u16) -> Result<u16, SettingsError> {
    if port < 1024 || port == crate::platform::INTERNAL_PORT {
        Err(SettingsError::Invalid(format!(
            "Pick a port from 1024 up, other than {}",
            crate::platform::INTERNAL_PORT
        )))
    } else {
        Ok(port)
    }
}

pub fn settings_path(dir: &Path) -> PathBuf {
    dir.join("settings.json")
}

impl Settings {
    /// Missing or unreadable file = defaults, so a first run (or a corrupt file) never blocks the app.
    pub fn load(dir: &Path) -> Settings {
        std::fs::read(settings_path(dir))
            .ok()
            .and_then(|b| serde_json::from_slice(&b).ok())
            .unwrap_or_default()
    }

    pub fn save(&self, dir: &Path) -> std::io::Result<()> {
        std::fs::create_dir_all(dir)?;
        let tmp = dir.join("settings.json.tmp");
        std::fs::write(&tmp, serde_json::to_vec_pretty(self)?)?;
        std::fs::rename(tmp, settings_path(dir))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn hostnames() {
        assert_eq!(validate_hostname(" Win11Machine.local ").unwrap(), "win11machine.local");
        assert_eq!(validate_hostname("10.0.0.5").unwrap(), "10.0.0.5");
        assert!(validate_hostname("").is_err());
        assert!(validate_hostname("has space.local").is_err());
        assert!(validate_hostname("-bad.local").is_err());
        assert!(validate_hostname("a..b").is_err());
        assert!(validate_hostname("http://x").is_err());
    }

    #[test]
    fn ports() {
        assert!(validate_port(8080).is_ok());
        assert!(validate_port(80).is_err());
        assert!(validate_port(crate::platform::INTERNAL_PORT).is_err());
    }

    #[test]
    fn roundtrip_and_defaults() {
        let d = tempfile::tempdir().unwrap();
        assert_eq!(Settings::load(d.path()), Settings::default());
        let s = Settings { mode: Mode::Local, hostname: Some("x.local".into()), port: 9000, timezone: None, channel: Channel::Edge };
        s.save(d.path()).unwrap();
        assert_eq!(Settings::load(d.path()), s);
        std::fs::write(settings_path(d.path()), b"{not json").unwrap();
        assert_eq!(Settings::load(d.path()), Settings::default());
    }
}
