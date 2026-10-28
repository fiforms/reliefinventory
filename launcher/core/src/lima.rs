//! macOS backend: Docker Engine inside a small Alpine VM of our own, run by Lima (`limactl`, Apache-2.0)
//! on Apple's Virtualization.framework (`vmType: vz`) — no Docker Desktop, no admin rights, no restart.
//! The sequence was proven by hand on an Apple-silicon Mac (macOS 26, Lima 2.2.1; see launcher/MAC_VERSION.md).
//!
//! Lima is downloaded (pinned, SHA-256 checked) into the data folder, and every `limactl` call runs with
//! `LIMA_HOME` set to our own folder, so a developer's own Lima/Colima instances are never seen or touched.
//! That folder is `~/.ri-lima`, not inside the data folder: Lima's sockets live under it and a socket path
//! must be shorter than 104 bytes, which `~/Library/Application Support/ReliefInventory/…` can exceed.
use crate::download::Downloader;
use crate::layer::{self, tail};
use crate::platform::*;
use crate::runner::{Output, Runner};
use async_trait::async_trait;
use std::path::{Path, PathBuf};
use std::time::Duration;

pub const INSTANCE: &str = "ri";
/// Alpine's aarch64 image under `vz` needs macOS 13.3 (Lima's own note on its Alpine template).
const MIN_MACOS: (u32, u32) = (13, 3);
/// The VM disk is sparse (60 GiB is a ceiling, not an allocation); this is what has to be free to start.
const MIN_FREE_GB: u64 = 10;
const CPUS: u32 = 2;
const DISK_GIB: u32 = 60;

/// Pinned downloads (checked by SHA-256). Bump deliberately: a new Lima or Alpine is a thing to test first.
/// Lima: the official release tarballs (only those carry the virtualization entitlement `vz` needs), sums
/// from the release's SHA256SUMS. Alpine: the cloud images Lima's own `alpine-3.23` template pins (that
/// template gives SHA-512s; these SHA-256s were computed from the same files and the SHA-512s re-checked).
pub struct Pins {
    pub lima_url: &'static str,
    pub lima_sha256: &'static str,
    pub image_url: &'static str,
    pub image_sha256: &'static str,
}

pub fn pins(arch: &str) -> Option<Pins> {
    match arch {
        "aarch64" => Some(Pins {
            lima_url: "https://github.com/lima-vm/lima/releases/download/v2.2.1/lima-2.2.1-Darwin-arm64.tar.gz",
            lima_sha256: "9e9eacce88f37e185c346bad73aa6136f738d8cdf8c3bb23cd42b071824bc66e",
            image_url: "https://dl-cdn.alpinelinux.org/alpine/v3.23/releases/cloud/nocloud_alpine-3.23.4-aarch64-uefi-cloudinit-r0.qcow2",
            image_sha256: "eeda75c83fcade4c9f0c461c923b965df0fb1b294e5c66d4ef09ed0dc2ad360c",
        }),
        "x86_64" => Some(Pins {
            lima_url: "https://github.com/lima-vm/lima/releases/download/v2.2.1/lima-2.2.1-Darwin-x86_64.tar.gz",
            lima_sha256: "6b776e4bc41f358e6ee01fefcc78e2eb26f11635a0fcff632234af77a566c6c4",
            image_url: "https://dl-cdn.alpinelinux.org/alpine/v3.23/releases/cloud/nocloud_alpine-3.23.4-x86_64-uefi-cloudinit-r0.qcow2",
            image_sha256: "4c32a9fa451e9c2e23ae04ee2c31dddeab05e0d2a72820e0f584816299b146ef",
        }),
        _ => None,
    }
}

/// The instance definition. Fixed CPU/disk; memory chosen from the Mac's (the stack idles near 450 MB, so
/// 2 GiB is enough on an 8 GB Mac and 4 GiB leaves headroom elsewhere). No folders from the Mac are shared
/// into the VM (`mounts: []`) — the database lives on the VM's own disk — and only the app's internal port
/// is forwarded, to the Mac's 127.0.0.1 alone; the launcher's proxy owns the public port.
pub fn instance_yaml(arch: &str, image: &Path, image_sha256: &str, memory_gib: u32) -> String {
    format!(
        r#"# Written by RI Launcher. Recreated only by reinstalling; edits here are not read again.
vmType: vz
arch: {arch}
cpus: {CPUS}
memory: {memory_gib}GiB
disk: {DISK_GIB}GiB
images:
- location: "{image}"
  arch: {arch}
  digest: "sha256:{image_sha256}"
mounts: []
containerd:
  system: false
  user: false
portForwards:
- guestPort: {port}
  hostIP: 127.0.0.1
- guestPortRange: [1, 65535]
  ignore: true
"#,
        image = image.display(),
        port = INTERNAL_PORT,
    )
}

pub fn memory_gib_for(host_bytes: u64) -> u32 {
    if host_bytes <= 8 << 30 {
        2
    } else {
        4
    }
}

