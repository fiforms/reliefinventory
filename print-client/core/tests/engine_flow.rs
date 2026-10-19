//! End-to-end behaviour of the engine against a mock of the server's device API and a fake OS
//! printing backend. Mirrors the conformance checklist in `rstation/PRINT_CLIENT_SPEC.md` §5.

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, AtomicU16, AtomicU32, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use async_trait::async_trait;
use axum::extract::{Path, State};
use axum::http::{HeaderMap, StatusCode};
use axum::response::IntoResponse;
use axum::routing::{get, post};
use axum::{Json, Router};
use ri_print_core::backend::*;
use ri_print_core::config::Config;
use ri_print_core::ledger::{JobState, Ledger};
use ri_print_core::types::{PrinterInfo, PrinterState};
use ri_print_core::{Engine, Options};
use serde_json::{json, Value};

// ---- mock server ----------------------------------------------------------------------------

#[derive(PartialEq, Clone, Copy, Debug)]
enum JobSt {
    Queued,
    Claimed,
    Done,
}

#[derive(Default)]
struct Mock {
    jobs: Mutex<Vec<(Value, JobSt)>>,
    claim_calls: Mutex<Vec<i64>>,
    claim_override: Mutex<HashMap<i64, u16>>,
    claim_window_ms: AtomicU32,
    results: Mutex<Vec<(i64, String, Option<String>)>>,
    heartbeats: Mutex<Vec<Value>>,
    revoked: AtomicBool,
    result_status: AtomicU16, // 0 = normal (200)
    payload_calls: AtomicUsize,
}

type S = Arc<Mock>;

fn authed(m: &Mock, h: &HeaderMap) -> Result<(), StatusCode> {
    let ok = h.get("authorization").and_then(|v| v.to_str().ok()) == Some("Bearer tok-123");
    if ok && !m.revoked.load(Ordering::Relaxed) {
        Ok(())
    } else {
        Err(StatusCode::UNAUTHORIZED)
    }
}

async fn pair(Json(b): Json<Value>) -> impl IntoResponse {
    if b["code"] == "123456" && b["device_uuid"].is_string() && b["device_name"].is_string() {
        (StatusCode::CREATED, Json(json!({"token":"tok-123","entity_name":"Test Org","device_id":1})))
    } else {
        (StatusCode::UNPROCESSABLE_ENTITY, Json(json!({"message":"Invalid code"})))
    }
}

async fn heartbeat(State(m): State<S>, h: HeaderMap, Json(b): Json<Value>) -> impl IntoResponse {
    if let Err(c) = authed(&m, &h) {
        return (c, Json(json!({}))).into_response();
    }
    m.heartbeats.lock().unwrap().push(b);
    Json(json!({"ok":true,"device_name":"Test PC","entity_name":"Test Org","print_realtime":null})).into_response()
}

async fn list_jobs(State(m): State<S>, h: HeaderMap) -> impl IntoResponse {
    if let Err(c) = authed(&m, &h) {
        return (c, Json(json!({}))).into_response();
    }
    let jobs: Vec<Value> =
        m.jobs.lock().unwrap().iter().filter(|(_, s)| *s == JobSt::Queued).map(|(j, _)| j.clone()).collect();
    Json(json!({ "jobs": jobs })).into_response()
}

async fn claim(State(m): State<S>, h: HeaderMap, Path(id): Path<i64>) -> impl IntoResponse {
    if let Err(c) = authed(&m, &h) {
        return (c, Json(json!({}))).into_response();
    }
    m.claim_calls.lock().unwrap().push(id);
    if let Some(code) = m.claim_override.lock().unwrap().get(&id) {
        return (StatusCode::from_u16(*code).unwrap(), Json(json!({"message":"nope"}))).into_response();
    }
    let mut jobs = m.jobs.lock().unwrap();
    let Some((j, st)) = jobs.iter_mut().find(|(j, _)| j["id"] == id) else {
        return (StatusCode::NOT_FOUND, Json(json!({}))).into_response();
    };
    if *st == JobSt::Done {
        return (StatusCode::CONFLICT, Json(json!({"message":"done"}))).into_response();
    }
    *st = JobSt::Claimed;
    let exp = chrono::Utc::now() + chrono::Duration::milliseconds(m.claim_window_ms.load(Ordering::Relaxed) as i64);
    Json(json!({"id":id,"queue_name":j["queue_name"],"copies":j["copies"],"claim_expires_at":exp.to_rfc3339()})).into_response()
}

