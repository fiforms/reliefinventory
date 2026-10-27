//! Backups pulled from a *remote* Relief Inventory server this computer is paired with (a federation `backup_client`
//! trust with the `operator.full_read` grant), kept in a local folder and made on a schedule.
//!
//! This is the other direction from `crate::backups`, which copies backups of the Relief Inventory *running on this
//! computer*. Nothing here touches Docker or the Linux layer, so it works on any OS and whether or not a local
//! server is installed.
//!
//! Layout: `identity` (our key, the signing format), `client` (HTTP + [`client::BackupApi`]), `runner` (one backup
//! run, tested against a fake server), `schedule` (when a run is due), `config` (what is paired and what happened),
//! and [`RemoteBackups`] here, which ties them together for the window and the command line.
//!
//! Reports are the intended next thing to fetch over the same trust (`operator.full_read` already covers them):
//! they would be more methods on [`client::RemoteClient`] and a sibling of `runner`, reusing pairing, sessions,
//! the scheduler and the per-server folder.

pub mod client;
pub mod config;
pub mod identity;
pub mod runner;
pub mod schedule;

use std::collections::HashSet;
use std::path::{Path, PathBuf};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use serde::{Deserialize, Serialize};

use client::{BackupApi, RemoteClient, RemoteError};
use config::{RemoteConfig, RemoteServer, Running, ServerState, StateFile};
use runner::{LocalBackup, Outcome, RunOptions};
use schedule::{Frequency, Schedule};

/// How often the scheduler looks for a due run. A run's own timing is by the clock, not by this.
const TICK: Duration = Duration::from_secs(30);

/// One paired server as the window or command line shows it.
#[derive(Debug, Clone, Serialize)]
pub struct ServerView {
    pub id: String,
    pub name: String,
    pub url: String,
    /// The server's key fingerprint, for comparing with the Trust Console.
    pub fingerprint: String,
    pub paired_at: i64,
    pub schedule: Schedule,
    pub scope: String,
    pub keep: u32,
    /// The folder as set (`None` = the default) and where copies really go.
    pub dest_dir: Option<String>,
    pub folder: String,
    pub state: ServerState,
    /// Unix seconds of the next run; at or before now means it is due.
    pub next_run_at: Option<i64>,
    pub files: Vec<LocalBackup>,
    pub total_bytes: u64,
}

/// A setting change; every field left `None` stays as it was. `dest_dir: Some("")` goes back to the default folder.
#[derive(Debug, Clone, Default, Deserialize)]
#[serde(default)]
pub struct Options {
    pub name: Option<String>,
    pub schedule: Option<Schedule>,
    pub scope: Option<String>,
    pub keep: Option<u32>,
    pub dest_dir: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct Paired {
    pub id: String,
    /// Something to tell the person even though pairing worked (the server didn't grant backups).
    pub warning: Option<String>,
}

pub struct RemoteBackups {
    dir: PathBuf,
    keys: Arc<identity::Keys>,
    /// Serialises read-modify-write of the two JSON files within this process.
    files: Mutex<()>,
    running: Mutex<HashSet<String>>,
}

impl RemoteBackups {
    pub fn new(dir: &Path) -> std::io::Result<Arc<Self>> {
        let this = Arc::new(RemoteBackups {
            dir: dir.to_path_buf(),
            keys: Arc::new(identity::Keys::load_or_create(dir)?),
            files: Mutex::new(()),
            running: Mutex::default(),
        });
        this.clear_stale_progress();
        Ok(this)
    }

    /// A run that was killed (a crash, a restart) leaves "running" in the state file. If nothing holds that
    /// server's folder lock, nothing is running, so say so.
    fn clear_stale_progress(&self) {
        let cfg = RemoteConfig::load(&self.dir);
        for s in &cfg.servers {
            let folder = s.backup_dir(&self.dir);
            if StateFile::load(&self.dir).servers.get(&s.id).is_some_and(|st| st.running.is_some()) && folder.is_dir() && runner::lock_folder(&folder).is_ok() {
                self.update_state(&s.id, |st| st.running = None);
            }
        }
    }

