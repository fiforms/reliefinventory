//! The print server itself: pairing, the heartbeat, the job loop, crash recovery.
//!
//! Behaviour follows `rstation/PRINT_CLIENT_SPEC.md`; the Pi's `print_station.py` is the reference.
//! The shape:
//!
//! * [`Engine::run`] waits until paired, then runs a *session*: a heartbeat loop (printer list every
//!   ~45 s and immediately on any state change), a job loop (poll, or wake via the optional
//!   realtime socket), and the realtime socket. A `401` anywhere ends the session, wipes the token
//!   and returns to waiting for a pairing code.
//! * One job at a time per queue, oldest first. Each job is claim → download → print → report,
//!   with a claim keep-alive while it prints, and every step written to the [`Ledger`] first so a
//!   crash can never double-print or lose a result.

use std::collections::{HashMap, HashSet};
use std::path::PathBuf;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use serde::Serialize;
use tokio::sync::{watch, Notify};
use tokio::task::JoinSet;

use crate::api::{normalize_server_url, ApiClient, ApiError};
use crate::backend::{OsJob, OsJobStatus, PrintBackend, SubmitRequest};
use crate::config::Config;
use crate::ledger::{JobState, Ledger};
use crate::realtime;
use crate::types::*;

#[derive(Clone, Debug)]
pub struct Options {
    pub app_version: String,
    /// Job poll interval without / with a live realtime socket.
    pub poll_fast: Duration,
    pub poll_slow: Duration,
    /// Send a heartbeat at least this often (and at once on a printer change).
    pub heartbeat_every: Duration,
    /// How often to re-read the OS printer list.
    pub printer_poll: Duration,
    /// Claim keep-alive: normally half the remaining claim window, but never above `keepalive_max`
    /// nor below `keepalive_floor` (a skewed clock must not make us hammer the server).
    pub keepalive_max: Duration,
    pub keepalive_floor: Duration,
    /// How long a job may sit on an offline/errored printer before we give up and cancel it.
    pub give_up_after: Duration,
    /// Absolute ceiling for one job on the printer.
    pub max_job: Duration,
    pub follow_interval: Duration,
    /// After giving up on a printer, keep reporting it offline this long.
    pub offline_hold: Duration,
}

impl Default for Options {
    fn default() -> Self {
        Options {
            app_version: env!("CARGO_PKG_VERSION").to_string(),
            poll_fast: Duration::from_secs(4),
            poll_slow: Duration::from_secs(45),
            heartbeat_every: Duration::from_secs(45),
            // PowerShell is slow to start; don't spawn it every few seconds.
            printer_poll: Duration::from_secs(if cfg!(windows) { 10 } else { 5 }),
            keepalive_max: Duration::from_secs(120),
            keepalive_floor: Duration::from_secs(5),
            give_up_after: Duration::from_secs(60),
            max_job: Duration::from_secs(20 * 60),
            follow_interval: Duration::from_secs(2),
            offline_hold: Duration::from_secs(120),
        }
    }
}

#[derive(Serialize, Clone, Debug)]
pub struct JobRecord {
    pub id: i64,
    pub queue_name: String,
    pub title: Option<String>,
    pub copies: u32,
    /// `printing` | `printed` | `failed`
    pub state: String,
    pub error: Option<String>,
    pub at: String,
}

/// Everything the UI shows. Pushed through a `watch` channel; never contains the token or the
/// realtime channel name.
#[derive(Serialize, Clone, Debug, Default)]
pub struct Status {
    pub paired: bool,
    pub server_url: Option<String>,
    pub device_name: Option<String>,
    pub entity_name: Option<String>,
    pub platform: String,
    pub printers: Vec<PrinterInfo>,
    /// Why the OS printer list couldn't be read, if it couldn't.
    pub printers_error: Option<String>,
    pub realtime: bool,
    pub last_heartbeat_at: Option<String>,
    pub last_error: Option<String>,
    /// One-off message for the user (e.g. "this computer was un-paired").
    pub notice: Option<String>,
    pub recent_jobs: Vec<JobRecord>,
}

