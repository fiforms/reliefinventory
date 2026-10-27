//! HTTP client for the federation API (`rstation/FEDERATION_AUTH.md`, `rstation/federation.openapi.yaml`): pair with a
//! code, then sign once for a session token and send that until it is about to expire or the server refuses it. The
//! backup calls are the `operator.full_read` endpoints under `/api/federation/v1/backups`.
//!
//! Status codes become [`RemoteError`] variants, so callers decide policy from the variant alone: `Revoked` is a
//! definite "this computer was un-paired" (stop, ask to pair again), the transient ones are worth retrying later.

use std::path::Path;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};

use async_trait::async_trait;
use futures_util::StreamExt;
use rand::distributions::Alphanumeric;
use rand::Rng;
use reqwest::{Client, Method, Response, StatusCode};
use serde::de::DeserializeOwned;
use serde::{Deserialize, Serialize};
use thiserror::Error;
use tokio::io::AsyncWriteExt;
use tokio::sync::Mutex;

use super::identity::{self, Keys};

/// The `kind` a launcher pairs as (`App\Models\Trust::KINDS`).
pub const KIND: &str = "backup_client";
/// The grant that lets us make and download backups.
pub const GRANT: &str = "operator.full_read";

#[derive(Debug, Error, Clone, PartialEq)]
pub enum RemoteError {
    /// 401: the server doesn't know this computer's key any more (revoked or removed). Never retried.
    #[error("the server no longer recognises this computer: it was un-paired there")]
    Revoked,
    /// 403: paired, but the trust doesn't include backups (an administrator removed the grant).
    #[error("the server doesn't allow this computer to make backups. An administrator can turn that on in the Trust Console")]
    Forbidden,
    #[error("not found on the server")]
    NotFound,
    /// 409: a backup is already running there; carries it when the server named it.
    #[error("the server is already making a backup")]
    Busy(Option<BackupInfo>),
    /// 422: validation (pairing: wrong or expired code).
    #[error("{0}")]
    Validation(String),
    #[error("the server asked us to slow down")]
    RateLimited,
    #[error("server error {0}: {1}")]
    Http(u16, String),
    #[error("can't reach the server: {0}")]
    Network(String),
    #[error("{0}")]
    Local(String),
}

impl RemoteError {
    /// Worth trying again later, as opposed to a definite answer.
    pub fn is_transient(&self) -> bool {
        matches!(self, RemoteError::Http(c, _) if *c >= 500) || matches!(self, RemoteError::Network(_) | RemoteError::RateLimited | RemoteError::Busy(_))
    }
}