    fn update_state(&self, id: &str, f: impl FnOnce(&mut ServerState)) {
        let _g = self.files.lock().unwrap();
        let mut all = StateFile::load(&self.dir);
        f(all.servers.entry(id.to_string()).or_default());
        if let Err(e) = all.save(&self.dir) {
            tracing::warn!("could not save backup state: {e}");
        }
    }

    pub fn list(&self) -> Vec<ServerView> {
        let cfg = RemoteConfig::load(&self.dir);
        let state = StateFile::load(&self.dir);
        let now = chrono::Local::now();
        cfg.servers
            .iter()
            .map(|s| {
                let st = state.servers.get(&s.id).cloned().unwrap_or_default();
                let folder = s.backup_dir(&self.dir);
                let files = runner::list_local(&folder);
                ServerView {
                    id: s.id.clone(),
                    name: s.name.clone(),
                    url: s.url.clone(),
                    fingerprint: identity::fingerprint(&s.server_public_key).unwrap_or_default(),
                    paired_at: s.paired_at,
                    schedule: s.schedule,
                    scope: s.scope.clone(),
                    keep: s.keep,
                    dest_dir: s.dest_dir.clone(),
                    folder: folder.to_string_lossy().into_owned(),
                    next_run_at: if st.needs_pairing { None } else { schedule::next_attempt_at(&s.schedule, &st.record, &now) },
                    total_bytes: files.iter().map(|f| f.size).sum(),
                    files,
                    state: st,
                }
            })
            .collect()
    }

    /// Whether any server has a schedule that will run, so the program shouldn't quit on its own.
    pub fn has_active_schedule(&self) -> bool {
        let state = StateFile::load(&self.dir);
        RemoteConfig::load(&self.dir)
            .servers
            .iter()
            .any(|s| s.schedule.frequency != Frequency::Off && !state.servers.get(&s.id).is_some_and(|st| st.needs_pairing))
    }

    /// Where a server's copies are (for "show in folder").
    pub fn folder(&self, id: &str) -> Option<PathBuf> {
        RemoteConfig::load(&self.dir).get(id).map(|s| s.backup_dir(&self.dir))
    }

    /// Pair with a server using a code from its Trust Console ("Backup client"). Pairing again (after the server
    /// un-paired us, say) keeps the settings and the folder.
    pub async fn pair(&self, address: &str, code: &str, name: Option<&str>) -> Result<Paired, RemoteError> {
        let url = client::normalize_server_url(address).map_err(RemoteError::Validation)?;
        client::check_transport(&url).map_err(RemoteError::Validation)?;
        if code.trim().is_empty() {
            return Err(RemoteError::Validation("Enter the pairing code.".into()));
        }
        let host = gethostname::gethostname().to_string_lossy().into_owned();
        let api = RemoteClient::new(&url, self.keys.clone());
        let reply = api.pair(code.trim(), &format!("RI Launcher on {host}")).await?;
        if reply.server.uuid.is_empty() || identity::unb64(&reply.server.public_key).is_none() {
            return Err(RemoteError::Http(201, "the server's answer didn't identify it".into()));
        }

        // What it grants us is the first thing to check: a pairing code made without the backup grant pairs fine and then
        // fails on every call, which is a confusing way to find out.
        let warning = match api.grants().await {
            Ok(g) if g.iter().any(|p| p == client::GRANT) => None,
            Ok(_) => Some("Paired, but this server did not allow backups. Ask its administrator to turn on \"Backup and reporting operator\" for this computer in the Trust Console.".to_string()),
            Err(e) => Some(format!("Paired, but could not confirm what the server allows: {e}")),
        };

        let _g = self.files.lock().unwrap();
        let mut cfg = RemoteConfig::load(&self.dir);
        let label = name.map(str::trim).filter(|n| !n.is_empty()).map(str::to_string).unwrap_or_else(|| reply.server.name.clone());
        let id = match cfg.servers.iter_mut().find(|s| s.server_uuid == reply.server.uuid) {
            Some(existing) => {
                existing.url = url;
                existing.server_public_key = reply.server.public_key.clone();
                existing.paired_at = chrono::Utc::now().timestamp();
                if name.is_some_and(|n| !n.trim().is_empty()) {
                    existing.name = label;
                }
                existing.id.clone()
            }
            None => {
                let id = identity::random_uuid()[..8].to_string();
                cfg.servers.push(RemoteServer {
                    id: id.clone(),
                    name: label,
                    url,
                    server_uuid: reply.server.uuid.clone(),
                    server_public_key: reply.server.public_key.clone(),
                    paired_at: chrono::Utc::now().timestamp(),
                    // A paired backup computer is for backing up: daily at 2 a.m. unless changed.
                    schedule: Schedule { frequency: Frequency::Daily, ..Schedule::default() },
                    scope: "database".into(),
                    keep: config::DEFAULT_KEEP,
                    dest_dir: None,
                });
                id
            }
        };
        cfg.save(&self.dir).map_err(|e| RemoteError::Local(format!("could not save the pairing: {e}")))?;
        let mut all = StateFile::load(&self.dir);
        let st = all.servers.entry(id.clone()).or_default();
        st.needs_pairing = false;
        st.last_error = warning.clone();
        let _ = all.save(&self.dir);
        Ok(Paired { id, warning })
    }