async fn payload(State(m): State<S>, h: HeaderMap, Path(_id): Path<i64>) -> impl IntoResponse {
    if let Err(c) = authed(&m, &h) {
        return (c, Vec::new()).into_response();
    }
    m.payload_calls.fetch_add(1, Ordering::Relaxed);
    ([("content-type", "application/pdf")], b"%PDF-1.4 fake".to_vec()).into_response()
}

async fn result(State(m): State<S>, h: HeaderMap, Path(id): Path<i64>, Json(b): Json<Value>) -> impl IntoResponse {
    if let Err(c) = authed(&m, &h) {
        return (c, Json(json!({}))).into_response();
    }
    let forced = m.result_status.load(Ordering::Relaxed);
    if forced != 0 {
        return (StatusCode::from_u16(forced).unwrap(), Json(json!({"message":"forced"}))).into_response();
    }
    m.results.lock().unwrap().push((id, b["status"].as_str().unwrap().into(), b["error"].as_str().map(Into::into)));
    if let Some((_, st)) = m.jobs.lock().unwrap().iter_mut().find(|(j, _)| j["id"] == id) {
        *st = JobSt::Done;
    }
    Json(json!({"id":id,"status":b["status"]})).into_response()
}

async fn start_server(m: S) -> String {
    let app = Router::new()
        .route("/up", get(|| async { "ok" }))
        .route("/api/devices/pair", post(pair))
        .route("/api/devices/heartbeat", post(heartbeat))
        .route("/api/devices/print-jobs", get(list_jobs))
        .route("/api/devices/print-jobs/:id/claim", post(claim))
        .route("/api/devices/print-jobs/:id/payload", get(payload))
        .route("/api/devices/print-jobs/:id/result", post(result))
        .with_state(m);
    let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
    let addr = listener.local_addr().unwrap();
    tokio::spawn(async move { axum::serve(listener, app).await.unwrap() });
    format!("http://{addr}")
}

fn add_job(m: &Mock, id: i64, queue: &str, copies: u32) {
    m.jobs.lock().unwrap().push((
        json!({"id":id,"queue_name":queue,"title":format!("Job {id}"),"copies":copies,"media":"report","width_in":8.5,"height_in":11}),
        JobSt::Queued,
    ));
}

// ---- fake OS backend --------------------------------------------------------------------------

struct Fake {
    printers: Mutex<Vec<PrinterInfo>>,
    submits: Mutex<Vec<(String, u32, Vec<u8>)>>,
    cancelled: Mutex<Vec<String>>,
    /// number of job_status polls before a submitted job reports Done
    polls_until_done: AtomicU32,
    hang: AtomicBool,
    submit_error: Mutex<Option<String>>,
    polls: Mutex<HashMap<String, u32>>,
    list_error: AtomicBool,
}

impl Fake {
    fn new(printers: Vec<PrinterInfo>) -> Arc<Fake> {
        Arc::new(Fake {
            printers: Mutex::new(printers),
            submits: Default::default(),
            cancelled: Default::default(),
            polls_until_done: AtomicU32::new(1),
            hang: AtomicBool::new(false),
            submit_error: Default::default(),
            polls: Default::default(),
            list_error: AtomicBool::new(false),
        })
    }
    fn set_state(&self, queue: &str, state: PrinterState, msg: Option<&str>) {
        for p in self.printers.lock().unwrap().iter_mut().filter(|p| p.queue_name == queue) {
            p.state = state;
            p.state_message = msg.map(str::to_string);
        }
    }
}

fn printer(name: &str) -> PrinterInfo {
    PrinterInfo { queue_name: name.into(), state: PrinterState::Idle, state_message: None }
}