/// One backup as the server describes it (`Backup` in the OpenAPI file).
#[derive(Debug, Clone, Default, PartialEq, Serialize, Deserialize)]
#[serde(default)]
pub struct BackupInfo {
    pub id: i64,
    pub scope: String,
    /// queued | running | complete | failed | missing
    pub status: String,
    pub progress: Option<String>,
    pub filename: Option<String>,
    pub size_bytes: Option<u64>,
    pub created_at: Option<String>,
    pub finished_at: Option<String>,
    pub error: Option<String>,
    pub sha256: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct ServerIdentity {
    pub uuid: String,
    pub name: String,
    pub public_key: String,
}

#[derive(Debug, Deserialize)]
pub struct PairResponse {
    pub server: ServerIdentity,
    #[serde(default)]
    pub permissions: Vec<String>,
}

/// What a backup run needs from the server. [`RemoteClient`] is the real one; tests use a scripted fake.
#[async_trait]
pub trait BackupApi: Send + Sync {
    /// Ask for a new backup. A server that is already making one answers [`RemoteError::Busy`].
    async fn create_backup(&self, scope: &str) -> Result<BackupInfo, RemoteError>;
    async fn get_backup(&self, id: i64) -> Result<BackupInfo, RemoteError>;
    /// Download into `part`, continuing what is already there. `expected` is the final size, if known.
    async fn download_backup(&self, id: i64, part: &Path, expected: Option<u64>, progress: &(dyn Fn(u64, Option<u64>) + Send + Sync)) -> Result<(), RemoteError>;
}

/// Authentication state: how we prove ourselves, and the session it earned.
struct Auth {
    keys: Arc<Keys>,
    session: Mutex<Option<(String, Instant)>>,
    /// Added to our clock when stamping a signature, learned from the server when ours is far off.
    clock_offset: AtomicI64,
}

#[derive(Clone)]
pub struct RemoteClient {
    http: Client,
    /// No overall timeout (a backup can be gigabytes), but a stalled connection still ends.
    stream: Client,
    server_url: String,
    auth: Arc<Auth>,
}

/// Strip whitespace and trailing slashes; assume https when no scheme was typed.
pub fn normalize_server_url(input: &str) -> Result<String, String> {
    let t = input.trim().trim_end_matches('/');
    if t.is_empty() {
        return Err("Enter the server address.".into());
    }
    let with_scheme = if t.contains("://") { t.to_string() } else { format!("https://{t}") };
    let u = url::Url::parse(&with_scheme).map_err(|_| "That doesn't look like a web address.".to_string())?;
    if !matches!(u.scheme(), "http" | "https") || u.host_str().is_none() {
        return Err("The server address must start with http:// or https://.".into());
    }
    // Only the origin matters: a pasted page address (…/setup/trust) must not become part of every API path. The one
    // exception is a warehouse prefix, `/w/<id>`: on a host shared by several warehouses it is what picks this one
    // (MULTI_WAREHOUSE.md §4), and it is part of every path we send and sign.
    let mut origin = format!("{}://{}", u.scheme(), u.host_str().unwrap_or_default());
    if let Some(p) = u.port() {
        origin.push_str(&format!(":{p}"));
    }
    let mut segments = u.path_segments().into_iter().flatten();
    if let (Some("w"), Some(id)) = (segments.next(), segments.next()) {
        if id.len() == 8 && id.chars().all(|c| c.is_ascii_alphanumeric()) {
            origin.push_str(&format!("/w/{}", id.to_ascii_uppercase()));
        }
    }
    Ok(origin)
}

/// A backup holds password hashes and personal data, so it travels over https unless the server is plainly on this
/// computer or its own network (the launcher's own LAN service is `http://name.local:8080`). Requests are signed
/// either way, which proves who is asking but doesn't hide the file.
pub fn check_transport(url: &str) -> Result<(), String> {
    let u = url::Url::parse(url).map_err(|_| "That doesn't look like a web address.".to_string())?;
    if u.scheme() == "https" {
        return Ok(());
    }
    let local = match u.host() {
        Some(url::Host::Ipv4(ip)) => ip.is_loopback() || ip.is_private() || ip.is_link_local(),
        Some(url::Host::Ipv6(ip)) => ip.is_loopback() || (ip.segments()[0] & 0xfe00) == 0xfc00,
        Some(url::Host::Domain(d)) => {
            let d = d.to_ascii_lowercase();
            d == "localhost" || !d.contains('.') || [".local", ".lan", ".home", ".internal"].iter().any(|s| d.ends_with(s))
        }
        None => false,
    };
    if local {
        Ok(())
    } else {
        Err("This server's address starts with http://, which would send its backups over the internet unprotected. Use its https:// address.".into())
    }
}

impl RemoteClient {
    pub fn new(server_url: &str, keys: Arc<Keys>) -> Self {
        let agent = concat!("ri-launcher/", env!("CARGO_PKG_VERSION"));
        let http = Client::builder().timeout(Duration::from_secs(30)).user_agent(agent).build().expect("http client");
        let stream = Client::builder()
            .connect_timeout(Duration::from_secs(20))
            .read_timeout(Duration::from_secs(90))
            .user_agent(agent)
            .build()
            .expect("http client");
        let auth = Arc::new(Auth { keys, session: Mutex::new(None), clock_offset: AtomicI64::new(0) });
        RemoteClient { http, stream, server_url: server_url.trim_end_matches('/').to_string(), auth }
    }

    fn api(&self, path: &str) -> String {
        format!("{}/api/federation/v1/{}", self.server_url, path.trim_start_matches('/'))
    }

