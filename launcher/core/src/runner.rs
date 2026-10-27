use async_trait::async_trait;

#[derive(Debug, Clone, Default)]
pub struct Output {
    pub code: i32,
    pub stdout: String,
    pub stderr: String,
}

impl Output {
    pub fn ok(&self) -> bool {
        self.code == 0
    }
}

/// Runs OS programs. A trait so the Windows backend's command sequence can be tested on any machine.
#[async_trait]
pub trait Runner: Send + Sync {
    async fn run(&self, program: &str, args: &[&str]) -> std::io::Result<Output>;
    /// Like `run`, feeding `input` to the program's stdin (writing a file inside the Linux layer).
    async fn run_stdin(&self, program: &str, args: &[&str], input: &[u8]) -> std::io::Result<Output>;
    /// Start a long-lived program and return at once (the keep-alive `wsl.exe … dockerd`).
    fn spawn_detached(&self, program: &str, args: &[&str]) -> std::io::Result<()>;
}

/// `wsl.exe`'s own management output (`-l`, `--version`) is UTF-16LE; output from inside a distro is UTF-8.
pub fn decode(bytes: &[u8]) -> String {
    let utf16 = bytes.len() >= 2 && bytes.len() % 2 == 0 && bytes[1] == 0;
    let text = if utf16 {
        let units: Vec<u16> = bytes.chunks_exact(2).map(|c| u16::from_le_bytes([c[0], c[1]])).collect();
        String::from_utf16_lossy(&units)
    } else {
        String::from_utf8_lossy(bytes).into_owned()
    };
    text.trim_start_matches('\u{feff}').replace('\r', "")
}

#[cfg(windows)]
const CREATE_NO_WINDOW: u32 = 0x0800_0000;

pub struct SystemRunner;

#[async_trait]
impl Runner for SystemRunner {
    async fn run(&self, program: &str, args: &[&str]) -> std::io::Result<Output> {
        let mut cmd = tokio::process::Command::new(program);
        cmd.args(args).stdin(std::process::Stdio::null());
        #[cfg(windows)]
        cmd.creation_flags(CREATE_NO_WINDOW);
        let out = cmd.output().await?;
        Ok(Output { code: out.status.code().unwrap_or(-1), stdout: decode(&out.stdout), stderr: decode(&out.stderr) })
    }

    async fn run_stdin(&self, program: &str, args: &[&str], input: &[u8]) -> std::io::Result<Output> {
        use tokio::io::AsyncWriteExt;
        let mut cmd = tokio::process::Command::new(program);
        cmd.args(args)
            .stdin(std::process::Stdio::piped())
            .stdout(std::process::Stdio::piped())
            .stderr(std::process::Stdio::piped());
        #[cfg(windows)]
        cmd.creation_flags(CREATE_NO_WINDOW);
        let mut child = cmd.spawn()?;
        if let Some(mut stdin) = child.stdin.take() {
            stdin.write_all(input).await?;
        }
        let out = child.wait_with_output().await?;
        Ok(Output { code: out.status.code().unwrap_or(-1), stdout: decode(&out.stdout), stderr: decode(&out.stderr) })
    }

    fn spawn_detached(&self, program: &str, args: &[&str]) -> std::io::Result<()> {
        let mut cmd = std::process::Command::new(program);
        cmd.args(args)
            .stdin(std::process::Stdio::null())
            .stdout(std::process::Stdio::null())
            .stderr(std::process::Stdio::null());
        #[cfg(windows)]
        {
            use std::os::windows::process::CommandExt;
            cmd.creation_flags(CREATE_NO_WINDOW);
        }
        cmd.spawn().map(|_| ())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn decodes_utf16_and_utf8() {
        let utf16: Vec<u8> = "  NAME  \r\n* ReliefInventory".encode_utf16().flat_map(|u| u.to_le_bytes()).collect();
        assert_eq!(decode(&utf16), "  NAME  \n* ReliefInventory");
        assert_eq!(decode(b"plain\r\ntext"), "plain\ntext");
        assert_eq!(decode(b""), "");
    }
}