#[async_trait]
impl PrintBackend for Fake {
    fn platform(&self) -> &'static str {
        "linux"
    }
    async fn list_printers(&self) -> Result<Vec<PrinterInfo>, BackendError> {
        if self.list_error.load(Ordering::Relaxed) {
            return Err(BackendError::new("CUPS isn't running"));
        }
        Ok(self.printers.lock().unwrap().clone())
    }
    async fn submit(&self, req: &SubmitRequest) -> Result<OsJob, BackendError> {
        if let Some(e) = self.submit_error.lock().unwrap().clone() {
            return Err(BackendError::new(e));
        }
        let bytes = std::fs::read(&req.file).expect("spool file must exist at submit time");
        let mut s = self.submits.lock().unwrap();
        s.push((req.queue_name.clone(), req.copies, bytes));
        Ok(OsJob(format!("{}-{}", req.queue_name, s.len())))
    }
    async fn job_status(&self, _q: &str, job: &OsJob) -> Result<OsJobStatus, BackendError> {
        if self.hang.load(Ordering::Relaxed) {
            return Ok(OsJobStatus::Pending);
        }
        let mut p = self.polls.lock().unwrap();
        let n = p.entry(job.0.clone()).or_insert(0);
        *n += 1;
        Ok(if *n >= self.polls_until_done.load(Ordering::Relaxed) { OsJobStatus::Done } else { OsJobStatus::Pending })
    }
    async fn cancel(&self, _q: &str, job: &OsJob) {
        self.cancelled.lock().unwrap().push(job.0.clone());
    }
}

// ---- harness ------------------------------------------------------------------------------------

fn fast() -> Options {
    Options {
        app_version: "9.9.9-test".into(),
        poll_fast: Duration::from_millis(30),
        poll_slow: Duration::from_millis(100),
        heartbeat_every: Duration::from_secs(5),
        printer_poll: Duration::from_millis(30),
        keepalive_max: Duration::from_secs(2),
        keepalive_floor: Duration::from_millis(50),
        give_up_after: Duration::from_millis(300),
        max_job: Duration::from_secs(10),
        follow_interval: Duration::from_millis(20),
        offline_hold: Duration::from_millis(600),
    }
}

async fn wait_for(what: &str, mut cond: impl FnMut() -> bool) {
    for _ in 0..300 {
        if cond() {
            return;
        }
        tokio::time::sleep(Duration::from_millis(20)).await;
    }
    panic!("timed out waiting for: {what}");
}

struct Rig {
    mock: S,
    fake: Arc<Fake>,
    engine: Arc<Engine>,
    dir: tempfile::TempDir,
    url: String,
}

/// Paired engine running against fresh mocks. `prefill` can seed the ledger before the engine opens it.
async fn rig_with(opts: Options, printers: Vec<PrinterInfo>, prefill: impl FnOnce(&Ledger)) -> Rig {
    let _ = tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .with_test_writer()
        .try_init();
    let mock: S = Arc::new(Mock::default());
    mock.claim_window_ms.store(60_000, Ordering::Relaxed);
    let url = start_server(mock.clone()).await;
    let fake = Fake::new(printers);
    let dir = tempfile::tempdir().unwrap();
    prefill(&Ledger::open(dir.path()).unwrap());
    let engine = Engine::new(dir.path().to_path_buf(), fake.clone(), opts).unwrap();
    engine.pair(&url, "123456", Some("Test PC")).await.unwrap();
    Rig { mock, fake, engine, dir, url }
}

async fn rig(printers: Vec<PrinterInfo>) -> Rig {
    rig_with(fast(), printers, |_| {}).await
}

impl Rig {
    fn run(&self) -> tokio::task::JoinHandle<()> {
        tokio::spawn(self.engine.clone().run())
    }
    fn results(&self) -> Vec<(i64, String, Option<String>)> {
        self.mock.results.lock().unwrap().clone()
    }
}

// ---- pairing --------------------------------------------------------------------------------------

#[tokio::test]
async fn pairing_persists_and_survives_restart() {
    let r = rig(vec![printer("Q")]).await;
    assert!(r.engine.status().paired);
    let cfg = Config::load_or_create(r.dir.path()).unwrap();
    assert_eq!(cfg.token.as_deref(), Some("tok-123"));
    assert_eq!(cfg.server_url.as_deref(), Some(r.url.as_str()));
    // a "restart": a second engine on the same directory is already paired, with the same identity
    let again = Engine::new(r.dir.path().to_path_buf(), r.fake.clone(), fast()).unwrap();
    assert!(again.is_paired());
    assert_eq!(Config::load_or_create(r.dir.path()).unwrap().device_uuid, cfg.device_uuid);
}