pub struct LimaBackend<R: Runner, D: Downloader> {
    runner: R,
    downloader: D,
    /// Lima's own files and the downloads (`~/Library/Application Support/ReliefInventory`).
    data_dir: PathBuf,
    /// `LIMA_HOME`: the instance (its disk holds the database) and Lima's sockets.
    lima_home: PathBuf,
    arch: String,
    poll: Duration,
    daemon_timeout: Duration,
}

fn os_err(what: &str, e: std::io::Error) -> PlatformError {
    PlatformError::Failed(format!("{what}: {e}"))
}

/// Single-quote for `sh`.
fn sq(s: &str) -> String {
    format!("'{}'", s.replace('\'', r"'\''"))
}

fn macos_version(sw_vers: &str) -> Option<(u32, u32)> {
    let mut parts = sw_vers.trim().split('.');
    Some((parts.next()?.parse().ok()?, parts.next().and_then(|m| m.parse().ok()).unwrap_or(0)))
}

/// Available space in GB from `df -Pk <path>` (second line, fourth column, in 1K blocks).
fn free_gb(df: &str) -> Option<u64> {
    let kb: u64 = df.lines().nth(1)?.split_whitespace().nth(3)?.parse().ok()?;
    Some(kb / 1_000_000)
}

/// Status of our instance from `limactl list --json` (one JSON object per line); None if it doesn't exist.
fn instance_status(list_json: &str) -> Option<String> {
    list_json
        .lines()
        .filter_map(|l| serde_json::from_str::<serde_json::Value>(l.trim()).ok())
        .find(|v| v["name"] == INSTANCE)
        .map(|v| v["status"].as_str().unwrap_or("").to_string())
}

impl<R: Runner, D: Downloader> LimaBackend<R, D> {
    pub fn new(runner: R, downloader: D, data_dir: PathBuf) -> Self {
        let home = dirs::home_dir().unwrap_or_else(|| data_dir.clone());
        LimaBackend {
            runner,
            downloader,
            data_dir,
            lima_home: home.join(".ri-lima"),
            arch: std::env::consts::ARCH.to_string(),
            poll: Duration::from_secs(1),
            daemon_timeout: Duration::from_secs(90),
        }
    }

    #[cfg(test)]
    fn for_test(runner: R, downloader: D, data_dir: PathBuf) -> Self {
        let mut b = Self::new(runner, downloader, data_dir.clone());
        b.lima_home = data_dir.join("lima-home");
        b.arch = "aarch64".into();
        b.poll = Duration::ZERO;
        b.daemon_timeout = Duration::from_millis(5);
        b
    }

    fn limactl(&self) -> PathBuf {
        self.data_dir.join("lima").join("bin").join("limactl")
    }

    async fn run(&self, program: &str, args: &[&str]) -> Result<Output> {
        self.runner
            .run(program, args)
            .await
            .map_err(|e| PlatformError::Failed(format!("could not run {program}: {e}")))
    }

    /// `env LIMA_HOME=… limactl <args>`: the Runner has no environment of its own, and this keeps the
    /// isolation visible in every recorded call.
    fn lima_argv(&self, args: &[&str]) -> (String, Vec<String>) {
        let mut argv = vec![format!("LIMA_HOME={}", self.lima_home.display()), self.limactl().display().to_string()];
        argv.extend(args.iter().map(|a| a.to_string()));
        ("/usr/bin/env".into(), argv)
    }

    async fn lima(&self, args: &[&str]) -> Result<Output> {
        let (program, argv) = self.lima_argv(args);
        let argv: Vec<&str> = argv.iter().map(String::as_str).collect();
        self.run(&program, &argv).await
    }

    async fn lima_ok(&self, what: &str, args: &[&str]) -> Result<()> {
        let out = self.lima(args).await?;
        if out.ok() {
            Ok(())
        } else {
            fail(format!("{what} failed: {}", tail(&out)))
        }
    }

    /// A command inside the VM as root (the guest user has passwordless sudo; Docker and `/opt/ri` are root's).
    async fn inside(&self, args: &[&str]) -> Result<Output> {
        let mut full = vec!["shell", "--workdir", "/", INSTANCE, "--", "sudo"];
        full.extend_from_slice(args);
        self.lima(&full).await
    }

    async fn inside_ok(&self, what: &str, script: &str) -> Result<String> {
        let out = self.inside(&["sh", "-c", script]).await?;
        if out.ok() {
            Ok(out.stdout)
        } else {
            fail(format!("{what} failed: {}", tail(&out)))
        }
    }

    async fn have_lima(&self) -> bool {
        self.limactl().exists() && self.lima(&["--version"]).await.map(|o| o.ok()).unwrap_or(false)
    }

    async fn status(&self) -> Option<String> {
        if !self.limactl().exists() {
            return None;
        }
        match self.lima(&["list", "--json"]).await {
            Ok(o) if o.ok() => instance_status(&o.stdout),
            _ => None,
        }
    }

