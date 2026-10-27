use crate::platform::{fail, Progress, ProgressFn, Result};
use async_trait::async_trait;
use futures_util::StreamExt;
use sha2::{Digest, Sha256};
use std::path::Path;
use tokio::io::AsyncWriteExt;

#[async_trait]
pub trait Downloader: Send + Sync {
    /// Fetch `url` to `dest`, refusing anything whose SHA-256 differs from `sha256`. A file already at
    /// `dest` with the right hash is reused, so a retried install doesn't re-download 367 MB.
    async fn fetch(&self, label: &str, url: &str, dest: &Path, sha256: &str, progress: &ProgressFn) -> Result<()>;
}

pub struct HttpDownloader;

pub(crate) async fn hash_file(path: &Path) -> std::io::Result<String> {
    let path = path.to_path_buf();
    tokio::task::spawn_blocking(move || {
        use std::io::Read;
        let mut f = std::fs::File::open(path)?;
        let mut h = Sha256::new();
        let mut buf = vec![0u8; 1 << 20];
        loop {
            let n = f.read(&mut buf)?;
            if n == 0 {
                break;
            }
            h.update(&buf[..n]);
        }
        Ok(hex(&h.finalize()))
    })
    .await
    .map_err(|e| std::io::Error::new(std::io::ErrorKind::Other, e))?
}

pub fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}

#[async_trait]
impl Downloader for HttpDownloader {
    async fn fetch(&self, label: &str, url: &str, dest: &Path, sha256: &str, progress: &ProgressFn) -> Result<()> {
        let io = |e: std::io::Error| crate::platform::PlatformError::Failed(format!("{label}: {e}"));
        if let Ok(have) = hash_file(dest).await {
            if have.eq_ignore_ascii_case(sha256) {
                return Ok(());
            }
        }
        if let Some(parent) = dest.parent() {
            tokio::fs::create_dir_all(parent).await.map_err(io)?;
        }
        let resp = reqwest::get(url).await.map_err(|e| crate::platform::PlatformError::Failed(format!("{label}: {e}")))?;
        if !resp.status().is_success() {
            return fail(format!("{label}: the download server answered {}", resp.status()));
        }
        let total = resp.content_length();
        let part = dest.with_extension("part");
        let mut file = tokio::fs::File::create(&part).await.map_err(io)?;
        let mut hasher = Sha256::new();
        let (mut done, mut last_report) = (0u64, 0u64);
        let mut stream = resp.bytes_stream();
        while let Some(chunk) = stream.next().await {
            let chunk = chunk.map_err(|e| crate::platform::PlatformError::Failed(format!("{label}: {e}")))?;
            hasher.update(&chunk);
            file.write_all(&chunk).await.map_err(io)?;
            done += chunk.len() as u64;
            if done - last_report >= 1 << 20 || Some(done) == total {
                last_report = done;
                progress(Progress {
                    step: format!("Downloading {label}"),
                    detail: match total {
                        Some(t) => format!("{} of {} MB", done >> 20, t >> 20),
                        None => format!("{} MB", done >> 20),
                    },
                    fraction: total.map(|t| (done as f32 / t as f32).min(1.0)),
                });
            }
        }
        file.flush().await.map_err(io)?;
        drop(file);
        let got = hex(&hasher.finalize());
        if !got.eq_ignore_ascii_case(sha256) {
            let _ = tokio::fs::remove_file(&part).await;
            return fail(format!("{label}: the downloaded file is not the one expected (checksum mismatch); try again"));
        }
        tokio::fs::rename(&part, dest).await.map_err(io)?;
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn hex_encoding() {
        assert_eq!(hex(&[0, 15, 255]), "000fff");
    }

    #[tokio::test]
    async fn reuses_a_file_with_the_right_hash() {
        let d = tempfile::tempdir().unwrap();
        let f = d.path().join("x.bin");
        std::fs::write(&f, b"hello").unwrap();
        let want = hex(&Sha256::digest(b"hello"));
        let progress: ProgressFn = std::sync::Arc::new(|_| {});
        // The URL is unreachable on purpose: a matching file must short-circuit before any request.
        HttpDownloader.fetch("x", "http://127.0.0.1:1/never", &f, &want, &progress).await.unwrap();
        assert!(HttpDownloader.fetch("x", "http://127.0.0.1:1/never", &f, "00", &progress).await.is_err());
    }
}
