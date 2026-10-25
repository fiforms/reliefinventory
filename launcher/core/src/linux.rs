//! Linux backend: there is no Linux layer to build, because the computer *is* Linux. It uses the Docker the
//! person already has (or is asked to install with their system's package manager — the launcher never
//! runs `sudo`) and drives the same compose stack with `docker compose`. Nothing needs administrator rights
//! except installing Docker itself, so there is no restart step and no elevated pass.
//!
//! Two translations keep the shared code (`backups`, `service`) unaware of the difference:
//! - `backups` names the stack folder `/opt/ri` (its path inside the Windows layer); `exec` rewrites that to
//!   this backend's real folder on the way in and back again on the way out.
//! - Files in the stack's `backups/` folder belong to the app's container user, not to whoever runs the
//!   launcher, so `pull`/`push` go through `docker compose cp` instead of reading the folder directly.
use crate::platform::{fail, InstallOutcome, Platform, Preflight, Progress, ProgressFn, Result, State};
use crate::runner::{Output, Runner};
use crate::wsl::REMOTE_DIR;
use async_trait::async_trait;
use std::path::{Path, PathBuf};

/// Compose project name: keeps our containers and volumes apart from any other stack on this Docker. Never the
/// default (the folder name, e.g. `reliefinventory`): a developer's own stack may already own that name, and
/// `down --volumes` on a shared name would delete *their* data.
const PROJECT: &str = "ri-launcher";

pub struct LinuxBackend<R: Runner> {
    runner: R,
    stack: PathBuf,
}

impl<R: Runner> LinuxBackend<R> {
    /// `data_dir` is the launcher's own folder (`~/.local/share/ReliefInventory`); the stack lives in `stack/`.
    pub fn new(runner: R, data_dir: PathBuf) -> Self {
        LinuxBackend { runner, stack: data_dir.join("stack") }
    }

    fn compose_file(&self) -> PathBuf {
        self.stack.join("compose.yaml")
    }

    /// Written last by `install`; "installed" means the images are in place, not merely that a file exists.
    fn marker(&self) -> PathBuf {
        self.stack.join(".installed")
    }

    async fn docker(&self, args: &[&str]) -> Result<Output> {
        self.runner
            .run("docker", args)
            .await
            .map_err(|e| crate::platform::PlatformError::Failed(format!("could not run docker: {e}")))
    }

    async fn compose(&self, args: &[&str]) -> Result<Output> {
        let (file, dir) = (self.compose_file(), self.stack.clone());
        let mut full: Vec<&str> = vec!["compose", "--project-name", PROJECT, "--project-directory"];
        let dir = dir.to_string_lossy().into_owned();
        let file = file.to_string_lossy().into_owned();
        full.extend([dir.as_str(), "-f", file.as_str()]);
        full.extend(args);
        self.docker(&full).await
    }

    async fn compose_ok(&self, what: &str, args: &[&str]) -> Result<String> {
        let out = self.compose(args).await?;
        if out.ok() {
            Ok(out.stdout)
        } else {
            fail(format!("{what} failed: {}", tail(&out)))
        }
    }

    async fn write_compose(&self, compose: &str) -> Result<()> {
        let (dir, file) = (self.stack.clone(), self.compose_file());
        let text = compose.to_string();
        tokio::task::spawn_blocking(move || {
            std::fs::create_dir_all(&dir)?;
            // atomic, so a crash can't leave half a compose file for the next start
            let tmp = file.with_extension("yaml.tmp");
            std::fs::write(&tmp, text)?;
            std::fs::rename(&tmp, &file)
        })
        .await
        .map_err(|e| crate::platform::PlatformError::Failed(e.to_string()))?
        .map_err(|e| crate::platform::PlatformError::Failed(format!("could not write the compose file: {e}")))
    }

    /// `/opt/ri/backups/x.zip` (the shared name) → `/backups/x.zip` (inside the app's container).
    fn container_path(remote: &str) -> Option<String> {
        let rel = remote.strip_prefix(&format!("{REMOTE_DIR}/backups/"))?;
        (!rel.is_empty() && !rel.split('/').any(|p| p == "..")).then(|| format!("/backups/{rel}"))
    }

