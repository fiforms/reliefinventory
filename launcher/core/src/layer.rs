//! What runs *inside* the Linux layer, shared by every backend (WSL on Windows, Lima on macOS) so the app is
//! installed and laid out the same way everywhere: Alpine, Docker Engine from `apk`, and the compose
//! project in `REMOTE_DIR` with the app's own backups under `REMOTE_DIR/backups`.
use crate::runner::Output;

pub const REMOTE_DIR: &str = "/opt/ri";

/// Docker Engine and compose from Alpine's own packages (no Docker Desktop, no get.docker.com script).
pub const INSTALL_DOCKER: &str = "apk add --no-cache docker docker-cli-compose iptables ip6tables 2>&1";

/// Test for an installed Docker, so a re-run install skips the `apk add`.
pub const HAS_DOCKER: &str = "command -v dockerd";

pub fn make_dirs() -> String {
    format!("mkdir -p {REMOTE_DIR}/backups")
}

pub fn compose_path() -> String {
    format!("{REMOTE_DIR}/compose.yaml")
}

/// Shell that writes its stdin to `path` atomically (a half-written compose file is never used).
pub fn write_stdin_to(path: &str) -> String {
    format!("cat > {path}.new && mv {path}.new {path}")
}

/// `docker compose <sub>` in the project folder, stderr folded in so failures are explained.
pub fn compose(sub: &str) -> String {
    format!("cd {REMOTE_DIR} && docker compose {sub} 2>&1")
}

/// The typed shortcuts for the terminal window: `artisan …` and `mysql …`/`mariadb …` run inside the right
/// container. `preamble` is shell that runs first and must leave the current folder at the compose project
/// (plus anything else the compose commands need). Without a terminal on stdin (`mysql < dump.sql`) `-T` keeps
/// docker from demanding one.
pub fn shortcuts(preamble: &str) -> Vec<(&'static str, String)> {
    let head = format!("#!/bin/sh\n{preamble}\nT=; [ -t 0 ] || T=-T\n");
    let artisan = format!("{head}exec docker compose exec $T app ri-entrypoint artisan \"$@\"\n");
    // The password is read inside the container, so it never appears on a command line out here.
    let mysql = format!(
        "{head}exec docker compose exec $T db sh -c 'exec mariadb -uroot -p\"$(cat /admin-secrets/db_root_password)\" \"$@\"' sh \"$@\"\n"
    );
    vec![("artisan", artisan.clone()), ("mysql", mysql.clone()), ("mariadb", mysql)]
}

/// A shell script (fed to `sh` on stdin) that writes `shortcuts(preamble)` into `dir`, executable.
pub fn install_shortcuts_script(dir: &str, preamble: &str) -> String {
    let mut s = format!("set -e\nmkdir -p {dir}\n");
    for (name, body) in shortcuts(preamble) {
        s.push_str(&format!("cat > {dir}/{name} <<'RI_SHORTCUT_EOF'\n{body}RI_SHORTCUT_EOF\nchmod 755 {dir}/{name}\n"));
    }
    s
}

/// Where the layer's shortcuts go (on every login shell's PATH; the layer has no other `mysql`).
pub const SHORTCUT_DIR: &str = "/usr/local/bin";
/// What a shortcut does first inside the layer.
pub fn layer_preamble() -> String {
    format!("cd {REMOTE_DIR} || exit 1")
}

/// The last few lines of a failed command's output, for an error message a person can act on.
pub fn tail(out: &Output) -> String {
    let text = if out.stderr.trim().is_empty() { &out.stdout } else { &out.stderr };
    let lines: Vec<&str> = text.lines().filter(|l| !l.trim().is_empty()).collect();
    let start = lines.len().saturating_sub(6);
    format!("{} (exit {})", lines[start..].join(" / "), out.code)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    #[cfg(unix)]
    fn shortcuts_run_the_right_docker_compose_command() {
        use std::process::{Command, Stdio};
        let dir = tempfile::tempdir().unwrap();
        let bin = dir.path().join("bin");
        let fake = dir.path().join("fakebin");
        std::fs::create_dir_all(&fake).unwrap();
        std::fs::write(fake.join("docker"), "#!/bin/sh\necho \"docker $*\"\n").unwrap();
        Command::new("chmod").arg("755").arg(fake.join("docker")).status().unwrap();
        let script = install_shortcuts_script(&bin.to_string_lossy(), "cd /");
        let mut sh = Command::new("sh").stdin(Stdio::piped()).spawn().unwrap();
        std::io::Write::write_all(sh.stdin.as_mut().unwrap(), script.as_bytes()).unwrap();
        assert!(sh.wait().unwrap().success());
        let path = format!("{}:{}:{}", bin.display(), fake.display(), std::env::var("PATH").unwrap());
        let run = |name: &str, args: &[&str]| {
            let out = Command::new(bin.join(name)).args(args).env("PATH", &path).stdin(Stdio::null()).output().unwrap();
            String::from_utf8(out.stdout).unwrap()
        };
        assert_eq!(run("artisan", &["user:promote", "--email=a@b.c"]), "docker compose exec -T app ri-entrypoint artisan user:promote --email=a@b.c\n");
        let my = run("mysql", &["-e", "show databases"]);
        assert!(my.starts_with("docker compose exec -T db sh -c exec mariadb -uroot -p\"$(cat /admin-secrets/db_root_password)\" \"$@\" sh -e show databases"), "{my}");
        assert!(bin.join("mariadb").exists());
    }

    #[test]
    fn tail_keeps_the_last_lines_and_the_exit_code() {
        let out = Output { code: 3, stdout: (1..=9).map(|n| n.to_string()).collect::<Vec<_>>().join("\n"), stderr: String::new() };
        assert_eq!(tail(&out), "4 / 5 / 6 / 7 / 8 / 9 (exit 3)");
        let out = Output { code: 1, stdout: "ignored".into(), stderr: "boom\n".into() };
        assert_eq!(tail(&out), "boom (exit 1)");
    }

    #[test]
    fn compose_runs_in_the_project_folder() {
        assert_eq!(compose("up -d"), "cd /opt/ri && docker compose up -d 2>&1");
        assert_eq!(write_stdin_to("/opt/ri/compose.yaml"), "cat > /opt/ri/compose.yaml.new && mv /opt/ri/compose.yaml.new /opt/ri/compose.yaml");
    }
}