#[tokio::test]
async fn pairing_errors_are_readable() {
    let mock: S = Arc::new(Mock::default());
    let url = start_server(mock).await;
    let dir = tempfile::tempdir().unwrap();
    let e = Engine::new(dir.path().to_path_buf(), Fake::new(vec![]), fast()).unwrap();
    assert!(e.pair(&url, "12345", None).await.unwrap_err().contains("6 digits"));
    assert!(e.pair(&url, "000000", None).await.unwrap_err().contains("invalid or has expired"));
    assert!(e.pair("http://127.0.0.1:1", "123456", None).await.unwrap_err().contains("Can't reach"));
    assert!(!e.is_paired(), "a failed pairing must leave no token behind");
}

// ---- heartbeat ------------------------------------------------------------------------------------

#[tokio::test]
async fn heartbeat_reports_all_printers_and_pushes_changes_immediately() {
    let r = rig(vec![printer("Zebra ZD421"), printer("Front Desk")]).await;
    let task = r.run();
    wait_for("first heartbeat", || !r.mock.heartbeats.lock().unwrap().is_empty()).await;
    let first = r.mock.heartbeats.lock().unwrap()[0].clone();
    assert_eq!(first["print_platform"], "linux");
    assert_eq!(first["app_version"], "9.9.9-test");
    let names: Vec<_> = first["print_printers"].as_array().unwrap().iter().map(|p| p["queue_name"].clone()).collect();
    assert_eq!(names, vec![json!("Zebra ZD421"), json!("Front Desk")]);

    // heartbeat_every is 5 s in this test, so a second beat within a moment can only be change-driven
    r.fake.set_state("Zebra ZD421", PrinterState::Error, Some("Out of labels"));
    wait_for("state-change heartbeat", || r.mock.heartbeats.lock().unwrap().len() >= 2).await;
    let second = r.mock.heartbeats.lock().unwrap()[1].clone();
    assert_eq!(second["print_printers"][0]["state"], "error");
    assert_eq!(second["print_printers"][0]["state_message"], "Out of labels");
    task.abort();
}

#[tokio::test]
async fn unreadable_printer_list_omits_the_key_instead_of_reporting_none() {
    let r = rig(vec![printer("Q")]).await;
    r.fake.list_error.store(true, Ordering::Relaxed);
    let task = r.run();
    wait_for("heartbeat", || !r.mock.heartbeats.lock().unwrap().is_empty()).await;
    let hb = r.mock.heartbeats.lock().unwrap()[0].clone();
    assert!(hb.get("print_printers").is_none(), "absent key = leave the server's snapshot alone");
    assert_eq!(r.engine.status().printers_error.as_deref(), Some("CUPS isn't running"));
    task.abort();
}

// ---- the job loop -----------------------------------------------------------------------------------

#[tokio::test]
async fn prints_with_right_copies_and_reports_printed_once() {
    let r = rig(vec![printer("Zebra")]).await;
    add_job(&r.mock, 1, "Zebra", 3);
    let task = r.run();
    wait_for("result", || !r.results().is_empty()).await;
    assert_eq!(r.results(), vec![(1, "printed".to_string(), None)]);
    let s = r.fake.submits.lock().unwrap().clone();
    assert_eq!(s.len(), 1);
    assert_eq!((s[0].0.as_str(), s[0].1), ("Zebra", 3));
    assert_eq!(s[0].2, b"%PDF-1.4 fake");
    assert_eq!(r.engine.status().recent_jobs[0].state, "printed");
    // nothing is left in the spool
    tokio::time::sleep(Duration::from_millis(100)).await;
    assert_eq!(r.mock.results.lock().unwrap().len(), 1);
    assert!(std::fs::read_dir(r.dir.path().join("spool")).map(|d| d.count() == 0).unwrap_or(true));
    task.abort();
}

#[tokio::test]
async fn offline_printer_fails_the_job_with_a_readable_reason_and_prints_nothing() {
    let mut p = printer("Zebra");
    p.state = PrinterState::Offline;
    p.state_message = Some("Printer paused".into());
    let r = rig(vec![p]).await;
    add_job(&r.mock, 1, "Zebra", 1);
    let task = r.run();
    wait_for("result", || !r.results().is_empty()).await;
    let (_, status, err) = r.results()[0].clone();
    assert_eq!(status, "failed");
    assert!(err.unwrap().contains("offline"));
    assert!(r.fake.submits.lock().unwrap().is_empty());
    task.abort();
}

