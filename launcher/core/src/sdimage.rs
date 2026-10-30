//! The R Station SD card image: learn what the current one is, download it with a checksum, hand it to
//! Raspberry Pi Imager.
//!
//! **Chain of trust.** The launcher knows one address, [`MANIFEST_URL`], a small JSON file attached to a fixed
//! `rstation-image` GitHub release (published with `npm run publish:rstation-image`). The manifest carries the
//! image's size, SHA-256 and the address(es) to fetch it from, so the image itself can sit on any server
//! (Nextcloud): nothing is trusted about where the bytes come from, only that they hash to what the manifest
//! says. New images need no launcher release. The launcher never writes to a disk itself — Raspberry Pi
//! Imager does that, because picking the right drive safely on every OS is its job, not ours.
use crate::download::{hash_file, hex};
use crate::platform::{fail, PlatformError, Result};
use futures_util::StreamExt;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::Duration;
use tokio::io::AsyncWriteExt;

pub const MANIFEST_URL: &str = "https://github.com/fiforms/reliefinventory/releases/download/rstation-image/rstation-image.json";
pub const IMAGER_URL: &str = "https://www.raspberrypi.com/software/";
pub const ETCHER_URL: &str = "https://etcher.balena.io/";

/// The newest manifest layout this launcher understands.
const SCHEMA: u32 = 1;
const MAX_MANIFEST_BYTES: usize = 64 * 1024;
const MAX_IMAGE_BYTES: u64 = 64 << 30;

/// The manifest address, or `RI_IMAGE_MANIFEST_URL` when set (testing a manifest before publishing it).
pub fn manifest_url() -> String {
    std::env::var("RI_IMAGE_MANIFEST_URL").ok().filter(|u| !u.trim().is_empty()).unwrap_or_else(|| MANIFEST_URL.to_string())
}

#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct Manifest {
    #[serde(default)]
    pub schema: u32,
    pub version: String,
    #[serde(default)]
    pub released: String,
    #[serde(default)]
    pub notes: String,
    pub filename: String,
    pub size: u64,
    pub sha256: String,
    pub urls: Vec<String>,
    #[serde(default)]
    pub min_launcher_version: Option<String>,
}

impl Manifest {
    /// Parse and sanity-check manifest bytes. Everything in it is untrusted until it passes: the file name
    /// becomes a path on this computer, and the addresses are fetched.
    pub fn parse(bytes: &[u8]) -> Result<Manifest> {
        let mut m: Manifest = serde_json::from_slice(bytes).map_err(|e| PlatformError::Failed(format!("The image manifest isn't valid: {e}")))?;
        if m.schema > SCHEMA {
            return fail("This image needs a newer RI Launcher. Update the launcher and try again.");
        }
        m.sha256 = m.sha256.trim().to_ascii_lowercase();
        if m.sha256.len() != 64 || !m.sha256.bytes().all(|b| b.is_ascii_hexdigit()) {
            return fail("The image manifest has no valid checksum.");
        }
        if m.size == 0 || m.size > MAX_IMAGE_BYTES {
            return fail("The image manifest has an implausible size.");
        }
        let safe_name = !m.filename.is_empty()
            && m.filename.len() <= 120
            && !m.filename.starts_with('.')
            && m.filename.bytes().all(|b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'-'));
        if !safe_name {
            return fail("The image manifest has an unusable file name.");
        }
        if m.urls.is_empty() || m.urls.iter().any(|u| !u.starts_with("https://")) {
            return fail("The image manifest has no secure (https) download address.");
        }
        Ok(m)
    }
}

fn client() -> Result<reqwest::Client> {
    reqwest::Client::builder()
        .user_agent(concat!("ri-launcher/", env!("CARGO_PKG_VERSION")))
        .connect_timeout(Duration::from_secs(20))
        .read_timeout(Duration::from_secs(60))
        .build()
        .map_err(|e| PlatformError::Failed(e.to_string()))
}

pub async fn fetch_manifest(url: &str) -> Result<Manifest> {
    let net = |e: reqwest::Error| PlatformError::Failed(format!("Couldn't reach the image list: {e}"));
    let c = reqwest::Client::builder()
        .user_agent(concat!("ri-launcher/", env!("CARGO_PKG_VERSION")))
        .timeout(Duration::from_secs(20))
        .build()
        .map_err(net)?;
    let resp = c.get(url).send().await.map_err(net)?;
    if !resp.status().is_success() {
        return fail(format!("The image list isn't available (the server answered {}).", resp.status()));
    }
    if resp.content_length().is_some_and(|n| n as usize > MAX_MANIFEST_BYTES) {
        return fail("The image manifest is too large.");
    }
    let bytes = resp.bytes().await.map_err(net)?;
    if bytes.len() > MAX_MANIFEST_BYTES {
        return fail("The image manifest is too large.");
    }
    Manifest::parse(&bytes)
}