    fn to_local(&self, s: &str) -> String {
        s.replace(REMOTE_DIR, &shell_quote(&self.stack.to_string_lossy()))
    }

    fn to_shared(&self, s: &str) -> String {
        s.replace(&*self.stack.to_string_lossy(), REMOTE_DIR)
    }
}

fn shell_quote(s: &str) -> String {
    format!("'{}'", s.replace('\'', "'\\''"))
}

fn tail(out: &Output) -> String {
    let text = if out.stderr.trim().is_empty() { &out.stdout } else { &out.stderr };
    let lines: Vec<&str> = text.lines().filter(|l| !l.trim().is_empty()).collect();
    lines[lines.len().saturating_sub(6)..].join("\n")
}

/// What to tell someone whose Docker is missing, for their distribution. Reads `/etc/os-release`.
pub fn install_hint(os_release: &str) -> String {
    let field = |k: &str| {
        os_release
            .lines()
            .find_map(|l| l.strip_prefix(&format!("{k}=")))
            .map(|v| v.trim().trim_matches('"').to_ascii_lowercase())
            .unwrap_or_default()
    };
    let (id, like) = (field("ID"), field("ID_LIKE"));
    let is = |names: &[&str]| names.iter().any(|n| id == *n || like.split_whitespace().any(|l| l == *n));
    let cmd = if is(&["debian", "ubuntu"]) {
        Some("sudo apt install docker.io docker-compose-v2")
    } else if is(&["fedora", "rhel", "centos"]) {
        Some("sudo dnf install moby-engine docker-compose")
    } else if is(&["arch"]) {
        Some("sudo pacman -S docker docker-compose")
    } else if is(&["suse", "opensuse"]) {
        Some("sudo zypper install docker docker-compose")
    } else {
        None
    };
    match cmd {
        Some(c) => format!(
            "In a terminal, run:  {c}\nthen:  sudo systemctl enable --now docker  and  sudo usermod -aG docker $USER\nand log out and back in. Then press Check again."
        ),
        None => "Install Docker Engine and its Compose plugin with your system's package manager (see https://docs.docker.com/engine/install/), start it, and add your user to the docker group. Then press Check again.".into(),
    }
}

#[async_trait]
impl<R: Runner> Platform for LinuxBackend<R> {
    async fn preflight(&self) -> Result<Preflight> {
        let hint = || install_hint(&std::fs::read_to_string("/etc/os-release").unwrap_or_default());
        let version = match self.runner.run("docker", &["--version"]).await {
            Ok(o) if o.ok() => o,
            _ => return Ok(Preflight::Blocked(format!("Docker is not installed on this computer.\n{}", hint()))),
        };
        let _ = version;
        match self.docker(&["compose", "version"]).await {
            Ok(o) if o.ok() => {}
            _ => {
                return Ok(Preflight::Blocked(format!(
                    "Docker is installed, but its Compose plugin is missing.\n{}",
                    hint()
                )))
            }
        }
        let info = self.docker(&["info", "--format", "{{.ServerVersion}}"]).await?;
        if info.ok() {
            return Ok(Preflight::Ok);
        }
        let why = format!("{} {}", info.stderr, info.stdout).to_ascii_lowercase();
        Ok(Preflight::Blocked(if why.contains("permission denied") {
            "Docker is running, but your user is not allowed to use it.\nIn a terminal run:  sudo usermod -aG docker $USER  then log out and back in. Then press Check again.".into()
        } else if why.contains("cannot connect") || why.contains("is the docker daemon running") {
            "Docker is installed but not running.\nIn a terminal run:  sudo systemctl enable --now docker  Then press Check again.".into()
        } else {
            format!("Docker did not answer: {}", tail(&info))
        }))
    }

    async fn install(&self, compose: &str, progress: ProgressFn) -> Result<InstallOutcome> {
        progress(Progress::step("Preparing", "Writing the Relief Inventory configuration"));
        self.write_compose(compose).await?;
        progress(Progress::step(
            "Downloading Relief Inventory",
            "This downloads roughly 1 GB the first time. Docker shows no percentage, so the bar just keeps moving.",
        ));
        self.compose_ok("downloading Relief Inventory", &["pull"]).await?;
        let marker = self.marker();
        tokio::fs::write(&marker, b"installed\n")
            .await
            .map_err(|e| crate::platform::PlatformError::Failed(format!("could not record the install: {e}")))?;
        Ok(InstallOutcome::Done)
    }