#[tokio::test]
async fn unknown_queue_and_os_rejection_are_reported_failed() {
    let r = rig(vec![printer("Zebra")]).await;
    *r.fake.submit_error.lock().unwrap() = Some("The system refused the print job for 'Zebra': no filter".into());
    add_job(&r.mock, 1, "Missing", 1);
    add_job(&r.mock, 2, "Zebra", 1);
    let task = r.run();
    wait_for("both results", || r.results().len() == 2).await;
    let by_id: HashMap<_, _> = r.results().into_iter().map(|(id, s, e)| (id, (s, e.unwrap_or_default()))).collect();
    assert!(by_id[&1].1.contains("isn't installed"));
    assert!(by_id[&2].1.contains("no filter"));
    task.abort();
}

#[tokio::test]
async fn a_claim_conflict_is_skipped_without_blocking_the_rest_of_the_queue() {
    let r = rig(vec![printer("Zebra")]).await;
    r.mock.claim_override.lock().unwrap().insert(1, 409);
    add_job(&r.mock, 1, "Zebra", 1);
    add_job(&r.mock, 2, "Zebra", 1);
    let task = r.run();
    wait_for("job 2", || !r.results().is_empty()).await;
    assert_eq!(r.results().iter().map(|r| r.0).collect::<Vec<_>>(), vec![2]);
    assert_eq!(r.fake.submits.lock().unwrap().len(), 1);
    task.abort();
}

#[tokio::test]
async fn jobs_on_one_queue_run_one_at_a_time_in_order_and_queues_run_independently() {
    let r = rig(vec![printer("A"), printer("B")]).await;
    r.fake.polls_until_done.store(5, Ordering::Relaxed);
    add_job(&r.mock, 1, "A", 1);
    add_job(&r.mock, 2, "A", 1);
    add_job(&r.mock, 3, "B", 1);
    let task = r.run();
    wait_for("all three", || r.results().len() == 3).await;
    let order: Vec<i64> = r.results().iter().map(|r| r.0).collect();
    let pos = |id| order.iter().position(|x| *x == id).unwrap();
    assert!(pos(1) < pos(2), "oldest first within a queue: {order:?}");
    task.abort();
}

#[tokio::test]
async fn a_long_job_keeps_its_claim_alive() {
    let r = rig(vec![printer("Zebra")]).await;
    r.mock.claim_window_ms.store(1000, Ordering::Relaxed); // keep-alive every ~500 ms
    r.fake.polls_until_done.store(80, Ordering::Relaxed); // ≈ 1.6 s of printing
    add_job(&r.mock, 1, "Zebra", 1);
    let task = r.run();
    wait_for("result", || !r.results().is_empty()).await;
    let claims = r.mock.claim_calls.lock().unwrap().iter().filter(|c| **c == 1).count();
    assert!(claims >= 3, "expected the initial claim plus keep-alives, saw {claims}");
    assert_eq!(r.fake.submits.lock().unwrap().len(), 1, "keep-alive must not cause a reprint");
    task.abort();
}

#[tokio::test]
async fn gives_up_on_a_printer_that_goes_offline_cancels_the_job_and_holds_it_offline() {
    let r = rig(vec![printer("Zebra")]).await;
    r.fake.hang.store(true, Ordering::Relaxed);
    add_job(&r.mock, 1, "Zebra", 1);
    let task = r.run();
    wait_for("submitted", || !r.fake.submits.lock().unwrap().is_empty()).await;
    r.fake.set_state("Zebra", PrinterState::Offline, Some("Printer not reachable"));
    wait_for("result", || !r.results().is_empty()).await;
    let (_, status, err) = r.results()[0].clone();
    assert_eq!(status, "failed");
    assert!(err.unwrap().contains("cancelled so it won't print later"));
    assert_eq!(r.fake.cancelled.lock().unwrap().len(), 1, "the OS job must be cancelled or it prints later as a duplicate");

    // the printer comes back at the OS level, but we keep calling it offline for a short while
    r.fake.set_state("Zebra", PrinterState::Idle, None);
    tokio::time::sleep(Duration::from_millis(150)).await;
    let st = r.engine.status();
    assert_eq!(st.printers[0].state, ri_print_core::types::PrinterState::Offline);
    task.abort();
}

