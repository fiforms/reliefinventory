use clap::{Parser, Subcommand};
use ri_launcher_core::backups;
use ri_launcher_core::platform::{InstallOutcome, Platform};
use ri_launcher_core::remote::client::ServerKind;
use ri_launcher_core::remote::RemoteBackups;
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
    /// Backups pulled from a remote Relief Inventory server this computer is paired with
    Remote {
        #[command(subcommand)]
        action: RemoteCmd,
    },
    /// The R Station Raspberry Pi SD card image
    Image {
        #[command(subcommand)]
        action: ImageCmd,
    },
}

#[derive(Subcommand)]
enum RemoteCmd {
    /// Pair with a server using a code from its Trust Console (kind "Backup client"), or with --instance a code from Instance Admin
    Pair {
        /// The server's address, like ri.example.org
        address: String,
        code: String,
        /// A name to show for it (default: the name the server gives itself)
        #[arg(long)]
        name: Option<String>,
        /// The code is from Instance Admin > Full Instance Backups: back up the WHOLE instance (every warehouse), not one warehouse
        #[arg(long)]
        instance: bool,
    },
    /// List paired servers, their schedules and the copies kept here
    List,
    /// Change a server's schedule or storage. Only the options you give are changed.
    Set {
        id: String,
        #[arg(long)]
        name: Option<String>,
        /// off, hourly, daily or weekly
        #[arg(long)]
        every: Option<String>,
        /// Time of day (HH:MM); for hourly only the minutes count
        #[arg(long)]
        at: Option<String>,
        /// Day of the week for weekly (mon … sun)
        #[arg(long)]
        day: Option<String>,
        /// database, or full (database and uploaded files)
        #[arg(long)]
        scope: Option<String>,
        /// How many backups to keep here
        #[arg(long)]
        keep: Option<u32>,
        /// Full path of the folder to keep them in ("default" goes back to the data folder)
        #[arg(long)]
        folder: Option<String>,
    },
    /// Make a backup now and wait for it
    Backup { id: String },
    /// The backups kept here for a server
    Files { id: String },
    /// Forget a server (its trust stays on the server until an administrator removes it there)
    Remove {
        id: String,
        /// Also delete the backups kept here
        #[arg(long)]
        delete_files: bool,
    },
    /// Run the schedule in the foreground until Ctrl+C
    Run,
}