pub struct Engine {
    opts: Options,
    data_dir: PathBuf,
    backend: Arc<dyn PrintBackend>,
    config: Mutex<Config>,
    ledger: Arc<Ledger>,
    status: watch::Sender<Status>,
    /// Bumped whenever pairing changes, so a running session restarts (or a waiting run starts).
    generation: watch::Sender<u64>,
    wake: Arc<Notify>,
}

/// Exponential backoff 2 s → 60 s with jitter (spec §3.6).
pub fn backoff(failures: u32) -> Duration {
    use rand::Rng;
    let base = 2u64.saturating_mul(1u64 << failures.min(5)).min(60);
    let jitter = rand::thread_rng().gen_range(0.75..1.25);
    Duration::from_millis((base as f64 * 1000.0 * jitter) as u64)
}

fn now_iso() -> String {
    chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs, true)
}

fn hostname() -> String {
    gethostname::gethostname().to_string_lossy().into_owned()
}

enum Outcome {
    Printed,
    Failed(String),
    /// We lost the claim (or couldn't fetch the file) before anything was sent to a printer.
    /// Nothing to report; the server will requeue it.
    Abandoned,
}

impl Engine {
    pub fn new(data_dir: PathBuf, backend: Arc<dyn PrintBackend>, opts: Options) -> std::io::Result<Arc<Engine>> {
        let config = Config::load_or_create(&data_dir)?;
        let ledger = Arc::new(Ledger::open(&data_dir)?);
        // Spool files of jobs that never reached the printer are garbage after a restart.
        let _ = std::fs::remove_dir_all(data_dir.join("spool"));
        let status = Status {
            paired: config.is_paired(),
            server_url: config.server_url.clone(),
            device_name: config.device_name.clone(),
            platform: backend.platform().to_string(),
            ..Default::default()
        };
        Ok(Arc::new(Engine {
            opts,
            data_dir,
            backend,
            config: Mutex::new(config),
            ledger,
            status: watch::channel(status).0,
            generation: watch::channel(0).0,
            wake: Arc::new(Notify::new()),
        }))
    }

    pub fn subscribe(&self) -> watch::Receiver<Status> {
        self.status.subscribe()
    }

    pub fn status(&self) -> Status {
        self.status.borrow().clone()
    }

    pub fn is_paired(&self) -> bool {
        self.config.lock().unwrap().is_paired()
    }

    pub fn server_url(&self) -> Option<String> {
        self.config.lock().unwrap().server_url.clone()
    }

    /// Ask the job loop to poll right now (UI "check now", realtime wake, a queue just freed up).
    pub fn poll_now(&self) {
        self.wake.notify_one();
    }

    fn update(&self, f: impl FnOnce(&mut Status)) {
        self.status.send_modify(f);
    }

    fn bump(&self) {
        self.generation.send_modify(|g| *g += 1);
    }

    /// Pair with a code (from the server's Devices page → "Pair a New Device or Print Server"): send this
    /// machine's public key with the code, and remember the server. Errors are worded for the person typing it.
    pub async fn pair(&self, server_url: &str, code: &str, device_name: Option<&str>) -> Result<(), String> {
        let url = normalize_server_url(server_url)?;
        let code = code.trim();
        if code.chars().filter(char::is_ascii_alphanumeric).count() != 10 {
            return Err("The pairing code is 10 letters and numbers, like ABCDE-FGHJK.".into());
        }
        let name = device_name.map(str::trim).filter(|n| !n.is_empty()).map(str::to_string).unwrap_or_else(hostname);
        let (uuid, identity) = {
            let c = self.config.lock().unwrap();
            (c.device_uuid.clone(), c.identity())
        };
        let identity = identity.ok_or("This computer's key could not be read. Remove the RI Print settings file and start again.")?;
        let api = ApiClient::new(&url, None);
        // /up is optional on a contract server, so only a network failure counts against it.
        if let Err(ApiError::Network(e)) = api.up().await {
            return Err(format!("Can't reach {url}: {e}"));
        }
        let resp = api.pair(code, &name, &uuid, &identity).await.map_err(|e| match e {
            ApiError::Validation(_) => "That pairing code is invalid or has expired. Generate a new one on the Devices page.".to_string(),
            ApiError::RateLimited => "Too many attempts. Wait a minute and try again.".to_string(),
            other => other.to_string(),
        })?;
        {
            let mut c = self.config.lock().unwrap();
            c.server_url = Some(url.clone());
            c.server_public_key = Some(resp.server.public_key.clone());
            c.paired = true;
            c.device_name = Some(name.clone());
            c.save(&self.data_dir).map_err(|e| format!("Paired, but couldn't save the settings: {e}"))?;
        }
        self.update(|s| {
            s.paired = true;
            s.server_url = Some(url);
            s.device_name = Some(name);
            s.entity_name = resp.entity_name;
            s.notice = None;
            s.last_error = None;
        });
        self.bump();
        Ok(())
    }

