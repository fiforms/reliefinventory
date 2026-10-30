//! The launcher's equivalent of the Docker web host's `docker/updater/ri-updater.py`: the app can't run `docker compose`
//! itself, so an instance administrator only *requests* a check, an update or a backup (`InstanceUpdate` in the app) and
//! this service, which sits outside the containers, polls for the request, does it and reports each step back.
//!
//!   check   `docker compose pull`, compare the pulled image's RI_VERSION with the running one. Nothing restarts.
//!   update  pull; if the image is newer, `down` then `up -d`, wait for /up, report.
//!   backup  ask the provisioner for a full-instance backup with files (`instance:backup --requested`), report.
//!
//! Never goes backwards (an image whose RI_VERSION is not newer, or has none, is not installed) and never uses `down -v`.
//! On the **edge** channel images carry no RI_VERSION, so "newer" means "a different image" there.
//!
//! Docker is reached only through `Platform::exec` (like `backups`), so the same code serves the Linux, Lima and WSL
//! backends; the app is reached over HTTP on its internal port with the shared `RI_UPDATE_SECRET`, which the launcher
//! generates once and writes into the compose file it renders (see `compose.rs`).
use crate::layer::REMOTE_DIR;
use crate::platform::{fail, Platform, PlatformError, Result, INTERNAL_PORT};
use crate::settings::Channel;
use async_trait::async_trait;
use rand::RngCore;
use serde::Deserialize;
use std::path::{Path, PathBuf};
use std::sync::Arc;
use std::time::Duration;

/// How long to keep trying to say how it went: the app is down while it restarts.
const REPORT_RETRY: Duration = Duration::from_secs(600);
/// A migration after an upgrade can take a while.
const HEALTH_WAIT: Duration = Duration::from_secs(600);
pub const POLL_EVERY: Duration = Duration::from_secs(30);

pub fn secret_path(dir: &Path) -> PathBuf {
    dir.join("update-secret")
}

/// The shared secret between this service and the app: made once, kept next to `settings.json`, readable only by this user.
pub fn load_or_create_secret(dir: &Path) -> std::io::Result<String> {
    let path = secret_path(dir);
    if let Ok(s) = std::fs::read_to_string(&path) {
        let s = s.trim();
        if s.len() >= 32 {
            return Ok(s.to_string());
        }
    }
    let mut bytes = [0u8; 24];
    rand::thread_rng().fill_bytes(&mut bytes);
    let secret: String = bytes.iter().map(|b| format!("{b:02x}")).collect();
    std::fs::create_dir_all(dir)?;
    let tmp = path.with_extension("tmp");
    std::fs::write(&tmp, &secret)?;
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        std::fs::set_permissions(&tmp, std::fs::Permissions::from_mode(0o600))?;
    }
    std::fs::rename(tmp, path)?;
    Ok(secret)
}

/// `1.4.0` / `v1.4.0-rc1` -> comparable tuple; None when it is not a release version.
pub fn version_key(v: &str) -> Option<(u64, u64, u64)> {
    let v = v.trim().trim_start_matches('v');
    let mut parts = v.splitn(3, '.');
    let major = parts.next()?.parse().ok()?;
    let minor = parts.next()?.parse().ok()?;
    let patch: String = parts.next()?.chars().take_while(|c| c.is_ascii_digit()).collect();
    Some((major, minor, patch.parse().ok()?))
}

/// The app's two calls. A trait so tests need no web server.
#[async_trait]
pub trait UpdateApi: Send + Sync {
    /// The waiting request (`check`, `update`, `backup`), if any.
    async fn poll(&self) -> Result<Option<String>>;
    async fn report(&self, r: &Report) -> Result<()>;
    /// The app answers `/up`.
    async fn healthy(&self) -> bool;
}

#[derive(Debug, Clone, Default, PartialEq)]
pub struct Report {
    pub state: &'static str,
    pub message: String,
    pub current: Option<String>,
    pub available: Option<String>,
}

fn report(state: &'static str, message: impl Into<String>) -> Report {
    Report { state, message: message.into(), ..Report::default() }
}

pub struct HttpApi {
    base: String,
    secret: String,
    client: reqwest::Client,
}

impl HttpApi {
    pub fn new(secret: String) -> Self {
        let client = reqwest::Client::builder().timeout(Duration::from_secs(15)).build().expect("a plain HTTP client always builds");
        HttpApi { base: format!("http://127.0.0.1:{INTERNAL_PORT}"), secret, client }
    }
}

