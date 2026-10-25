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