    /// Forget the pairing on this computer (the server's record stays until an admin removes it).
    pub fn unpair(&self) {
        self.forget_pairing("Un-paired on this computer.");
    }

    /// Forget the server's side of the pairing. The key and `device_uuid` stay: they identify this machine.
    fn forget_pairing(&self, notice: &str) {
        {
            let mut c = self.config.lock().unwrap();
            c.paired = false;
            c.server_public_key = None;
            if let Err(e) = c.save(&self.data_dir) {
                tracing::error!("could not save settings after un-pairing: {e}");
            }
        }
        let notice = notice.to_string();
        self.update(|s| {
            s.paired = false;
            s.realtime = false;
            s.printers.clear();
            s.notice = Some(notice);
        });
        self.bump();
    }

    /// Run forever: wait for a pairing, serve it, repeat. Cancel by dropping/aborting the future.
    pub async fn run(self: Arc<Self>) {
        loop {
            // Subscribe *before* reading the config, so a pairing that lands in between still wakes us.
            let mut gen = self.generation.subscribe();
            let url = {
                let c = self.config.lock().unwrap();
                if c.is_paired() { c.server_url.clone() } else { None }
            };
            let Some(url) = url else {
                if gen.changed().await.is_err() {
                    return;
                }
                continue;
            };
            tokio::select! {
                end = self.clone().session(url) => {
                    if end == SessionEnd::Revoked {
                        tracing::warn!("device was revoked on the server; returning to pairing");
                        self.forget_pairing("This computer was un-paired from the server. Pair it again with a new code.");
                    }
                }
                _ = gen.changed() => {} // re-paired or un-paired: restart with the current settings
            }
        }
    }

    async fn session(self: Arc<Self>, url: String) -> SessionEnd {
        let auth = {
            let c = self.config.lock().unwrap();
            c.identity().map(|id| (id, c.device_uuid.clone()))
        };
        let Some(auth) = auth else {
            return SessionEnd::Revoked; // no usable key: fall back to pairing
        };
        let (rt_tx, rt_rx) = watch::channel(None);
        let ctx = Arc::new(Session {
            engine: self.clone(),
            api: ApiClient::new(&url, Some(auth)),
            printers: Mutex::new(Vec::new()),
            held_offline: Mutex::new(HashMap::new()),
            busy_queues: Mutex::new(HashSet::new()),
            reporting: Mutex::new(HashSet::new()),
            rt_tx,
            rt_up: Arc::new(AtomicBool::new(false)),
            revoked: Notify::new(),
        });
        ctx.refresh_printers().await;
        let realtime_task = {
            let (wake, up) = (self.wake.clone(), ctx.rt_up.clone());
            async move {
                realtime::run(rt_rx, wake, up).await;
                std::future::pending::<()>().await
            }
        };
        tokio::select! {
            _ = ctx.clone().heartbeat_loop() => SessionEnd::Revoked,
            _ = ctx.clone().job_loop() => SessionEnd::Revoked,
            _ = ctx.revoked.notified() => SessionEnd::Revoked,
            _ = realtime_task => SessionEnd::Revoked,
        }
    }
}

#[derive(PartialEq, Eq, Debug)]
enum SessionEnd {
    Revoked,
}

