//! HTTP client for the device contract (`DEVICE_CONTRACT.md`) and the print job endpoints.
//! Status codes are mapped to [`ApiError`] exactly as `PRINT_CLIENT_SPEC.md` §3.6 tabulates them,
//! so callers decide policy (drop, skip, retry) from the variant alone.

use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};

use rand::distributions::Alphanumeric;
use rand::Rng;
use reqwest::{Client, Method, RequestBuilder, Response, StatusCode};
use serde::de::DeserializeOwned;
use thiserror::Error;
use tokio::sync::Mutex;

use crate::identity::{self, Identity};
use crate::types::*;

#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum ApiError {
    /// 401 on an authenticated call: the device was revoked (or its key is unknown). Forget the pairing; never retry.
    #[error("this computer was un-paired from the server")]
    Revoked,
    /// 404: not one of our jobs, or gone. Drop it.
    #[error("not found")]
    NotFound,
    /// 409: no longer claimable / we don't hold the claim / result already settled.
    #[error("conflict: {0}")]
    Conflict(String),
    /// 410: the PDF was cleaned up.
    #[error("file no longer available")]
    Gone,
    /// 422: validation (pairing: bad or expired code).
    #[error("rejected: {0}")]
    Validation(String),
    /// 429: back off.
    #[error("rate limited")]
    RateLimited,
    /// 5xx or an unexpected 4xx.
    #[error("server error {0}: {1}")]
    Http(u16, String),
    /// Couldn't reach the server (DNS, TLS, timeout, ...).
    #[error("can't reach the server: {0}")]
    Network(String),
}

impl ApiError {
    /// Worth retrying later (as opposed to a definite answer from the server).
    pub fn is_transient(&self) -> bool {
        matches!(self, ApiError::Http(c, _) if *c >= 500)
            || matches!(self, ApiError::Network(_) | ApiError::RateLimited)
    }
}

/// How an authenticated client proves itself (`rstation/FEDERATION_AUTH.md`): it signs one call with its key
/// to earn a session token, then sends that token until it is about to expire or the server refuses it.
struct Auth {
    identity: Identity,
    uuid: String,
    session: Mutex<Option<(String, Instant)>>,
    /// Added to our clock when stamping a signature, learned from the server when ours is far off.
    clock_offset: AtomicI64,
}

#[derive(Clone)]
pub struct ApiClient {
    http: Client,
    server_url: String,
    auth: Option<Arc<Auth>>,
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
    Ok(with_scheme)
}

impl ApiClient {
    /// `auth` is this machine's key and uuid once paired; `None` is only good for `up()` and `pair()`.
    pub fn new(server_url: &str, auth: Option<(Identity, String)>) -> Self {
        let http = Client::builder()
            .timeout(Duration::from_secs(10)) // the contract's 10 s
            .user_agent(concat!("ri-print/", env!("CARGO_PKG_VERSION")))
            .build()
            .expect("http client");
        let auth = auth.map(|(identity, uuid)| {
            Arc::new(Auth { identity, uuid, session: Mutex::new(None), clock_offset: AtomicI64::new(0) })
        });
        ApiClient { http, server_url: server_url.trim_end_matches('/').to_string(), auth }
    }

    fn base(&self, path: &str) -> String {
        format!("{}/api/devices/{}", self.server_url, path.trim_start_matches('/'))
    }

    fn federation(&self, path: &str) -> String {
        format!("{}/api/federation/v1/{}", self.server_url, path.trim_start_matches('/'))
    }