/// True when `m` asks for a launcher newer than `current` ("1.2.3" style; unparsable counts as not too old).
pub fn launcher_too_old(m: &Manifest, current: &str) -> bool {
    fn parts(v: &str) -> Option<Vec<u64>> {
        v.trim().trim_start_matches('v').split('.').map(|p| p.split(|c: char| !c.is_ascii_digit()).next()?.parse().ok()).collect()
    }
    match (m.min_launcher_version.as_deref().and_then(parts), parts(current)) {
        (Some(min), Some(cur)) => {
            let n = min.len().max(cur.len());
            let pad = |v: &Vec<u64>| (0..n).map(|i| v.get(i).copied().unwrap_or(0)).collect::<Vec<_>>();
            pad(&cur) < pad(&min)
        }
        _ => false,
    }
}

pub fn images_dir(dir: &Path) -> PathBuf {
    dir.join("images")
}

pub fn image_path(dir: &Path, m: &Manifest) -> PathBuf {
    images_dir(dir).join(&m.filename)
}

fn marker_path(dir: &Path, m: &Manifest) -> PathBuf {
    images_dir(dir).join(format!("{}.verified", m.filename))
}

fn cached_manifest_path(dir: &Path) -> PathBuf {
    images_dir(dir).join("manifest.json")
}

/// True when the image is on disk, the right size, and was checksum-verified against this manifest's hash
/// when it finished downloading (the marker file records that; no 1 GB re-hash on every look).
pub fn is_downloaded(dir: &Path, m: &Manifest) -> bool {
    std::fs::metadata(image_path(dir, m)).is_ok_and(|f| f.len() == m.size)
        && std::fs::read_to_string(marker_path(dir, m)).is_ok_and(|s| s.trim() == m.sha256)
}

#[derive(Debug, Clone, Serialize)]
pub struct ImageInfo {
    pub manifest: Manifest,
    pub downloaded: bool,
    pub path: String,
    pub needs_newer_launcher: bool,
    pub imager_found: bool,
    /// The list couldn't be fetched, so this is the last one seen.
    pub offline: bool,
}

/// What the tab shows: the current manifest (or the last one seen when offline) and the state on disk.
pub async fn info(dir: &Path, launcher_version: &str) -> Result<ImageInfo> {
    let (manifest, offline) = match fetch_manifest(&manifest_url()).await {
        Ok(m) => {
            let _ = std::fs::create_dir_all(images_dir(dir));
            let _ = std::fs::write(cached_manifest_path(dir), serde_json::to_vec(&m).unwrap_or_default());
            (m, false)
        }
        Err(e) => match std::fs::read(cached_manifest_path(dir)).ok().and_then(|b| Manifest::parse(&b).ok()) {
            Some(m) => (m, true),
            None => return Err(e),
        },
    };
    Ok(ImageInfo {
        downloaded: is_downloaded(dir, &manifest),
        path: image_path(dir, &manifest).to_string_lossy().into_owned(),
        needs_newer_launcher: launcher_too_old(&manifest, launcher_version),
        imager_found: find_imager().is_some(),
        offline,
        manifest,
    })
}

/// Download the image into `<dir>/images`, resuming a partial file, trying each address in turn, and keeping it only
/// if it hashes to the manifest's SHA-256. `progress(done, total)` is called as bytes arrive. Setting `cancel` stops
/// it with the partial file kept for next time.
pub async fn download(m: &Manifest, dir: &Path, cancel: &AtomicBool, progress: &(dyn Fn(u64, u64) + Send + Sync)) -> Result<PathBuf> {
    let imgs = images_dir(dir);
    tokio::fs::create_dir_all(&imgs).await.map_err(|e| PlatformError::Failed(format!("Couldn't create {}: {e}", imgs.display())))?;
    let dest = image_path(dir, m);
    if is_downloaded(dir, m) {
        progress(m.size, m.size);
        return Ok(dest);
    }
    let mut last = PlatformError::Failed("The manifest lists no download address.".into());
    for url in &m.urls {
        match fetch_one(url, m, &dest, cancel, progress).await {
            Ok(()) => {
                let _ = std::fs::write(marker_path(dir, m), &m.sha256);
                remove_stale(&imgs, m);
                return Ok(dest);
            }
            Err(e) if cancel.load(Ordering::Relaxed) => return Err(e),
            Err(e) => last = e,
        }
    }
    Err(last)
}