struct Session {
    engine: Arc<Engine>,
    api: ApiClient,
    /// The latest effective printer list (what the heartbeat reports and the job loop checks).
    printers: Mutex<Vec<PrinterInfo>>,
    held_offline: Mutex<HashMap<String, (Instant, String)>>,
    busy_queues: Mutex<HashSet<String>>,
    /// Jobs whose result is being sent right now, so the poll loop's retry pass and the job's own
    /// task never POST the same result concurrently.
    reporting: Mutex<HashSet<i64>>,
    rt_tx: watch::Sender<Option<PrintRealtime>>,
    rt_up: Arc<AtomicBool>,
    revoked: Notify,
}

/// Marks a queue busy for as long as it lives (also on task abort).
struct QueueGuard {
    ctx: Arc<Session>,
    queue: String,
}

impl Drop for QueueGuard {
    fn drop(&mut self) {
        self.ctx.busy_queues.lock().unwrap().remove(&self.queue);
        // Something may be waiting behind this job; don't make it wait for the next poll.
        self.ctx.engine.wake.notify_one();
    }
}

impl Session {
    fn opts(&self) -> &Options {
        &self.engine.opts
    }

    fn printer(&self, queue: &str) -> Option<PrinterInfo> {
        self.printers.lock().unwrap().iter().find(|p| p.queue_name == queue).cloned()
    }

    fn hold_offline(&self, queue: &str, msg: &str) {
        let until = Instant::now() + self.opts().offline_hold;
        self.held_offline.lock().unwrap().insert(queue.to_string(), (until, msg.to_string()));
        // Reflect it immediately in what we show and check.
        let mut g = self.printers.lock().unwrap();
        for p in g.iter_mut().filter(|p| p.queue_name == queue) {
            p.state = PrinterState::Offline;
            p.state_message = Some(msg.to_string());
        }
    }

    /// Re-read the OS printer list, apply "held offline" overrides, publish to the UI.
    /// Returns the effective list, or `None` if the OS list couldn't be read.
    async fn refresh_printers(&self) -> Option<Vec<PrinterInfo>> {
        match self.engine.backend.list_printers().await {
            Ok(mut list) => {
                let now = Instant::now();
                let mut held = self.held_offline.lock().unwrap();
                held.retain(|_, (until, _)| *until > now);
                for p in list.iter_mut() {
                    if let Some((_, msg)) = held.get(&p.queue_name) {
                        if matches!(p.state, PrinterState::Idle | PrinterState::Printing) {
                            p.state = PrinterState::Offline;
                            p.state_message = Some(msg.clone());
                        }
                    }
                }
                drop(held);
                *self.printers.lock().unwrap() = list.clone();
                self.engine.update(|s| {
                    s.printers = list.clone();
                    s.printers_error = None;
                });
                Some(list)
            }
            Err(e) => {
                self.engine.update(|s| s.printers_error = Some(e.0.clone()));
                None
            }
        }
    }

    fn note_job(&self, job: &PrintJob, state: &str, error: Option<&str>) {
        self.note_job_raw(job.id, &job.queue_name, job.title.clone(), job.copies, state, error);
    }

    fn note_job_raw(&self, id: i64, queue: &str, title: Option<String>, copies: u32, state: &str, error: Option<&str>) {
        self.engine.update(|s| {
            s.recent_jobs.retain(|j| j.id != id);
            s.recent_jobs.insert(
                0,
                JobRecord {
                    id,
                    queue_name: queue.to_string(),
                    title,
                    copies,
                    state: state.to_string(),
                    error: error.map(str::to_string),
                    at: now_iso(),
                },
            );
            s.recent_jobs.truncate(30);
        });
    }

    // ---- heartbeat -------------------------------------------------------------------------

