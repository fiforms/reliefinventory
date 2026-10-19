//! HTTP client for the device contract (`DEVICE_CONTRACT.md`) and the print job endpoints.
//! Status codes are mapped to [`ApiError`] exactly as `PRINT_CLIENT_SPEC.md` §3.6 tabulates them,
//! so callers decide policy (drop, skip, retry) from the variant alone.

use std::time::Duration;

use reqwest::{Client, RequestBuilder, Response, StatusCode};
use serde::de::DeserializeOwned;
use thiserror::Error;

use crate::types::*;

#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum ApiError {
    /// 401 on an authenticated call: the device was revoked. Wipe the token; never retry.
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

#[derive(Clone)]
pub struct ApiClient {
    http: Client,
    server_url: String,
    token: Option<String>,
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
    pub fn new(server_url: &str, token: Option<String>) -> Self {
        let http = Client::builder()
            .timeout(Duration::from_secs(10)) // the contract's 10 s
            .user_agent(concat!("ri-print/", env!("CARGO_PKG_VERSION")))
            .build()
            .expect("http client");
        ApiClient { http, server_url: server_url.trim_end_matches('/').to_string(), token }
    }

    fn base(&self, path: &str) -> String {
        format!("{}/api/devices/{}", self.server_url, path.trim_start_matches('/'))
    }

    fn authed(&self, rb: RequestBuilder) -> RequestBuilder {
        match &self.token {
            Some(t) => rb.bearer_auth(t),
            None => rb,
        }
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
        let resp = Self::send(rb).await?;
        resp.json::<T>().await.map_err(|e| ApiError::Http(200, format!("unreadable response: {e}")))
    }

    /// `GET {server_url}/up` — liveness, not under the API base.
    pub async fn up(&self) -> Result<(), ApiError> {
        Self::send(self.http.get(format!("{}/up", self.server_url))).await.map(|_| ())
    }

    pub async fn pair(
        &self,
        code: &str,
        device_name: &str,
        device_uuid: &str,
    ) -> Result<PairResponse, ApiError> {
        let body = serde_json::json!({
            "code": code,
            "device_name": clip(device_name, 255),
            "device_uuid": device_uuid,
            "mac_address": null,
        });
        Self::json(self.http.post(self.base("pair")).json(&body)).await
    }

    pub async fn heartbeat(&self, req: &HeartbeatRequest) -> Result<HeartbeatResponse, ApiError> {
        Self::json(self.authed(self.http.post(self.base("heartbeat")).json(req))).await
    }

    pub async fn list_jobs(&self) -> Result<Vec<PrintJob>, ApiError> {
        Ok(Self::json::<JobList>(self.authed(self.http.get(self.base("print-jobs")))).await?.jobs)
    }

    /// Claim a job, or extend a claim already held (idempotent keep-alive).
    pub async fn claim(&self, id: i64) -> Result<ClaimResponse, ApiError> {
        Self::json(self.authed(self.http.post(self.base(&format!("print-jobs/{id}/claim"))))).await
    }

    pub async fn payload(&self, id: i64) -> Result<Vec<u8>, ApiError> {
        let rb = self
            .authed(self.http.get(self.base(&format!("print-jobs/{id}/payload"))))
            .timeout(Duration::from_secs(60));
        let resp = Self::send(rb).await?;
        resp.bytes().await.map(|b| b.to_vec()).map_err(|e| ApiError::Network(simplify(e)))
    }

    pub async fn result(&self, id: i64, printed: bool, error: Option<&str>) -> Result<(), ApiError> {
        let error = error.map(|e| clip(e, 2000));
        let body = ResultRequest {
            status: if printed { "printed" } else { "failed" },
            error: error.as_deref(),
        };
        Self::send(self.authed(self.http.post(self.base(&format!("print-jobs/{id}/result"))).json(&body)))
            .await
            .map(|_| ())
    }
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
