//! The real HTTP client against a tiny local server: signing in for a session, then downloading a backup that is
//! cut off, resumed with a `Range` request, or served in full by a server that ignores `Range`. (The sequence around it
//! is tested in `remote::runner` against a fake; `launcher/e2e/run.sh` runs the whole thing against the real app.)

use std::collections::VecDeque;
use std::sync::{Arc, Mutex};

use ri_launcher_core::remote::client::{BackupApi, RemoteClient, RemoteError, ServerKind};
use ri_launcher_core::remote::identity::Keys;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpListener;

#[derive(Clone, Copy, Debug, PartialEq)]
enum Behaviour {
    /// Honour Range; cut the connection after half of what is being sent.
    CutHalfway,
    /// Honour Range and send it all.
    Serve,
    /// Ignore Range and send the whole file with a 200.
    IgnoreRange,
    /// Answer 416, as when the partial file is longer than the server's.
    RangeNotSatisfiable,
}

struct Server {
    url: String,
    ranges: Arc<Mutex<Vec<Option<String>>>>,
    script: Arc<Mutex<VecDeque<Behaviour>>>,
}

async fn serve(data: Vec<u8>, script: Vec<Behaviour>) -> Server {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let url = format!("http://{}", listener.local_addr().unwrap());
    let ranges = Arc::new(Mutex::new(Vec::new()));
    let script = Arc::new(Mutex::new(VecDeque::from(script)));
    let (r, s) = (ranges.clone(), script.clone());
    tokio::spawn(async move {
        loop {
            let (mut sock, _) = listener.accept().await.unwrap();
            let (data, r, s) = (data.clone(), r.clone(), s.clone());
            tokio::spawn(async move {
                let mut buf = Vec::new();
                let mut chunk = [0u8; 4096];
                while !buf.windows(4).any(|w| w == b"\r\n\r\n") {
                    let n = sock.read(&mut chunk).await.unwrap_or(0);
                    if n == 0 {
                        return;
                    }
                    buf.extend_from_slice(&chunk[..n]);
                }
                // a POST carries a small body after the headers; this server never needs it
                let head = String::from_utf8_lossy(&buf).to_string();
                let request_line = head.lines().next().unwrap_or("").to_string();
                let header = |name: &str| {
                    head.lines().skip(1).find_map(|l| {
                        let (k, v) = l.split_once(':')?;
                        k.eq_ignore_ascii_case(name).then(|| v.trim().to_string())
                    })
                };
                if request_line.starts_with("POST /api/federation/v1/session") {
                    let body = br#"{"session_token":"RIS-test","expires_in":3600}"#;
                    let head = format!("HTTP/1.1 200 OK\r\ncontent-type: application/json\r\ncontent-length: {}\r\nconnection: close\r\n\r\n", body.len());
                    let _ = sock.write_all(head.as_bytes()).await;
                    let _ = sock.write_all(body).await;
                    return;
                }
                assert!(request_line.starts_with("GET /api/federation/v1/backups/7/download"), "{request_line}");
                assert_eq!(header("authorization").as_deref(), Some("Bearer RIS-test"));
                let range = header("range");
                r.lock().unwrap().push(range.clone());
                let mode = s.lock().unwrap().pop_front().unwrap_or(Behaviour::Serve);
                let from: usize = range.as_deref().and_then(|v| v.strip_prefix("bytes=")?.strip_suffix('-')?.parse().ok()).unwrap_or(0);
                let (status, extra, body): (&str, String, &[u8]) = match mode {
                    Behaviour::RangeNotSatisfiable => ("416 Range Not Satisfiable", String::new(), b""),
                    Behaviour::IgnoreRange => ("200 OK", String::new(), &data[..]),
                    _ if from > 0 => ("206 Partial Content", format!("content-range: bytes {from}-{}/{}\r\n", data.len() - 1, data.len()), &data[from..]),
                    _ => ("200 OK", String::new(), &data[..]),
                };
                let head = format!("HTTP/1.1 {status}\r\n{extra}content-length: {}\r\nconnection: close\r\n\r\n", body.len());
                let _ = sock.write_all(head.as_bytes()).await;
                let send = if mode == Behaviour::CutHalfway { body.len() / 2 } else { body.len() };
                let _ = sock.write_all(&body[..send]).await;
                // dropping the socket here is the cut: fewer bytes than content-length
            });
        }
    });
    Server { url, ranges, script }
}

