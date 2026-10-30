use crate::compose;
use crate::network::{self, Machine};
use crate::platform::{fail, InstallOutcome, Platform, PlatformError, Preflight, Result, State, INTERNAL_PORT};
use crate::status::{self, InstallStatus};
use crate::proxy;
use crate::settings::{self, Channel, Mode, Settings};
use crate::updater;
use serde::Serialize;
use std::net::SocketAddr;
use std::path::PathBuf;
use std::sync::Arc;
use tokio::sync::Mutex;
use tokio::task::JoinHandle;

/// Everything the window shows, in one call.
#[derive(Debug, Clone, Serialize)]
pub struct Summary {
    pub state: State,
    pub proxy_running: bool,
    pub mode: Mode,
    pub hostname: Option<String>,
    pub default_hostname: String,
    pub port: u16,
    pub timezone: Option<String>,
    pub channel: Channel,
    /// The address people open on this computer.
    pub url: String,
    /// What to send to others on the network (empty in local-only mode).
    pub share_urls: Vec<String>,
    /// `windows` / `macos`, so the window can word things for this computer (firewall prompt, tray vs menu bar).
    pub os: &'static str,
}

/// Ties the settings file, the compose editor, the LAN proxy and a platform backend together. The proxy
/// lives in this process, so while the launcher is closed the app is reachable only from the Linux layer.
pub struct Launcher<P: Platform> {
    platform: Arc<P>,
    dir: PathBuf,
    proxy: Mutex<Option<JoinHandle<()>>>,
}

fn io(e: std::io::Error) -> PlatformError {
    PlatformError::Failed(e.to_string())
}

fn invalid(e: settings::SettingsError) -> PlatformError {
    PlatformError::Failed(e.to_string())
}