    /// Signature headers for one request: `X-RI-Trust` (our uuid), timestamp, nonce, signature.
    fn signed_headers(auth: &Auth, method: &Method, url: &str, body: &[u8]) -> Result<Vec<(&'static str, String)>, ApiError> {
        let u = url::Url::parse(url).map_err(|e| ApiError::Network(e.to_string()))?;
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
            ("X-RI-Trust", auth.uuid.clone()),
            ("X-RI-Timestamp", timestamp.to_string()),
            ("X-RI-Nonce", nonce),
            ("X-RI-Signature", auth.identity.sign(&canonical)),
        ])
    }

    /// A session token: the cached one, or one earned by a signed `POST /session`. A server that says our
    /// clock is off (400 + `X-RI-Server-Time`, never a 401) is believed once and the call repeated.
    async fn session_token(&self, auth: &Auth) -> Result<String, ApiError> {
        let mut slot = auth.session.lock().await;
        if let Some((token, until)) = slot.as_ref() {
            if Instant::now() < *until {
                return Ok(token.clone());
            }
        }
        let url = self.federation("session");
        let body = b"{}".to_vec();
        for attempt in 0..2 {
            let mut rb = self.http.post(&url).header("content-type", "application/json").body(body.clone());
            for (k, v) in Self::signed_headers(auth, &Method::POST, &url, &body)? {
                rb = rb.header(k, v);
            }
            let resp = rb.send().await.map_err(|e| ApiError::Network(simplify(e)))?;
            if resp.status() == StatusCode::BAD_REQUEST && attempt == 0 {
                if let Some(server_time) =
                    resp.headers().get("x-ri-server-time").and_then(|v| v.to_str().ok()).and_then(|v| v.parse::<i64>().ok())
                {
                    auth.clock_offset.store(server_time - chrono::Utc::now().timestamp(), Ordering::Relaxed);
                    continue;
                }
            }
            let reply: SessionResponse = Self::json_of(Self::check(resp).await?).await?;
            let keep = reply.expires_in.saturating_sub(60).max(30);
            *slot = Some((reply.session_token.clone(), Instant::now() + Duration::from_secs(keep)));
            return Ok(reply.session_token);
        }
        Err(ApiError::Http(400, "the server rejected this computer's clock".into()))
    }

    /// One authenticated call under `/api/devices`. A 401 on a cached session retries once with a fresh one
    /// (sessions expire and the server may have restarted); a 401 on a fresh one is a real revocation.
    async fn call(&self, method: Method, path: &str, body: Option<Vec<u8>>, timeout: Option<Duration>) -> Result<Response, ApiError> {
        let auth = self.auth.as_ref().expect("authenticated call without a key");
        let url = self.base(path);
        for attempt in 0..2 {
            let token = self.session_token(auth).await?;
            let mut rb = self.http.request(method.clone(), &url).bearer_auth(&token);
            if let Some(b) = &body {
                rb = rb.header("content-type", "application/json").body(b.clone());
            }
            if let Some(t) = timeout {
                rb = rb.timeout(t);
            }
            let resp = rb.send().await.map_err(|e| ApiError::Network(simplify(e)))?;
            if resp.status() == StatusCode::UNAUTHORIZED && attempt == 0 {
                *auth.session.lock().await = None;
                continue;
            }
            return Self::check(resp).await;
        }
        Err(ApiError::Revoked)
    }

    async fn call_json<T: DeserializeOwned>(&self, method: Method, path: &str, body: Option<Vec<u8>>) -> Result<T, ApiError> {
        Self::json_of(self.call(method, path, body, None).await?).await
    }

    async fn json_of<T: DeserializeOwned>(resp: Response) -> Result<T, ApiError> {
        resp.json::<T>().await.map_err(|e| ApiError::Http(200, format!("unreadable response: {e}")))
    }

    async fn check(resp: Response) -> Result<Response, ApiError> {
        let status = resp.status();
        if status.is_success() {
            return Ok(resp);
        }
        let body = resp.text().await.unwrap_or_default();
        let message = serde_json::from_str::<serde_json::Value>(&body)
            .ok()
            .and_then(|v| v.get("message").and_then(|m| m.as_str().map(str::to_string)))
            .unwrap_or_else(|| crate::types::clip(&body, 200));
        Err(match status {
            StatusCode::UNAUTHORIZED => ApiError::Revoked,
            StatusCode::NOT_FOUND => ApiError::NotFound,
            StatusCode::CONFLICT => ApiError::Conflict(message),
            StatusCode::GONE => ApiError::Gone,
            StatusCode::UNPROCESSABLE_ENTITY => ApiError::Validation(message),
            StatusCode::TOO_MANY_REQUESTS => ApiError::RateLimited,
            s => ApiError::Http(s.as_u16(), message),
        })
    }

    async fn send(rb: RequestBuilder) -> Result<Response, ApiError> {
        let resp = rb.send().await.map_err(|e| ApiError::Network(simplify(e)))?;
        Self::check(resp).await
    }

    async fn json<T: DeserializeOwned>(rb: RequestBuilder) -> Result<T, ApiError> {
        Self::json_of(Self::send(rb).await?).await
    }

    /// `GET {server_url}/up` — liveness, not under the API base.
    pub async fn up(&self) -> Result<(), ApiError> {
        Self::send(self.http.get(format!("{}/up", self.server_url))).await.map(|_| ())
    }

    /// Pair with a code from the server's Trust Console / Devices page: submit our public key and prove we hold
    /// it by signing the code. Unauthenticated; the code is the credential.
    pub async fn pair(
        &self,
        code: &str,
        device_name: &str,
        device_uuid: &str,
        identity: &Identity,
    ) -> Result<PairResponse, ApiError> {
        let public_key = identity.public_key();
        let body = serde_json::json!({
            "code": code,
            "kind": "print_client",
            "uuid": device_uuid,
            "name": clip(device_name, 255),
            "public_key": public_key,
            "signature": identity.sign(&identity::pairing_message(code, &public_key, device_uuid)),
            "mac_address": null,
        });
        Self::json(self.http.post(self.federation("pair")).json(&body)).await
    }

    pub async fn heartbeat(&self, req: &HeartbeatRequest) -> Result<HeartbeatResponse, ApiError> {
        self.call_json(Method::POST, "heartbeat", Some(to_body(req)?)).await
    }

    pub async fn list_jobs(&self) -> Result<Vec<PrintJob>, ApiError> {
        Ok(self.call_json::<JobList>(Method::GET, "print-jobs", None).await?.jobs)
    }

    /// Claim a job, or extend a claim already held (idempotent keep-alive).
    pub async fn claim(&self, id: i64) -> Result<ClaimResponse, ApiError> {
        self.call_json(Method::POST, &format!("print-jobs/{id}/claim"), Some(b"{}".to_vec())).await
    }

    pub async fn payload(&self, id: i64) -> Result<Vec<u8>, ApiError> {
        let resp = self
            .call(Method::GET, &format!("print-jobs/{id}/payload"), None, Some(Duration::from_secs(60)))
            .await?;
        resp.bytes().await.map(|b| b.to_vec()).map_err(|e| ApiError::Network(simplify(e)))
    }

    pub async fn result(&self, id: i64, printed: bool, error: Option<&str>) -> Result<(), ApiError> {
        let error = error.map(|e| clip(e, 2000));
        let body = ResultRequest {
            status: if printed { "printed" } else { "failed" },
            error: error.as_deref(),
        };
        self.call(Method::POST, &format!("print-jobs/{id}/result"), Some(to_body(&body)?), None).await.map(|_| ())
    }
}