#[derive(Deserialize)]
struct Poll {
    action: Option<String>,
}

fn http(e: reqwest::Error) -> PlatformError {
    PlatformError::Failed(e.to_string())
}

#[async_trait]
impl UpdateApi for HttpApi {
    async fn poll(&self) -> Result<Option<String>> {
        let r = self.client.get(format!("{}/api/instance/update", self.base)).bearer_auth(&self.secret).header("Accept", "application/json").send().await.map_err(http)?;
        if !r.status().is_success() {
            return fail(format!("the app answered {}", r.status()));
        }
        Ok(r.json::<Poll>().await.map_err(http)?.action)
    }

    async fn report(&self, r: &Report) -> Result<()> {
        let message: String = r.message.chars().take(1000).collect();
        let body = serde_json::json!({
            "state": r.state,
            "message": if message.is_empty() { None } else { Some(message) },
            "current_version": r.current,
            "available_version": r.available,
        });
        let resp = self.client.post(format!("{}/api/instance/update/status", self.base)).bearer_auth(&self.secret).header("Accept", "application/json").json(&body).send().await.map_err(http)?;
        if resp.status().is_success() {
            Ok(())
        } else {
            fail(format!("the app answered {}", resp.status()))
        }
    }

    async fn healthy(&self) -> bool {
        matches!(self.client.get(format!("{}/up", self.base)).send().await, Ok(r) if r.status().is_success())
    }
}

fn sq(s: &str) -> String {
    format!("'{}'", s.replace('\'', "'\\''"))
}

fn dc(sub: &str) -> String {
    format!("cd {REMOTE_DIR} && docker compose {sub}")
}

pub struct UpdateService<P> {
    platform: Arc<P>,
    api: Arc<dyn UpdateApi>,
    retry_every: Duration,
    report_retry: Duration,
    health_wait: Duration,
}

struct Checked {
    version: Option<String>,
    running_version: Option<String>,
    newer: bool,
}

