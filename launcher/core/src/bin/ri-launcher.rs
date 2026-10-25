use clap::{Parser, Subcommand};
use ri_launcher_core::backups;
use ri_launcher_core::platform::{InstallOutcome, Platform};
use ri_launcher_core::service::Launcher;
use ri_launcher_core::settings::Mode;
use std::path::PathBuf;
use std::sync::Arc;

#[derive(Parser)]
#[command(name = "ri-launcher", about = "Install and run Relief Inventory without Docker Desktop")]
struct Cli {
    /// Where settings, downloads and the Linux layer live (default: the per-user app data folder)
    #[arg(long, global = true)]
    data_dir: Option<PathBuf>,
    #[command(subcommand)]
    command: Cmd,
}

#[derive(Subcommand)]
enum Cmd {
    /// Show state, address and settings
    Status,
    /// Check this computer can run it
    Preflight,
    /// Install everything (needs administrator rights on Windows; may ask for a restart — run it again after)
    Install,
    /// Start Relief Inventory and the LAN proxy, and stay running until Ctrl+C
    Run,
    /// Start in the background of this command only (the proxy ends when this command does — prefer `run`)
    Start,
    Stop,
    /// Pull newer images and restart
    Update,
    Uninstall {
        /// Leave the Linux layer and its database in place
        #[arg(long)]
        keep_data: bool,
    },
    /// "lan" lets other devices connect; "local" is this computer only
    SetMode { mode: String },
    /// Name other devices type, e.g. office-pc.local (no argument clears it back to the computer's name)
    SetHost { name: Option<String> },
    SetPort { port: u16 },
    SetTimezone { zone: Option<String> },
    /// Print the link to send to others on the network
    Link,
    Backups {
        #[command(subcommand)]
        action: BackupCmd,
    },
}

#[derive(Subcommand)]
enum BackupCmd {
    List,
    /// scope: database (default) or full
    Create { scope: Option<String> },
    /// Copy a backup (by file name) out as a read-only file
    Export { name: String, dest: Option<PathBuf> },
    /// Restore from a backup zip on disk (takes a safety backup first)
    Import { file: PathBuf },
}

fn data_dir(cli: &Cli) -> PathBuf {
    cli.data_dir.clone().unwrap_or_else(ri_launcher_core::default_data_dir)
}

fn backend(dir: &std::path::Path) -> ri_launcher_core::DefaultBackend {
    let exe = std::env::current_exe().unwrap_or_default();
    let resume = format!("\"{}\" install", exe.display());
    ri_launcher_core::default_backend(dir, exe, Some(resume))
}

fn die<T, E: std::fmt::Display>(r: Result<T, E>) -> T {
    r.unwrap_or_else(|e| {
        eprintln!("error: {e}");
        std::process::exit(1)
    })
}

#[tokio::main]
async fn main() {
    tracing_subscriber::fmt().with_env_filter(tracing_subscriber::EnvFilter::from_default_env()).init();
    let cli = Cli::parse();
    let dir = data_dir(&cli);
    let launcher = Launcher::new(Arc::new(backend(&dir)), dir.clone());

    match cli.command {
        Cmd::Status => println!("{}", serde_json::to_string_pretty(&launcher.summary().await).unwrap()),
        Cmd::Preflight => println!("{:?}", die(launcher.platform().preflight().await)),
        Cmd::Install => match die(launcher.install().await) {
            InstallOutcome::Done => println!("Installed. Run `ri-launcher run` to start it."),
            InstallOutcome::NeedsReboot => println!("Restart the computer, then run `ri-launcher install` again to continue."),
        },
        Cmd::Run => {
            die(launcher.start().await);
            let s = launcher.summary().await;
            println!("Relief Inventory is running at {}", s.url);
            for u in s.share_urls {
                println!("  others can use {u}");
            }
            println!("Press Ctrl+C to stop.");
            let mut last = launcher.settings();
            loop {
                tokio::select! {
                    _ = tokio::signal::ctrl_c() => break,
                    _ = tokio::time::sleep(std::time::Duration::from_secs(3)) => {
                        if let Err(e) = launcher.follow_settings(&mut last).await {
                            eprintln!("could not apply the new settings: {e}");
                        }
                    }
                }
            }
            die(launcher.stop().await);
        }
        Cmd::Start => {
            die(launcher.start().await);
            println!("Started (the LAN proxy only lives while a launcher process runs).");
        }
        Cmd::Stop => die(launcher.stop().await),
        Cmd::Update => die(launcher.update().await),
        Cmd::Uninstall { keep_data } => die(launcher.platform().uninstall(keep_data).await),
        Cmd::SetMode { mode } => {
            let mut s = launcher.settings();
            s.mode = match mode.as_str() {
                "lan" => Mode::Lan,
                "local" => Mode::Local,
                _ => die(Err("mode must be lan or local")),
            };
            die(launcher.apply(s).await);
        }
        Cmd::SetHost { name } => {
            let mut s = launcher.settings();
            s.hostname = name;
            die(launcher.apply(s).await);
        }
        Cmd::SetPort { port } => {
            let mut s = launcher.settings();
            s.port = port;
            die(launcher.apply(s).await);
        }
        Cmd::SetTimezone { zone } => {
            let mut s = launcher.settings();
            s.timezone = zone;
            die(launcher.apply(s).await);
        }
        Cmd::Link => println!("{}", die(launcher.shareable_link())),
        Cmd::Backups { action } => {
            let p = launcher.platform();
            match action {
                BackupCmd::List => {
                    for b in die(backups::list(p.as_ref()).await) {
                        println!("{}\t{} bytes\t{}", b.name, b.size, b.modified);
                    }
                }
                BackupCmd::Create { scope } => die(backups::create_now(p.as_ref(), scope.as_deref().unwrap_or("database")).await),
                BackupCmd::Export { name, dest } => {
                    let all = die(backups::list(p.as_ref()).await);
                    let Some(b) = all.iter().find(|b| b.name == name) else { die(Err::<(), _>(format!("no backup named {name}"))) ; return };
                    let dest = dest.unwrap_or_else(|| dirs::document_dir().unwrap_or_default().join("Relief Inventory Backups"));
                    println!("{}", die(backups::export(p.as_ref(), b, &dest).await).display());
                }
                BackupCmd::Import { file } => die(backups::import(p.as_ref(), &file).await),
            }
        }
    }
}
