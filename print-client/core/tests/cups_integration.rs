//! The Unix backend against a *real* CUPS, using throwaway loopback queues that are removed again.
//! Opt-in (it adds and deletes printer queues, so it needs `lpadmin` rights):
//!
//! ```text
//! RI_CUPS_INTEGRATION=1 cargo test -p ri-print-core --test cups_integration -- --nocapture
//! ```
//!
//! Mirrors `rstation/product/backend/test_cups_integration.py` for the Pi client.
#![cfg(unix)]

use std::io::Read;
use std::net::TcpListener;
use std::process::Command;
use std::time::{Duration, Instant};

use ri_print_core::backend::unix::UnixBackend;
use ri_print_core::backend::*;
use ri_print_core::types::PrinterState;

fn enabled() -> bool {
    std::env::var("RI_CUPS_INTEGRATION").is_ok()
}

/// A throwaway CUPS queue, deleted on drop (even if the test panics).
struct Queue(String);

impl Queue {
    fn new(tag: &str, uri: &str) -> Queue {
        let name = format!("ri-it-{}-{}", tag, std::process::id());
        let out = Command::new("lpadmin").args(["-p", &name, "-E", "-v", uri, "-m", "raw"]).output().expect("lpadmin");
        assert!(out.status.success(), "lpadmin failed: {}", String::from_utf8_lossy(&out.stderr));
        Queue(name)
    }
    fn tool(&self, cmd: &str) {
        let out = Command::new(cmd).arg(&self.0).output().unwrap();
        assert!(out.status.success(), "{cmd}: {}", String::from_utf8_lossy(&out.stderr));
    }
}

impl Drop for Queue {
    fn drop(&mut self) {
        let _ = Command::new("cancel").args(["-a", &self.0]).output();
        let _ = Command::new("lpadmin").args(["-x", &self.0]).output();
    }
}

async fn state_of(b: &UnixBackend, q: &str) -> (PrinterState, Option<String>) {
    let p = b.list_printers().await.unwrap().into_iter().find(|p| p.queue_name == q).expect("queue listed");
    (p.state, p.state_message)
}

async fn until<T>(what: &str, secs: u64, mut f: impl AsyncFnMut() -> Option<T>) -> T {
    let end = Instant::now() + Duration::from_secs(secs);
    loop {
        if let Some(v) = f().await {
            return v;
        }
        assert!(Instant::now() < end, "timed out waiting for: {what}");
        tokio::time::sleep(Duration::from_millis(300)).await;
    }
}

fn pdf(dir: &tempfile::TempDir) -> std::path::PathBuf {
    let p = dir.path().join("t.pdf");
    std::fs::write(&p, b"%PDF-1.4\n% ri-print integration test\n%%EOF\n").unwrap();
    p
}

#[tokio::test]
async fn prints_to_a_listening_printer_and_sees_the_job_finish() {
    if !enabled() {
        return;
    }
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    let received = std::thread::spawn(move || {
        let (mut s, _) = listener.accept().unwrap();
        let mut buf = Vec::new();
        s.read_to_end(&mut buf).unwrap();
        buf
    });
    let q = Queue::new("ok", &format!("socket://127.0.0.1:{port}"));
    let b = UnixBackend::default();
    let dir = tempfile::tempdir().unwrap();

    assert!(b.list_printers().await.unwrap().iter().any(|p| p.queue_name == q.0));
    let job = b
        .submit(&SubmitRequest { queue_name: q.0.clone(), file: pdf(&dir), copies: 1, title: "it".into(), width_in: 8.5, height_in: 11.0 })
        .await
        .unwrap();
    assert!(job.0.starts_with(&q.0), "job id should be '<queue>-<n>', got {}", job.0);

    until("job to leave the queue", 30, async || (b.job_status(&q.0, &job).await.unwrap() == OsJobStatus::Done).then_some(())).await;
    let bytes = received.join().unwrap();
    assert!(bytes.starts_with(b"%PDF"), "the printer should have received our file");
}

#[tokio::test]
async fn an_unreachable_network_printer_shows_as_offline_while_a_job_is_attempted_then_cancels_cleanly() {
    if !enabled() {
        return;
    }
    let q = Queue::new("dead", "socket://127.0.0.1:1"); // nothing listens: connection refused
    let b = UnixBackend::default();
    let dir = tempfile::tempdir().unwrap();

    // idle and untried, a dead network printer looks healthy — the documented CUPS behaviour
    assert_eq!(state_of(&b, &q.0).await.0, PrinterState::Idle);

    let job = b
        .submit(&SubmitRequest { queue_name: q.0.clone(), file: pdf(&dir), copies: 1, title: "it".into(), width_in: 8.5, height_in: 11.0 })
        .await
        .unwrap();
    let msg = until("printer to read as offline", 40, async || {
        match state_of(&b, &q.0).await {
            (PrinterState::Offline, m) => Some(m),
            _ => None,
        }
    })
    .await;
    assert!(msg.unwrap().starts_with("Printer not reachable"));
    assert_eq!(b.job_status(&q.0, &job).await.unwrap_or(OsJobStatus::Done), OsJobStatus::Pending, "job is stuck, not done");

    b.cancel(&q.0, &job).await;
    until("cancelled job to leave the queue", 15, async || {
        let out = Command::new("lpstat").args(["-W", "not-completed", "-o", &q.0]).env("LC_ALL", "C").output().unwrap();
        (!String::from_utf8_lossy(&out.stdout).contains(&job.0)).then_some(())
    })
    .await;
}

#[tokio::test]
async fn paused_and_rejecting_queues_read_as_offline() {
    if !enabled() {
        return;
    }
    let q = Queue::new("pause", "socket://127.0.0.1:1");
    let b = UnixBackend::default();

    q.tool("cupsdisable");
    let (s, m) = state_of(&b, &q.0).await;
    assert_eq!((s, m.as_deref()), (PrinterState::Offline, Some("Printer paused")));
    q.tool("cupsenable");
    assert_eq!(state_of(&b, &q.0).await.0, PrinterState::Idle);

    q.tool("cupsreject");
    let (s, m) = state_of(&b, &q.0).await;
    assert_eq!((s, m.as_deref()), (PrinterState::Offline, Some("Printer is not accepting jobs")));
}

#[tokio::test]
async fn submitting_to_a_missing_queue_is_a_readable_error() {
    if !enabled() {
        return;
    }
    let dir = tempfile::tempdir().unwrap();
    let err = UnixBackend::default()
        .submit(&SubmitRequest { queue_name: "ri-no-such-queue".into(), file: pdf(&dir), copies: 1, title: "it".into(), width_in: 1.0, height_in: 1.0 })
        .await
        .unwrap_err();
    assert!(err.0.contains("ri-no-such-queue"), "{err}");
}
