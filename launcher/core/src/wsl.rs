//! Windows backend: Docker Engine inside a small Alpine WSL 2 distro of our own (`ReliefInventory`),
//! installed without Docker Desktop and without Ubuntu. The sequence was proven by hand on a clean
//! Windows 11 machine (see launcher/README.md); notably `wsl --install --no-distribution` does NOT work
//! on a machine with no WSL, so the features are enabled with DISM and WSL comes from its own MSI.
use crate::download::Downloader;
use crate::layer::{self, tail};
use crate::platform::*;
use crate::runner::{Output, Runner};
use async_trait::async_trait;
use std::path::{Path, PathBuf};
use std::time::Duration;

pub const DISTRO: &str = "ReliefInventory";
pub use crate::layer::REMOTE_DIR;
const FIREWALL_RULE: &str = "RI Launcher";
const RUN_KEY: &str = r"HKCU\Software\Microsoft\Windows\CurrentVersion\RunOnce";
const MIN_BUILD: u32 = 19041; // Windows 10 2004, the first with WSL 2
const FEATURES: [&str; 2] = ["Microsoft-Windows-Subsystem-Linux", "VirtualMachinePlatform"];

/// Pinned downloads (checked by SHA-256). Bump deliberately: a new WSL or Alpine is a thing to test first.
pub struct Pins {
    pub msi_url: &'static str,
    pub msi_sha256: &'static str,
    pub rootfs_url: &'static str,
    pub rootfs_sha256: &'static str,
}

pub fn pins(arch: &str) -> Option<Pins> {
    match arch {
        "x86_64" => Some(Pins {
            msi_url: "https://github.com/microsoft/WSL/releases/download/3.0.3/wsl.3.0.3.0.x64.msi",
            msi_sha256: "ae977286974255bd26bf925f00f40f5692c6b2084155cbc0563c09aea091f211",
            rootfs_url: "https://dl-cdn.alpinelinux.org/alpine/v3.24/releases/x86_64/alpine-minirootfs-3.24.3-x86_64.tar.gz",
            rootfs_sha256: "f8e1cafba9b1b283b5d30d06f9885c8d2bc161ffe9083ade8e2e157813a53407",
        }),
        "aarch64" => Some(Pins {
            msi_url: "https://github.com/microsoft/WSL/releases/download/3.0.3/wsl.3.0.3.0.arm64.msi",
            msi_sha256: "2937c7ce840bb35ec6e97f052189bdfff27f8c3463bfdd231df9a958212787a3",
            rootfs_url: "https://dl-cdn.alpinelinux.org/alpine/v3.24/releases/aarch64/alpine-minirootfs-3.24.3-aarch64.tar.gz",
            rootfs_sha256: "4d3f80afaebd447c5591a63a73bde380a149fe8b2d85488cafabb2ff97551f50",
        }),
        _ => None,
    }
}

pub struct WslBackend<R: Runner, D: Downloader> {
    runner: R,
    downloader: D,
    /// Where the distro's disk and the downloads live (`%LOCALAPPDATA%\ReliefInventory`).
    data_dir: PathBuf,
    /// The program the firewall rule allows (the one that runs the proxy).
    exe: PathBuf,
    /// Command registered to run at next sign-in when setup is waiting on a restart.
    resume_command: Option<String>,
    arch: String,
    poll: Duration,
    daemon_timeout: Duration,
}

impl<R: Runner, D: Downloader> WslBackend<R, D> {
    pub fn new(runner: R, downloader: D, data_dir: PathBuf, exe: PathBuf, resume_command: Option<String>) -> Self {
        WslBackend {
            runner,
            downloader,
            data_dir,
            exe,
            resume_command,
            arch: std::env::consts::ARCH.to_string(),
            poll: Duration::from_secs(1),
            daemon_timeout: Duration::from_secs(90),
        }
    }