    /// Forget a server here. Its trust on the server stays until an administrator removes it there (there is no
    /// call for us to do that); copies already downloaded are kept unless `delete_files`.
    pub fn remove(&self, id: &str, delete_files: bool) -> Result<(), String> {
        if self.running.lock().unwrap().contains(id) {
            return Err("A backup is running for this server. Wait for it to finish.".into());
        }
        let _g = self.files.lock().unwrap();
        let mut cfg = RemoteConfig::load(&self.dir);
        let server = cfg.get(id).cloned().ok_or("That server isn't in the list.")?;
        if delete_files {
            for f in runner::list_local(&server.backup_dir(&self.dir)) {
                let p = PathBuf::from(&f.path);
                let _ = make_writable(&p);
                std::fs::remove_file(&p).map_err(|e| format!("{}: {e}", p.display()))?;
            }
        }
        cfg.servers.retain(|s| s.id != id);
        cfg.save(&self.dir).map_err(|e| e.to_string())?;
        let mut all = StateFile::load(&self.dir);
        all.servers.remove(id);
        let _ = all.save(&self.dir);
        Ok(())
    }

    pub fn configure(&self, id: &str, o: Options) -> Result<(), String> {
        if let Some(s) = &o.schedule {
            s.validate()?;
        }
        if let Some(s) = &o.scope {
            config::validate_scope(s)?;
        }
        if let Some(k) = o.keep {
            config::validate_keep(k)?;
        }
        if let Some(n) = &o.name {
            if n.trim().is_empty() {
                return Err("Give the server a name.".into());
            }
        }
        let _g = self.files.lock().unwrap();
        let mut cfg = RemoteConfig::load(&self.dir);
        let s = cfg.get_mut(id).ok_or("That server isn't in the list.")?;
        if let Some(n) = o.name {
            s.name = n.trim().to_string();
        }
        if let Some(sc) = o.schedule {
            s.schedule = sc;
        }
        if let Some(sc) = o.scope {
            s.scope = sc;
        }
        if let Some(k) = o.keep {
            s.keep = k;
        }
        if let Some(d) = o.dest_dir {
            s.dest_dir = (!d.trim().is_empty()).then(|| d.trim().to_string());
            if let Some(d) = &s.dest_dir {
                if !Path::new(d).is_absolute() {
                    return Err("Use the full path of the folder, like /mnt/backups or D:\\Backups.".into());
                }
            }
        }
        cfg.save(&self.dir).map_err(|e| e.to_string())
    }

    /// Start a backup in the background and return at once; the window follows `list()` for progress.
    pub fn start_now(self: &Arc<Self>, id: &str) -> Result<(), String> {
        if RemoteConfig::load(&self.dir).get(id).is_none() {
            return Err("That server isn't in the list.".into());
        }
        if self.running.lock().unwrap().contains(id) {
            return Err("A backup is already running for this server.".into());
        }
        let (this, id) = (self.clone(), id.to_string());
        tokio::spawn(async move {
            if let Err(e) = this.run_now(&id).await {
                tracing::warn!("backup of {id} failed: {e}");
            }
        });
        Ok(())
    }