    /// Signature headers for one request: `X-RI-Trust` (our uuid), timestamp, nonce, signature.
    fn signed_headers(auth: &Auth, method: &Method, url: &str, body: &[u8]) -> Result<Vec<(&'static str, String)>, RemoteError> {
        let u = url::Url::parse(url).map_err(|e| RemoteError::Network(e.to_string()))?;
        let host = match u.port() {
            Some(p) => format!("{}:{p}", u.host_str().unwrap_or_default()),
            None => u.host_str().unwrap_or_default().to_string(),
        };
        let path_and_query = match u.query() {
            Some(q) => format!("{}?{q}", u.path()),
            None => u.path().to_string(),
        };
        let timestamp = chrono::Utc::now().timestamp() + auth.clock_offset.load(Ordering::Relaxed);
        let nonce: String = rand::thread_rng().sample_iter(&Alphanumeric).take(24).map(char::from).collect();
        let canonical = identity::canonical(method.as_str(), &path_and_query, &host, timestamp, &nonce, body);
        Ok(vec![
            ("X-RI-Trust", auth.keys.uuid.clone()),
            ("X-RI-Timestamp", timestamp.to_string()),
            ("X-RI-Nonce", nonce),
            ("X-RI-Signature", auth.keys.identity.sign(&canonical)),
        ])
    }

    /// A session token: the cached one, or one earned by a signed `POST /session`. A server that says our clock is
    /// off (400 + `X-RI-Server-Time`, never a 401) is believed once and the call repeated.
    async fn session_token(&self) -> Result<String, RemoteError> {
        let auth = &self.auth;
        let mut slot = auth.session.lock().await;
        if let Some((token, until)) = slot.as_ref() {
            if Instant::now() < *until {
                return Ok(token.clone());
            }
        }
        let url = self.api("session");
        let body = b"{}".to_vec();
        for attempt in 0..2 {
            let mut rb = self.http.post(&url).header("content-type", "application/json").body(body.clone());
            for (k, v) in Self::signed_headers(auth, &Method::POST, &url, &body)? {
                rb = rb.header(k, v);
            }
            let resp = rb.send().await.map_err(|e| RemoteError::Network(simplify(e)))?;
            if resp.status() == StatusCode::BAD_REQUEST && attempt == 0 {
                if let Some(server_time) =
                    resp.headers().get("x-ri-server-time").and_then(|v| v.to_str().ok()).and_then(|v| v.parse::<i64>().ok())
                {
                    auth.clock_offset.store(server_time - chrono::Utc::now().timestamp(), Ordering::Relaxed);
                    continue;
                }
            }
            #[derive(Deserialize)]
            struct Session {
                session_token: String,
                expires_in: u64,
            }
            let reply: Session = json_of(check(resp).await?).await?;
            let keep = reply.expires_in.saturating_sub(60).max(30);
            *slot = Some((reply.session_token.clone(), Instant::now() + Duration::from_secs(keep)));
            return Ok(reply.session_token);
        }
        Err(RemoteError::Http(400, "the server rejected this computer's clock".into()))
    }

    /// One authenticated call. A 401 on a cached session retries once with a fresh one (sessions expire, and the
    /// server may have restarted); a 401 on a fresh one is a real revocation.
    async fn call(&self, client: &Client, method: Method, path: &str, body: Option<Vec<u8>>, range_from: Option<u64>) -> Result<Response, RemoteError> {
        let url = self.api(path);
        for attempt in 0..2 {
            let token = self.session_token().await?;
            let mut rb = client.request(method.clone(), &url).bearer_auth(&token);
            if let Some(b) = &body {
                rb = rb.header("content-type", "application/json").body(b.clone());
            }
            if let Some(from) = range_from {
                rb = rb.header("range", format!("bytes={from}-"));
            }
            let resp = rb.send().await.map_err(|e| RemoteError::Network(simplify(e)))?;
            if resp.status() == StatusCode::UNAUTHORIZED && attempt == 0 {
                *self.auth.session.lock().await = None;
                continue;
            }
            return check(resp).await;
        }
        Err(RemoteError::Revoked)
    }

    async fn call_json<T: DeserializeOwned>(&self, method: Method, path: &str, body: Option<Vec<u8>>) -> Result<T, RemoteError> {
        json_of(self.call(&self.http, method, path, body, None).await?).await
    }