impl<P: Platform + 'static> Launcher<P> {
    pub fn new(platform: Arc<P>, dir: PathBuf) -> Self {
        Launcher { platform, dir, proxy: Mutex::new(None) }
    }

    pub fn platform(&self) -> &Arc<P> {
        &self.platform
    }

    pub fn dir(&self) -> &std::path::Path {
        &self.dir
    }

    pub fn settings(&self) -> Settings {
        Settings::load(&self.dir)
    }

    pub fn compose_for(&self, settings: &Settings, machine: &Machine) -> Result<String> {
        let mut opts = self.platform.stack_options();
        // the shared secret that lets the update service (`updater.rs`) answer the app's update and backup requests
        opts.update_secret = Some(updater::load_or_create_secret(&self.dir).map_err(io)?);
        compose::render_for(compose::TEMPLATE, settings, machine, &opts).map_err(|e| PlatformError::Failed(e.to_string()))
    }

    /// Answer the app's update, check and backup requests for as long as this process lives (see `updater`). It only
    /// acts while the stack is running; the channel is read from the settings every time, so a change applies at once.
    pub async fn serve_update_requests(self: Arc<Self>) {
        let secret = match updater::load_or_create_secret(&self.dir) {
            Ok(s) => s,
            Err(e) => {
                tracing::warn!("update service not started: {e}");
                return;
            }
        };
        let service = updater::UpdateService::new(self.platform.clone(), Arc::new(updater::HttpApi::new(secret)));
        loop {
            if self.platform.state().await.ok() == Some(State::Running) {
                service.poll_once(self.settings().channel).await;
            }
            tokio::time::sleep(updater::POLL_EVERY).await;
        }
    }

    async fn proxy_running(&self) -> bool {
        self.proxy.lock().await.as_ref().map(|h| !h.is_finished()).unwrap_or(false)
    }

    pub async fn summary(&self) -> Summary {
        let (s, m) = (self.settings(), Machine::detect());
        Summary {
            state: self.platform.state().await.unwrap_or(State::Stopped),
            proxy_running: self.proxy_running().await,
            mode: s.mode,
            hostname: s.hostname.clone(),
            default_hostname: m.default_hostname(),
            port: s.port,
            timezone: s.timezone.clone(),
            channel: s.channel,
            url: network::app_url(&s, &m),
            share_urls: network::share_urls(&s, &m),
            os: std::env::consts::OS,
        }
    }

    fn listen_addr(s: &Settings) -> SocketAddr {
        let ip = match s.mode {
            Mode::Lan => [0, 0, 0, 0],
            Mode::Local => [127, 0, 0, 1],
        };
        SocketAddr::from((ip, s.port))
    }

    /// (Re)start the proxy for the current settings. Binds before dropping the old listener's task so a
    /// busy port is reported, then swaps.
    async fn restart_proxy(&self, s: &Settings) -> Result<()> {
        let mut slot = self.proxy.lock().await;
        if let Some(old) = slot.take() {
            old.abort();
            let _ = old.await; // the listener is closed once the aborted task is gone
        }
        let listener = proxy::bind(Self::listen_addr(s)).await.map_err(|e| {
            PlatformError::Failed(format!(
                "Port {} on this computer is already in use ({e}). Close whatever is using it, or choose another port in Settings.",
                s.port
            ))
        })?;
        *slot = Some(tokio::spawn(proxy::serve(listener, format!("127.0.0.1:{INTERNAL_PORT}"))));
        Ok(())
    }

    /// The whole install, reporting through the status file (see `status`) so a window that is not the
    /// elevated process running this can show progress. Safe to run again after an error or a restart.
    pub async fn install(&self) -> Result<InstallOutcome> {
        let write = |s: InstallStatus| status::write(&self.dir, &s);
        write(InstallStatus { running: true, step: "Checking this computer".into(), ..Default::default() });
        let result = async {
            if let Preflight::Blocked(why) = self.platform.preflight().await? {
                return fail(why);
            }
            let (s, m) = (self.settings(), Machine::detect());
            let compose = self.compose_for(&s, &m)?;
            self.platform.install(&compose, status::writer(&self.dir)).await
        }
        .await;
        match &result {
            Ok(InstallOutcome::Done) => write(InstallStatus { done: true, step: "Installed".into(), ..Default::default() }),
            Ok(InstallOutcome::NeedsReboot) => write(InstallStatus {
                needs_reboot: true,
                step: "Restart needed".into(),
                detail: "Windows needs to restart to finish turning on its Linux features. Setup continues after you sign back in.".into(),
                ..Default::default()
            }),
            Err(e) => write(InstallStatus { error: Some(e.to_string()), step: "Install failed".into(), ..Default::default() }),
        }
        result
    }

    pub async fn start(&self) -> Result<()> {
        let (s, m) = (self.settings(), Machine::detect());
        let compose = self.compose_for(&s, &m)?;
        self.platform.start(&compose).await?;
        self.restart_proxy(&s).await
    }

    /// A window opened while the app is already up (the launcher was quit or restarted but the Linux
    /// layer kept running) takes the LAN proxy back, so sharing resumes without pressing Start.
    pub async fn adopt(&self) -> Result<()> {
        if !self.proxy_running().await && self.platform.state().await? == State::Running {
            self.restart_proxy(&self.settings()).await?;
        }
        Ok(())
    }

    pub async fn stop(&self) -> Result<()> {
        if let Some(h) = self.proxy.lock().await.take() {
            h.abort();
        }
        self.platform.stop().await
    }

    pub async fn update(&self) -> Result<()> {
        let (s, m) = (self.settings(), Machine::detect());
        let compose = self.compose_for(&s, &m)?;
        self.platform.update(&compose).await
    }

    /// Save new settings and, if the app is running, apply them: a changed address or timezone recreates
    /// the app's containers (a short interruption), a changed mode or port moves the proxy.
    pub async fn apply(&self, new: Settings) -> Result<()> {
        let mut new = new;
        if let Some(h) = &new.hostname {
            new.hostname = Some(settings::validate_hostname(h).map_err(invalid)?);
        }
        if let Some(t) = &new.timezone {
            new.timezone = Some(settings::validate_timezone(t).map_err(invalid)?);
        }
        settings::validate_port(new.port).map_err(invalid)?;
        let old = self.settings();
        if old == new {
            return Ok(());
        }
        new.save(&self.dir).map_err(io)?;
        if self.platform.state().await? == State::Running {
            let m = Machine::detect();
            let compose = self.compose_for(&new, &m)?;
            if compose != self.compose_for(&old, &m)? {
                if new.channel != old.channel {
                    // a different tag: download it rather than reuse a stale local copy
                    self.platform.update(&compose).await?;
                } else {
                    self.platform.start(&compose).await?;
                }
            }
            // only move a proxy this process owns; another process's (`ri-launcher run`) notices through
            // `follow_settings`
            if self.proxy.lock().await.is_some() {
                self.restart_proxy(&new).await?;
            }
        }
        Ok(())
    }

    /// For a long-running process that owns the proxy while settings are changed from elsewhere (another
    /// `ri-launcher` command): call every few seconds; moves the proxy when the saved settings differ from
    /// `last`.
    pub async fn follow_settings(&self, last: &mut Settings) -> Result<()> {
        let now = self.settings();
        if now != *last {
            *last = now.clone();
            self.restart_proxy(&now).await?;
        }
        Ok(())
    }

    /// The link to send to someone else on the network. Fails in local-only mode rather than handing out
    /// an address that cannot work.
    pub fn shareable_link(&self) -> Result<String> {
        let (s, m) = (self.settings(), Machine::detect());
        match network::share_urls(&s, &m).into_iter().next() {
            Some(u) => Ok(u),
            None => fail("Turn on \"Run as a LAN service\" first — in local-only mode nobody else can open the app."),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::platform::{InstallOutcome, Preflight, ProgressFn};
    use async_trait::async_trait;
    use std::sync::Mutex as StdMutex;

    #[derive(Default)]
    struct FakePlatform {
        running: StdMutex<bool>,
        started_with: StdMutex<Vec<String>>,
    }

    #[async_trait]
    impl Platform for FakePlatform {
        async fn preflight(&self) -> Result<Preflight> {
            Ok(Preflight::Ok)
        }
        async fn install(&self, _c: &str, _p: ProgressFn) -> Result<InstallOutcome> {
            Ok(InstallOutcome::Done)
        }
        async fn state(&self) -> Result<State> {
            Ok(if *self.running.lock().unwrap() { State::Running } else { State::Stopped })
        }
        async fn start(&self, compose: &str) -> Result<()> {
            *self.running.lock().unwrap() = true;
            self.started_with.lock().unwrap().push(compose.to_string());
            Ok(())
        }
        async fn stop(&self) -> Result<()> {
            *self.running.lock().unwrap() = false;
            Ok(())
        }
        async fn update(&self, _c: &str) -> Result<()> {
            Ok(())
        }
        async fn uninstall(&self, _k: bool) -> Result<()> {
            Ok(())
        }
        async fn exec(&self, _a: &[&str]) -> Result<String> {
            Ok(String::new())
        }
        async fn pull(&self, _r: &str, _l: &std::path::Path) -> Result<()> {
            Ok(())
        }
        async fn push(&self, _l: &std::path::Path, _r: &str) -> Result<()> {
            Ok(())
        }
    }

    fn launcher(port: u16) -> (Launcher<FakePlatform>, tempfile::TempDir) {
        let d = tempfile::tempdir().unwrap();
        Settings { port, ..Settings::default() }.save(d.path()).unwrap();
        (Launcher::new(Arc::new(FakePlatform::default()), d.path().to_path_buf()), d)
    }

    async fn free_port() -> u16 {
        let l = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        l.local_addr().unwrap().port()
    }

    #[tokio::test]
    async fn start_writes_edited_compose_and_runs_the_proxy() {
        let port = free_port().await;
        let (l, _d) = launcher(port);
        l.start().await.unwrap();
        let compose = l.platform.started_with.lock().unwrap()[0].clone();
        assert!(compose.contains(&format!("APP_URL: http://{}.local:{port}", l.summary().await.default_hostname.trim_end_matches(".local"))));
        assert!(compose.contains("\"18080:8080\""));
        let s = l.summary().await;
        assert!(s.proxy_running && s.state == State::Running);
        l.stop().await.unwrap();
        assert!(!l.summary().await.proxy_running);
    }

    #[tokio::test]
    async fn lan_mode_listens_on_all_interfaces_and_local_mode_does_not() {
        assert_eq!(Launcher::<FakePlatform>::listen_addr(&Settings::default()).ip().to_string(), "0.0.0.0");
        let local = Settings { mode: Mode::Local, ..Settings::default() };
        assert_eq!(Launcher::<FakePlatform>::listen_addr(&local).ip().to_string(), "127.0.0.1");
    }

    #[tokio::test]
    async fn changing_the_hostname_rewrites_the_compose_file_while_running() {
        let port = free_port().await;
        let (l, _d) = launcher(port);
        l.start().await.unwrap();
        let mut s = l.settings();
        s.hostname = Some("Win11Machine.local".into());
        l.apply(s).await.unwrap();
        let starts = l.platform.started_with.lock().unwrap().clone();
        assert_eq!(starts.len(), 2);
        assert!(starts[1].contains(&format!("APP_URL: http://win11machine.local:{port}")));
        assert_eq!(l.settings().hostname.as_deref(), Some("win11machine.local"));
        l.stop().await.unwrap();
    }

    #[tokio::test]
    async fn switching_to_local_only_keeps_the_app_running_and_drops_the_share_link() {
        let port = free_port().await;
        let (l, _d) = launcher(port);
        l.start().await.unwrap();
        assert!(l.shareable_link().is_ok());
        let mut s = l.settings();
        s.mode = Mode::Local;
        l.apply(s).await.unwrap();
        assert!(l.shareable_link().is_err());
        let sum = l.summary().await;
        assert!(sum.proxy_running && sum.share_urls.is_empty() && sum.url == format!("http://localhost:{port}"));
        l.stop().await.unwrap();
    }

    #[tokio::test]
    async fn another_process_changing_settings_moves_this_ones_proxy() {
        let port = free_port().await;
        let (l, d) = launcher(port);
        l.start().await.unwrap();
        let mut last = l.settings();
        // a second launcher on the same data dir plays the part of `ri-launcher set-mode local`
        let other = Launcher::new(l.platform.clone(), d.path().to_path_buf());
        let mut s = other.settings();
        s.mode = Mode::Local;
        other.apply(s).await.unwrap();
        assert!(!other.summary().await.proxy_running, "the other process owns no proxy");
        l.follow_settings(&mut last).await.unwrap();
        assert_eq!(last.mode, Mode::Local);
        assert!(l.summary().await.proxy_running);
        l.stop().await.unwrap();
    }

    #[tokio::test]
    async fn invalid_settings_are_rejected_and_not_saved() {
        let (l, _d) = launcher(free_port().await);
        let mut s = l.settings();
        s.hostname = Some("bad name".into());
        assert!(l.apply(s).await.is_err());
        assert_eq!(l.settings().hostname, None);
        let mut s = l.settings();
        s.port = 80;
        assert!(l.apply(s).await.is_err());
    }

    #[tokio::test]
    async fn a_busy_port_is_reported_at_start() {
        let busy = tokio::net::TcpListener::bind("0.0.0.0:0").await.unwrap();
        let (l, _d) = launcher(busy.local_addr().unwrap().port());
        let err = l.start().await.unwrap_err().to_string();
        assert!(err.contains("already in use"), "{err}");
    }
}