    async fn heartbeat_loop(self: Arc<Self>) {
        let mut last_sent: Option<Vec<PrinterInfo>> = None;
        let mut last_beat: Option<Instant> = None;
        let mut fails = 0u32;
        let mut next_try = Instant::now();
        loop {
            let listed = self.refresh_printers().await;
            let changed = listed.as_ref().is_some_and(|l| Some(l) != last_sent.as_ref());
            let due = last_beat.map_or(true, |t| t.elapsed() >= self.opts().heartbeat_every);
            if (changed || due) && Instant::now() >= next_try {
                let req = HeartbeatRequest {
                    app_version: Some(self.opts().app_version.clone()),
                    architecture: Some(std::env::consts::ARCH.to_string()),
                    hostname: Some(hostname()),
                    print_platform: Some(self.engine.backend.platform().to_string()),
                    print_printers: listed.as_ref().map(|l| {
                        l.iter()
                            .map(|p| PrinterInfo {
                                queue_name: clip(&p.queue_name, 255),
                                state: p.state,
                                state_message: p.state_message.as_deref().map(|m| clip(m, 500)),
                            })
                            .collect()
                    }),
                };
                match self.api.heartbeat(&req).await {
                    Ok(resp) => {
                        fails = 0;
                        last_beat = Some(Instant::now());
                        last_sent = listed;
                        self.rt_tx.send_if_modified(|cur| {
                            if *cur != resp.print_realtime {
                                *cur = resp.print_realtime.clone();
                                true
                            } else {
                                false
                            }
                        });
                        self.engine.update(|s| {
                            s.last_heartbeat_at = Some(now_iso());
                            s.last_error = None;
                            if let Some(n) = resp.device_name.clone() {
                                s.device_name = Some(n);
                            }
                            if let Some(n) = resp.entity_name.clone() {
                                s.entity_name = Some(n);
                            }
                        });
                        if let Some(n) = resp.device_name {
                            let mut c = self.engine.config.lock().unwrap();
                            if c.device_name.as_deref() != Some(n.as_str()) {
                                c.device_name = Some(n);
                                let _ = c.save(&self.engine.data_dir);
                            }
                        }
                    }
                    Err(ApiError::Revoked) => return,
                    Err(e) => {
                        fails += 1;
                        next_try = Instant::now() + backoff(fails);
                        tracing::warn!("heartbeat failed: {e}");
                        self.engine.update(|s| s.last_error = Some(e.to_string()));
                    }
                }
            }
            let up = self.rt_up.load(Ordering::Relaxed);
            self.engine.update(|s| {
                if s.realtime != up {
                    s.realtime = up;
                }
            });
            tokio::time::sleep(self.opts().printer_poll).await;
        }
    }

    // ---- job loop --------------------------------------------------------------------------

    /// Claim `queue` for the duration of the returned guard, or `None` if it already has a job.
    fn begin_queue(self: &Arc<Self>, queue: &str) -> Option<QueueGuard> {
        if self.busy_queues.lock().unwrap().insert(queue.to_string()) {
            Some(QueueGuard { ctx: self.clone(), queue: queue.to_string() })
        } else {
            None
        }
    }

    async fn job_loop(self: Arc<Self>) {
        let mut tasks: JoinSet<()> = JoinSet::new();

        // Anything a previous run left mid-flight is resolved before new work for that queue starts.
        for (id, entry) in self.engine.ledger.interrupted() {
            // Interrupted entries of one queue are resumed one after another by one task.
            let queue = entry.queue_name.clone();
            if let Some(guard) = self.begin_queue(&queue) {
                let todo: Vec<_> =
                    self.engine.ledger.interrupted().into_iter().filter(|(_, e)| e.queue_name == queue).collect();
                let ctx = self.clone();
                tasks.spawn(async move {
                    let _guard = guard;
                    for (id, entry) in todo {
                        if ctx.resume(id, entry).await.is_err() {
                            ctx.revoked.notify_one();
                            return;
                        }
                    }
                });
            }
            let _ = id;
        }

        let mut fails = 0u32;
        loop {
            while tasks.try_join_next().is_some() {}
            if self.flush_unreported().await.is_err() {
                return;
            }
            match self.api.list_jobs().await {
                Ok(jobs) => {
                    fails = 0;
                    // Group by queue, keeping the server's oldest-first order.
                    let mut by_queue: Vec<(String, Vec<PrintJob>)> = Vec::new();
                    for job in jobs {
                        // Jobs we've already handled (or are mid-way through) must not occupy a queue.
                        if self.engine.ledger.get(job.id).is_some_and(|e| e.state != JobState::Claimed) {
                            continue;
                        }
                        match by_queue.iter_mut().find(|(q, _)| *q == job.queue_name) {
                            Some((_, v)) => v.push(job),
                            None => by_queue.push((job.queue_name.clone(), vec![job])),
                        }
                    }
                    for (queue, jobs) in by_queue {
                        let Some(guard) = self.begin_queue(&queue) else { continue };
                        let ctx = self.clone();
                        tasks.spawn(async move {
                            let _guard = guard;
                            for job in jobs {
                                if ctx.process_job(job).await.is_err() {
                                    ctx.revoked.notify_one();
                                    return;
                                }
                            }
                        });
                    }
                }
                Err(ApiError::Revoked) => return,
                Err(ApiError::RateLimited) => tokio::time::sleep(Duration::from_secs(10)).await,
                Err(e) => {
                    fails += 1;
                    tracing::warn!("couldn't fetch print jobs: {e}");
                    self.engine.update(|s| s.last_error = Some(e.to_string()));
                }
            }
            let wait = if fails > 0 {
                backoff(fails)
            } else if self.rt_up.load(Ordering::Relaxed) {
                self.opts().poll_slow
            } else {
                self.opts().poll_fast
            };
            tokio::select! {
                _ = self.engine.wake.notified() => {}
                _ = tokio::time::sleep(wait) => {}
            }
        }
    }