    /// Run one backup now and record what happened.
    pub async fn run_now(self: &Arc<Self>, id: &str) -> Result<Outcome, RemoteError> {
        let server = RemoteConfig::load(&self.dir).get(id).cloned().ok_or_else(|| RemoteError::Local("That server isn't in the list.".into()))?;
        let api = RemoteClient::new(&server.url, self.keys.clone());
        self.execute(&server, &api, &RunOptions::default()).await
    }

    async fn execute(self: &Arc<Self>, server: &RemoteServer, api: &dyn BackupApi, opts: &RunOptions) -> Result<Outcome, RemoteError> {
        if !self.running.lock().unwrap().insert(server.id.clone()) {
            return Err(RemoteError::Local("A backup is already running for this server.".into()));
        }
        let started = chrono::Utc::now().timestamp();
        self.update_state(&server.id, |st| {
            st.record.last_attempt_at = Some(started);
            st.running = Some(Running { step: "Starting".into(), detail: String::new(), fraction: None, started_at: started });
        });

        // Progress goes to the state file at most twice a second: a download reports every megabyte.
        let last = Mutex::new(std::time::Instant::now() - Duration::from_secs(1));
        let (this, id) = (self.clone(), server.id.clone());
        let report = move |step: &str, detail: &str, fraction: Option<f32>| {
            let mut last = last.lock().unwrap();
            if last.elapsed() < Duration::from_millis(500) && fraction != Some(1.0) {
                return;
            }
            *last = std::time::Instant::now();
            this.update_state(&id, |st| {
                st.running = Some(Running { step: step.into(), detail: detail.into(), fraction, started_at: started });
            });
        };
        let result = runner::run_backup(api, &server.backup_dir(&self.dir), &server.scope, server.keep, opts, &report).await;

        self.update_state(&server.id, |st| {
            st.running = None;
            match &result {
                Ok(out) => {
                    st.record.last_success_at = Some(chrono::Utc::now().timestamp());
                    st.record.failures = 0;
                    st.last_error = None;
                    st.needs_pairing = false;
                    st.last_file = out.path.file_name().map(|n| n.to_string_lossy().into_owned());
                }
                Err(RemoteError::Revoked) => {
                    st.needs_pairing = true;
                    st.last_error = Some(RemoteError::Revoked.to_string());
                }
                Err(e) => {
                    st.record.failures += 1;
                    st.last_error = Some(e.to_string());
                }
            }
        });
        self.running.lock().unwrap().remove(&server.id);
        result
    }

    /// Run whatever is due, one server at a time (a backup is heavy for both ends).
    pub async fn tick(self: &Arc<Self>) {
        let cfg = RemoteConfig::load(&self.dir);
        let now = chrono::Local::now();
        for s in cfg.servers {
            let st = StateFile::load(&self.dir).servers.get(&s.id).cloned().unwrap_or_default();
            if st.needs_pairing || self.running.lock().unwrap().contains(&s.id) || !schedule::is_due(&s.schedule, &st.record, &now) {
                continue;
            }
            tracing::info!("scheduled backup of {} is due", s.name);
            if let Err(e) = self.run_now(&s.id).await {
                tracing::warn!("scheduled backup of {} failed: {e}", s.name);
            }
        }
    }

    /// The scheduler: checks every 30 seconds, for as long as the program lives.
    pub async fn run_forever(self: Arc<Self>) {
        loop {
            self.tick().await;
            tokio::time::sleep(TICK).await;
        }
    }
}

/// Our copies are read-only; Windows refuses to delete those.
fn make_writable(path: &Path) -> std::io::Result<()> {
    let mut perm = std::fs::metadata(path)?.permissions();
    #[allow(clippy::permissions_set_readonly_false)]
    perm.set_readonly(false);
    std::fs::set_permissions(path, perm)
}

#[cfg(test)]
mod tests {
    use super::*;
    use runner::tests::{info, Fake};
    use std::collections::VecDeque;

    fn setup() -> (tempfile::TempDir, Arc<RemoteBackups>) {
        let d = tempfile::tempdir().unwrap();
        let rb = RemoteBackups::new(d.path()).unwrap();
        (d, rb)
    }