/// Older images (and their markers/partials) are 1+ GB each; only the current one is kept.
fn remove_stale(imgs: &Path, m: &Manifest) {
    let keep = [m.filename.clone(), format!("{}.verified", m.filename), "manifest.json".to_string()];
    if let Ok(rd) = std::fs::read_dir(imgs) {
        for e in rd.flatten() {
            let name = e.file_name().to_string_lossy().into_owned();
            if !keep.contains(&name) && (name.ends_with(".verified") || name.ends_with(".part") || name.contains(".img")) {
                let _ = std::fs::remove_file(e.path());
            }
        }
    }
}

async fn fetch_one(url: &str, m: &Manifest, dest: &Path, cancel: &AtomicBool, progress: &(dyn Fn(u64, u64) + Send + Sync)) -> Result<()> {
    let net = |e: reqwest::Error| PlatformError::Failed(format!("The download was interrupted: {e}. Press Download to resume."));
    let io = |e: std::io::Error| PlatformError::Failed(format!("Couldn't save the image: {e}"));
    let part = dest.with_file_name(format!("{}.part", m.filename));

    let mut have = tokio::fs::metadata(&part).await.map(|f| f.len()).unwrap_or(0);
    if have > m.size {
        let _ = tokio::fs::remove_file(&part).await;
        have = 0;
    }
    let mut hasher = Sha256::new();
    if have > 0 {
        hasher = hash_prefix(&part).await.map_err(io)?;
    }

    let mut req = client()?.get(url);
    if have > 0 {
        req = req.header(reqwest::header::RANGE, format!("bytes={have}-"));
    }
    let resp = req.send().await.map_err(net)?;
    let status = resp.status();
    if resp.headers().get(reqwest::header::CONTENT_TYPE).and_then(|v| v.to_str().ok()).is_some_and(|t| t.starts_with("text/html")) {
        return fail("The download address returned a web page, not the image.");
    }

    let mut done = have;
    if have == m.size && status == reqwest::StatusCode::RANGE_NOT_SATISFIABLE {
        // the partial file already holds every byte; fall through to the checksum
    } else {
        let mut file = if have > 0 && status == reqwest::StatusCode::PARTIAL_CONTENT {
            tokio::fs::OpenOptions::new().append(true).open(&part).await.map_err(io)?
        } else if status.is_success() {
            // a server that ignores Range sends the whole file again: start over
            hasher = Sha256::new();
            done = 0;
            tokio::fs::File::create(&part).await.map_err(io)?
        } else {
            return fail(format!("The download server answered {status}."));
        };
        let mut last_report = 0u64;
        let mut stream = resp.bytes_stream();
        while let Some(chunk) = stream.next().await {
            if cancel.load(Ordering::Relaxed) {
                let _ = file.flush().await;
                return fail("Download cancelled.");
            }
            let chunk = match chunk {
                Ok(c) => c,
                Err(e) => {
                    // A tokio File writes in the background; flush so the bytes already received are on disk for the resume.
                    let _ = file.flush().await;
                    return Err(net(e));
                }
            };
            done += chunk.len() as u64;
            if done > m.size {
                drop(file);
                let _ = tokio::fs::remove_file(&part).await;
                return fail("The download is larger than the manifest says, so it was discarded.");
            }
            hasher.update(&chunk);
            file.write_all(&chunk).await.map_err(io)?;
            if done - last_report >= 1 << 20 {
                last_report = done;
                progress(done, m.size);
            }
        }
        file.flush().await.map_err(io)?;
    }

    progress(done, m.size);
    if done != m.size {
        return fail(format!("The download stopped early ({} of {} MB). Press Download to resume.", done >> 20, m.size >> 20));
    }
    if !hex(&hasher.finalize()).eq_ignore_ascii_case(&m.sha256) {
        let _ = tokio::fs::remove_file(&part).await;
        return fail("The downloaded file is not the expected image (checksum mismatch), so it was discarded. Try again.");
    }
    tokio::fs::rename(&part, dest).await.map_err(io)?;
    Ok(())
}