    /// Report outcomes a previous attempt couldn't deliver (network down, restart, ...).
    async fn flush_unreported(&self) -> Result<(), ApiError> {
        for (id, _) in self.engine.ledger.unreported() {
            self.report(id).await?;
        }
        Ok(())
    }

    /// Send the ledger's recorded outcome for `id`. Only `Err(Revoked)` propagates; a transient
    /// failure just leaves it for the next flush.
    async fn report(&self, id: i64) -> Result<(), ApiError> {
        if !self.reporting.lock().unwrap().insert(id) {
            return Ok(());
        }
        let result = self.report_inner(id).await;
        self.reporting.lock().unwrap().remove(&id);
        result
    }

    async fn report_inner(&self, id: i64) -> Result<(), ApiError> {
        let Some(entry) = self.engine.ledger.get(id) else { return Ok(()) };
        let (printed, error) = match &entry.state {
            JobState::PrintedUnreported => (true, None),
            JobState::FailedUnreported { error } => (false, Some(error.clone())),
            _ => return Ok(()),
        };
        match self.api.result(id, printed, error.as_deref()).await {
            // 404/409: the server has settled it some other way (expired, cancelled, requeued).
            // Log and drop; never retry (spec §3.6).
            Ok(()) | Err(ApiError::NotFound) | Err(ApiError::Conflict(_)) => {
                self.engine.ledger.transition(id, JobState::Reported);
                Ok(())
            }
            Err(ApiError::Revoked) => Err(ApiError::Revoked),
            Err(e) => {
                tracing::warn!("couldn't report job {id}: {e} (will retry)");
                Ok(())
            }
        }
    }

    async fn process_job(&self, job: PrintJob) -> Result<(), ApiError> {
        let claim = match self.api.claim(job.id).await {
            Ok(c) => c,
            Err(ApiError::Revoked) => return Err(ApiError::Revoked),
            Err(e) => {
                tracing::debug!("not taking job {}: {e}", job.id);
                return Ok(());
            }
        };
        tracing::debug!("job {} claimed for '{}' ({} copies)", job.id, job.queue_name, job.copies);
        self.engine.ledger.set(job.id, &job.queue_name, job.title.as_deref(), JobState::Claimed);
        self.note_job(&job, "printing", None);

        let keepalive = tokio::spawn(keepalive(self.api.clone(), job.id, claim.claim_expires_at, self.opts().clone()));
        let outcome = self.print(&job).await;
        keepalive.abort();
        let outcome = outcome?;

        tracing::debug!(
            "job {} outcome: {}",
            job.id,
            match &outcome {
                Outcome::Printed => "printed".to_string(),
                Outcome::Failed(m) => format!("failed: {m}"),
                Outcome::Abandoned => "abandoned".to_string(),
            }
        );
        match outcome {
            Outcome::Abandoned => {
                self.engine.ledger.remove(job.id);
                self.engine.update(|s| s.recent_jobs.retain(|j| j.id != job.id));
                return Ok(());
            }
            Outcome::Printed => {
                self.engine.ledger.transition(job.id, JobState::PrintedUnreported);
                self.note_job(&job, "printed", None);
            }
            Outcome::Failed(msg) => {
                self.engine.ledger.transition(job.id, JobState::FailedUnreported { error: msg.clone() });
                self.note_job(&job, "failed", Some(&msg));
            }
        }
        self.report(job.id).await
    }