    #[cfg(test)]
    fn for_test(runner: R, downloader: D, data_dir: PathBuf) -> Self {
        let mut b = Self::new(runner, downloader, data_dir, PathBuf::from(r"C:\RI\ri-launcher.exe"), Some("resume".into()));
        b.arch = "x86_64".into();
        b.poll = Duration::ZERO;
        b.daemon_timeout = Duration::from_millis(5);
        b
    }

    async fn run(&self, program: &str, args: &[&str]) -> Result<Output> {
        self.runner
            .run(program, args)
            .await
            .map_err(|e| PlatformError::Failed(format!("could not run {program}: {e}")))
    }

    /// `wsl.exe <args>` for management (`-l`, `--import`, …).
    async fn wsl(&self, args: &[&str]) -> Result<Output> {
        self.run("wsl.exe", args).await
    }

    /// A command inside our distro, bypassing its shell (`--exec`).
    async fn inside(&self, args: &[&str]) -> Result<Output> {
        let mut full = vec!["-d", DISTRO, "--exec"];
        full.extend_from_slice(args);
        self.wsl(&full).await
    }

    async fn inside_ok(&self, what: &str, script: &str) -> Result<String> {
        let out = self.inside(&["sh", "-c", script]).await?;
        if out.ok() {
            Ok(out.stdout)
        } else {
            fail(format!("{what} failed: {}", tail(&out)))
        }
    }

    async fn is_admin(&self) -> bool {
        self.run("net.exe", &["session"]).await.map(|o| o.ok()).unwrap_or(false)
    }

    async fn listed(&self, extra: &[&str]) -> bool {
        let mut args = vec!["-l"];
        args.extend_from_slice(extra);
        args.push("-q");
        match self.wsl(&args).await {
            Ok(o) if o.ok() => o.stdout.lines().any(|l| l.trim() == DISTRO),
            _ => false,
        }
    }

    async fn distro_exists(&self) -> bool {
        self.listed(&[]).await
    }

    async fn distro_running(&self) -> bool {
        self.listed(&["--running"]).await
    }

    async fn daemon_up(&self) -> bool {
        self.inside(&["docker", "info"]).await.map(|o| o.ok()).unwrap_or(false)
    }

    async fn feature_enabled(&self, name: &str) -> bool {
        let arg = format!("/featurename:{name}");
        match self.run("dism.exe", &["/online", "/get-featureinfo", &arg]).await {
            Ok(o) => o
                .stdout
                .lines()
                .filter_map(|l| l.trim().strip_prefix("State"))
                .any(|rest| rest.trim_start_matches([' ', ':']).trim() == "Enabled"),
            Err(_) => false,
        }
    }

    /// Start the distro with `dockerd` as its long-lived process: WSL stops an idle distro, and this
    /// process (one hidden `wsl.exe`) is what keeps it, and Docker, alive for as long as we want.
    async fn ensure_daemon(&self) -> Result<()> {
        if self.daemon_up().await {
            return Ok(());
        }
        self.runner
            .spawn_detached("wsl.exe", &["-d", DISTRO, "--exec", "dockerd"])
            .map_err(|e| PlatformError::Failed(format!("could not start the Linux layer: {e}")))?;
        let started = std::time::Instant::now();
        while started.elapsed() < self.daemon_timeout {
            if self.daemon_up().await {
                return Ok(());
            }
            tokio::time::sleep(self.poll).await;
        }
        fail("Docker did not start inside the Linux layer within 90 seconds")
    }

    async fn write_compose(&self, compose: &str) -> Result<()> {
        self.inside_ok("creating the app folder", &layer::make_dirs()).await?;
        let script = layer::write_stdin_to(&layer::compose_path());
        let out = self
            .runner
            .run_stdin("wsl.exe", &["-d", DISTRO, "--exec", "sh", "-c", &script], compose.as_bytes())
            .await
            .map_err(|e| PlatformError::Failed(format!("could not write the compose file: {e}")))?;
        if out.ok() {
            Ok(())
        } else {
            fail(format!("could not write the compose file: {}", tail(&out)))
        }
    }