    fn add_server(d: &Path, id: &str, schedule: Schedule) -> RemoteServer {
        let s = RemoteServer {
            id: id.into(),
            name: "Statesville".into(),
            url: "https://ri.example.org".into(),
            server_uuid: "srv".into(),
            server_public_key: identity::b64(&[7u8; 32]),
            paired_at: 1,
            schedule,
            scope: "database".into(),
            keep: 3,
            dest_dir: None,
        };
        let mut cfg = RemoteConfig::load(d);
        cfg.servers.push(s.clone());
        cfg.save(d).unwrap();
        s
    }

    fn daily() -> Schedule {
        Schedule { frequency: Frequency::Daily, ..Schedule::default() }
    }

    fn good_fake() -> Fake {
        let data = b"zip-bytes".to_vec();
        Fake {
            create: Mutex::new(VecDeque::from([Ok(info(1, "database", "complete", &data))])),
            data,
            ..Default::default()
        }
    }

    fn fast() -> RunOptions {
        RunOptions { poll: Duration::from_millis(1), timeout: Duration::from_millis(200), retry_pause: Duration::from_millis(1) }
    }

    #[tokio::test]
    async fn a_good_run_is_recorded_and_the_next_one_isnt_due_until_a_slot_passes() {
        let (d, rb) = setup();
        let s = add_server(d.path(), "a1", daily());
        assert!(rb.list()[0].next_run_at.is_some_and(|t| t <= chrono::Utc::now().timestamp()), "never run: due now");

        let out = rb.execute(&s, &good_fake(), &fast()).await.unwrap();
        let v = &rb.list()[0];
        assert_eq!(v.files.len(), 1);
        assert_eq!(v.state.last_file.as_deref(), out.path.file_name().unwrap().to_str());
        assert!(v.state.last_error.is_none() && v.state.running.is_none() && v.state.record.failures == 0);
        assert!(v.next_run_at.unwrap() > chrono::Utc::now().timestamp());
        assert!(!schedule::is_due(&s.schedule, &v.state.record, &chrono::Local::now()));
    }

    #[tokio::test]
    async fn a_failure_is_recorded_with_a_retry_time_and_the_next_success_clears_it() {
        let (d, rb) = setup();
        let s = add_server(d.path(), "a1", daily());
        let down = Fake { create: Mutex::new(VecDeque::from([Err(RemoteError::Network("connection failed".into()))])), ..Default::default() };
        assert!(rb.execute(&s, &down, &fast()).await.is_err());
        let v = &rb.list()[0];
        assert_eq!(v.state.record.failures, 1);
        assert!(v.state.last_error.as_deref().unwrap().contains("connection failed"));
        assert!(!schedule::is_due(&s.schedule, &v.state.record, &chrono::Local::now()), "waits out the backoff");

        rb.execute(&s, &good_fake(), &fast()).await.unwrap();
        let v = &rb.list()[0];
        assert!(v.state.record.failures == 0 && v.state.last_error.is_none());
    }

    #[tokio::test]
    async fn being_unpaired_stops_scheduling_until_paired_again() {
        let (d, rb) = setup();
        let s = add_server(d.path(), "a1", daily());
        let revoked = Fake { create: Mutex::new(VecDeque::from([Err(RemoteError::Revoked)])), ..Default::default() };
        assert_eq!(rb.execute(&s, &revoked, &fast()).await.unwrap_err(), RemoteError::Revoked);
        let v = &rb.list()[0];
        assert!(v.state.needs_pairing && v.next_run_at.is_none());
        rb.tick().await; // would panic on a real attempt: no fake is wired in, so it must skip this server
    }

    #[tokio::test]
    async fn only_one_run_per_server_at_a_time() {
        let (d, rb) = setup();
        let s = add_server(d.path(), "a1", daily());
        rb.running.lock().unwrap().insert("a1".into());
        assert!(rb.execute(&s, &good_fake(), &fast()).await.unwrap_err().to_string().contains("already running"));
        assert!(rb.start_now("a1").is_err());
    }