    async fn daemon_up(&self) -> bool {
        self.inside(&["docker", "info"]).await.map(|o| o.ok()).unwrap_or(false)
    }

    /// Boot the VM if it is not running. Lima's host agent is its own background process, so the VM stays
    /// up after the launcher quits; dockerd is an OpenRC service inside it and comes up with it.
    async fn ensure_vm(&self, progress: Option<&ProgressFn>) -> Result<()> {
        if self.status().await.as_deref() == Some("Running") {
            return Ok(());
        }
        if let Some(p) = progress {
            p(Progress::step("Starting the Linux layer", "This takes about a minute"));
        }
        self.lima_ok("starting the Linux layer", &["start", "--tty=false", INSTANCE]).await
    }

    async fn ensure_daemon(&self) -> Result<()> {
        if self.daemon_up().await {
            return Ok(());
        }
        let _ = self.inside(&["rc-service", "docker", "start"]).await;
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
        let (program, argv) = self.lima_argv(&["shell", "--workdir", "/", INSTANCE, "--", "sudo", "sh", "-c", &script]);
        let argv: Vec<&str> = argv.iter().map(String::as_str).collect();
        let out = self
            .runner
            .run_stdin(&program, &argv, compose.as_bytes())
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

    async fn host_memory(&self) -> u64 {
        match self.run("/usr/sbin/sysctl", &["-n", "hw.memsize"]).await {
            Ok(o) if o.ok() => o.stdout.trim().parse().unwrap_or(16 << 30),
            _ => 16 << 30,
        }
    }

    /// A unique scratch name in the VM's /tmp for staging copies (`limactl copy` runs as the guest user,
    /// which can't read or write root's files directly).
    fn staging(&self) -> String {
        let nanos = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.as_nanos()).unwrap_or(0);
        format!("/tmp/ri-xfer-{}-{nanos}", std::process::id())
    }
}

#[async_trait]
impl<R: Runner, D: Downloader> Platform for LimaBackend<R, D> {
    async fn preflight(&self) -> Result<Preflight> {
        if pins(&self.arch).is_none() {
            return Ok(Preflight::Blocked(format!("This type of Mac ({}) is not supported.", self.arch)));
        }
        let ver = self.run("/usr/bin/sw_vers", &["-productVersion"]).await?;
        if let Some(v) = macos_version(&ver.stdout) {
            if v < MIN_MACOS {
                return Ok(Preflight::Blocked(
                    "This version of macOS is too old. Update to macOS Ventura 13.3 or newer.".into(),
                ));
            }
        }
        let hv = self.run("/usr/sbin/sysctl", &["-n", "kern.hv_support"]).await?;
        if hv.ok() && hv.stdout.trim() == "0" {
            return Ok(Preflight::Blocked(
                "This Mac can't run virtual machines. If this Mac is itself a virtual machine, its host must allow nested virtualization.".into(),
            ));
        }
        let home = self.lima_home.parent().map(Path::to_path_buf).unwrap_or_else(|| self.data_dir.clone());
        let df = self.run("/bin/df", &["-Pk", &home.to_string_lossy()]).await?;
        if let Some(gb) = free_gb(&df.stdout) {
            if gb < MIN_FREE_GB {
                return Ok(Preflight::Blocked(format!(
                    "There is not enough free disk space ({gb} GB free). Relief Inventory needs at least {MIN_FREE_GB} GB; free up some space and try again."
                )));
            }
        }
        Ok(Preflight::Ok)
    }

    async fn install(&self, compose: &str, progress: ProgressFn) -> Result<InstallOutcome> {
        let pins = pins(&self.arch).ok_or_else(|| PlatformError::Failed("unsupported computer type".into()))?;

        if !self.have_lima().await {
            let tgz = self.download("Lima (the virtual machine runner)", pins.lima_url, "lima.tar.gz", pins.lima_sha256, &progress).await?;
            progress(Progress::step("Installing Lima", "Unpacking"));
            let dir = self.data_dir.join("lima");
            tokio::fs::create_dir_all(&dir).await.map_err(|e| os_err(&dir.display().to_string(), e))?;
            let out = self.run("/usr/bin/tar", &["-xzf", &tgz.to_string_lossy(), "-C", &dir.to_string_lossy()]).await?;
            if !out.ok() || !self.have_lima().await {
                return fail(format!("could not unpack Lima: {}", tail(&out)));
            }
        }

        if self.status().await.is_none() {
            let image = self.download("the Linux base image", pins.image_url, &format!("alpine-{}.qcow2", self.arch), pins.image_sha256, &progress).await?;
            progress(Progress::step("Creating the Linux layer", "Setting up a small virtual machine"));
            let yaml = self.data_dir.join("ri-instance.yaml");
            let memory = memory_gib_for(self.host_memory().await);
            tokio::fs::write(&yaml, instance_yaml(&self.arch, &image, pins.image_sha256, memory))
                .await
                .map_err(|e| os_err(&yaml.display().to_string(), e))?;
            tokio::fs::create_dir_all(&self.lima_home).await.map_err(|e| os_err(&self.lima_home.display().to_string(), e))?;
            self.lima_ok("creating the Linux layer", &["create", "--name", INSTANCE, "--tty=false", &yaml.to_string_lossy()]).await?;
        }

        self.ensure_vm(Some(&progress)).await?;

        let have_docker = self.inside(&["sh", "-c", layer::HAS_DOCKER]).await.map(|o| o.ok()).unwrap_or(false);
        if !have_docker {
            progress(Progress::step("Installing Docker", "Downloading Docker Engine into the Linux layer"));
            self.inside_ok("installing Docker", layer::INSTALL_DOCKER).await?;
        }
        // start with the VM from now on (re-adding is harmless, so a re-run install repeats it)
        self.inside_ok("enabling Docker", "rc-update add docker default 2>&1").await?;

        progress(Progress::step("Downloading Relief Inventory", "Pulling the application images (several minutes the first time)"));
        self.ensure_daemon().await?;
        self.write_compose(compose).await?;
        self.inside_ok("downloading the application", &layer::compose("pull")).await?;
        Ok(InstallOutcome::Done)
    }