    /// Download and print one claimed job. `Err` only for revocation.
    async fn print(&self, job: &PrintJob) -> Result<Outcome, ApiError> {
        let q = &job.queue_name;

        let bytes = match self.api.payload(job.id).await {
            Ok(b) => b,
            Err(ApiError::Revoked) => return Err(ApiError::Revoked),
            Err(ApiError::Conflict(_)) | Err(ApiError::NotFound) => return Ok(Outcome::Abandoned),
            Err(ApiError::Gone) => {
                return Ok(Outcome::Failed(
                    "The print file is no longer on the server — print it again from the print queue.".into(),
                ))
            }
            Err(e) => {
                tracing::warn!("couldn't download job {}: {e}", job.id);
                return Ok(Outcome::Abandoned); // nothing sent anywhere; the claim lapses and it's re-queued
            }
        };
        if !bytes.starts_with(b"%PDF") {
            return Ok(Outcome::Failed("The server sent something that isn't a PDF.".into()));
        }

        // Check the printer before spending the job on it.
        match self.printer(q) {
            None => return Ok(Outcome::Failed(format!("Printer '{q}' isn't installed on this computer."))),
            Some(p) if p.state == PrinterState::Offline => {
                return Ok(Outcome::Failed(format!(
                    "Printer '{q}' is offline{}.",
                    p.state_message.map(|m| format!(" ({m})")).unwrap_or_default()
                )))
            }
            Some(p) if p.state == PrinterState::Error => {
                return Ok(Outcome::Failed(format!(
                    "Printer '{q}' needs attention: {}.",
                    p.state_message.unwrap_or_else(|| "it reports an error".into())
                )))
            }
            Some(_) => {}
        }

        let spool = self.engine.data_dir.join("spool");
        let file = spool.join(format!("{}.pdf", job.id));
        if let Err(e) = std::fs::create_dir_all(&spool).and_then(|_| std::fs::write(&file, &bytes)) {
            return Ok(Outcome::Failed(format!("Couldn't save the print file on this computer: {e}")));
        }

        // From here on a crash is ambiguous, so record intent *before* touching the OS.
        self.engine.ledger.transition(job.id, JobState::Submitting);
        let req = SubmitRequest {
            queue_name: q.clone(),
            file: file.clone(),
            copies: job.copies,
            title: job.title.clone().unwrap_or_else(|| format!("Relief Inventory job {}", job.id)),
            width_in: job.width_in,
            height_in: job.height_in,
        };
        let outcome = match self.engine.backend.submit(&req).await {
            Err(e) => Outcome::Failed(e.0),
            Ok(os_job) => {
                tracing::debug!("job {} handed to the system as {}", job.id, os_job.0);
                self.engine.ledger.transition(job.id, JobState::Submitted { os_job: os_job.0.clone() });
                self.follow(q, &os_job).await
            }
        };
        let _ = std::fs::remove_file(&file);
        Ok(outcome)
    }