    #[tokio::test]
    async fn an_off_schedule_never_runs_on_its_own() {
        let (d, rb) = setup();
        assert!(!rb.has_active_schedule());
        add_server(d.path(), "a1", Schedule::default());
        assert!(!rb.has_active_schedule());
        rb.configure("a1", Options { schedule: Some(daily()), ..Default::default() }).unwrap();
        assert!(rb.has_active_schedule());
        rb.configure("a1", Options { schedule: Some(Schedule::default()), ..Default::default() }).unwrap();
        rb.tick().await;
        assert!(rb.list()[0].state.record.last_attempt_at.is_none());
        assert!(rb.list()[0].next_run_at.is_none());
    }

    #[test]
    fn settings_are_validated_and_only_the_named_fields_change() {
        let (d, rb) = setup();
        add_server(d.path(), "a1", daily());
        assert!(rb.configure("a1", Options { keep: Some(0), ..Default::default() }).is_err());
        assert!(rb.configure("a1", Options { scope: Some("storage".into()), ..Default::default() }).is_err());
        assert!(rb.configure("a1", Options { dest_dir: Some("relative/path".into()), ..Default::default() }).is_err());
        assert!(rb.configure("a1", Options { schedule: Some(Schedule { hour: 30, ..daily() }), ..Default::default() }).is_err());
        assert!(rb.configure("nope", Options::default()).is_err());

        let target = d.path().join("usb");
        rb.configure("a1", Options { keep: Some(30), scope: Some("full".into()), dest_dir: Some(target.to_string_lossy().into()), ..Default::default() }).unwrap();
        let v = &rb.list()[0];
        assert_eq!((v.keep, v.scope.as_str(), v.name.as_str()), (30, "full", "Statesville"));
        assert_eq!(v.folder, target.to_string_lossy());
        rb.configure("a1", Options { dest_dir: Some(String::new()), ..Default::default() }).unwrap();
        assert!(rb.list()[0].dest_dir.is_none());
    }

    #[tokio::test]
    async fn removing_a_server_keeps_or_deletes_its_copies() {
        let (d, rb) = setup();
        let s = add_server(d.path(), "a1", daily());
        rb.execute(&s, &good_fake(), &fast()).await.unwrap();
        let folder = rb.folder("a1").unwrap();

        rb.remove("a1", false).unwrap();
        assert!(rb.list().is_empty() && !runner::list_local(&folder).is_empty(), "copies kept");

        let s = add_server(d.path(), "a2", daily());
        rb.execute(&s, &good_fake(), &fast()).await.unwrap();
        let folder = rb.folder("a2").unwrap();
        assert_eq!(runner::list_local(&folder).len(), 1);
        rb.remove("a2", true).unwrap();
        assert!(runner::list_local(&folder).is_empty());
        assert!(rb.remove("a2", false).is_err());
    }

    #[test]
    fn a_stale_running_flag_from_a_killed_run_is_cleared_at_start() {
        let d = tempfile::tempdir().unwrap();
        let s = add_server(d.path(), "a1", daily());
        std::fs::create_dir_all(s.backup_dir(d.path())).unwrap();
        let mut st = StateFile::default();
        st.servers.insert("a1".into(), ServerState { running: Some(Running { step: "x".into(), detail: String::new(), fraction: None, started_at: 1 }), ..Default::default() });
        st.save(d.path()).unwrap();
        let rb = RemoteBackups::new(d.path()).unwrap();
        assert!(rb.list()[0].state.running.is_none());

        // but a folder another process really is using keeps its flag
        let busy = tempfile::tempdir().unwrap();
        let s2 = add_server(busy.path(), "b1", daily());
        std::fs::create_dir_all(s2.backup_dir(busy.path())).unwrap();
        let mut st = StateFile::default();
        st.servers.insert("b1".into(), ServerState { running: Some(Running { step: "x".into(), detail: String::new(), fraction: None, started_at: 1 }), ..Default::default() });
        st.save(busy.path()).unwrap();
        let _held = runner::lock_folder(&s2.backup_dir(busy.path())).unwrap();
        let rb = RemoteBackups::new(busy.path()).unwrap();
        assert!(rb.list()[0].state.running.is_some());
    }
}