    /// Pair with a code from the server's Trust Console: submit our public key and prove we hold it by signing the
    /// code. Unauthenticated; the code is the credential.
    pub async fn pair(&self, code: &str, name: &str) -> Result<PairResponse, RemoteError> {
        let public_key = self.auth.keys.identity.public_key();
        let uuid = &self.auth.keys.uuid;
        let body = serde_json::json!({
            "code": code,
            "kind": KIND,
            "uuid": uuid,
            "name": clip(name, 255),
            "public_key": public_key,
            "signature": self.auth.keys.identity.sign(&identity::pairing_message(code, &public_key, uuid)),
        });
        let resp = self.http.post(self.api("pair")).json(&body).send().await.map_err(|e| RemoteError::Network(simplify(e)))?;
        json_of(check(resp).await?).await
    }

    /// What the server currently lets us do to it.
    pub async fn grants(&self) -> Result<Vec<String>, RemoteError> {
        #[derive(Deserialize)]
        struct G {
            permissions: Vec<String>,
        }
        Ok(self.call_json::<G>(Method::GET, "grants", None).await?.permissions)
    }

    pub async fn list_backups(&self) -> Result<Vec<BackupInfo>, RemoteError> {
        #[derive(Deserialize)]
        struct L {
            backups: Vec<BackupInfo>,
        }
        Ok(self.call_json::<L>(Method::GET, "backups", None).await?.backups)
    }
}

#[derive(Deserialize)]
struct One {
    backup: BackupInfo,
}

#[async_trait]
impl BackupApi for RemoteClient {
    async fn create_backup(&self, scope: &str) -> Result<BackupInfo, RemoteError> {
        let body = serde_json::to_vec(&serde_json::json!({ "scope": scope })).expect("json");
        Ok(self.call_json::<One>(Method::POST, "backups", Some(body)).await?.backup)
    }

    async fn get_backup(&self, id: i64) -> Result<BackupInfo, RemoteError> {
        Ok(self.call_json::<One>(Method::GET, &format!("backups/{id}"), None).await?.backup)
    }