#[derive(Subcommand)]
enum ImageCmd {
    /// Show the current image (from the published manifest) and whether it is downloaded
    Info,
    /// Download (or resume) the current image and verify its checksum
    Download,
    /// Re-check the downloaded file against the manifest's SHA-256
    Verify,
    /// Open Raspberry Pi Imager with the downloaded image chosen
    Write,
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
            // scheduled backups from paired remote servers run for as long as the launcher does
            tokio::spawn(die(RemoteBackups::new(&dir)).run_forever());
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
        Cmd::Image { action } => image_command(&dir, action).await,
        Cmd::Remote { action } => remote_command(&dir, action).await,
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

fn show_time(unix: Option<i64>) -> String {
    use chrono::TimeZone;
    unix.and_then(|t| chrono::Local.timestamp_opt(t, 0).single())
        .map(|t| t.format("%Y-%m-%d %H:%M").to_string())
        .unwrap_or_else(|| "-".into())
}

async fn remote_command(dir: &std::path::Path, action: RemoteCmd) {
    use ri_launcher_core::remote::schedule::{self, Frequency};
    use ri_launcher_core::remote::Options;
    let remote = die(RemoteBackups::new(dir));
    match action {
        RemoteCmd::Pair { address, code, name, instance } => {
            let kind = if instance { ServerKind::Instance } else { ServerKind::Warehouse };
            let paired = die(remote.pair(&address, &code, name.as_deref(), kind).await);
            println!("Paired. Its id here is {}.", paired.id);
            if let Some(w) = paired.warning {
                eprintln!("warning: {w}");
            }
            println!("Backups: daily at 02:00 by default. Change it with `ri-launcher remote set {} --every …`.", paired.id);
        }
        RemoteCmd::List => {
            let all = remote.list();
            if all.is_empty() {
                println!("Nothing is paired. Make a pairing code in the server's Trust Console (kind: Backup client), then `ri-launcher remote pair <address> <code>`.");
            }
            for v in all {
                let s = v.schedule;
                let when = match s.frequency {
                    Frequency::Off => "off".to_string(),
                    Frequency::Hourly => format!("hourly at :{:02}", s.minute),
                    Frequency::Daily => format!("daily at {:02}:{:02}", s.hour, s.minute),
                    Frequency::Weekly => format!("weekly, day {} at {:02}:{:02}", s.weekday, s.hour, s.minute),
                };
                println!("{}  {}  {}  key {}", v.id, v.name, v.url, v.fingerprint);
                println!("    {when}, {} backups, keep {} in {}", v.scope, v.keep, v.folder);
                println!("    last success {}; next {}; {} copies, {} MB", show_time(v.state.record.last_success_at), if v.state.needs_pairing { "-".into() } else { show_time(v.next_run_at) }, v.files.len(), v.total_bytes >> 20);
                if v.state.needs_pairing {
                    println!("    NOT PAIRED ANY MORE: make a new pairing code and run `ri-launcher remote pair`");
                }
                if let Some(e) = &v.state.last_error {
                    println!("    last problem: {e}");
                }
            }
        }
        RemoteCmd::Set { id, name, every, at, day, scope, keep, folder } => {
            let current = die(remote.list().into_iter().find(|v| v.id == id).ok_or("no such server")).schedule;
            let mut sched = current;
            if let Some(e) = &every {
                sched.frequency = die(schedule::parse_frequency(e));
            }
            if let Some(a) = &at {
                let (h, m) = die(schedule::parse_time(a));
                (sched.hour, sched.minute) = (h, m);
            }
            if let Some(d) = &day {
                sched.weekday = die(schedule::parse_weekday(d));
            }
            let changed = every.is_some() || at.is_some() || day.is_some();
            let dest_dir = folder.map(|f| if f == "default" { String::new() } else { f });
            die(remote.configure(&id, Options { name, schedule: changed.then_some(sched), scope, keep, dest_dir }));
            println!("Saved.");
        }
        RemoteCmd::Backup { id } => {
            let last = std::sync::Mutex::new(String::new());
            let me = std::sync::Arc::clone(&remote);
            let watcher = tokio::spawn({
                let (id, me) = (id.clone(), me.clone());
                async move {
                    loop {
                        tokio::time::sleep(std::time::Duration::from_secs(1)).await;
                        if let Some(r) = me.list().into_iter().find(|v| v.id == id).and_then(|v| v.state.running) {
                            let line = format!("{} {}", r.step, r.detail);
                            let mut l = last.lock().unwrap();
                            if *l != line {
                                eprintln!("  {line}");
                                *l = line;
                            }
                        }
                    }
                }
            });
            let out = die(remote.run_now(&id).await);
            watcher.abort();
            println!("{}{} ({} MB){}", if out.reused { "Already had " } else { "Saved " }, out.path.display(), out.size >> 20, if out.pruned > 0 { format!(", removed {} old", out.pruned) } else { String::new() });
        }
        RemoteCmd::Files { id } => {
            let v = die(remote.list().into_iter().find(|v| v.id == id).ok_or("no such server"));
            for f in v.files {
                println!("{}\t{} bytes\t{}", f.name, f.size, show_time(Some(f.modified)));
            }
        }
        RemoteCmd::Remove { id, delete_files } => {
            die(remote.remove(&id, delete_files));
            println!("Removed.");
        }
        RemoteCmd::Run => {
            println!("Running the backup schedule. Press Ctrl+C to stop.");
            tokio::select! {
                _ = remote.run_forever() => {}
                _ = tokio::signal::ctrl_c() => {}
            }
        }
    }
}

async fn image_command(dir: &std::path::Path, action: ImageCmd) {
    use ri_launcher_core::sdimage;
    let version = env!("CARGO_PKG_VERSION");
    match action {
        ImageCmd::Info => println!("{}", serde_json::to_string_pretty(&die(sdimage::info(dir, version).await)).unwrap()),
        ImageCmd::Download => {
            let m = die(sdimage::fetch_manifest(&sdimage::manifest_url()).await);
            println!("{} ({} MB)", m.filename, m.size >> 20);
            let cancel = std::sync::atomic::AtomicBool::new(false);
            let p = die(sdimage::download(&m, dir, &cancel, &|done, total| eprint!("\r  {} of {} MB", done >> 20, total >> 20)).await);
            eprintln!();
            println!("Downloaded and verified: {}", p.display());
        }
        ImageCmd::Verify => {
            let m = die(sdimage::fetch_manifest(&sdimage::manifest_url()).await);
            if sdimage::verify(dir, &m).await {
                println!("OK: {} matches the manifest.", m.filename);
            } else {
                eprintln!("error: {} is missing or does not match the manifest.", m.filename);
                std::process::exit(1);
            }
        }
        ImageCmd::Write => {
            let m = die(sdimage::fetch_manifest(&sdimage::manifest_url()).await);
            if !sdimage::is_downloaded(dir, &m) {
                die::<(), _>(Err("download the image first (ri-launcher image download)"));
            }
            die(sdimage::open_in_imager(&sdimage::image_path(dir, &m)));
            println!("Raspberry Pi Imager opened.");
        }
    }
}