    async fn download(&self, label: &str, url: &str, name: &str, sha: &str, progress: &ProgressFn) -> Result<PathBuf> {
        let dest = self.data_dir.join("downloads").join(name);
        self.downloader.fetch(label, url, &dest, sha, progress).await?;
        Ok(dest)
    }

    async fn remember_to_resume(&self) {
        // best effort: if it can't be registered the person just reopens the launcher after restarting
        if let Some(cmd) = &self.resume_command {
            let _ = self.run("reg.exe", &["add", RUN_KEY, "/v", "RILauncherResume", "/t", "REG_SZ", "/d", cmd, "/f"]).await;
        }
    }
}

fn windows_build(ver_output: &str) -> Option<u32> {
    // "Microsoft Windows [Version 10.0.26200.9711]"
    let v = ver_output.split("Version").nth(1)?.trim().trim_end_matches(']');
    v.split('.').nth(2)?.trim().parse().ok()
}

#[async_trait]
impl<R: Runner, D: Downloader> Platform for WslBackend<R, D> {
    async fn preflight(&self) -> Result<Preflight> {
        if pins(&self.arch).is_none() {
            return Ok(Preflight::Blocked(format!("This type of computer ({}) is not supported.", self.arch)));
        }
        let ver = self.run("cmd.exe", &["/c", "ver"]).await?;
        match windows_build(&ver.stdout) {
            Some(b) if b < MIN_BUILD => {
                return Ok(Preflight::Blocked(
                    "This version of Windows is too old. Update to Windows 10 version 2004 (May 2020) or newer, or Windows 11.".into(),
                ))
            }
            _ => {}
        }
        let script = "$c=Get-CimInstance Win32_ComputerSystem; $p=Get-CimInstance Win32_Processor | Select-Object -First 1; \"$($c.HypervisorPresent)|$($p.VirtualizationFirmwareEnabled)\"";
        let out = self.run("powershell.exe", &["-NoProfile", "-NonInteractive", "-Command", script]).await?;
        let line = out.stdout.lines().find(|l| l.contains('|')).unwrap_or("").trim().to_ascii_lowercase();
        let mut parts = line.split('|');
        let (hyper, firmware) = (parts.next() == Some("true"), parts.next() == Some("true"));
        if out.ok() && !line.is_empty() && !hyper && !firmware {
            return Ok(Preflight::Blocked(
                "Virtualization is switched off on this computer. Turn on \"Intel Virtualization Technology\" / \"SVM Mode\" / \"Virtualization\" in the computer's BIOS (UEFI) settings, then try again. If this is a virtual machine, its host must allow nested virtualization.".into(),
            ));
        }
        Ok(Preflight::Ok)
    }