impl<P: Platform + 'static> UpdateService<P> {
    pub fn new(platform: Arc<P>, api: Arc<dyn UpdateApi>) -> Self {
        UpdateService { platform, api, retry_every: Duration::from_secs(5), report_retry: REPORT_RETRY, health_wait: HEALTH_WAIT }
    }

    async fn sh(&self, script: &str) -> Result<String> {
        self.platform.exec(&["sh", "-c", script]).await
    }

    async fn compose(&self, sub: &str) -> Result<String> {
        self.sh(&dc(sub)).await
    }

    /// Say how it went, retrying: the app is down while it restarts.
    async fn say(&self, r: Report, retry: bool) -> bool {
        let deadline = tokio::time::Instant::now() + if retry { self.report_retry } else { Duration::ZERO };
        loop {
            match self.api.report(&r).await {
                Ok(()) => return true,
                Err(e) => {
                    if tokio::time::Instant::now() >= deadline {
                        tracing::warn!("could not report {}: {e}", r.state);
                        return false;
                    }
                    tokio::time::sleep(self.retry_every).await;
                }
            }
        }
    }

    async fn image_ref(&self) -> Result<String> {
        let out = self.compose("config --format json").await?;
        let v: serde_json::Value = serde_json::from_str(&out).map_err(|e| PlatformError::Failed(format!("docker compose config: {e}")))?;
        match v["services"]["app"]["image"].as_str() {
            Some(s) => Ok(s.to_string()),
            None => fail("the compose file has no app image"),
        }
    }

    /// (image id, RI_VERSION), or None when the image is not there.
    async fn image_info(&self, image: &str) -> Option<(String, Option<String>)> {
        let script = format!("docker image inspect {} --format '{{{{.Id}}}}|{{{{json .Config.Env}}}}'", sq(image));
        let out = self.sh(&script).await.ok()?;
        let (id, env) = out.trim().split_once('|')?;
        let env: Vec<String> = serde_json::from_str(env).unwrap_or_default();
        let version = env.iter().find_map(|e| e.strip_prefix("RI_VERSION=")).filter(|v| !v.is_empty()).map(str::to_string);
        Some((id.to_string(), version))
    }

    async fn running_image_id(&self) -> Option<String> {
        let script = format!("cid=$({} ps -q app | head -n1); [ -n \"$cid\" ] && docker inspect \"$cid\" --format '{{{{.Image}}}}'", dc(""));
        let out = self.sh(&script).await.ok()?;
        let id = out.trim();
        (!id.is_empty()).then(|| id.to_string())
    }

    /// Pull, then say what is there. Nothing restarts.
    async fn check(&self, channel: Channel) -> Result<Checked> {
        let image = self.image_ref().await?;
        let running_id = self.running_image_id().await;
        let running_version = match &running_id {
            Some(id) => self.image_info(id).await.and_then(|(_, v)| v),
            None => None,
        };
        self.compose("pull").await.map_err(|e| PlatformError::Failed(format!("docker compose pull failed: {e}")))?;
        let (new_id, version) = self.image_info(&image).await.unzip();
        let version = version.flatten();
        let different = new_id.is_some() && new_id != running_id;
        let newer = different
            && match channel {
                // unreleased builds carry no number: a different image is the only signal there is
                Channel::Edge => true,
                Channel::Latest => match (version.as_deref().and_then(version_key), running_version.as_deref().and_then(version_key)) {
                    (Some(new), Some(old)) => new > old,
                    (Some(_), None) => true,
                    _ => false,
                },
            };
        Ok(Checked { version, running_version, newer })
    }

    async fn do_check(&self, channel: Channel) {
        self.say(report("checking", "Looking for a new image"), false).await;
        match self.check(channel).await {
            Err(e) => self.say(report("failed", e.to_string()), true).await,
            Ok(c) if c.newer => {
                let msg = match &c.version {
                    Some(v) => format!("Version {v} is available."),
                    None => "A newer edge build is available.".into(),
                };
                self.say(Report { state: "done", message: msg, current: c.running_version, available: c.version }, true).await
            }
            Ok(c) => self.say(Report { state: "done", message: "You are running the newest version.".into(), current: c.running_version, available: None }, true).await,
        };
    }

    async fn do_update(&self, channel: Channel) {
        self.say(report("pulling", "Downloading the new image"), false).await;
        let c = match self.check(channel).await {
            Ok(c) => c,
            Err(e) => {
                self.say(report("failed", e.to_string()), true).await;
                return;
            }
        };
        if !c.newer {
            self.say(Report { state: "done", message: "Already running the newest version.".into(), current: c.running_version, available: None }, true).await;
            return;
        }
        let label = c.version.clone().unwrap_or_else(|| "the newest edge build".into());
        self.say(report("restarting", format!("Installing {label}; the site is offline for a few minutes")), false).await;
        for sub in ["down", "up -d --remove-orphans"] {
            if let Err(e) = self.compose(sub).await {
                self.say(report("failed", format!("docker compose {} failed: {e}", sub.split(' ').next().unwrap_or(sub))), true).await;
                return;
            }
        }
        if !self.wait_healthy().await {
            self.say(report("failed", "The new version did not become healthy in time; check `docker compose logs app`."), true).await;
            return;
        }
        self.say(Report { state: "done", message: format!("Updated to {label}."), current: c.version, available: None }, true).await;
    }

    async fn wait_healthy(&self) -> bool {
        let deadline = tokio::time::Instant::now() + self.health_wait;
        while tokio::time::Instant::now() < deadline {
            if self.api.healthy().await {
                return true;
            }
            tokio::time::sleep(self.retry_every).await;
        }
        false
    }

    /// The same fixed request Instance Admin's "Back up now" writes; the provisioner (which alone holds the database
    /// administrator's password) makes the zip, with every warehouse's files and its keys sealed to the recovery key.
    async fn do_backup(&self) {
        self.say(report("backing-up", "Taking a full-instance backup"), false).await;
        let r = match self.compose("exec -T app php artisan instance:backup --requested --no-interaction").await {
            Ok(out) => {
                let note = out.lines().rev().find(|l| !l.trim().is_empty()).unwrap_or("Backup finished.").trim().to_string();
                report("done", note)
            }
            Err(e) => report("failed", format!("The backup did not finish: {e}")),
        };
        self.say(r, true).await;
    }

    /// One look at the app for a waiting request; returns what it did.
    pub async fn poll_once(&self, channel: Channel) -> Option<String> {
        let action = match self.api.poll().await {
            Ok(a) => a?,
            Err(e) => {
                tracing::debug!("app not reachable for the update service: {e}");
                return None;
            }
        };
        match action.as_str() {
            "check" => self.do_check(channel).await,
            "update" => self.do_update(channel).await,
            "backup" => self.do_backup().await,
            _ => return None,
        }
        Some(action)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::platform::{InstallOutcome, Preflight, ProgressFn, State};
    use std::sync::Mutex;

    struct FakeApi {
        waiting: Mutex<Option<String>>,
        reports: Mutex<Vec<Report>>,
        healthy: bool,
    }

    #[async_trait]
    impl UpdateApi for FakeApi {
        async fn poll(&self) -> Result<Option<String>> {
            Ok(self.waiting.lock().unwrap().take())
        }
        async fn report(&self, r: &Report) -> Result<()> {
            self.reports.lock().unwrap().push(r.clone());
            Ok(())
        }
        async fn healthy(&self) -> bool {
            self.healthy
        }
    }

    /// A Docker that knows the running image, the pulled one and each one's version.
    struct FakeDocker {
        cmds: Mutex<Vec<String>>,
        running: (&'static str, Option<&'static str>),
        pulled: (&'static str, Option<&'static str>),
        fail_on: Option<&'static str>,
    }

    #[async_trait]
    impl Platform for FakeDocker {
        async fn preflight(&self) -> Result<Preflight> {
            Ok(Preflight::Ok)
        }
        async fn install(&self, _c: &str, _p: ProgressFn) -> Result<InstallOutcome> {
            Ok(InstallOutcome::Done)
        }
        async fn state(&self) -> Result<State> {
            Ok(State::Running)
        }
        async fn start(&self, _c: &str) -> Result<()> {
            Ok(())
        }
        async fn stop(&self) -> Result<()> {
            Ok(())
        }
        async fn update(&self, _c: &str) -> Result<()> {
            Ok(())
        }
        async fn uninstall(&self, _k: bool) -> Result<()> {
            Ok(())
        }
        async fn exec(&self, a: &[&str]) -> Result<String> {
            let line = a.join(" ");
            self.cmds.lock().unwrap().push(line.clone());
            if let Some(f) = self.fail_on {
                if line.contains(f) {
                    return fail("boom");
                }
            }
            let env = |v: Option<&str>| match v {
                Some(v) => format!("[\"PATH=/bin\",\"RI_VERSION={v}\"]"),
                None => "[\"PATH=/bin\"]".into(),
            };
            Ok(if line.contains("config --format json") {
                r#"{"services":{"app":{"image":"ghcr.io/x/app:latest"}}}"#.into()
            } else if line.contains("ps -q app") {
                format!("{}\n", self.running.0)
            } else if line.contains("image inspect 'ghcr.io/x/app:latest'") {
                format!("{}|{}\n", self.pulled.0, env(self.pulled.1))
            } else if line.contains("image inspect 'sha256:old'") {
                format!("sha256:old|{}\n", env(self.running.1))
            } else if line.contains("instance:backup") {
                "instance backup written: /backups/instance/x.zip\n".into()
            } else {
                String::new()
            })
        }
        async fn pull(&self, _r: &str, _l: &Path) -> Result<()> {
            Ok(())
        }
        async fn push(&self, _l: &Path, _r: &str) -> Result<()> {
            Ok(())
        }
    }

    fn service(action: &str, running_v: Option<&'static str>, pulled: (&'static str, Option<&'static str>), healthy: bool, fail_on: Option<&'static str>) -> (UpdateService<FakeDocker>, Arc<FakeApi>, Arc<FakeDocker>) {
        let api = Arc::new(FakeApi { waiting: Mutex::new(Some(action.into())), reports: Mutex::new(vec![]), healthy });
        let docker = Arc::new(FakeDocker { cmds: Mutex::new(vec![]), running: ("sha256:old", running_v), pulled, fail_on });
        let mut s = UpdateService::new(docker.clone(), api.clone());
        s.retry_every = Duration::from_millis(1);
        s.report_retry = Duration::from_millis(5);
        s.health_wait = Duration::from_millis(20);
        (s, api, docker)
    }

    fn states(api: &FakeApi) -> Vec<&'static str> {
        api.reports.lock().unwrap().iter().map(|r| r.state).collect()
    }

    fn ran(d: &FakeDocker, what: &str) -> bool {
        d.cmds.lock().unwrap().iter().any(|c| c.contains(what))
    }

    #[test]
    fn versions() {
        assert_eq!(version_key("1.4.0"), Some((1, 4, 0)));
        assert_eq!(version_key("v1.4.2-rc1"), Some((1, 4, 2)));
        assert_eq!(version_key(""), None);
        assert_eq!(version_key("edge"), None);
        assert!(version_key("1.10.0") > version_key("1.9.9"));
    }

    #[test]
    fn the_secret_is_made_once_and_kept() {
        let d = tempfile::tempdir().unwrap();
        let a = load_or_create_secret(d.path()).unwrap();
        assert_eq!(a.len(), 48);
        assert_eq!(load_or_create_secret(d.path()).unwrap(), a);
    }

    #[tokio::test]
    async fn a_newer_release_is_installed_and_reported() {
        let (s, api, d) = service("update", Some("1.0.0"), ("sha256:new", Some("1.1.0")), true, None);
        assert_eq!(s.poll_once(Channel::Latest).await.as_deref(), Some("update"));
        assert_eq!(states(&api), ["pulling", "restarting", "done"]);
        assert!(ran(&d, "docker compose down") && ran(&d, "up -d --remove-orphans"));
        let last = api.reports.lock().unwrap().last().cloned().unwrap();
        assert_eq!((last.message.as_str(), last.current.as_deref()), ("Updated to 1.1.0.", Some("1.1.0")));
    }

    #[tokio::test]
    async fn an_older_or_unnumbered_image_is_never_installed() {
        for pulled in [("sha256:new", Some("0.9.0")), ("sha256:new", None)] {
            let (s, api, d) = service("update", Some("1.0.0"), pulled, true, None);
            s.poll_once(Channel::Latest).await;
            assert_eq!(states(&api), ["pulling", "done"]);
            assert!(!ran(&d, "down"));
        }
    }

    #[tokio::test]
    async fn the_same_image_changes_nothing() {
        let (s, api, d) = service("update", Some("1.0.0"), ("sha256:old", Some("1.0.0")), true, None);
        s.poll_once(Channel::Latest).await;
        assert_eq!(states(&api), ["pulling", "done"]);
        assert!(!ran(&d, "down"));
    }

    #[tokio::test]
    async fn edge_installs_any_different_image() {
        let (s, api, d) = service("update", None, ("sha256:edge", None), true, None);
        s.poll_once(Channel::Edge).await;
        assert_eq!(states(&api), ["pulling", "restarting", "done"]);
        assert!(ran(&d, "docker compose down"));
    }

    #[tokio::test]
    async fn a_check_pulls_and_says_what_is_available_without_restarting() {
        let (s, api, d) = service("check", Some("1.0.0"), ("sha256:new", Some("1.2.0")), true, None);
        s.poll_once(Channel::Latest).await;
        assert_eq!(states(&api), ["checking", "done"]);
        assert_eq!(api.reports.lock().unwrap().last().unwrap().available.as_deref(), Some("1.2.0"));
        assert!(ran(&d, "docker compose pull") && !ran(&d, "down"));
    }

    #[tokio::test]
    async fn a_failed_pull_is_reported_and_nothing_restarts() {
        let (s, api, d) = service("update", Some("1.0.0"), ("sha256:new", Some("1.1.0")), true, Some("compose pull"));
        s.poll_once(Channel::Latest).await;
        assert_eq!(states(&api), ["pulling", "failed"]);
        assert!(!ran(&d, "down"));
    }

    #[tokio::test]
    async fn an_update_that_never_becomes_healthy_is_reported_failed() {
        let (s, api, _d) = service("update", Some("1.0.0"), ("sha256:new", Some("1.1.0")), false, None);
        s.poll_once(Channel::Latest).await;
        assert_eq!(states(&api), ["pulling", "restarting", "failed"]);
    }

    #[tokio::test]
    async fn a_backup_request_asks_the_provisioner_and_never_touches_the_image() {
        let (s, api, d) = service("backup", Some("1.0.0"), ("sha256:new", Some("1.1.0")), true, None);
        assert_eq!(s.poll_once(Channel::Latest).await.as_deref(), Some("backup"));
        assert_eq!(states(&api), ["backing-up", "done"]);
        assert!(ran(&d, "exec -T app php artisan instance:backup --requested"));
        assert!(!ran(&d, "pull") && !ran(&d, "down"));
        assert_eq!(api.reports.lock().unwrap().last().unwrap().message, "instance backup written: /backups/instance/x.zip");
    }

    #[tokio::test]
    async fn a_failed_backup_is_reported() {
        let (s, api, _d) = service("backup", None, ("sha256:new", None), true, Some("instance:backup"));
        s.poll_once(Channel::Latest).await;
        assert_eq!(states(&api), ["backing-up", "failed"]);
    }

    #[tokio::test]
    async fn nothing_waiting_does_nothing() {
        let (s, api, d) = service("noop", None, ("sha256:new", None), true, None);
        assert_eq!(s.poll_once(Channel::Latest).await, None);
        assert!(api.reports.lock().unwrap().is_empty() && d.cmds.lock().unwrap().is_empty());
    }
}
