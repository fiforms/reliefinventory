//! Wire types for the device contract and print extension
//! (`rstation/print-extension.openapi.yaml`). Unknown fields are ignored on the way in, as the
//! contract requires.

use serde::{Deserialize, Serialize};

#[derive(Serialize, Deserialize, Clone, Copy, PartialEq, Eq, Debug)]
#[serde(rename_all = "lowercase")]
pub enum PrinterState {
    Idle,
    Printing,
    Error,
    Offline,
}

/// One OS print queue as reported in the heartbeat. `queue_name` is the printer's identity on the
/// server, so it must be the OS's stable name.
#[derive(Serialize, Deserialize, Clone, PartialEq, Eq, Debug)]
pub struct PrinterInfo {
    pub queue_name: String,
    pub state: PrinterState,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub state_message: Option<String>,
}

#[derive(Serialize, Debug, Default)]
pub struct HeartbeatRequest {
    pub app_version: Option<String>,
    pub architecture: Option<String>,
    pub hostname: Option<String>,
    pub print_platform: Option<String>,
    /// `None` leaves the server's snapshot alone (used when the OS printer list couldn't be read).
    #[serde(skip_serializing_if = "Option::is_none")]
    pub print_printers: Option<Vec<PrinterInfo>>,
}

#[derive(Deserialize, Clone, PartialEq, Eq)]
pub struct PrintRealtime {
    pub key: String,
    pub host: String,
    pub port: u16,
    pub scheme: String,
    #[serde(default)]
    pub path: String,
    pub channel: String,
    pub event: String,
}

impl std::fmt::Debug for PrintRealtime {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "PrintRealtime({self}, channel: <redacted>)")
    }
}

impl std::fmt::Display for PrintRealtime {
    /// Never shows the channel: its unguessable name is the only secret.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "{}://{}:{}", self.scheme, self.host, self.port)
    }
}

#[derive(Deserialize, Debug, Default)]
pub struct HeartbeatResponse {
    pub device_name: Option<String>,
    pub entity_name: Option<String>,
    #[serde(default)]
    pub print_realtime: Option<PrintRealtime>,
}

#[derive(Deserialize, Clone, Debug, PartialEq)]
pub struct PrintJob {
    pub id: i64,
    pub queue_name: String,
    #[serde(default)]
    pub title: Option<String>,
    pub copies: u32,
    #[serde(default)]
    pub media: String,
    #[serde(default)]
    pub width_in: f64,
    #[serde(default)]
    pub height_in: f64,
}

#[derive(Deserialize, Debug)]
pub struct JobList {
    pub jobs: Vec<PrintJob>,
}

#[derive(Deserialize, Debug)]
pub struct ClaimResponse {
    pub id: i64,
    #[serde(default)]
    pub claim_expires_at: Option<String>,
}

#[derive(Serialize, Debug)]
pub struct ResultRequest<'a> {
    pub status: &'a str,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub error: Option<&'a str>,
}

#[derive(Deserialize, Debug)]
pub struct PairResponse {
    pub token: String,
    #[serde(default)]
    pub entity_name: Option<String>,
}

/// Truncate to at most `max` characters (the server rejects over-long strings with a 422).
pub fn clip(s: &str, max: usize) -> String {
    s.chars().take(max).collect()
}