    async fn install(&self, compose: &str, progress: ProgressFn) -> Result<InstallOutcome> {
        let pins = pins(&self.arch).ok_or_else(|| PlatformError::Failed("unsupported computer type".into()))?;
        if !self.is_admin().await {
            return fail("Installing needs administrator rights. Run the launcher as administrator.");
        }

        progress(Progress::step("Windows features", "Checking WSL and Virtual Machine Platform"));
        let mut reboot = false;
        for f in FEATURES {
            if self.feature_enabled(f).await {
                continue;
            }
            progress(Progress::step("Windows features", &format!("Turning on {f}")));
            let arg = format!("/featurename:{f}");
            let out = self.run("dism.exe", &["/online", "/enable-feature", &arg, "/all", "/norestart"]).await?;
            if !out.ok() && out.code != 3010 {
                return fail(format!("could not turn on {f}: {}", tail(&out)));
            }
            // 3010 = success, restart needed; a feature that is only "Enable Pending" is not usable yet
            reboot |= out.code == 3010 || !self.feature_enabled(f).await;
        }
        if reboot {
            self.remember_to_resume().await;
            return Ok(InstallOutcome::NeedsReboot);
        }

        if !self.wsl(&["--version"]).await.map(|o| o.ok()).unwrap_or(false) {
            let msi = self.download("Windows Subsystem for Linux", pins.msi_url, "wsl.msi", pins.msi_sha256, &progress).await?;
            progress(Progress::step("Installing WSL", "This takes a minute"));
            let out = self.run("msiexec.exe", &["/i", &msi.to_string_lossy(), "/qn", "/norestart"]).await?;
            if !out.ok() && out.code != 3010 {
                return fail(format!("installing WSL failed (msiexec exit {})", out.code));
            }
            if !self.wsl(&["--version"]).await.map(|o| o.ok()).unwrap_or(false) {
                self.remember_to_resume().await;
                return Ok(InstallOutcome::NeedsReboot);
            }
        }

        if !self.distro_exists().await {
            let tar = self.download("the Linux base image", pins.rootfs_url, "alpine-rootfs.tar.gz", pins.rootfs_sha256, &progress).await?;
            progress(Progress::step("Creating the Linux layer", "Importing into WSL"));
            let dir = self.data_dir.join("distro");
            tokio::fs::create_dir_all(&dir).await.map_err(|e| PlatformError::Failed(format!("{}: {e}", dir.display())))?;
            let out = self
                .wsl(&["--import", DISTRO, &dir.to_string_lossy(), &tar.to_string_lossy(), "--version", "2"])
                .await?;
            if !out.ok() {
                return fail(format!("could not create the Linux layer: {}", tail(&out)));
            }
        }

        let have_docker = self.inside(&["sh", "-c", layer::HAS_DOCKER]).await.map(|o| o.ok()).unwrap_or(false);
        if !have_docker {
            progress(Progress::step("Installing Docker", "Downloading Docker Engine into the Linux layer"));
            self.inside_ok("installing Docker", layer::INSTALL_DOCKER).await?;
        }

        progress(Progress::step("Firewall", "Allowing the launcher to accept connections from your network"));
        let _ = self.run("netsh.exe", &["advfirewall", "firewall", "delete", "rule", &format!("name={FIREWALL_RULE}")]).await;
        let _ = self
            .run(
                "netsh.exe",
                &[
                    "advfirewall", "firewall", "add", "rule",
                    &format!("name={FIREWALL_RULE}"),
                    "dir=in", "action=allow", "enable=yes", "profile=private,domain",
                    &format!("program={}", self.exe.display()),
                ],
            )
            .await;

        progress(Progress::step("Downloading Relief Inventory", "Pulling the application images (several minutes the first time)"));
        self.ensure_daemon().await?;
        self.write_compose(compose).await?;
        self.inside_ok("downloading the application", &layer::compose("pull")).await?;
        let _ = self.run("reg.exe", &["delete", RUN_KEY, "/v", "RILauncherResume", "/f"]).await;
        Ok(InstallOutcome::Done)
    }

    async fn state(&self) -> Result<State> {
        if !self.distro_exists().await {
            return Ok(State::NotInstalled);
        }
        if self.distro_running().await && self.daemon_up().await {
            Ok(State::Running)
        } else {
            Ok(State::Stopped)
        }
    }

    async fn start(&self, compose: &str) -> Result<()> {
        if !self.distro_exists().await {
            return fail("Relief Inventory is not installed yet.");
        }
        self.ensure_daemon().await?;
        self.write_compose(compose).await?;
        self.inside_ok("starting the application", &layer::compose("up -d")).await?;
        Ok(())
    }

    async fn stop(&self) -> Result<()> {
        if !self.distro_exists().await {
            return Ok(());
        }
        if self.distro_running().await {
            // graceful first so MariaDB flushes; the terminate below also ends the keep-alive process
            let _ = self.inside(&["sh", "-c", &layer::compose("stop")]).await;
        }
        let out = self.wsl(&["--terminate", DISTRO]).await?;
        if out.ok() {
            Ok(())
        } else {
            fail(format!("could not stop the Linux layer: {}", tail(&out)))
        }
    }