fn client(url: &str) -> (tempfile::TempDir, RemoteClient) {
    let d = tempfile::tempdir().unwrap();
    let keys = Arc::new(Keys::load_or_create(d.path()).unwrap());
    (d, RemoteClient::new(url, keys, ServerKind::Warehouse))
}

fn data() -> Vec<u8> {
    (0..3_000_000u32).map(|n| (n % 253) as u8).collect()
}

async fn download(c: &RemoteClient, part: &std::path::Path, size: usize) -> Result<(), RemoteError> {
    c.download_backup(7, part, Some(size as u64), &|_, _| {}).await
}

#[tokio::test]
async fn a_cut_download_is_an_error_and_the_next_attempt_continues_from_where_it_stopped() {
    let bytes = data();
    let server = serve(bytes.clone(), vec![Behaviour::CutHalfway, Behaviour::Serve]).await;
    let (dir, c) = client(&server.url);
    let part = dir.path().join("x.zip.part");

    let err = download(&c, &part, bytes.len()).await.unwrap_err();
    assert!(err.is_transient(), "{err:?}");
    let have = std::fs::metadata(&part).unwrap().len() as usize;
    assert!(have > 0 && have < bytes.len(), "kept a partial file ({have} bytes)");

    download(&c, &part, bytes.len()).await.unwrap();
    assert_eq!(std::fs::read(&part).unwrap(), bytes);
    let ranges = server.ranges.lock().unwrap().clone();
    assert_eq!(ranges, vec![None, Some(format!("bytes={have}-"))]);
}

#[tokio::test]
async fn a_server_that_ignores_range_restarts_the_file_instead_of_appending_a_second_copy() {
    let bytes = data();
    let server = serve(bytes.clone(), vec![Behaviour::IgnoreRange]).await;
    let (dir, c) = client(&server.url);
    let part = dir.path().join("x.zip.part");
    std::fs::write(&part, &bytes[..1000]).unwrap();

    download(&c, &part, bytes.len()).await.unwrap();
    assert_eq!(std::fs::read(&part).unwrap(), bytes);
    assert_eq!(server.ranges.lock().unwrap().len(), 1);
}

#[tokio::test]
async fn a_416_throws_the_partial_away_and_starts_over() {
    let bytes = data();
    let server = serve(bytes.clone(), vec![Behaviour::RangeNotSatisfiable, Behaviour::Serve]).await;
    let (dir, c) = client(&server.url);
    let part = dir.path().join("x.zip.part");
    std::fs::write(&part, &bytes[..1000]).unwrap();

    download(&c, &part, bytes.len()).await.unwrap();
    assert_eq!(std::fs::read(&part).unwrap(), bytes);
    assert_eq!(server.ranges.lock().unwrap().clone(), vec![Some("bytes=1000-".to_string()), None]);
}

#[tokio::test]
async fn a_partial_longer_than_the_file_is_discarded_and_a_complete_one_is_not_fetched_again() {
    let bytes = data();
    let server = serve(bytes.clone(), vec![]).await;
    let (dir, c) = client(&server.url);

    let part = dir.path().join("long.part");
    std::fs::write(&part, vec![9u8; bytes.len() + 5]).unwrap();
    download(&c, &part, bytes.len()).await.unwrap();
    assert_eq!(std::fs::read(&part).unwrap(), bytes);

    let calls = server.ranges.lock().unwrap().len();
    download(&c, &part, bytes.len()).await.unwrap(); // already complete: no request
    assert_eq!(server.ranges.lock().unwrap().len(), calls);
    assert!(server.script.lock().unwrap().is_empty());
}