/// The hash state after the bytes already in a partial file, so a resumed download still verifies end to end.
async fn hash_prefix(path: &Path) -> std::io::Result<Sha256> {
    let path = path.to_path_buf();
    tokio::task::spawn_blocking(move || {
        use std::io::Read;
        let mut f = std::fs::File::open(path)?;
        let mut h = Sha256::new();
        let mut buf = vec![0u8; 1 << 20];
        loop {
            let n = f.read(&mut buf)?;
            if n == 0 {
                return Ok(h);
            }
            h.update(&buf[..n]);
        }
    })
    .await
    .map_err(|e| std::io::Error::new(std::io::ErrorKind::Other, e))?
}

/// Re-hash a downloaded image against the manifest (for the CLI; the window trusts the marker).
pub async fn verify(dir: &Path, m: &Manifest) -> bool {
    hash_file(&image_path(dir, m)).await.is_ok_and(|h| h.eq_ignore_ascii_case(&m.sha256))
}

// --- Raspberry Pi Imager -------------------------------------------------------------------------------

pub fn find_in_path(name: &str, path_var: &std::ffi::OsStr) -> Option<PathBuf> {
    std::env::split_paths(path_var).map(|d| d.join(name)).find(|p| p.is_file())
}

/// Where Raspberry Pi Imager's program is on this computer, if it is installed.
pub fn find_imager() -> Option<PathBuf> {
    #[cfg(windows)]
    {
        let roots = ["ProgramFiles", "ProgramFiles(x86)", "LOCALAPPDATA"];
        return roots.iter().filter_map(|k| std::env::var_os(k)).flat_map(|r| {
            let r = PathBuf::from(r);
            [r.join("Raspberry Pi Imager").join("rpi-imager.exe"), r.join("Programs").join("Raspberry Pi Imager").join("rpi-imager.exe")]
        }).find(|p| p.is_file());
    }
    #[cfg(target_os = "macos")]
    {
        let rel = "Raspberry Pi Imager.app/Contents/MacOS/rpi-imager";
        let mut roots = vec![PathBuf::from("/Applications")];
        if let Some(h) = dirs::home_dir() {
            roots.push(h.join("Applications"));
        }
        return roots.into_iter().map(|r| r.join(rel)).find(|p| p.is_file());
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        let from_path = std::env::var_os("PATH").and_then(|p| find_in_path("rpi-imager", &p));
        from_path.or_else(|| ["/usr/bin/rpi-imager", "/usr/local/bin/rpi-imager", "/snap/bin/rpi-imager"].iter().map(PathBuf::from).find(|p| p.is_file()))
    }
}