    /// Watch the OS job until it finishes, or give up on a printer that has gone offline/errored
    /// (cancelling the OS job, or it would print later as a duplicate).
    async fn follow(&self, queue: &str, os_job: &OsJob) -> Outcome {
        let started = Instant::now();
        let mut problem_since: Option<Instant> = None;
        let mut errors = 0u32;
        loop {
            let polled = self.engine.backend.job_status(queue, os_job).await;
            tracing::trace!("os job {} on '{queue}': {polled:?}", os_job.0);
            match polled {
                Ok(OsJobStatus::Done) => return Outcome::Printed,
                Ok(OsJobStatus::Failed(m)) => return Outcome::Failed(m),
                Ok(OsJobStatus::Pending) => errors = 0,
                Err(e) => {
                    tracing::debug!("couldn't read status of os job {}: {}", os_job.0, e.0);
                    errors += 1;
                    if errors >= 5 {
                        return Outcome::Failed(format!("Lost track of the print job: {}", e.0));
                    }
                }
            }
            let problem = match self.printer(queue) {
                None => Some("is no longer available".to_string()),
                Some(p) => match p.state {
                    PrinterState::Offline => {
                        Some(format!("is offline{}", p.state_message.map(|m| format!(" ({m})")).unwrap_or_default()))
                    }
                    PrinterState::Error => {
                        Some(format!("has a problem: {}", p.state_message.unwrap_or_else(|| "needs attention".into())))
                    }
                    _ => None,
                },
            };
            match problem {
                Some(what) => {
                    let since = *problem_since.get_or_insert_with(Instant::now);
                    if since.elapsed() >= self.opts().give_up_after {
                        self.engine.backend.cancel(queue, os_job).await;
                        let msg = format!("Printer '{queue}' {what}. The job was cancelled so it won't print later; send it again once the printer is ready.");
                        self.hold_offline(queue, &format!("Gave up on a job: {what}"));
                        return Outcome::Failed(msg);
                    }
                }
                None => problem_since = None,
            }
            if started.elapsed() >= self.opts().max_job {
                self.engine.backend.cancel(queue, os_job).await;
                return Outcome::Failed(format!(
                    "Printer '{queue}' didn't finish within {} minutes, so the job was cancelled.",
                    self.opts().max_job.as_secs() / 60
                ));
            }
            tokio::time::sleep(self.opts().follow_interval).await;
        }
    }

    /// Finish a job a previous run was in the middle of.
    async fn resume(&self, id: i64, entry: crate::ledger::Entry) -> Result<(), ApiError> {
        let outcome = match entry.state {
            // We don't know whether the OS received it. Never guess "printed", never re-print.
            JobState::Submitting => Outcome::Failed(
                "This computer restarted while sending the job to the printer, so it may or may not have printed. Check the printer, then send it again if needed.".into(),
            ),
            JobState::Submitted { os_job } => self.follow(&entry.queue_name, &OsJob(os_job)).await,
            _ => return Ok(()),
        };
        let (state, err) = match outcome {
            Outcome::Printed => (JobState::PrintedUnreported, None),
            Outcome::Failed(m) => (JobState::FailedUnreported { error: m.clone() }, Some(m)),
            Outcome::Abandoned => return Ok(()),
        };
        self.engine.ledger.transition(id, state);
        self.note_job_raw(id, &entry.queue_name, entry.title.clone(), 0, if err.is_none() { "printed" } else { "failed" }, err.as_deref());
        self.report(id).await
    }
}

/// While a job prints, re-claim it at least every half claim-window so the server doesn't take it
/// back and print it twice. Stops when the claim is lost.
async fn keepalive(api: ApiClient, id: i64, first_expiry: Option<String>, opts: Options) {
    let next_delay = |expiry: &Option<String>| -> Duration {
        let derived = expiry
            .as_deref()
            .and_then(|s| chrono::DateTime::parse_from_rfc3339(s).ok())
            .and_then(|t| (t.with_timezone(&chrono::Utc) - chrono::Utc::now()).to_std().ok())
            .map(|remaining| remaining / 2)
            .unwrap_or(opts.keepalive_max);
        derived.clamp(opts.keepalive_floor, opts.keepalive_max)
    };
    let mut delay = next_delay(&first_expiry);
    loop {
        tokio::time::sleep(delay).await;
        match api.claim(id).await {
            Ok(c) => delay = next_delay(&c.claim_expires_at),
            Err(ApiError::NotFound) | Err(ApiError::Conflict(_)) | Err(ApiError::Revoked) => return,
            Err(_) => delay = opts.keepalive_floor.max(Duration::from_secs(1)).min(opts.keepalive_max),
        }
    }
}
