//! The crash-recovery ledger (`PRINT_CLIENT_SPEC.md` §3.7): "never print a job twice, never lose a
//! result". Every state change is written to disk (atomically) *before* the next irreversible step,
//! so after a crash we know exactly what happened:
//!
//! ```text
//! Claimed ──► Submitting ──► Submitted{os_job} ──► PrintedUnreported ──► Reported
//!    │             │                                 FailedUnreported ──►
//!    │             └─ crash here: unknown whether the OS got it ─► reported failed ("check the printer")
//!    └─ crash here: nothing was sent, safe to claim and print again
//! ```

use std::collections::HashMap;
use std::path::{Path, PathBuf};
use std::sync::Mutex;
use std::time::{SystemTime, UNIX_EPOCH};

use serde::{Deserialize, Serialize};

#[derive(Serialize, Deserialize, Clone, Debug, PartialEq, Eq)]
#[serde(tag = "state", rename_all = "snake_case")]
pub enum JobState {
    Claimed,
    /// About to hand the file to the OS; the OS job id is not known yet.
    Submitting,
    Submitted { os_job: String },
    PrintedUnreported,
    FailedUnreported { error: String },
    Reported,
}

#[derive(Serialize, Deserialize, Clone, Debug)]
pub struct Entry {
    #[serde(flatten)]
    pub state: JobState,
    pub queue_name: String,
    #[serde(default)]
    pub title: Option<String>,
    pub updated_at: u64,
}

pub struct Ledger {
    path: PathBuf,
    entries: Mutex<HashMap<i64, Entry>>,
}

const KEEP_REPORTED_SECS: u64 = 7 * 24 * 3600;

fn now() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0)
}

impl Ledger {
    pub fn open(dir: &Path) -> std::io::Result<Ledger> {
        std::fs::create_dir_all(dir)?;
        let path = dir.join("ledger.json");
        let mut entries: HashMap<i64, Entry> = std::fs::read_to_string(&path)
            .ok()
            .and_then(|t| serde_json::from_str(&t).ok())
            .unwrap_or_default();
        let cutoff = now().saturating_sub(KEEP_REPORTED_SECS);
        entries.retain(|_, e| e.state != JobState::Reported || e.updated_at >= cutoff);
        Ok(Ledger { path, entries: Mutex::new(entries) })
    }

    pub fn get(&self, id: i64) -> Option<Entry> {
        self.entries.lock().unwrap().get(&id).cloned()
    }

    /// Record a state. Durable before returning.
    pub fn set(&self, id: i64, queue_name: &str, title: Option<&str>, state: JobState) {
        let mut g = self.entries.lock().unwrap();
        g.insert(
            id,
            Entry {
                state,
                queue_name: queue_name.to_string(),
                title: title.map(str::to_string),
                updated_at: now(),
            },
        );
        self.persist(&g);
    }

    /// Change just the state of an existing entry (keeps queue/title).
    pub fn transition(&self, id: i64, state: JobState) {
        let mut g = self.entries.lock().unwrap();
        if let Some(e) = g.get_mut(&id) {
            e.state = state;
            e.updated_at = now();
            self.persist(&g);
        }
    }

    /// Forget a job we never submitted (e.g. the claim was lost before printing).
    pub fn remove(&self, id: i64) {
        let mut g = self.entries.lock().unwrap();
        if g.remove(&id).is_some() {
            self.persist(&g);
        }
    }

    /// Jobs whose outcome is known but hasn't reached the server yet.
    pub fn unreported(&self) -> Vec<(i64, Entry)> {
        self.filtered(|s| matches!(s, JobState::PrintedUnreported | JobState::FailedUnreported { .. }))
    }

    /// Jobs a previous run left mid-flight.
    pub fn interrupted(&self) -> Vec<(i64, Entry)> {
        self.filtered(|s| matches!(s, JobState::Submitting | JobState::Submitted { .. }))
    }

    fn filtered(&self, f: impl Fn(&JobState) -> bool) -> Vec<(i64, Entry)> {
        let mut v: Vec<_> =
            self.entries.lock().unwrap().iter().filter(|(_, e)| f(&e.state)).map(|(k, e)| (*k, e.clone())).collect();
        v.sort_by_key(|(id, _)| *id);
        v
    }

    fn persist(&self, g: &HashMap<i64, Entry>) {
        // A failed ledger write must not take the print loop down; it is logged and we carry on
        // (worst case after a crash: the job is offered again and printed once more).
        match serde_json::to_vec(g) {
            Ok(bytes) => {
                if let Err(e) = crate::config::write_private(&self.path, &bytes) {
                    tracing::error!("could not write ledger: {e}");
                }
            }
            Err(e) => tracing::error!("could not serialise ledger: {e}"),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn survives_restart() {
        let d = tempfile::tempdir().unwrap();
        {
            let l = Ledger::open(d.path()).unwrap();
            l.set(7, "Zebra", Some("Pallet 12"), JobState::Submitted { os_job: "Zebra-9".into() });
            l.set(8, "Zebra", None, JobState::PrintedUnreported);
        }
        let l = Ledger::open(d.path()).unwrap();
        assert_eq!(l.interrupted().len(), 1);
        assert_eq!(l.unreported().len(), 1);
        assert_eq!(l.get(7).unwrap().state, JobState::Submitted { os_job: "Zebra-9".into() });
        l.transition(7, JobState::Reported);
        assert!(l.interrupted().is_empty());
    }

    #[test]
    fn old_reported_entries_are_pruned() {
        let d = tempfile::tempdir().unwrap();
        let l = Ledger::open(d.path()).unwrap();
        l.set(1, "Q", None, JobState::Reported);
        l.entries.lock().unwrap().get_mut(&1).unwrap().updated_at = 1;
        l.set(2, "Q", None, JobState::Reported);
        let l2 = Ledger::open(d.path()).unwrap();
        assert!(l2.get(1).is_none());
        assert!(l2.get(2).is_some());
    }
}