/// Open Raspberry Pi Imager with `image` already chosen. It asks which card to write, so the launcher never
/// touches a drive itself.
pub fn open_in_imager(image: &Path) -> Result<()> {
    let Some(exe) = find_imager() else {
        return fail("Raspberry Pi Imager isn't installed on this computer yet.");
    };
    if !image.is_file() {
        return fail("The image hasn't been downloaded yet.");
    }
    let mut child = std::process::Command::new(exe)
        .arg(image)
        .stdin(std::process::Stdio::null())
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .spawn()
        .map_err(|e| PlatformError::Failed(format!("Couldn't start Raspberry Pi Imager: {e}")))?;
    std::thread::spawn(move || {
        let _ = child.wait();
    });
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::AtomicU64;
    use std::sync::Arc;
    use tokio::io::AsyncReadExt;
    use tokio::net::TcpListener;

    fn good() -> serde_json::Value {
        serde_json::json!({
            "schema": 1, "version": "1.0", "filename": "rstation-1.0.img.xz", "size": 1234,
            "sha256": "A".repeat(64), "urls": ["https://example.org/x"]
        })
    }

    fn parse(v: serde_json::Value) -> Result<Manifest> {
        Manifest::parse(v.to_string().as_bytes())
    }

    #[test]
    fn a_good_manifest_parses_and_normalizes_the_hash() {
        let m = parse(good()).unwrap();
        assert_eq!(m.sha256, "a".repeat(64));
        assert_eq!(m.notes, "");
    }

    #[test]
    fn hostile_manifests_are_refused() {
        let bad = |k: &str, v: serde_json::Value| {
            let mut g = good();
            g[k] = v;
            assert!(parse(g).is_err(), "{k} should be refused");
        };
        bad("filename", "../../etc/passwd".into());
        bad("filename", "a/b.img".into());
        bad("filename", "C:evil.exe".into());
        bad("filename", ".hidden".into());
        bad("filename", "".into());
        bad("sha256", "abc".into());
        bad("sha256", "z".repeat(64).into());
        bad("size", 0.into());
        bad("size", u64::MAX.into());
        bad("urls", serde_json::json!([]));
        bad("urls", serde_json::json!(["http://example.org/x"]));
        bad("urls", serde_json::json!(["https://ok.example/x", "file:///etc/passwd"]));
        bad("schema", 2.into());
        assert!(Manifest::parse(b"not json").is_err());
    }

    #[test]
    fn minimum_launcher_version() {
        let mut m = parse(good()).unwrap();
        assert!(!launcher_too_old(&m, "0.1.0"));
        m.min_launcher_version = Some("0.3.0".into());
        assert!(launcher_too_old(&m, "0.2.9"));
        assert!(!launcher_too_old(&m, "0.3.0"));
        assert!(!launcher_too_old(&m, "1.0"));
        m.min_launcher_version = Some("1.0".into());
        assert!(launcher_too_old(&m, "0.9.9"));
        assert!(!launcher_too_old(&m, "garbage"));
    }

    #[test]
    fn finds_a_program_on_a_path_list() {
        let d = tempfile::tempdir().unwrap();
        std::fs::write(d.path().join("rpi-imager"), b"").unwrap();
        let joined = std::env::join_paths([PathBuf::from("/nonexistent"), d.path().to_path_buf()]).unwrap();
        assert_eq!(find_in_path("rpi-imager", &joined), Some(d.path().join("rpi-imager")));
        assert_eq!(find_in_path("other", &joined), None);
    }

    /// A tiny HTTP server for one body. With `ranges` it answers `Range: bytes=N-` with 206, otherwise it always
    /// sends everything (like a server that ignores Range). `limit` cuts the first response short to simulate a drop.
    async fn serve(body: Vec<u8>, ranges: bool, mut limit: Option<usize>) -> (String, Arc<AtomicU64>) {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let url = format!("http://{}/img", listener.local_addr().unwrap());
        let hits = Arc::new(AtomicU64::new(0));
        let h = hits.clone();
        tokio::spawn(async move {
            loop {
                let (mut s, _) = listener.accept().await.unwrap();
                h.fetch_add(1, Ordering::SeqCst);
                let mut buf = vec![0u8; 4096];
                let n = s.read(&mut buf).await.unwrap();
                let req = String::from_utf8_lossy(&buf[..n]).to_lowercase();
                let start = req.split("range: bytes=").nth(1).and_then(|r| r.split('-').next()?.trim().parse::<usize>().ok()).filter(|_| ranges);
                let (code, from) = match start {
                    Some(n) => ("206 Partial Content", n),
                    None => ("200 OK", 0),
                };
                let rest = &body[from..];
                let send = limit.take().map_or(rest.len(), |l| l.min(rest.len()));
                let head = format!("HTTP/1.1 {code}\r\nContent-Type: application/octet-stream\r\nContent-Length: {}\r\nConnection: close\r\n\r\n", rest.len());
                let _ = s.write_all(head.as_bytes()).await;
                let _ = s.write_all(&rest[..send]).await;
                let _ = s.shutdown().await;
            }
        });
        (url, hits)
    }

    fn manifest_for(body: &[u8], urls: Vec<String>) -> Manifest {
        Manifest {
            schema: 1, version: "t".into(), released: String::new(), notes: String::new(), filename: "t.img.xz".into(),
            size: body.len() as u64, sha256: hex(&Sha256::digest(body)), urls, min_launcher_version: None,
        }
    }

    fn body() -> Vec<u8> {
        (0..3_000_000u32).map(|i| (i % 251) as u8).collect()
    }

    #[tokio::test]
    async fn downloads_verifies_and_marks_the_image() {
        let (b, d) = (body(), tempfile::tempdir().unwrap());
        let (url, _) = serve(b.clone(), true, None).await;
        let m = manifest_for(&b, vec![url]);
        let cancel = AtomicBool::new(false);
        let p = download(&m, d.path(), &cancel, &|_, _| {}).await.unwrap();
        assert_eq!(std::fs::read(&p).unwrap(), b);
        assert!(is_downloaded(d.path(), &m));
        assert!(!images_dir(d.path()).join("t.img.xz.part").exists());
        // already there: no server needed
        let again = Manifest { urls: vec!["http://127.0.0.1:1/never".into()], ..m };
        download(&again, d.path(), &cancel, &|_, _| {}).await.unwrap();
    }

    #[tokio::test]
    async fn a_dropped_download_resumes_with_a_range_request_and_still_verifies() {
        let (b, d) = (body(), tempfile::tempdir().unwrap());
        let (url, hits) = serve(b.clone(), true, Some(1_000_000)).await;
        let m = manifest_for(&b, vec![url]);
        let cancel = AtomicBool::new(false);
        assert!(download(&m, d.path(), &cancel, &|_, _| {}).await.is_err());
        let part = images_dir(d.path()).join("t.img.xz.part");
        assert_eq!(std::fs::metadata(&part).unwrap().len(), 1_000_000);
        download(&m, d.path(), &cancel, &|_, _| {}).await.unwrap();
        assert_eq!(std::fs::read(image_path(d.path(), &m)).unwrap(), b);
        assert_eq!(hits.load(Ordering::SeqCst), 2);
    }

    #[tokio::test]
    async fn a_server_that_ignores_range_restarts_cleanly() {
        let (b, d) = (body(), tempfile::tempdir().unwrap());
        let (url, _) = serve(b.clone(), false, Some(500_000)).await;
        let m = manifest_for(&b, vec![url]);
        let cancel = AtomicBool::new(false);
        assert!(download(&m, d.path(), &cancel, &|_, _| {}).await.is_err());
        download(&m, d.path(), &cancel, &|_, _| {}).await.unwrap();
        assert_eq!(std::fs::read(image_path(d.path(), &m)).unwrap(), b);
    }

    #[tokio::test]
    async fn a_wrong_file_is_discarded_and_never_marked() {
        let (b, d) = (body(), tempfile::tempdir().unwrap());
        let mut evil = b.clone();
        evil[10] ^= 0xff;
        let (url, _) = serve(evil, true, None).await;
        let m = manifest_for(&b, vec![url]);
        let e = download(&m, d.path(), &AtomicBool::new(false), &|_, _| {}).await.unwrap_err();
        assert!(e.to_string().contains("checksum"), "{e}");
        assert!(!image_path(d.path(), &m).exists());
        assert!(!images_dir(d.path()).join("t.img.xz.part").exists());
        assert!(!is_downloaded(d.path(), &m));
    }

    #[tokio::test]
    async fn a_bigger_than_promised_download_is_cut_off() {
        let (b, d) = (body(), tempfile::tempdir().unwrap());
        let mut longer = b.clone();
        longer.extend_from_slice(&[7u8; 5000]);
        let (url, _) = serve(longer, true, None).await;
        let m = manifest_for(&b, vec![url]);
        let e = download(&m, d.path(), &AtomicBool::new(false), &|_, _| {}).await.unwrap_err();
        assert!(e.to_string().contains("larger"), "{e}");
        assert!(!image_path(d.path(), &m).exists());
    }

    #[tokio::test]
    async fn falls_back_to_the_next_address() {
        let (b, d) = (body(), tempfile::tempdir().unwrap());
        let (url, _) = serve(b.clone(), true, None).await;
        let m = manifest_for(&b, vec!["http://127.0.0.1:1/dead".into(), url]);
        download(&m, d.path(), &AtomicBool::new(false), &|_, _| {}).await.unwrap();
        assert!(is_downloaded(d.path(), &m));
    }

    #[tokio::test]
    async fn cancel_keeps_the_partial_file() {
        let (b, d) = (body(), tempfile::tempdir().unwrap());
        let (url, _) = serve(b.clone(), true, None).await;
        let m = manifest_for(&b, vec![url]);
        let cancel = AtomicBool::new(true);
        let e = download(&m, d.path(), &cancel, &|_, _| {}).await.unwrap_err();
        assert!(e.to_string().contains("cancelled"), "{e}");
        assert!(!is_downloaded(d.path(), &m));
    }

    #[test]
    fn newer_images_replace_older_ones() {
        let d = tempfile::tempdir().unwrap();
        let imgs = images_dir(d.path());
        std::fs::create_dir_all(&imgs).unwrap();
        for f in ["old.img.xz", "old.img.xz.verified", "old.img.xz.part", "t.img.xz", "t.img.xz.verified", "manifest.json", "notes.txt"] {
            std::fs::write(imgs.join(f), b"x").unwrap();
        }
        remove_stale(&imgs, &manifest_for(b"x", vec![]));
        let mut left: Vec<_> = std::fs::read_dir(&imgs).unwrap().map(|e| e.unwrap().file_name().to_string_lossy().into_owned()).collect();
        left.sort();
        assert_eq!(left, ["manifest.json", "notes.txt", "t.img.xz", "t.img.xz.verified"]);
    }
}
