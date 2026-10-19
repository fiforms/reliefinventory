//! Headless print server: pair once, then `run` (a systemd/launchd unit works well for an
//! unattended machine). The desktop app wraps the same engine with a window and tray icon.

use std::path::PathBuf;

use clap::{Parser, Subcommand};
use ri_print_core::backend::{default_backend, PrintBackend};
use ri_print_core::config::default_data_dir;
use ri_print_core::{Engine, Options};

#[derive(Parser)]
#[command(name = "ri-print", version, about = "Relief Inventory print server")]
struct Cli {
    /// Where settings and the job ledger live (default: the per-user app-data directory).
    #[arg(long, global = true)]
    data_dir: Option<PathBuf>,
    /// Path to SumatraPDF.exe (Windows only).
    #[arg(long, global = true)]
    sumatra: Option<PathBuf>,
    #[command(subcommand)]
    cmd: Cmd,
}

#[derive(Subcommand)]
enum Cmd {
    /// Pair with the server using the 6-digit code from Setup → Devices.
    Pair {
        #[arg(long)]
        server: String,
        #[arg(long)]
        code: String,
        /// Name shown on the Devices page (default: this computer's name).
        #[arg(long)]
        name: Option<String>,
    },
    /// Serve print jobs until stopped.
    Run,
    /// List the printers this computer would report.
    Printers,
    /// Forget the pairing on this computer.
    Unpair,
}

#[tokio::main]
async fn main() {
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::try_from_default_env().unwrap_or_else(|_| "info".into()))
        .init();
    let cli = Cli::parse();
    let dir = cli.data_dir.unwrap_or_else(default_data_dir);
    let backend = default_backend(cli.sumatra);

    let code = match cli.cmd {
        Cmd::Printers => match backend.list_printers().await {
            Ok(list) => {
                for p in list {
                    println!("{:<40} {:?}{}", p.queue_name, p.state, p.state_message.map(|m| format!("  — {m}")).unwrap_or_default());
                }
                0
            }
            Err(e) => {
                eprintln!("{e}");
                1
            }
        },
        Cmd::Pair { server, code, name } => {
            let engine = engine(dir, backend);
            match engine.pair(&server, &code, name.as_deref()).await {
                Ok(()) => {
                    println!("Paired. Start serving with: ri-print run");
                    0
                }
                Err(e) => {
                    eprintln!("{e}");
                    1
                }
            }
        }
        Cmd::Unpair => {
            engine(dir, backend).unpair();
            println!("Un-paired on this computer.");
            0
        }
        Cmd::Run => {
            let engine = engine(dir, backend);
            if !engine.is_paired() {
                eprintln!("Not paired yet. Run: ri-print pair --server https://… --code 123456");
                std::process::exit(2);
            }
            let mut status = engine.subscribe();
            tokio::spawn(async move {
                let mut seen_jobs = std::collections::HashMap::new();
                while status.changed().await.is_ok() {
                    let s = status.borrow().clone();
                    for j in s.recent_jobs.iter().rev() {
                        if seen_jobs.insert(j.id, j.state.clone()).as_deref() != Some(j.state.as_str()) {
                            tracing::info!("job {} on '{}': {}{}", j.id, j.queue_name, j.state, j.error.as_ref().map(|e| format!(" — {e}")).unwrap_or_default());
                        }
                    }
                    if let Some(n) = s.notice {
                        tracing::warn!("{n}");
                    }
                }
            });
            tokio::select! {
                _ = engine.clone().run() => {}
                _ = tokio::signal::ctrl_c() => tracing::info!("stopping"),
            }
            0
        }
    };
    std::process::exit(code);
}

fn engine(dir: PathBuf, backend: std::sync::Arc<dyn PrintBackend>) -> std::sync::Arc<Engine> {
    Engine::new(dir, backend, Options::default()).unwrap_or_else(|e| {
        eprintln!("Can't open the settings directory: {e}");
        std::process::exit(1);
    })
}