// ---- transient failures and crash recovery ---------------------------------------------------------------

#[tokio::test]
async fn a_result_that_cant_be_delivered_is_kept_and_sent_later_without_reprinting() {
    let r = rig(vec![printer("Zebra")]).await;
    r.mock.result_status.store(503, Ordering::Relaxed);
    add_job(&r.mock, 1, "Zebra", 1);
    let task = r.run();
    wait_for("printed", || r.fake.submits.lock().unwrap().len() == 1).await;
    tokio::time::sleep(Duration::from_millis(200)).await;
    assert!(r.results().is_empty());
    r.mock.result_status.store(0, Ordering::Relaxed);
    wait_for("delivered", || !r.results().is_empty()).await;
    assert_eq!(r.results()[0].1, "printed");
    assert_eq!(r.fake.submits.lock().unwrap().len(), 1);
    task.abort();
}

#[tokio::test]
async fn after_a_crash_unreported_results_are_sent_and_nothing_is_reprinted() {
    let r = rig_with(fast(), vec![printer("Zebra")], |l| {
        l.set(5, "Zebra", Some("A"), JobState::PrintedUnreported); // printed, result lost
        l.set(6, "Zebra", Some("B"), JobState::Submitting); // died around the OS call
        l.set(7, "Zebra", Some("C"), JobState::Submitted { os_job: "Zebra-7".into() }); // OS job exists
    })
    .await;
    // the server still lists all three as queued (their claims expired), which is the dangerous case
    for id in [5, 6, 7] {
        add_job(&r.mock, id, "Zebra", 1);
    }
    let task = r.run();
    wait_for("three results", || r.results().len() == 3).await;
    let by_id: HashMap<_, _> = r.results().into_iter().map(|(id, s, e)| (id, (s, e))).collect();
    assert_eq!(by_id[&5].0, "printed");
    assert_eq!(by_id[&6].0, "failed");
    assert!(by_id[&6].1.as_ref().unwrap().contains("may or may not have printed"));
    assert_eq!(by_id[&7].0, "printed");
    tokio::time::sleep(Duration::from_millis(200)).await;
    assert!(r.fake.submits.lock().unwrap().is_empty(), "no job may be printed twice");
    assert_eq!(r.mock.payload_calls.load(Ordering::Relaxed), 0);
    task.abort();
}

#[tokio::test]
async fn an_interrupted_job_whose_claim_is_gone_is_dropped_quietly() {
    let r = rig_with(fast(), vec![printer("Zebra")], |l| {
        l.set(9, "Zebra", None, JobState::PrintedUnreported);
    })
    .await;
    add_job(&r.mock, 9, "Zebra", 1);
    r.mock.result_status.store(409, Ordering::Relaxed); // server says: already settled
    let task = r.run();
    wait_for("ledger settled", || Ledger::open(r.dir.path()).unwrap().unreported().is_empty()).await;
    assert!(r.mock.results.lock().unwrap().is_empty());
    task.abort();
}

// ---- revocation ---------------------------------------------------------------------------------------------

#[tokio::test]
async fn a_401_wipes_the_token_and_returns_to_pairing() {
    let r = rig(vec![printer("Q")]).await;
    let task = r.run();
    wait_for("first heartbeat", || !r.mock.heartbeats.lock().unwrap().is_empty()).await;
    r.mock.revoked.store(true, Ordering::Relaxed);
    wait_for("un-paired", || !r.engine.status().paired).await;
    assert!(Config::load_or_create(r.dir.path()).unwrap().token.is_none());
    assert!(r.engine.status().notice.unwrap().contains("un-paired"));
    assert!(Config::load_or_create(r.dir.path()).unwrap().server_url.is_some(), "keep the address for re-pairing");

    // pairing again with a fresh code resumes service on the same running engine
    r.mock.revoked.store(false, Ordering::Relaxed);
    let before = r.mock.heartbeats.lock().unwrap().len();
    r.engine.pair(&r.url, "123456", None).await.unwrap();
    wait_for("serving again", || r.mock.heartbeats.lock().unwrap().len() > before).await;
    assert!(r.engine.status().paired);
    task.abort();
}