    async fn state(&self) -> Result<State> {
        match self.status().await.as_deref() {
            None => Ok(State::NotInstalled),
            Some("Running") if self.daemon_up().await => Ok(State::Running),
            Some(_) => Ok(State::Stopped),
        }
    }

    async fn start(&self, compose: &str) -> Result<()> {
        if self.status().await.is_none() {
            return fail("Relief Inventory is not installed yet.");
        }
        self.ensure_vm(None).await?;
        self.ensure_daemon().await?;
        self.write_compose(compose).await?;
        self.inside_ok("starting the application", &layer::compose("up -d")).await?;
        Ok(())
    }

    async fn stop(&self) -> Result<()> {
        match self.status().await.as_deref() {
            None => return Ok(()),
            Some("Running") => {
                // graceful first so MariaDB flushes, then shut the VM down
                let _ = self.inside(&["sh", "-c", &layer::compose("stop")]).await;
            }
            Some(_) => return Ok(()),
        }
        self.lima_ok("stopping the Linux layer", &["stop", INSTANCE]).await
    }

    async fn update(&self, compose: &str) -> Result<()> {
        self.ensure_vm(None).await?;
        self.ensure_daemon().await?;
        self.write_compose(compose).await?;
        self.inside_ok("downloading the update", &layer::compose("pull")).await?;
        self.inside_ok("restarting with the update", &layer::compose("up -d")).await?;
        Ok(())
    }

    async fn uninstall(&self, keep_data: bool) -> Result<()> {
        self.stop().await?;
        if keep_data {
            // the VM (and the database on its disk) and Lima itself stay, so a reinstall picks them up
            return Ok(());
        }
        if self.status().await.is_some() {
            self.lima_ok("removing the Linux layer", &["delete", "--force", INSTANCE]).await?;
        }
        let _ = tokio::fs::remove_dir_all(&self.lima_home).await;
        for sub in ["lima", "downloads"] {
            let _ = tokio::fs::remove_dir_all(self.data_dir.join(sub)).await;
        }
        let _ = tokio::fs::remove_file(self.data_dir.join("ri-instance.yaml")).await;
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
        let tmp = self.staging();
        self.inside_ok("preparing the copy", &format!("cp -- {} {tmp} && chmod 644 {tmp}", sq(remote))).await?;
        let from = format!("{INSTANCE}:{tmp}");
        let result = self.lima_ok("copying the file out", &["copy", &from, &local.to_string_lossy()]).await;
        let _ = self.inside(&["rm", "-f", &tmp]).await;
        result
    }

    /// Terminal.app with a root shell in the VM's compose folder (the stack and Docker are root's).
    async fn open_terminal(&self) -> Result<()> {
        let script = layer::install_shortcuts_script(layer::SHORTCUT_DIR, &layer::layer_preamble());
        let (program, argv) = self.lima_argv(&["shell", "--workdir", "/", INSTANCE, "--", "sudo", "sh"]);
        let argv: Vec<&str> = argv.iter().map(String::as_str).collect();
        let out = self
            .runner
            .run_stdin(&program, &argv, script.as_bytes())
            .await
            .map_err(|e| PlatformError::Failed(format!("could not set up the command shortcuts: {e}")))?;
        if !out.ok() {
            return fail(format!("could not set up the command shortcuts: {}", tail(&out)));
        }
        let shell = format!(
            "/usr/bin/env LIMA_HOME={} {} shell --workdir {} {INSTANCE} -- sudo -s",
            sq(&self.lima_home.to_string_lossy()),
            sq(&self.limactl().to_string_lossy()),
            layer::REMOTE_DIR,
        );
        let script = format!(
            "tell application \"Terminal\"\nactivate\ndo script \"{}\"\nend tell",
            shell.replace('\\', "\\\\").replace('"', "\\\"")
        );
        self.runner
            .spawn_detached("/usr/bin/osascript", &["-e", &script])
            .map_err(|e| PlatformError::Failed(format!("could not open Terminal: {e}")))
    }

