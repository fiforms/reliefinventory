//! The optional realtime wake-up (`PRINT_SERVER.md`, *Realtime wake-ups*): a Pusher-protocol
//! WebSocket (Laravel Reverb) on a public, per-device channel. The only message we care about is
//! the empty `print-job.available` event, which means "poll now". It is an accelerator, never a
//! dependency: if the socket is off or drops, the job loop falls back to fast polling on its own.
//!
//! The channel name is the only secret — it is never logged or shown (see `PrintRealtime`'s
//! `Display`).

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::Duration;

use futures_util::{SinkExt, StreamExt};
use tokio::sync::{watch, Notify};
use tokio_tungstenite::tungstenite::Message;

use crate::types::PrintRealtime;

pub fn ws_url(rt: &PrintRealtime) -> String {
    let scheme = if rt.scheme == "https" { "wss" } else { "ws" };
    format!(
        "{scheme}://{}:{}{}/app/{}?protocol=7&client=ri-print&version={}",
        rt.host,
        rt.port,
        rt.path.trim_end_matches('/'),
        rt.key,
        env!("CARGO_PKG_VERSION")
    )
}

#[derive(Debug, PartialEq, Eq)]
pub enum Action {
    Send(String),
    Subscribed,
    Wake,
    None,
}

/// Interpret one text frame from the server.
pub fn handle_text(text: &str, rt: &PrintRealtime) -> Action {
    let Ok(v) = serde_json::from_str::<serde_json::Value>(text) else { return Action::None };
    match v.get("event").and_then(|e| e.as_str()).unwrap_or("") {
        "pusher:connection_established" => Action::Send(
            serde_json::json!({"event": "pusher:subscribe", "data": {"channel": rt.channel}}).to_string(),
        ),
        "pusher:ping" => Action::Send(r#"{"event":"pusher:pong","data":{}}"#.to_string()),
        "pusher_internal:subscription_succeeded" => Action::Subscribed,
        e if e == rt.event => Action::Wake,
        _ => Action::None,
    }
}

/// Keep a socket open for as long as the heartbeat says realtime is on, reconnecting with backoff.
/// `up` reflects whether we are subscribed right now (the job loop slows its polling while true).
pub async fn run(mut cfg: watch::Receiver<Option<PrintRealtime>>, wake: Arc<Notify>, up: Arc<AtomicBool>) {
    let mut fails = 0u32;
    loop {
        let current = cfg.borrow_and_update().clone();
        let Some(rt) = current else {
            up.store(false, Ordering::Relaxed);
            if cfg.changed().await.is_err() {
                return;
            }
            continue;
        };
        tokio::select! {
            r = connect_once(&rt, &wake, &up) => {
                up.store(false, Ordering::Relaxed);
                match r {
                    Ok(()) => fails = 0,
                    Err(e) => {
                        fails = fails.saturating_add(1);
                        tracing::debug!("realtime to {rt} dropped: {e}");
                    }
                }
                let delay = crate::engine::backoff(fails);
                tokio::select! {
                    _ = tokio::time::sleep(delay) => {}
                    _ = cfg.changed() => {}
                }
            }
            _ = cfg.changed() => { up.store(false, Ordering::Relaxed); }
        }
    }
}

async fn connect_once(rt: &PrintRealtime, wake: &Notify, up: &AtomicBool) -> Result<(), String> {
    let (mut ws, _) = tokio::time::timeout(Duration::from_secs(10), tokio_tungstenite::connect_async(ws_url(rt)))
        .await
        .map_err(|_| "connect timed out".to_string())?
        .map_err(|e| e.to_string())?;
    loop {
        // Reverb pings every ~30 s; silence for much longer than that means a dead connection.
        let frame = tokio::time::timeout(Duration::from_secs(150), ws.next())
            .await
            .map_err(|_| "no activity".to_string())?;
        match frame {
            None => return Err("closed".into()),
            Some(Err(e)) => return Err(e.to_string()),
            Some(Ok(Message::Text(t))) => match handle_text(&t, rt) {
                Action::Send(s) => ws.send(Message::Text(s)).await.map_err(|e| e.to_string())?,
                Action::Subscribed => up.store(true, Ordering::Relaxed),
                Action::Wake => wake.notify_one(),
                Action::None => {}
            },
            Some(Ok(Message::Close(_))) => return Err("closed by server".into()),
            Some(Ok(_)) => {}
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn rt() -> PrintRealtime {
        PrintRealtime {
            key: "appkey".into(),
            host: "ri.example.org".into(),
            port: 443,
            scheme: "https".into(),
            path: "".into(),
            channel: "wake.SECRET".into(),
            event: "print-job.available".into(),
        }
    }

    #[test]
    fn url() {
        assert!(ws_url(&rt()).starts_with("wss://ri.example.org:443/app/appkey?protocol=7"));
    }

    #[test]
    fn handshake_ping_wake() {
        let r = rt();
        let Action::Send(s) = handle_text(r#"{"event":"pusher:connection_established","data":"{\"socket_id\":\"1.2\"}"}"#, &r)
        else {
            panic!("expected subscribe")
        };
        let v: serde_json::Value = serde_json::from_str(&s).unwrap();
        assert_eq!(v["event"], "pusher:subscribe");
        assert_eq!(v["data"]["channel"], "wake.SECRET");
        assert_eq!(handle_text(r#"{"event":"pusher_internal:subscription_succeeded","data":"{}"}"#, &r), Action::Subscribed);
        assert!(matches!(handle_text(r#"{"event":"pusher:ping"}"#, &r), Action::Send(p) if p.contains("pusher:pong")));
        assert_eq!(handle_text(r#"{"event":"print-job.available","channel":"wake.SECRET","data":"{}"}"#, &r), Action::Wake);
        assert_eq!(handle_text("garbage", &r), Action::None);
        assert_eq!(handle_text(r#"{"event":"something-else"}"#, &r), Action::None);
    }

    #[test]
    fn display_never_leaks_the_channel() {
        assert!(!format!("{}", rt()).contains("SECRET"));
    }
}