    async fn state(&self) -> Result<State> {
        if !self.marker().exists() {
            return Ok(State::NotInstalled);
        }
        // Docker being down, or anything else going wrong, simply reads as "not running".
        match self.compose(&["ps", "--status", "running", "--services"]).await {
            Ok(o) if o.ok() && o.stdout.lines().any(|l| l.trim() == "app") => Ok(State::Running),
            _ => Ok(State::Stopped),
        }
    }

    async fn start(&self, compose: &str) -> Result<()> {
        if !self.marker().exists() {
            return fail("Relief Inventory is not installed yet.");
        }
        if let Preflight::Blocked(why) = self.preflight().await? {
            return fail(why);
        }
        self.write_compose(compose).await?;
        self.compose_ok("starting Relief Inventory", &["up", "-d", "--remove-orphans"]).await?;
        Ok(())
    }

    async fn stop(&self) -> Result<()> {
        if !self.compose_file().exists() {
            return Ok(());
        }
        // Docker not running means nothing of ours is either.
        let out = self.compose(&["stop"]).await;
        match out {
            Ok(o) if !o.ok() && !tail(&o).to_ascii_lowercase().contains("cannot connect") => {
                fail(format!("stopping Relief Inventory failed: {}", tail(&o)))
            }
            _ => Ok(()),
        }
    }

    async fn update(&self, compose: &str) -> Result<()> {
        if let Preflight::Blocked(why) = self.preflight().await? {
            return fail(why);
        }
        self.write_compose(compose).await?;
        self.compose_ok("downloading the update", &["pull"]).await?;
        self.compose_ok("restarting with the update", &["up", "-d", "--remove-orphans"]).await?;
        Ok(())
    }

    async fn uninstall(&self, keep_data: bool) -> Result<()> {
        self.stop().await?;
        if self.compose_file().exists() {
            if !keep_data {
                // The backups folder belongs to the app's container user, so empty it from inside one.
                let _ = self
                    .compose(&["run", "--rm", "--no-deps", "--entrypoint", "sh", "app", "-c", "rm -rf /backups/* /backups/.[!.]*"])
                    .await;
            }
            let mut down = vec!["down", "--remove-orphans"];
            if !keep_data {
                down.push("--volumes");
            }
            let out = self.compose(&down).await?;
            let why = tail(&out).to_ascii_lowercase();
            if !out.ok() && !why.contains("cannot connect") {
                return fail(format!("removing Relief Inventory's containers failed: {}", tail(&out)));
            }
        }
        if keep_data {
            let _ = tokio::fs::remove_file(self.marker()).await; // reinstalling finds the data volumes again
            return Ok(());
        }
        // The downloaded images stay in Docker's cache on purpose: Docker is shared with whatever else this
        // person runs (another checkout may use the very same tag), so we never remove an image.
        match tokio::fs::remove_dir_all(&self.stack).await {
            Ok(()) => Ok(()),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
            Err(e) => fail(format!("Relief Inventory was removed, but {} could not be deleted ({e}). You can delete it yourself.", self.stack.display())),
        }
    }

    async fn exec(&self, args: &[&str]) -> Result<String> {
        let Some((program, rest)) = args.split_first() else { return fail("nothing to run") };
        let rest: Vec<String> = rest.iter().map(|a| self.to_local(a)).collect();
        let rest: Vec<&str> = rest.iter().map(String::as_str).collect();
        let out = self
            .runner
            .run(program, &rest)
            .await
            .map_err(|e| crate::platform::PlatformError::Failed(format!("could not run {program}: {e}")))?;
        if out.ok() {
            Ok(self.to_shared(&out.stdout))
        } else {
            fail(tail(&out))
        }
    }

    fn host_path(&self, remote: &str) -> PathBuf {
        match remote.strip_prefix(REMOTE_DIR) {
            Some(rest) => self.stack.join(rest.trim_start_matches('/')),
            None => PathBuf::from(remote),
        }
    }