    async fn download_backup(&self, id: i64, part: &Path, expected: Option<u64>, progress: &(dyn Fn(u64, Option<u64>) + Send + Sync)) -> Result<(), RemoteError> {
        let local = |e: std::io::Error| RemoteError::Local(format!("{}: {e}", part.display()));
        let mut have = tokio::fs::metadata(part).await.map(|m| m.len()).unwrap_or(0);
        match expected {
            Some(e) if have > e => {
                let _ = tokio::fs::remove_file(part).await; // not a prefix of this file: start over
                have = 0;
            }
            Some(e) if have == e => {
                progress(e, Some(e)); // fully there already; the caller checks the hash
                return Ok(());
            }
            _ => {}
        }

        let path = format!("backups/{id}/download");
        let resp = match self.call(&self.stream, Method::GET, &path, None, (have > 0).then_some(have)).await {
            // The server doesn't think our partial file fits (it changed, or we're past its end): start over.
            Err(RemoteError::Http(416, _)) if have > 0 => {
                let _ = tokio::fs::remove_file(part).await;
                have = 0;
                self.call(&self.stream, Method::GET, &path, None, None).await?
            }
            other => other?,
        };
        if resp.status() != StatusCode::PARTIAL_CONTENT {
            have = 0; // 200: the whole file again, whatever we asked for
        }
        let total = resp.content_length().map(|n| n + have).or(expected);

        if let Some(parent) = part.parent() {
            tokio::fs::create_dir_all(parent).await.map_err(local)?;
        }
        let mut file = if have > 0 {
            tokio::fs::OpenOptions::new().append(true).open(part).await.map_err(local)?
        } else {
            tokio::fs::File::create(part).await.map_err(local)?
        };
        let (mut done, mut last_report) = (have, have);
        let mut stream = resp.bytes_stream();
        while let Some(chunk) = stream.next().await {
            let chunk = chunk.map_err(|e| RemoteError::Network(simplify(e)))?;
            file.write_all(&chunk).await.map_err(local)?;
            done += chunk.len() as u64;
            if done - last_report >= 1 << 20 {
                last_report = done;
                progress(done, total);
            }
        }
        file.flush().await.map_err(local)?;
        progress(done, total);
        // A connection cut short ends the stream quietly; the size is what tells us we aren't done.
        match total {
            Some(t) if done < t => Err(RemoteError::Network("the download was cut short".into())),
            _ => Ok(()),
        }
    }
}

async fn json_of<T: DeserializeOwned>(resp: Response) -> Result<T, RemoteError> {
    resp.json::<T>().await.map_err(|e| RemoteError::Http(200, format!("unreadable response: {e}")))
}

async fn check(resp: Response) -> Result<Response, RemoteError> {
    let status = resp.status();
    if status.is_success() {
        return Ok(resp);
    }
    let body = resp.text().await.unwrap_or_default();
    let json = serde_json::from_str::<serde_json::Value>(&body).ok();
    let message = json
        .as_ref()
        .and_then(|v| v.get("message").and_then(|m| m.as_str().map(str::to_string)))
        .unwrap_or_else(|| clip(&body, 200));
    Err(match status {
        StatusCode::UNAUTHORIZED => RemoteError::Revoked,
        StatusCode::FORBIDDEN => RemoteError::Forbidden,
        StatusCode::NOT_FOUND => RemoteError::NotFound,
        StatusCode::CONFLICT => RemoteError::Busy(json.and_then(|v| serde_json::from_value(v.get("backup")?.clone()).ok())),
        StatusCode::UNPROCESSABLE_ENTITY => RemoteError::Validation(message),
        StatusCode::TOO_MANY_REQUESTS => RemoteError::RateLimited,
        s => RemoteError::Http(s.as_u16(), message),
    })
}

fn clip(s: &str, max: usize) -> String {
    s.chars().take(max).collect()
}

/// reqwest's Display includes the full URL chain; keep just the useful cause.
fn simplify(e: reqwest::Error) -> String {
    if e.is_timeout() {
        "the request timed out".into()
    } else if e.is_connect() {
        "connection failed".into()
    } else {
        e.without_url().to_string()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn urls_are_reduced_to_their_origin() {
        assert_eq!(normalize_server_url(" ri.example.org/ ").unwrap(), "https://ri.example.org");
        assert_eq!(normalize_server_url("http://10.0.0.5:8000//").unwrap(), "http://10.0.0.5:8000");
        assert_eq!(normalize_server_url("https://ri.example.org/setup/trust?x=1").unwrap(), "https://ri.example.org");
        assert!(normalize_server_url("").is_err() && normalize_server_url("ftp://x").is_err());
        // a warehouse on a shared host keeps its prefix, and nothing after it
        assert_eq!(normalize_server_url("https://ri.example.org/w/k7q2x9md/setup/trust").unwrap(), "https://ri.example.org/w/K7Q2X9MD");
        assert_eq!(normalize_server_url("https://ri.example.org/w/short").unwrap(), "https://ri.example.org");
    }

    #[test]
    fn plain_http_is_for_this_computer_and_its_own_network_only() {
        for ok in ["https://ri.example.org", "http://localhost:8080", "http://127.0.0.1:8080", "http://192.168.1.20:8080", "http://10.1.2.3", "http://office-pc.local:8080", "http://ri-box", "http://[::1]:8080", "http://172.16.0.9"] {
            assert!(check_transport(ok).is_ok(), "{ok}");
        }
        for bad in ["http://ri.example.org", "http://8.8.8.8", "http://203.0.113.7:8080", "http://[2001:db8::1]"] {
            assert!(check_transport(bad).is_err(), "{bad}");
        }
    }

    #[test]
    fn transient_classification() {
        assert!(RemoteError::Network("x".into()).is_transient() && RemoteError::Http(503, "".into()).is_transient());
        assert!(RemoteError::RateLimited.is_transient() && RemoteError::Busy(None).is_transient());
        assert!(!RemoteError::Revoked.is_transient() && !RemoteError::Forbidden.is_transient() && !RemoteError::Http(400, "".into()).is_transient());
    }
}