fn to_body<T: serde::Serialize>(value: &T) -> Result<Vec<u8>, ApiError> {
    serde_json::to_vec(value).map_err(|e| ApiError::Http(0, format!("could not encode the request: {e}")))
}

/// reqwest's Display includes the full URL chain; keep just the useful cause.
fn simplify(e: reqwest::Error) -> String {
    if e.is_timeout() {
        "the request timed out".into()
    } else if e.is_connect() {
        "connection failed".into()
    } else {
        // strip the URL so tokens/paths in query strings can never reach a log
        e.without_url().to_string()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn normalizes_urls() {
        assert_eq!(normalize_server_url(" ri.example.org/ ").unwrap(), "https://ri.example.org");
        assert_eq!(normalize_server_url("http://10.0.0.5:8000//").unwrap(), "http://10.0.0.5:8000");
        assert!(normalize_server_url("").is_err());
        assert!(normalize_server_url("ftp://x").is_err());
    }

    #[test]
    fn transient_classification() {
        assert!(ApiError::Network("x".into()).is_transient());
        assert!(ApiError::Http(503, "".into()).is_transient());
        assert!(!ApiError::Http(400, "".into()).is_transient());
        assert!(!ApiError::Conflict("".into()).is_transient());
        assert!(!ApiError::Revoked.is_transient());
    }
}