    async fn pull(&self, remote: &str, local: &Path) -> Result<()> {
        let Some(inside) = Self::container_path(remote) else { return fail("that is not a backup file") };
        let from = format!("app:{inside}");
        self.compose_ok("copying the backup out", &["cp", &from, &local.to_string_lossy()]).await.map(|_| ())
    }

    async fn push(&self, local: &Path, remote: &str) -> Result<()> {
        let Some(inside) = Self::container_path(remote) else { return fail("that is not a backup folder") };
        let to = format!("app:{inside}");
        self.compose_ok("copying the file in", &["cp", &local.to_string_lossy(), &to]).await?;
        // `docker cp` keeps the file's owner and mode from this computer, which can leave it unreadable to the
        // app's own user (a read-only export, say), so make it readable from inside.
        self.compose_ok("preparing the file", &["exec", "-T", "-u", "root", "app", "chmod", "644", &inside]).await.map(|_| ())
    }

    fn stack_options(&self) -> crate::compose::StackOptions {
        crate::compose::StackOptions { loopback_only: true, project_name: Some(PROJECT.into()) }
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

    fn out(code: i32, stdout: &str, stderr: &str) -> Output {
        Output { code, stdout: stdout.into(), stderr: stderr.into() }
    }

    #[async_trait]
    impl Runner for Fake {
        async fn run(&self, program: &str, args: &[&str]) -> std::io::Result<Output> {
            let line = format!("{program} {}", args.join(" "));
            self.calls.lock().unwrap().push(line.clone());
            Ok((self.script)(&line).unwrap_or_else(|| out(0, "", "")))
        }
        async fn run_stdin(&self, _p: &str, _a: &[&str], _i: &[u8]) -> std::io::Result<Output> {
            unreachable!("the Linux backend writes files directly")
        }
        fn spawn_detached(&self, _p: &str, _a: &[&str]) -> std::io::Result<()> {
            unreachable!("nothing needs a keep-alive on Linux")
        }
    }

    fn backend(script: Script) -> (LinuxBackend<Fake>, Arc<Mutex<Vec<String>>>, tempfile::TempDir) {
        let d = tempfile::tempdir().unwrap();
        let calls = Arc::new(Mutex::new(vec![]));
        (LinuxBackend::new(Fake { calls: calls.clone(), script }, d.path().to_path_buf()), calls, d)
    }

    fn silent() -> ProgressFn {
        Arc::new(|_| {})
    }

    fn blocked(p: Preflight) -> String {
        match p {
            Preflight::Blocked(m) => m,
            Preflight::Ok => panic!("expected Blocked"),
        }
    }

    #[test]
    fn hints_follow_the_distribution() {
        assert!(install_hint("ID=ubuntu\nID_LIKE=debian\n").contains("apt install docker.io docker-compose-v2"));
        assert!(install_hint("ID=linuxmint\nID_LIKE=\"ubuntu debian\"\n").contains("apt install"));
        assert!(install_hint("ID=fedora\n").contains("dnf install"));
        assert!(install_hint("ID=manjaro\nID_LIKE=arch\n").contains("pacman -S docker"));
        assert!(install_hint("ID=plan9\n").contains("docs.docker.com"));
        assert!(install_hint("").contains("docs.docker.com"));
    }

    #[tokio::test]
    async fn preflight_says_what_is_wrong() {
        let (b, _, _d) = backend(Box::new(|c| c.starts_with("docker --version").then(|| out(127, "", "not found"))));
        assert!(blocked(b.preflight().await.unwrap()).contains("not installed"));

        let (b, _, _d) = backend(Box::new(|c| c.starts_with("docker compose version").then(|| out(1, "", "unknown command"))));
        assert!(blocked(b.preflight().await.unwrap()).contains("Compose plugin"));

        let (b, _, _d) = backend(Box::new(|c| {
            c.starts_with("docker info").then(|| out(1, "", "permission denied while trying to connect to the Docker daemon socket"))
        }));
        assert!(blocked(b.preflight().await.unwrap()).contains("usermod -aG docker"));

        let (b, _, _d) = backend(Box::new(|c| {
            c.starts_with("docker info").then(|| out(1, "", "Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?"))
        }));
        assert!(blocked(b.preflight().await.unwrap()).contains("systemctl enable --now docker"));

        let (b, _, _d) = backend(Box::new(|_| None));
        assert_eq!(b.preflight().await.unwrap(), Preflight::Ok);
    }

    #[tokio::test]
    async fn install_writes_the_file_pulls_then_marks_installed() {
        let (b, calls, d) = backend(Box::new(|_| None));
        assert_eq!(b.state().await.unwrap(), State::NotInstalled);
        assert_eq!(b.install("services: {}\n", silent()).await.unwrap(), InstallOutcome::Done);
        assert_eq!(std::fs::read_to_string(d.path().join("stack/compose.yaml")).unwrap(), "services: {}\n");
        let calls = calls.lock().unwrap();
        assert_eq!(calls.len(), 1);
        assert!(calls[0].contains("--project-name ri-launcher") && calls[0].ends_with(" pull"));
        assert!(d.path().join("stack/.installed").exists());
    }

    #[tokio::test]
    async fn a_failed_pull_leaves_it_not_installed_and_can_be_retried() {
        let (b, _, _d) = backend(Box::new(|c| c.ends_with(" pull").then(|| out(1, "", "toomanyrequests\nno route to host"))));
        let err = b.install("x", silent()).await.unwrap_err().to_string();
        assert!(err.contains("no route to host"));
        assert_eq!(b.state().await.unwrap(), State::NotInstalled);
    }

    #[tokio::test]
    async fn state_reads_running_services() {
        let (b, _, d) = backend(Box::new(|c| c.contains(" ps ").then(|| out(0, "db\napp\nqueue\n", ""))));
        std::fs::create_dir_all(d.path().join("stack")).unwrap();
        std::fs::write(d.path().join("stack/.installed"), "").unwrap();
        assert_eq!(b.state().await.unwrap(), State::Running);

        let (b, _, d) = backend(Box::new(|c| c.contains(" ps ").then(|| out(0, "db\n", ""))));
        std::fs::create_dir_all(d.path().join("stack")).unwrap();
        std::fs::write(d.path().join("stack/.installed"), "").unwrap();
        assert_eq!(b.state().await.unwrap(), State::Stopped);

        // Docker down: not an error, just not running
        let (b, _, d) = backend(Box::new(|c| c.contains(" ps ").then(|| out(1, "", "Cannot connect"))));
        std::fs::create_dir_all(d.path().join("stack")).unwrap();
        std::fs::write(d.path().join("stack/.installed"), "").unwrap();
        assert_eq!(b.state().await.unwrap(), State::Stopped);
    }

    #[tokio::test]
    async fn start_checks_docker_then_rewrites_compose_and_brings_it_up() {
        let (b, calls, d) = backend(Box::new(|_| None));
        assert!(b.start("x").await.is_err()); // not installed
        b.install("old", silent()).await.unwrap();
        calls.lock().unwrap().clear();
        b.start("new").await.unwrap();
        assert_eq!(std::fs::read_to_string(d.path().join("stack/compose.yaml")).unwrap(), "new");
        let calls = calls.lock().unwrap();
        assert!(calls.iter().position(|c| c.starts_with("docker info")).unwrap() < calls.iter().position(|c| c.ends_with("up -d --remove-orphans")).unwrap());
    }

    #[tokio::test]
    async fn start_refuses_when_docker_is_down() {
        let (b, calls, _d) = backend(Box::new(|c| c.starts_with("docker info").then(|| out(1, "", "Cannot connect to the Docker daemon"))));
        b.install("x", silent()).await.unwrap();
        let err = b.start("x").await.unwrap_err().to_string();
        assert!(err.contains("not running"));
        assert!(!calls.lock().unwrap().iter().any(|c| c.contains(" up ")));
    }

    #[tokio::test]
    async fn stop_is_quiet_when_docker_is_gone() {
        let (b, _, d) = backend(Box::new(|c| c.ends_with(" stop").then(|| out(1, "", "Cannot connect to the Docker daemon"))));
        b.stop().await.unwrap(); // nothing installed
        std::fs::create_dir_all(d.path().join("stack")).unwrap();
        std::fs::write(d.path().join("stack/compose.yaml"), "x").unwrap();
        b.stop().await.unwrap();
    }

    #[tokio::test]
    async fn update_pulls_then_recreates() {
        let (b, calls, _d) = backend(Box::new(|_| None));
        b.update("c").await.unwrap();
        let calls = calls.lock().unwrap();
        let pull = calls.iter().position(|c| c.ends_with(" pull")).unwrap();
        let up = calls.iter().position(|c| c.ends_with("up -d --remove-orphans")).unwrap();
        assert!(pull < up);
    }

    #[tokio::test]
    async fn uninstall_keep_data_keeps_the_volumes_and_the_folder() {
        let (b, calls, d) = backend(Box::new(|_| None));
        b.install("c", silent()).await.unwrap();
        calls.lock().unwrap().clear();
        b.uninstall(true).await.unwrap();
        let calls = calls.lock().unwrap();
        assert!(calls.iter().any(|c| c.contains(" down ")) && !calls.iter().any(|c| c.contains("--volumes")));
        assert!(d.path().join("stack/compose.yaml").exists());
        assert!(!d.path().join("stack/.installed").exists());
        assert_eq!(b.state().await.unwrap(), State::NotInstalled);
    }

    #[tokio::test]
    async fn uninstall_everything_drops_volumes_and_folder_but_never_an_image() {
        let (b, calls, d) = backend(Box::new(|_| None));
        b.install("c", silent()).await.unwrap();
        calls.lock().unwrap().clear();
        b.uninstall(false).await.unwrap();
        let calls = calls.lock().unwrap();
        let at = |n: &str| calls.iter().position(|c| c.contains(n)).unwrap();
        assert!(at("rm -rf /backups") < at("down --remove-orphans --volumes"));
        assert!(!calls.iter().any(|c| c.contains("image rm") || c.contains("--rmi")));
        assert!(!d.path().join("stack").exists());
    }

    #[tokio::test]
    async fn exec_translates_the_shared_stack_path_both_ways() {
        let d = tempfile::tempdir().unwrap();
        let stack = d.path().join("stack");
        let reply = format!("{}/backups/a.zip|10|100\n", stack.display());
        let calls = Arc::new(Mutex::new(vec![]));
        let b = LinuxBackend::new(Fake { calls: calls.clone(), script: Box::new(move |_| Some(out(0, &reply, ""))) }, d.path().to_path_buf());
        let got = b.exec(&["sh", "-c", "find /opt/ri/backups -name '*.zip'"]).await.unwrap();
        assert_eq!(got, "/opt/ri/backups/a.zip|10|100\n");
        let sent = calls.lock().unwrap().last().unwrap().clone();
        assert!(sent.contains(&format!("find '{}'/backups", stack.display())), "{sent}");
    }

    #[tokio::test]
    async fn pull_and_push_use_compose_cp_and_refuse_other_paths() {
        let (b, calls, _d) = backend(Box::new(|_| None));
        b.pull("/opt/ri/backups/x.zip", Path::new("/tmp/out.zip")).await.unwrap();
        b.push(Path::new("/tmp/in.zip"), "/opt/ri/backups/imported.zip").await.unwrap();
        let calls = calls.lock().unwrap().clone();
        assert!(calls[0].ends_with("cp app:/backups/x.zip /tmp/out.zip"));
        assert!(calls[1].ends_with("cp /tmp/in.zip app:/backups/imported.zip"));
        assert!(calls[2].ends_with("exec -T -u root app chmod 644 /backups/imported.zip"));
        assert!(b.pull("/etc/passwd", Path::new("/tmp/o")).await.is_err());
        assert!(b.pull("/opt/ri/backups/../x", Path::new("/tmp/o")).await.is_err());
        assert!(b.push(Path::new("/tmp/i"), "/opt/ri/other").await.is_err());
    }

    #[test]
    fn the_stack_is_private_and_loopback_only() {
        let (b, _, _d) = backend(Box::new(|_| None));
        let o = b.stack_options();
        assert!(o.loopback_only);
        assert_eq!(o.project_name.as_deref(), Some(PROJECT));
    }
}