    async fn update(&self, compose: &str) -> Result<()> {
        self.ensure_daemon().await?;
        self.write_compose(compose).await?;
        self.inside_ok("downloading the update", &layer::compose("pull")).await?;
        self.inside_ok("restarting with the update", &layer::compose("up -d")).await?;
        Ok(())
    }

    async fn uninstall(&self, keep_data: bool) -> Result<()> {
        self.stop().await?;
        let _ = self.run("netsh.exe", &["advfirewall", "firewall", "delete", "rule", &format!("name={FIREWALL_RULE}")]).await;
        let _ = self.run("reg.exe", &["delete", RUN_KEY, "/v", "RILauncherResume", "/f"]).await;
        if keep_data {
            return Ok(());
        }
        if self.distro_exists().await {
            let out = self.wsl(&["--unregister", DISTRO]).await?;
            if !out.ok() {
                return fail(format!("could not remove the Linux layer: {}", tail(&out)));
            }
        }
        for sub in ["distro", "downloads"] {
            let _ = tokio::fs::remove_dir_all(self.data_dir.join(sub)).await;
        }
        Ok(())
    }

    async fn exec(&self, args: &[&str]) -> Result<String> {
        let out = self.inside(args).await?;
        if out.ok() {
            Ok(out.stdout)
        } else {
            fail(tail(&out))
        }
    }

    async fn pull(&self, remote: &str, local: &Path) -> Result<()> {
        copy_file(host_path(remote), local.to_path_buf()).await
    }

    /// A new console window with a shell inside the layer, in the compose folder. `start` is what gives it a
    /// window (the runner hides the console of anything it spawns directly).
    async fn open_terminal(&self) -> Result<()> {
        let script = layer::install_shortcuts_script(layer::SHORTCUT_DIR, &layer::layer_preamble());
        let out = self
            .runner
            .run_stdin("wsl.exe", &["-d", DISTRO, "--exec", "sh"], script.as_bytes())
            .await
            .map_err(|e| PlatformError::Failed(format!("could not set up the command shortcuts: {e}")))?;
        if !out.ok() {
            return fail(format!("could not set up the command shortcuts: {}", tail(&out)));
        }
        self.runner
            .spawn_detached("cmd.exe", &["/c", "start", "Relief Inventory", "wsl.exe", "-d", DISTRO, "--cd", REMOTE_DIR])
            .map_err(|e| PlatformError::Failed(format!("could not open a terminal: {e}")))
    }

    async fn push(&self, local: &Path, remote: &str) -> Result<()> {
        copy_file(local.to_path_buf(), host_path(remote)).await
    }
}

/// Where a path inside the distro can be read/written from Windows (only while the distro is running).
fn host_path(remote: &str) -> PathBuf {
    let mut p = format!(r"\\wsl.localhost\{DISTRO}");
    for part in remote.split('/').filter(|s| !s.is_empty()) {
        p.push('\\');
        p.push_str(part);
    }
    PathBuf::from(p)
}

