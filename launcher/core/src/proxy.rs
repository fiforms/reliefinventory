use std::net::SocketAddr;
use tokio::io::copy_bidirectional;
use tokio::net::{TcpListener, TcpStream};

/// Bind first, serve second: a port that is already taken is reported to the person at Start time
/// instead of silently inside a background task.
pub async fn bind(addr: SocketAddr) -> std::io::Result<TcpListener> {
    TcpListener::bind(addr).await
}

/// Forward every accepted connection to `target` (127.0.0.1:<internal port>, where WSL/Lima forward the
/// app). Runs until dropped/aborted; one failed connection never stops the listener.
pub async fn serve(listener: TcpListener, target: String) {
    loop {
        let Ok((mut inbound, _)) = listener.accept().await else {
            tokio::time::sleep(std::time::Duration::from_millis(50)).await;
            continue;
        };
        let target = target.clone();
        tokio::spawn(async move {
            if let Ok(mut outbound) = TcpStream::connect(&target).await {
                let _ = copy_bidirectional(&mut inbound, &mut outbound).await;
            }
        });
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tokio::io::{AsyncReadExt, AsyncWriteExt};

    #[tokio::test]
    async fn forwards_bytes_both_ways() {
        let backend = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let backend_addr = backend.local_addr().unwrap().to_string();
        tokio::spawn(async move {
            let (mut s, _) = backend.accept().await.unwrap();
            let mut buf = [0u8; 4];
            s.read_exact(&mut buf).await.unwrap();
            s.write_all(&buf).await.unwrap();
        });
        let listener = bind("127.0.0.1:0".parse().unwrap()).await.unwrap();
        let front = listener.local_addr().unwrap();
        tokio::spawn(serve(listener, backend_addr));
        let mut c = TcpStream::connect(front).await.unwrap();
        c.write_all(b"ping").await.unwrap();
        let mut out = [0u8; 4];
        c.read_exact(&mut out).await.unwrap();
        assert_eq!(&out, b"ping");
    }

    #[tokio::test]
    async fn a_taken_port_is_an_error() {
        let first = bind("127.0.0.1:0".parse().unwrap()).await.unwrap();
        assert!(bind(first.local_addr().unwrap()).await.is_err());
    }
}