    async fn push(&self, local: &Path, remote: &str) -> Result<()> {
        let tmp = self.staging();
        let to = format!("{INSTANCE}:{tmp}");
        let copied = self.lima_ok("copying the file in", &["copy", &local.to_string_lossy(), &to]).await;
        if copied.is_ok() {
            let moved = self.inside_ok("placing the file", &format!("mv -f {tmp} {} && chown root:root {}", sq(remote), sq(remote))).await;
            if moved.is_ok() {
                return Ok(());
            }
            let _ = self.inside(&["rm", "-f", &tmp]).await;
            return moved.map(|_| ());
        }
        copied
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::{Arc, Mutex};

    type Script = Box<dyn Fn(&str) -> Option<Output> + Send + Sync>;

    struct Fake {
        calls: Arc<Mutex<Vec<String>>>,
        script: Script,
    }

    fn out(code: i32, stdout: &str) -> Output {
        Output { code, stdout: stdout.into(), stderr: String::new() }
    }

    #[async_trait]
    impl Runner for Fake {
        async fn run(&self, program: &str, args: &[&str]) -> std::io::Result<Output> {
            let line = format!("{program} {}", args.join(" "));
            self.calls.lock().unwrap().push(line.clone());
            Ok((self.script)(&line).unwrap_or_else(|| out(0, "")))
        }
        async fn run_stdin(&self, program: &str, args: &[&str], input: &[u8]) -> std::io::Result<Output> {
            let line = format!("{program} {} <stdin:{}>", args.join(" "), String::from_utf8_lossy(input));
            self.calls.lock().unwrap().push(line.clone());
            Ok((self.script)(&line).unwrap_or_else(|| out(0, "")))
        }
        fn spawn_detached(&self, program: &str, args: &[&str]) -> std::io::Result<()> {
            self.calls.lock().unwrap().push(format!("SPAWN {program} {}", args.join(" ")));
            Ok(())
        }
    }

    struct NoDownload(Arc<Mutex<Vec<String>>>);

    #[async_trait]
    impl Downloader for NoDownload {
        async fn fetch(&self, label: &str, _u: &str, _d: &Path, _s: &str, _p: &ProgressFn) -> Result<()> {
            self.0.lock().unwrap().push(format!("DOWNLOAD {label}"));
            Ok(())
        }
    }

    /// A backend over a temp data folder; `lima_installed` creates the limactl file `have_lima` looks for.
    fn backend(script: Script, lima_installed: bool) -> (LimaBackend<Fake, NoDownload>, Arc<Mutex<Vec<String>>>, tempfile::TempDir) {
        let d = tempfile::tempdir().unwrap();
        if lima_installed {
            std::fs::create_dir_all(d.path().join("lima/bin")).unwrap();
            std::fs::write(d.path().join("lima/bin/limactl"), b"").unwrap();
        }
        let calls = Arc::new(Mutex::new(vec![]));
        let b = LimaBackend::for_test(Fake { calls: calls.clone(), script }, NoDownload(calls.clone()), d.path().to_path_buf());
        (b, calls, d)
    }

    fn listed(status: &str) -> Output {
        out(0, &format!("{{\"name\":\"other\",\"status\":\"Running\"}}\n{{\"name\":\"ri\",\"status\":\"{status}\"}}\n"))
    }

    #[tokio::test]
    async fn open_terminal_asks_terminal_app_for_a_root_shell_in_the_compose_folder() {
        let (b, calls, _d) = backend(Box::new(|_| None), true);
        b.open_terminal().await.unwrap();
        let last = calls.lock().unwrap().last().unwrap().clone();
        assert!(last.starts_with("SPAWN /usr/bin/osascript -e tell application \"Terminal\""), "{last}");
        assert!(last.contains("shell --workdir /opt/ri ri -- sudo -s"), "{last}");
        assert!(last.contains("LIMA_HOME="), "{last}");
    }

    fn silent() -> ProgressFn {
        Arc::new(|_| {})
    }

    fn position(calls: &[String], needle: &str) -> usize {
        calls.iter().position(|c| c.contains(needle)).unwrap_or_else(|| panic!("missing {needle}\n{calls:#?}"))
    }

    #[test]
    fn parsers() {
        assert_eq!(macos_version("26.6.2\n"), Some((26, 6)));
        assert_eq!(macos_version("13"), Some((13, 0)));
        assert_eq!(macos_version("x"), None);
        assert_eq!(free_gb("Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/disk3s5 970000000 740000000 205000000 79% /System/Volumes/Data\n"), Some(205));
        assert_eq!(instance_status(&listed("Stopped").stdout).as_deref(), Some("Stopped"));
        assert_eq!(instance_status("{\"name\":\"other\",\"status\":\"Running\"}\n"), None);
        assert_eq!(instance_status(""), None);
        assert_eq!(memory_gib_for(8 << 30), 2);
        assert_eq!(memory_gib_for(16 << 30), 4);
        assert_eq!(sq("it's"), r"'it'\''s'");
    }

    #[test]
    fn instance_yaml_isolates_the_vm() {
        let y = instance_yaml("aarch64", Path::new("/d/alpine.qcow2"), "abc", 4);
        assert!(y.contains("vmType: vz\narch: aarch64\n"));
        assert!(y.contains("memory: 4GiB\n"));
        assert!(y.contains("location: \"/d/alpine.qcow2\"\n  arch: aarch64\n  digest: \"sha256:abc\""));
        assert!(y.contains("mounts: []\n"), "no Mac folders are shared into the VM");
        assert!(y.contains("- guestPort: 18080\n  hostIP: 127.0.0.1\n"), "only the internal port, localhost only");
        assert!(y.contains("ignore: true"));
    }

    #[test]
    fn every_limactl_call_carries_our_lima_home() {
        let (b, _, d) = backend(Box::new(|_| None), true);
        let (program, argv) = b.lima_argv(&["list"]);
        assert_eq!(program, "/usr/bin/env");
        assert_eq!(argv[0], format!("LIMA_HOME={}", d.path().join("lima-home").display()));
        assert!(argv[1].ends_with("lima/bin/limactl"));
    }

    #[tokio::test]
    async fn state_follows_the_instance_and_docker() {
        let (b, _, _d) = backend(Box::new(|_| None), false);
        assert_eq!(b.state().await.unwrap(), State::NotInstalled, "no Lima at all");
        let (b, _, _d) = backend(Box::new(|c| c.contains("list --json").then(|| out(0, ""))), true);
        assert_eq!(b.state().await.unwrap(), State::NotInstalled, "Lima but no instance");
        let (b, _, _d) = backend(Box::new(|c| c.contains("list --json").then(|| listed("Stopped"))), true);
        assert_eq!(b.state().await.unwrap(), State::Stopped);
        let (b, _, _d) = backend(Box::new(|c| c.contains("list --json").then(|| listed("Running"))), true);
        assert_eq!(b.state().await.unwrap(), State::Running);
        let script: Script = Box::new(|c| {
            if c.contains("list --json") {
                Some(listed("Running"))
            } else if c.contains("docker info") {
                Some(out(1, ""))
            } else {
                None
            }
        });
        let (b, _, _d) = backend(script, true);
        assert_eq!(b.state().await.unwrap(), State::Stopped, "VM up but Docker not answering");
    }

    #[tokio::test]
    async fn full_install_runs_every_step_in_order() {
        // nothing installed: no limactl until tar runs, then no instance until create, then stopped until start
        let created = Arc::new(Mutex::new(false));
        let running = Arc::new(Mutex::new(false));
        let has_docker = Arc::new(Mutex::new(false));
        let (c2, r2, h2) = (created.clone(), running.clone(), has_docker.clone());
        let d = tempfile::tempdir().unwrap();
        let lima_bin = d.path().join("lima/bin/limactl");
        let script: Script = Box::new(move |c| {
            if c.starts_with("/usr/bin/tar") {
                std::fs::create_dir_all(lima_bin.parent().unwrap()).unwrap();
                std::fs::write(&lima_bin, b"").unwrap();
                return Some(out(0, ""));
            }
            if c.contains("limactl create") {
                *c2.lock().unwrap() = true;
            }
            if c.contains("limactl start") {
                *r2.lock().unwrap() = true;
            }
            if c.contains("apk add") {
                *h2.lock().unwrap() = true;
            }
            if c.contains("list --json") {
                return Some(match (*c2.lock().unwrap(), *r2.lock().unwrap()) {
                    (false, _) => out(0, ""),
                    (true, false) => listed("Stopped"),
                    (true, true) => listed("Running"),
                });
            }
            if c.contains("command -v dockerd") {
                return Some(out(if *h2.lock().unwrap() { 0 } else { 1 }, ""));
            }
            if c.contains("docker info") {
                return Some(out(if *h2.lock().unwrap() { 0 } else { 1 }, ""));
            }
            if c.contains("hw.memsize") {
                return Some(out(0, "8589934592\n"));
            }
            None
        });
        let calls = Arc::new(Mutex::new(vec![]));
        let b = LimaBackend::for_test(Fake { calls: calls.clone(), script }, NoDownload(calls.clone()), d.path().to_path_buf());
        assert_eq!(b.install("compose-body", silent()).await.unwrap(), InstallOutcome::Done);
        let calls = calls.lock().unwrap().clone();
        let at = |n: &str| position(&calls, n);
        assert!(at("DOWNLOAD Lima") < at("/usr/bin/tar -xzf"));
        assert!(at("/usr/bin/tar -xzf") < at("DOWNLOAD the Linux base image"));
        assert!(at("DOWNLOAD the Linux base image") < at("limactl create --name ri --tty=false"));
        assert!(at("limactl create") < at("limactl start --tty=false ri"));
        assert!(at("limactl start") < at("apk add"));
        assert!(at("apk add") < at("rc-update add docker default"));
        assert!(at("rc-update add docker default") < at("<stdin:compose-body>"));
        assert!(at("<stdin:compose-body>") < at("docker compose pull"));
        assert!(calls.iter().all(|c| !c.contains("limactl") || c.starts_with("/usr/bin/env LIMA_HOME=")), "every limactl call is isolated");
        assert!(calls.iter().filter(|c| c.contains("limactl shell")).all(|c| c.contains("shell --workdir / ri -- sudo")));
        let yaml = std::fs::read_to_string(d.path().join("ri-instance.yaml")).unwrap();
        assert!(yaml.contains("memory: 2GiB"), "an 8 GB Mac gets a 2 GiB VM");
        assert!(yaml.contains(&d.path().join("downloads/alpine-aarch64.qcow2").display().to_string()));
    }

    #[tokio::test]
    async fn rerunning_install_skips_finished_steps() {
        let (b, calls, _d) = backend(Box::new(|c| c.contains("list --json").then(|| listed("Running"))), true);
        assert_eq!(b.install("x", silent()).await.unwrap(), InstallOutcome::Done);
        let calls = calls.lock().unwrap().join("\n");
        for skipped in ["DOWNLOAD", "/usr/bin/tar", "limactl create", "limactl start", "apk add"] {
            assert!(!calls.contains(skipped), "{skipped} should be skipped\n{calls}");
        }
        assert!(calls.contains("docker compose pull"));
    }

    #[tokio::test]
    async fn install_reports_a_failed_create() {
        let script: Script = Box::new(|c| {
            if c.contains("list --json") {
                Some(out(0, ""))
            } else if c.contains("limactl create") {
                Some(Output { code: 1, stdout: String::new(), stderr: "fatal: disk full".into() })
            } else {
                None
            }
        });
        let (b, calls, _d) = backend(script, true);
        let e = b.install("x", silent()).await.unwrap_err().to_string();
        assert!(e.contains("creating the Linux layer failed: fatal: disk full"), "{e}");
        assert!(!calls.lock().unwrap().join("\n").contains("limactl start"));
    }

    #[tokio::test]
    async fn start_boots_the_vm_then_brings_the_stack_up() {
        let running = Arc::new(Mutex::new(false));
        let r2 = running.clone();
        let script: Script = Box::new(move |c| {
            if c.contains("limactl start") {
                *r2.lock().unwrap() = true;
            }
            if c.contains("list --json") {
                return Some(listed(if *r2.lock().unwrap() { "Running" } else { "Stopped" }));
            }
            None
        });
        let (b, calls, _d) = backend(script, true);
        b.start("compose-body").await.unwrap();
        let calls = calls.lock().unwrap().clone();
        let at = |n: &str| position(&calls, n);
        assert!(at("limactl start --tty=false ri") < at("<stdin:compose-body>"));
        assert!(at("<stdin:compose-body>") < at("docker compose up -d"));
    }

    #[tokio::test]
    async fn start_waits_for_docker() {
        let up = Arc::new(Mutex::new(false));
        let u2 = up.clone();
        let script: Script = Box::new(move |c| {
            if c.contains("list --json") {
                Some(listed("Running"))
            } else if c.contains("rc-service docker start") {
                *u2.lock().unwrap() = true;
                Some(out(0, ""))
            } else if c.contains("docker info") {
                Some(out(if *u2.lock().unwrap() { 0 } else { 1 }, ""))
            } else {
                None
            }
        });
        let (b, calls, _d) = backend(script, true);
        b.start("x").await.unwrap();
        let calls = calls.lock().unwrap().clone();
        assert!(!calls.iter().any(|c| c.contains("limactl start")), "a running VM is not restarted");
        assert!(position(&calls, "rc-service docker start") < position(&calls, "docker compose up -d"));

        let script: Script = Box::new(|c| {
            if c.contains("list --json") {
                Some(listed("Running"))
            } else if c.contains("docker info") {
                Some(out(1, ""))
            } else {
                None
            }
        });
        let (b, _, _d) = backend(script, true);
        assert!(b.start("x").await.unwrap_err().to_string().contains("Docker did not start"));
    }

    #[tokio::test]
    async fn start_refuses_when_not_installed() {
        let (b, _, _d) = backend(Box::new(|_| None), false);
        assert!(b.start("x").await.unwrap_err().to_string().contains("not installed"));
    }

    #[tokio::test]
    async fn stop_is_graceful_then_shuts_the_vm_down() {
        let (b, calls, _d) = backend(Box::new(|c| c.contains("list --json").then(|| listed("Running"))), true);
        b.stop().await.unwrap();
        let calls = calls.lock().unwrap().clone();
        assert!(position(&calls, "docker compose stop") < position(&calls, "limactl stop ri"));
        // already stopped, or never installed: nothing to do
        let (b, calls, _d) = backend(Box::new(|c| c.contains("list --json").then(|| listed("Stopped"))), true);
        b.stop().await.unwrap();
        assert!(!calls.lock().unwrap().join("\n").contains("limactl stop"));
        let (b, _, _d) = backend(Box::new(|_| None), false);
        b.stop().await.unwrap();
    }

    #[tokio::test]
    async fn uninstall_keep_data_leaves_the_vm_and_lima() {
        let (b, calls, d) = backend(Box::new(|c| c.contains("list --json").then(|| listed("Stopped"))), true);
        b.uninstall(true).await.unwrap();
        assert!(!calls.lock().unwrap().join("\n").contains("delete"));
        assert!(d.path().join("lima/bin/limactl").exists());

        let (b, calls, d) = backend(Box::new(|c| c.contains("list --json").then(|| listed("Stopped"))), true);
        std::fs::create_dir_all(d.path().join("lima-home/ri")).unwrap();
        std::fs::create_dir_all(d.path().join("downloads")).unwrap();
        b.uninstall(false).await.unwrap();
        assert!(calls.lock().unwrap().join("\n").contains("limactl delete --force ri"));
        for gone in ["lima-home", "lima", "downloads"] {
            assert!(!d.path().join(gone).exists(), "{gone} should be removed");
        }
    }

    #[tokio::test]
    async fn pull_and_push_stage_through_tmp_as_root() {
        let (b, calls, _d) = backend(Box::new(|_| None), true);
        b.pull("/opt/ri/backups/a b.zip", Path::new("/Users/x/Documents/a b.zip")).await.unwrap();
        let c = calls.lock().unwrap().clone();
        let tmp = c[0].split_whitespace().find(|w| w.starts_with("/tmp/ri-xfer-")).unwrap().to_string();
        assert!(c[0].contains(&format!("sudo sh -c cp -- '/opt/ri/backups/a b.zip' {tmp} && chmod 644 {tmp}")), "{c:#?}");
        assert!(c[1].ends_with(&format!("limactl copy ri:{tmp} /Users/x/Documents/a b.zip")), "{c:#?}");
        assert!(c[2].ends_with(&format!("sudo rm -f {tmp}")));

        let (b, calls, _d) = backend(Box::new(|_| None), true);
        b.push(Path::new("/Users/x/in.zip"), "/opt/ri/backups/imported.zip").await.unwrap();
        let c = calls.lock().unwrap().clone();
        assert!(c[0].contains("limactl copy /Users/x/in.zip ri:/tmp/ri-xfer-"));
        assert!(c[1].contains("mv -f /tmp/ri-xfer-") && c[1].contains("'/opt/ri/backups/imported.zip' && chown root:root"));
    }

    #[tokio::test]
    async fn failed_pull_still_cleans_up() {
        let (b, calls, _d) = backend(Box::new(|c| c.contains("limactl copy").then(|| out(23, "rsync failed"))), true);
        assert!(b.pull("/opt/ri/backups/x.zip", Path::new("/tmp/x.zip")).await.is_err());
        assert!(calls.lock().unwrap().last().unwrap().contains("rm -f /tmp/ri-xfer-"));
    }

    #[tokio::test]
    async fn preflight_blocks_old_macos_no_virtualization_and_full_disk() {
        let ok_df = "Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/x 900000000 1 500000000 1% /\n";
        let mk = |ver: &'static str, hv: &'static str, df: &'static str| -> Script {
            Box::new(move |c| {
                if c.contains("sw_vers") {
                    Some(out(0, ver))
                } else if c.contains("kern.hv_support") {
                    Some(out(0, hv))
                } else if c.starts_with("/bin/df") {
                    Some(out(0, df))
                } else {
                    None
                }
            })
        };
        let (b, _, _d) = backend(mk("13.2.1\n", "1\n", ok_df), false);
        assert!(matches!(b.preflight().await.unwrap(), Preflight::Blocked(m) if m.contains("too old")));
        let (b, _, _d) = backend(mk("14.0\n", "0\n", ok_df), false);
        assert!(matches!(b.preflight().await.unwrap(), Preflight::Blocked(m) if m.contains("virtual machines")));
        let (b, _, _d) = backend(mk("15.1\n", "1\n", "Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/x 900000000 1 4000000 99% /\n"), false);
        assert!(matches!(b.preflight().await.unwrap(), Preflight::Blocked(m) if m.contains("4 GB free")));
        let (b, _, _d) = backend(mk("26.6.2\n", "1\n", ok_df), false);
        assert_eq!(b.preflight().await.unwrap(), Preflight::Ok);
    }
}