async fn copy_file(from: PathBuf, to: PathBuf) -> Result<()> {
    tokio::task::spawn_blocking(move || std::fs::copy(&from, &to))
        .await
        .map_err(|e| PlatformError::Failed(e.to_string()))?
        .map(|_| ())
        .map_err(|e| PlatformError::Failed(format!("could not copy the file: {e}")))
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::{Arc, Mutex};

    type Script = Box<dyn Fn(&str) -> Option<Output> + Send + Sync>;

    struct Fake {
        calls: Arc<Mutex<Vec<String>>>,
        script: Script,
        /// set once the keep-alive process has been spawned (a real `docker info` only works after that)
        spawned: Arc<Mutex<bool>>,
    }

    impl Fake {
        /// Fresh-install tests: Docker is down until the keep-alive is spawned.
        fn daemon_starts_down(&self) -> bool {
            self.calls.lock().unwrap().iter().any(|c| c.contains("--import ReliefInventory") || c.contains("apk add"))
        }
    }

    fn out(code: i32, stdout: &str) -> Output {
        Output { code, stdout: stdout.into(), stderr: String::new() }
    }

    #[async_trait]
    impl Runner for Fake {
        async fn run(&self, program: &str, args: &[&str]) -> std::io::Result<Output> {
            let line = format!("{program} {}", args.join(" "));
            self.calls.lock().unwrap().push(line.clone());
            if let Some(o) = (self.script)(&line) {
                return Ok(o);
            }
            if line.contains("docker info") && !*self.spawned.lock().unwrap() && line.contains("--exec docker info") && self.daemon_starts_down() {
                return Ok(out(1, ""));
            }
            Ok(out(0, ""))
        }
        async fn run_stdin(&self, program: &str, args: &[&str], input: &[u8]) -> std::io::Result<Output> {
            let line = format!("{program} {} <stdin:{}>", args.join(" "), String::from_utf8_lossy(input));
            self.calls.lock().unwrap().push(line.clone());
            Ok((self.script)(&line).unwrap_or_else(|| out(0, "")))
        }
        fn spawn_detached(&self, program: &str, args: &[&str]) -> std::io::Result<()> {
            self.calls.lock().unwrap().push(format!("SPAWN {program} {}", args.join(" ")));
            *self.spawned.lock().unwrap() = true;
            Ok(())
        }
    }

    struct NoDownload(Arc<Mutex<Vec<String>>>);

    #[async_trait]
    impl Downloader for NoDownload {
        async fn fetch(&self, label: &str, _u: &str, _d: &std::path::Path, _s: &str, _p: &ProgressFn) -> Result<()> {
            self.0.lock().unwrap().push(format!("DOWNLOAD {label}"));
            Ok(())
        }
    }

    fn backend(script: Script) -> (WslBackend<Fake, NoDownload>, Arc<Mutex<Vec<String>>>) {
        let calls = Arc::new(Mutex::new(vec![]));
        let b = WslBackend::for_test(Fake { calls: calls.clone(), script, spawned: Arc::new(Mutex::new(false)) }, NoDownload(calls.clone()), std::env::temp_dir().join("ri-test"));
        (b, calls)
    }

    fn silent() -> ProgressFn {
        Arc::new(|_| {})
    }

    #[test]
    fn parses_windows_build() {
        assert_eq!(windows_build("\nMicrosoft Windows [Version 10.0.26200.9711]\n"), Some(26200));
        assert_eq!(windows_build("nonsense"), None);
    }

    #[test]
    fn host_path_is_a_wsl_unc_path() {
        assert_eq!(host_path("/opt/ri/backups/x.zip").to_string_lossy(), r"\\wsl.localhost\ReliefInventory\opt\ri\backups\x.zip");
    }

    #[tokio::test]
    async fn open_terminal_starts_a_console_in_the_compose_folder() {
        let (b, calls) = backend(Box::new(|_| None));
        b.open_terminal().await.unwrap();
        assert_eq!(calls.lock().unwrap().last().unwrap(), "SPAWN cmd.exe /c start Relief Inventory wsl.exe -d ReliefInventory --cd /opt/ri");
    }

    #[tokio::test]
    async fn state_not_installed_when_distro_missing() {
        let (b, _) = backend(Box::new(|c| c.contains("-l -q").then(|| out(1, "WSL is not installed"))));
        assert_eq!(b.state().await.unwrap(), State::NotInstalled);
    }

    #[tokio::test]
    async fn state_running_needs_distro_and_daemon() {
        let script: Script = Box::new(|c| {
            if c.contains("-l --running -q") || c.contains("-l -q") {
                Some(out(0, "ReliefInventory\n"))
            } else {
                None
            }
        });
        let (b, _) = backend(script);
        assert_eq!(b.state().await.unwrap(), State::Running);
        let script: Script = Box::new(|c| {
            if c.contains("docker info") {
                Some(out(1, ""))
            } else if c.contains("-l --running -q") {
                Some(out(0, "There are no running distributions.\n"))
            } else if c.contains("-l -q") {
                Some(out(0, "ReliefInventory\n"))
            } else {
                None
            }
        });
        let (b, _) = backend(script);
        assert_eq!(b.state().await.unwrap(), State::Stopped);
    }

    #[tokio::test]
    async fn install_requires_admin() {
        let (b, _) = backend(Box::new(|c| c.starts_with("net.exe").then(|| out(5, ""))));
        assert!(b.install("x", silent()).await.unwrap_err().to_string().contains("administrator"));
    }

    #[tokio::test]
    async fn install_enables_features_then_asks_for_reboot() {
        // dism reports both features Disabled, enabling succeeds with 3010
        let script: Script = Box::new(|c| {
            if c.contains("/get-featureinfo") {
                Some(out(0, "Feature Name : X\nState : Disabled\n"))
            } else if c.contains("/enable-feature") {
                Some(out(3010, ""))
            } else {
                None
            }
        });
        let (b, calls) = backend(script);
        assert_eq!(b.install("x", silent()).await.unwrap(), InstallOutcome::NeedsReboot);
        let calls = calls.lock().unwrap().join("\n");
        assert!(calls.contains("/featurename:Microsoft-Windows-Subsystem-Linux /all /norestart"));
        assert!(calls.contains("/featurename:VirtualMachinePlatform /all /norestart"));
        assert!(calls.contains("RILauncherResume"), "must register the resume command");
        assert!(!calls.contains("DOWNLOAD"), "nothing is downloaded before the restart");
    }

    #[tokio::test]
    async fn full_install_runs_every_step_in_order() {
        // features on, WSL missing, distro missing, docker missing
        let script: Script = Box::new(|c| {
            if c.contains("/get-featureinfo") {
                Some(out(0, "State : Enabled\n"))
            } else if c.starts_with("wsl.exe --version") {
                // first call: not installed; after msiexec ran: installed
                None
            } else if c.contains("-l -q") {
                Some(out(1, "")) // not listed
            } else if c.contains("command -v dockerd") {
                Some(out(1, ""))
            } else {
                None
            }
        });
        // wsl --version must flip after msiexec; emulate with a counter
        let installed = Arc::new(Mutex::new(false));
        let i2 = installed.clone();
        let script2: Script = Box::new(move |c| {
            if c.starts_with("msiexec") {
                *i2.lock().unwrap() = true;
                return Some(out(0, ""));
            }
            if c.starts_with("wsl.exe --version") {
                return Some(if *i2.lock().unwrap() { out(0, "WSL version: 3.0.3") } else { out(1, "not installed") });
            }
            script(c)
        });
        let (b, calls) = backend(script2);
        assert_eq!(b.install("compose-body", silent()).await.unwrap(), InstallOutcome::Done);
        let calls = calls.lock().unwrap().clone();
        let at = |needle: &str| calls.iter().position(|c| c.contains(needle)).unwrap_or_else(|| panic!("missing {needle}\n{calls:#?}"));
        assert!(at("DOWNLOAD Windows Subsystem") < at("msiexec"));
        assert!(at("msiexec") < at("--import ReliefInventory"));
        assert!(at("--import ReliefInventory") < at("apk add"));
        assert!(at("apk add") < at("netsh.exe advfirewall firewall add rule"));
        assert!(at("netsh.exe advfirewall firewall add rule") < at("SPAWN wsl.exe -d ReliefInventory --exec dockerd"));
        assert!(at("<stdin:compose-body>") < at("docker compose pull"));
        assert!(calls.iter().any(|c| c.contains("reg.exe delete")), "resume entry is cleaned up");
    }

    #[tokio::test]
    async fn rerunning_install_skips_finished_steps() {
        let script: Script = Box::new(|c| {
            if c.contains("/get-featureinfo") {
                Some(out(0, "State : Enabled\n"))
            } else if c.contains("-l -q") {
                Some(out(0, "ReliefInventory\n"))
            } else {
                None
            }
        });
        let (b, calls) = backend(script);
        assert_eq!(b.install("x", silent()).await.unwrap(), InstallOutcome::Done);
        let calls = calls.lock().unwrap().join("\n");
        assert!(!calls.contains("DOWNLOAD") && !calls.contains("--import") && !calls.contains("apk add") && !calls.contains("msiexec"));
    }

    #[tokio::test]
    async fn start_keeps_dockerd_alive_then_brings_the_stack_up() {
        let installed = Arc::new(Mutex::new(false));
        let i2 = installed.clone();
        let script: Script = Box::new(move |c| {
            if c.contains("-l -q") {
                Some(out(0, "ReliefInventory\n"))
            } else if c.contains("docker info") {
                // down until the keep-alive is spawned: spawn is recorded as a call before the next poll
                Some(if *i2.lock().unwrap() { out(0, "") } else { *i2.lock().unwrap() = true; out(1, "") })
            } else {
                None
            }
        });
        let (b, calls) = backend(script);
        b.start("compose-body").await.unwrap();
        let calls = calls.lock().unwrap().clone();
        let at = |n: &str| calls.iter().position(|c| c.contains(n)).unwrap_or_else(|| panic!("missing {n}\n{calls:#?}"));
        assert!(at("SPAWN wsl.exe -d ReliefInventory --exec dockerd") < at("<stdin:compose-body>"));
        assert!(at("<stdin:compose-body>") < at("docker compose up -d"));
    }

    #[tokio::test]
    async fn stop_is_graceful_then_terminates() {
        let script: Script = Box::new(|c| (c.contains("-l -q") || c.contains("--running")).then(|| out(0, "ReliefInventory\n")));
        let (b, calls) = backend(script);
        b.stop().await.unwrap();
        let calls = calls.lock().unwrap().clone();
        let at = |n: &str| calls.iter().position(|c| c.contains(n)).unwrap();
        assert!(at("docker compose stop") < at("--terminate ReliefInventory"));
    }

    #[tokio::test]
    async fn uninstall_keep_data_leaves_the_distro() {
        let script: Script = Box::new(|c| c.contains("-l -q").then(|| out(0, "ReliefInventory\n")));
        let (b, calls) = backend(script);
        b.uninstall(true).await.unwrap();
        assert!(!calls.lock().unwrap().join("\n").contains("--unregister"));
        let script: Script = Box::new(|c| c.contains("-l -q").then(|| out(0, "ReliefInventory\n")));
        let (b, calls) = backend(script);
        b.uninstall(false).await.unwrap();
        assert!(calls.lock().unwrap().join("\n").contains("--unregister ReliefInventory"));
    }

    #[tokio::test]
    async fn preflight_blocks_old_windows_and_no_virtualization() {
        let (b, _) = backend(Box::new(|c| c.starts_with("cmd.exe").then(|| out(0, "Microsoft Windows [Version 10.0.18363.1]"))));
        assert!(matches!(b.preflight().await.unwrap(), Preflight::Blocked(m) if m.contains("too old")));
        let script: Script = Box::new(|c| {
            if c.starts_with("cmd.exe") {
                Some(out(0, "Microsoft Windows [Version 10.0.26200.1]"))
            } else if c.starts_with("powershell.exe") {
                Some(out(0, "False|False\n"))
            } else {
                None
            }
        });
        let (b, _) = backend(script);
        assert!(matches!(b.preflight().await.unwrap(), Preflight::Blocked(m) if m.contains("Virtualization")));
        let script: Script = Box::new(|c| {
            if c.starts_with("cmd.exe") {
                Some(out(0, "Microsoft Windows [Version 10.0.26200.1]"))
            } else if c.starts_with("powershell.exe") {
                Some(out(0, "False|True\n"))
            } else {
                None
            }
        });
        let (b, _) = backend(script);
        assert_eq!(b.preflight().await.unwrap(), Preflight::Ok);
    }
}
