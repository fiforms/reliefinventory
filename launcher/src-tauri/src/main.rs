//! RI Launcher desktop shell: a tray icon and a window around `ri-launcher-core`.
//!
//! Everything that installs, starts, stops, edits settings or touches backups lives in the core crate
//! (and is tested there); this file wires it to the window (commands), the tray, start-at-login,
//! single-instance behaviour and the elevated `--install` pass (Windows; a Mac installs in-process).

#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use std::path::PathBuf;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use ri_launcher_core::backups::{self, BackupFile};
use ri_launcher_core::platform::{InstallOutcome, Platform, State as RunState};
use ri_launcher_core::remote::client::ServerKind;
use ri_launcher_core::remote::{Options as RemoteOptions, Paired, RemoteBackups, ServerView};
use ri_launcher_core::sdimage::{self, ImageInfo};
use ri_launcher_core::service::{Launcher, Summary};
use ri_launcher_core::settings::{Channel, Mode, Settings};
use ri_launcher_core::updater::Checked;
use ri_launcher_core::status::{self, InstallStatus};
use ri_launcher_core::DefaultBackend;
use tauri::menu::{Menu, MenuItem};
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Emitter, Manager, State, WindowEvent};
use tauri_plugin_autostart::{MacosLauncher, ManagerExt};
use tauri_plugin_opener::OpenerExt;

const TRAY_ID: &str = "main";

type L = Launcher<DefaultBackend>;

struct AppState {
    launcher: Arc<L>,
    /// Remote servers this computer is paired with, whose backups it pulls on a schedule.
    remote: Arc<RemoteBackups>,
    image: Arc<Mutex<ImageDownload>>,
    image_cancel: Arc<AtomicBool>,
}

/// Progress of the R Station image download, polled by the window (the download itself runs in a task here, so it
/// carries on if the tab is changed).
#[derive(Default, Clone, serde::Serialize)]
struct ImageDownload {
    running: bool,
    done: u64,
    total: u64,
    error: Option<String>,
    finished: bool,
}

fn data_dir() -> PathBuf {
    ri_launcher_core::default_data_dir()
}

fn make_launcher() -> Arc<L> {
    let dir = data_dir();
    let exe = std::env::current_exe().unwrap_or_default();
    // if setup is waiting on a restart, signing back in opens this window, which offers "Continue"
    let resume = format!("\"{}\" --resume", exe.display());
    Arc::new(Launcher::new(Arc::new(ri_launcher_core::default_backend(&dir, exe, Some(resume))), dir))
}

fn err<E: std::fmt::Display>(e: E) -> String {
    e.to_string()
}

#[tauri::command]
async fn get_summary(state: State<'_, AppState>) -> Result<Summary, String> {
    Ok(state.launcher.summary().await)
}

#[tauri::command]
fn get_install_status(state: State<'_, AppState>) -> Option<InstallStatus> {
    status::read(state.launcher.dir())
}

/// On Windows, install needs administrator rights, so it runs in an elevated copy of this program
/// (`--install`), which reports through the status file that `get_install_status` reads.
#[cfg(windows)]
#[tauri::command]
async fn start_install(state: State<'_, AppState>) -> Result<(), String> {
    let dir = state.launcher.dir().to_path_buf();
    status::write(&dir, &InstallStatus { running: true, step: "Waiting for permission".into(), detail: "Approve the Windows prompt to continue.".into(), ..Default::default() });
    let exe = std::env::current_exe().map_err(err)?;
    ri_launcher_core::elevate::relaunch_elevated(&exe, &["--install"]).map_err(|e| {
        status::clear(&dir);
        match e.kind() {
            std::io::ErrorKind::PermissionDenied => "Installing needs permission from an administrator. Try again and choose Yes.".to_string(),
            _ => e.to_string(),
        }
    })
}

/// Off Windows nothing needs administrator rights (a Mac, or Docker on Linux), so the install runs in this process — still
/// reporting through the status file, so the window shows progress exactly as it does on Windows.
#[cfg(not(windows))]
#[tauri::command]
async fn start_install(state: State<'_, AppState>) -> Result<(), String> {
    let launcher = state.launcher.clone();
    status::write(launcher.dir(), &InstallStatus { running: true, step: "Starting".into(), ..Default::default() });
    tauri::async_runtime::spawn(async move {
        if let Err(e) = launcher.install().await {
            tracing::error!("install failed: {e}");
        }
    });
    Ok(())
}

/// Why this computer can't be installed on yet (Docker missing, not running, …) in plain words, or nothing if
/// it is ready.
#[tauri::command]
async fn get_preflight(state: State<'_, AppState>) -> Result<Option<String>, String> {
    use ri_launcher_core::platform::Preflight;
    Ok(match state.launcher.platform().preflight().await.map_err(err)? {
        Preflight::Ok => None,
        Preflight::Blocked(why) => Some(why),
    })
}

/// An install that ran in this process can't still be running when the program starts, so a status file
/// that says so is left from a quit or crash mid-install: turn it into a "try again" (install resumes).
#[cfg(not(windows))]
fn recover_interrupted_install(dir: &std::path::Path) {
    if status::read(dir).is_some_and(|s| s.running) {
        status::write(dir, &InstallStatus {
            step: "Install interrupted".into(),
            error: Some("Setup stopped before it finished (RI Launcher was closed). Press Try again to continue where it left off.".into()),
            ..Default::default()
        });
    }
}

#[tauri::command]
fn dismiss_install_status(state: State<'_, AppState>) {
    status::clear(state.launcher.dir());
}

#[tauri::command]
async fn start(state: State<'_, AppState>) -> Result<(), String> {
    state.launcher.start().await.map_err(err)
}

#[tauri::command]
async fn stop(state: State<'_, AppState>) -> Result<(), String> {
    state.launcher.stop().await.map_err(err)
}

#[tauri::command]
async fn update(state: State<'_, AppState>) -> Result<(), String> {
    state.launcher.update().await.map_err(err)
}

#[tauri::command]
async fn uninstall(state: State<'_, AppState>, keep_data: bool) -> Result<(), String> {
    state.launcher.stop().await.map_err(err)?;
    state.launcher.platform().uninstall(keep_data).await.map_err(err)?;
    status::clear(state.launcher.dir());
    Ok(())
}

#[tauri::command]
fn get_settings(state: State<'_, AppState>) -> Settings {
    state.launcher.settings()
}

/// `mode` is "lan" or "local"; the other fields are applied as given (None clears hostname/timezone); `channel` is "latest" or "edge".
#[tauri::command]
async fn apply_settings(
    state: State<'_, AppState>,
    mode: String,
    hostname: Option<String>,
    port: u16,
    timezone: Option<String>,
    channel: String,
) -> Result<(), String> {
    let norm = |s: Option<String>| s.map(|v| v.trim().to_string()).filter(|v| !v.is_empty());
    let settings = Settings {
        mode: match mode.as_str() {
            "lan" => Mode::Lan,
            "local" => Mode::Local,
            _ => return Err("Unknown mode".into()),
        },
        hostname: norm(hostname),
        port,
        timezone: norm(timezone),
        channel: match channel.as_str() {
            "latest" => Channel::Latest,
            "edge" => Channel::Edge,
            _ => return Err("Unknown channel".into()),
        },
    };
    state.launcher.apply(settings).await.map_err(err)
}

#[tauri::command]
fn shareable_link(state: State<'_, AppState>) -> Result<String, String> {
    state.launcher.shareable_link().map_err(err)
}

/// Opens the app in the default browser using this computer's own address, which works in both modes.
#[tauri::command]
fn open_app(app: AppHandle, state: State<'_, AppState>) -> Result<(), String> {
    open_local(&app, &state.launcher)
}

fn open_local(app: &AppHandle, launcher: &L) -> Result<(), String> {
    let url = format!("http://localhost:{}", launcher.settings().port);
    app.opener().open_url(url, None::<&str>).map_err(|e| format!("Couldn't open the browser: {e}"))
}

#[tauri::command]
async fn open_terminal(state: State<'_, AppState>) -> Result<(), String> {
    state.launcher.platform().open_terminal().await.map_err(err)
}

#[tauri::command]
async fn backups_list(state: State<'_, AppState>) -> Result<Vec<BackupFile>, String> {
    backups::list(state.launcher.platform().as_ref()).await.map_err(err)
}

#[tauri::command]
async fn backup_create(state: State<'_, AppState>, scope: String) -> Result<(), String> {
    backups::create_now(state.launcher.platform().as_ref(), &scope).await.map_err(err)
}

/// Copies one backup to Documents/Relief Inventory Backups as a read-only file and shows it in the file manager.
#[tauri::command]
async fn backup_export(app: AppHandle, state: State<'_, AppState>, name: String) -> Result<String, String> {
    let p = state.launcher.platform();
    let all = backups::list(p.as_ref()).await.map_err(err)?;
    let b = all.iter().find(|b| b.name == name).ok_or("That backup no longer exists.")?;
    let dest = app
        .path()
        .document_dir()
        .or_else(|_| app.path().home_dir()) // some Linux desktops have no Documents folder
        .map_err(err)?
        .join("Relief Inventory Backups");
    let saved = backups::export(p.as_ref(), b, &dest).await.map_err(err)?;
    let _ = app.opener().reveal_item_in_dir(&saved);
    Ok(saved.to_string_lossy().into_owned())
}

fn backups_folder(app: &AppHandle) -> Result<std::path::PathBuf, String> {
    Ok(app.path().document_dir().or_else(|_| app.path().home_dir()).map_err(err)?.join("Relief Inventory Backups"))
}

/// Copies every backup (including the whole-instance ones) to Documents/Relief Inventory Backups, skipping ones already
/// there, and shows the folder. Returns "<n> copied, <m> already there".
#[tauri::command]
async fn backup_export_all(app: AppHandle, state: State<'_, AppState>) -> Result<String, String> {
    let dest = backups_folder(&app)?;
    std::fs::create_dir_all(&dest).map_err(err)?;
    let r = backups::export_all(state.launcher.platform().as_ref(), &dest).await.map_err(err)?;
    let _ = app.opener().open_path(dest.to_string_lossy(), None::<&str>);
    let mut msg = format!("{} copied, {} already in the folder.", r.copied, r.already_there);
    if r.failed > 0 {
        msg.push_str(&format!(" {} could not be copied.", r.failed));
    }
    Ok(msg)
}

/// Opens the folder that "Save a copy" and "Save all" write to.
#[tauri::command]
fn backup_open_folder(app: AppHandle) -> Result<(), String> {
    let dest = backups_folder(&app)?;
    std::fs::create_dir_all(&dest).map_err(err)?;
    app.opener().open_path(dest.to_string_lossy(), None::<&str>).map_err(|e| e.to_string())
}

#[tauri::command]
async fn check_update(state: State<'_, AppState>) -> Result<Checked, String> {
    state.launcher.check_update().await.map_err(err)
}

#[tauri::command]
async fn install_update(state: State<'_, AppState>) -> Result<Checked, String> {
    state.launcher.install_update().await.map_err(err)
}

#[tauri::command]
async fn backup_import(state: State<'_, AppState>, path: String) -> Result<(), String> {
    backups::import(state.launcher.platform().as_ref(), std::path::Path::new(&path)).await.map_err(err)
}

// ---- Remote backups: servers this computer is paired with (federation backup trust) ----

#[tauri::command]
fn remote_list(state: State<'_, AppState>) -> Vec<ServerView> {
    state.remote.list()
}

#[tauri::command]
async fn remote_pair(state: State<'_, AppState>, address: String, code: String, name: Option<String>, kind: Option<String>) -> Result<Paired, String> {
    let kind = ServerKind::parse(kind.as_deref().unwrap_or("")).ok_or("Choose a warehouse or the whole instance.")?;
    state.remote.pair(&address, &code, name.as_deref(), kind).await.map_err(err)
}

#[tauri::command]
fn remote_configure(state: State<'_, AppState>, id: String, options: RemoteOptions) -> Result<(), String> {
    state.remote.configure(&id, options)
}

/// Starts a backup in the background; the window follows `remote_list` for its progress.
#[tauri::command]
fn remote_backup_now(state: State<'_, AppState>, id: String) -> Result<(), String> {
    state.remote.start_now(&id)
}

#[tauri::command]
fn remote_remove(state: State<'_, AppState>, id: String, delete_files: bool) -> Result<(), String> {
    state.remote.remove(&id, delete_files)
}

/// Shows the folder holding a server's copies in the file manager (created first, so there is something to open).
#[tauri::command]
fn remote_reveal(app: AppHandle, state: State<'_, AppState>, id: String) -> Result<(), String> {
    let folder = state.remote.folder(&id).ok_or("That server isn't in the list.")?;
    std::fs::create_dir_all(&folder).map_err(err)?;
    app.opener().open_path(folder.to_string_lossy(), None::<&str>).map_err(err)
}

#[tauri::command]
async fn image_info(app: AppHandle, state: State<'_, AppState>) -> Result<ImageInfo, String> {
    sdimage::info(state.launcher.dir(), &app.package_info().version.to_string()).await.map_err(err)
}

#[tauri::command]
fn image_download_status(state: State<'_, AppState>) -> ImageDownload {
    state.image.lock().map(|s| s.clone()).unwrap_or_default()
}

/// Starts (or resumes) the download in the background; the window follows `image_download_status`.
#[tauri::command]
async fn image_download_start(state: State<'_, AppState>) -> Result<(), String> {
    {
        let mut s = state.image.lock().map_err(err)?;
        if s.running {
            return Ok(());
        }
        *s = ImageDownload { running: true, ..Default::default() };
    }
    state.image_cancel.store(false, Ordering::Relaxed);
    let (shared, cancel, dir) = (state.image.clone(), state.image_cancel.clone(), state.launcher.dir().to_path_buf());
    tauri::async_runtime::spawn(async move {
        let set = |f: &dyn Fn(&mut ImageDownload)| {
            if let Ok(mut s) = shared.lock() {
                f(&mut s)
            }
        };
        let result = match sdimage::fetch_manifest(&sdimage::manifest_url()).await {
            Ok(m) => {
                set(&|s| s.total = m.size);
                let progress = |done: u64, total: u64| set(&|s| {
                    s.done = done;
                    s.total = total;
                });
                sdimage::download(&m, &dir, &cancel, &progress).await.map(|_| ())
            }
            Err(e) => Err(e),
        };
        set(&|s| {
            s.running = false;
            match &result {
                Ok(()) => s.finished = true,
                Err(e) => s.error = Some(e.to_string()),
            }
        });
    });
    Ok(())
}

#[tauri::command]
fn image_download_cancel(state: State<'_, AppState>) {
    state.image_cancel.store(true, Ordering::Relaxed);
}

/// Opens Raspberry Pi Imager with the downloaded image chosen. Re-reads the (cached) manifest rather than trusting a
/// path from the window.
#[tauri::command]
async fn image_write(state: State<'_, AppState>) -> Result<(), String> {
    let m = sdimage::fetch_manifest(&sdimage::manifest_url()).await.map_err(err)?;
    if !sdimage::is_downloaded(state.launcher.dir(), &m) {
        return Err("The image hasn't been downloaded and checked yet.".into());
    }
    sdimage::open_in_imager(&sdimage::image_path(state.launcher.dir(), &m)).map_err(err)
}

#[tauri::command]
async fn image_reveal(app: AppHandle, state: State<'_, AppState>) -> Result<(), String> {
    let m = sdimage::fetch_manifest(&sdimage::manifest_url()).await.map_err(err)?;
    app.opener().reveal_item_in_dir(sdimage::image_path(state.launcher.dir(), &m)).map_err(|e| e.to_string())
}

/// The two download pages the window may open (a fixed list, never an address from the page).
#[tauri::command]
fn open_tool_page(app: AppHandle, which: String) -> Result<(), String> {
    let url = match which.as_str() {
        "imager" => sdimage::IMAGER_URL,
        "etcher" => sdimage::ETCHER_URL,
        _ => return Err("Unknown page".into()),
    };
    app.opener().open_url(url, None::<&str>).map_err(|e| format!("Couldn't open the browser: {e}"))
}

#[cfg(windows)]
#[tauri::command]
fn restart_computer() -> Result<(), String> {
    if !cfg!(windows) {
        return Err("Restarting is only needed on Windows.".into());
    }
    std::process::Command::new("shutdown")
        .args(["/r", "/t", "20", "/c", "Restarting to finish setting up Relief Inventory"])
        .spawn()
        .map(|_| ())
        .map_err(err)
}

/// Setup never asks a Mac to restart, so the window never offers this there.
#[cfg(not(windows))]
#[tauri::command]
fn restart_computer() -> Result<(), String> {
    Err("Restarting is only needed on Windows.".into())
}

#[tauri::command]
fn get_autostart(app: AppHandle) -> bool {
    app.autolaunch().is_enabled().unwrap_or(false)
}

#[tauri::command]
fn set_autostart(app: AppHandle, enabled: bool) -> Result<bool, String> {
    let al = app.autolaunch();
    let r = if enabled { al.enable() } else { al.disable() };
    r.map_err(|e| format!("Couldn't change the start-at-login setting: {e}"))?;
    Ok(al.is_enabled().unwrap_or(enabled))
}

#[tauri::command]
fn app_version(app: AppHandle) -> String {
    app.package_info().version.to_string()
}

/// On a Mac the launcher is a menu-bar app while its window is hidden (no Dock icon for a window that
/// isn't there), and a normal app while the window is open.
#[cfg(target_os = "macos")]
fn set_dock_icon(app: &AppHandle, visible: bool) {
    let policy = if visible { tauri::ActivationPolicy::Regular } else { tauri::ActivationPolicy::Accessory };
    let _ = app.set_activation_policy(policy);
}

#[cfg(not(target_os = "macos"))]
fn set_dock_icon(_app: &AppHandle, _visible: bool) {}

fn show_main(app: &AppHandle) {
    set_dock_icon(app, true);
    if let Some(w) = app.get_webview_window("main") {
        let _ = w.show();
        let _ = w.unminimize();
        let _ = w.set_focus();
    }
}

fn tray_icon(running: bool) -> tauri::image::Image<'static> {
    let bytes: &[u8] = if running { include_bytes!("../icons/tray/ok.png") } else { include_bytes!("../icons/tray/idle.png") };
    tauri::image::Image::from_bytes(bytes).expect("embedded tray icon is a valid PNG")
}

const QUIT_LABEL: &str = "Quit";
const QUIT_AND_STOP_LABEL: &str = "Shut Down Server and Quit";

/// "Quit" when no server is running; one combined item when it is, so quitting can't silently leave Docker (or the LAN
/// proxy's absence) behind.
fn quit_label(state: RunState) -> &'static str {
    if state == RunState::Running {
        QUIT_AND_STOP_LABEL
    } else {
        QUIT_LABEL
    }
}

/// The tray's Quit: shuts the server down first if it is running (whatever the label last said), and only exits once
/// that worked. If it fails the app stays open and the window says why.
fn quit_app(app: &AppHandle, launcher: Arc<L>, item: MenuItem<tauri::Wry>) {
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        if launcher.platform().state().await.ok() == Some(RunState::Running) {
            let _ = item.set_enabled(false);
            let _ = item.set_text("Shutting down…");
            if let Err(e) = launcher.stop().await {
                tracing::warn!("could not shut down on quit: {e}");
                let _ = item.set_enabled(true);
                let _ = item.set_text(QUIT_AND_STOP_LABEL);
                show_main(&app);
                let _ = app.emit("tray-error", format!("Couldn't shut the server down, so RI Launcher stayed open: {e}"));
                return;
            }
        }
        app.exit(0);
    });
}

fn tooltip(s: &Summary) -> String {
    match s.state {
        RunState::NotInstalled => "RI Launcher — not installed".into(),
        RunState::Stopped => "RI Launcher — stopped".into(),
        RunState::Running => match s.share_urls.first() {
            Some(u) => format!("RI Launcher — running · others: {u}"),
            None => "RI Launcher — running (this computer only)".into(),
        },
    }
}

/// The elevated pass: do the install, report through the status file, exit. No window.
fn run_install_headless() -> i32 {
    let launcher = make_launcher();
    let rt = tokio::runtime::Builder::new_multi_thread().enable_all().build().expect("runtime");
    match rt.block_on(launcher.install()) {
        Ok(InstallOutcome::Done) | Ok(InstallOutcome::NeedsReboot) => 0,
        Err(e) => {
            tracing::error!("install failed: {e}");
            1
        }
    }
}

fn main() {
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::try_from_default_env().unwrap_or_else(|_| "info".into()))
        .init();

    if std::env::args().any(|a| a == "--install") {
        std::process::exit(run_install_headless());
    }

    tauri::Builder::default()
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| show_main(app)))
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_autostart::init(MacosLauncher::LaunchAgent, Some(vec!["--hidden"])))
        .invoke_handler(tauri::generate_handler![
            get_summary, get_install_status, start_install, get_preflight, dismiss_install_status, start, stop, update, uninstall,
            get_settings, apply_settings, shareable_link, open_app, open_terminal, backups_list, backup_create, backup_export,
            backup_import, backup_export_all, backup_open_folder, check_update, install_update, restart_computer, get_autostart, set_autostart, app_version, image_info, image_download_status,
            image_download_start, image_download_cancel, image_write, image_reveal, open_tool_page, remote_list, remote_pair,
            remote_configure, remote_backup_now, remote_remove, remote_reveal
        ])
        .on_window_event(|window, event| {
            // The window is just a view; closing it leaves Relief Inventory (and its LAN proxy) running in the tray.
            if let WindowEvent::CloseRequested { api, .. } = event {
                api.prevent_close();
                let _ = window.hide();
                set_dock_icon(window.app_handle(), false);
            }
        })
        .setup(|app| {
            let launcher = make_launcher();
            #[cfg(not(windows))]
            recover_interrupted_install(launcher.dir());
            let remote = RemoteBackups::new(&data_dir()).map_err(|e| format!("could not set up remote backups: {e}"))?;
            // scheduled backups of paired remote servers run for as long as the launcher does (it lives in the tray)
            tauri::async_runtime::spawn(remote.clone().run_forever());
            // ...and so does the answer to the app's own update, check and backup requests (Updates & Backups page)
            tauri::async_runtime::spawn(launcher.clone().serve_update_requests());
            app.manage(AppState { launcher: launcher.clone(), remote, image: Arc::default(), image_cancel: Arc::default() });

            let open = MenuItem::with_id(app, "open_app", "Open Relief Inventory", true, None::<&str>)?;
            let show = MenuItem::with_id(app, "show", "Show RI Launcher", true, None::<&str>)?;
            let quit = MenuItem::with_id(app, "quit", QUIT_LABEL, true, None::<&str>)?;
            let menu = Menu::with_items(app, &[&open, &show, &quit])?;
            let (l2, quit2) = (launcher.clone(), quit.clone());
            TrayIconBuilder::<tauri::Wry>::with_id(TRAY_ID)
                .tooltip("RI Launcher")
                .icon(tray_icon(false))
                .menu(&menu)
                // a Mac's menu-bar icons open their menu on click; Windows' tray opens the window on click
                .show_menu_on_left_click(cfg!(target_os = "macos"))
                .on_menu_event(move |app, e| match e.id.as_ref() {
                    "open_app" => {
                        let _ = open_local(app, &l2);
                    }
                    "show" => show_main(app),
                    "quit" => quit_app(app, l2.clone(), quit2.clone()),
                    _ => {}
                })
                .on_tray_icon_event(|tray, event| {
                    if cfg!(target_os = "macos") {
                        return;
                    }
                    if let TrayIconEvent::Click { button: MouseButton::Left, button_state: MouseButtonState::Up, .. } = event {
                        show_main(tray.app_handle());
                    }
                })
                .build(app)?;

            // Signed-in launch (--hidden): stay in the tray and bring Relief Inventory up so sharing resumes
            // without anyone opening the window. Otherwise show the window.
            let at_login = std::env::args().any(|a| a == "--hidden");
            if at_login {
                set_dock_icon(app.handle(), false);
            } else {
                show_main(app.handle());
            }
            let l3 = launcher.clone();
            tauri::async_runtime::spawn(async move {
                if at_login && l3.platform().state().await.ok() == Some(RunState::Stopped) {
                    if let Err(e) = l3.start().await {
                        tracing::warn!("could not start at sign-in: {e}");
                    }
                }
                let _ = l3.adopt().await;
            });

            // Keep the tray tooltip/icon in step with reality.
            let (handle, l4, quit3) = (app.handle().clone(), launcher.clone(), quit.clone());
            tauri::async_runtime::spawn(async move {
                loop {
                    let s = l4.summary().await;
                    if let Some(tray) = handle.tray_by_id(TRAY_ID) {
                        let _ = tray.set_tooltip(Some(tooltip(&s)));
                        let _ = tray.set_icon(Some(tray_icon(s.state == RunState::Running)));
                        let _ = quit3.set_text(quit_label(s.state));
                    }
                    tokio::time::sleep(Duration::from_secs(10)).await;
                }
            });
            Ok(())
        })
        .build(tauri::generate_context!())
        .expect("error while running RI Launcher")
        .run(|app, event| {
            // clicking the Dock icon while the window is hidden brings it back
            #[cfg(target_os = "macos")]
            if let tauri::RunEvent::Reopen { .. } = event {
                show_main(app);
            }
            if let tauri::RunEvent::ExitRequested { api, code: None, .. } = event {
                keep_running_while_serving(app, api);
            }
        });
}

/// macOS Cmd-Q and the Dock's Quit ask the app to exit without going through the tray menu, which would stop the
/// LAN proxy while Docker keeps running. Treat them like closing the window while the server is running, or while a
/// scheduled backup of a remote server is waiting to happen (stay in the
/// menu bar; the tray's "Shut Down Server and Quit" is the way out) and quit normally when it isn't. Only a request
/// from outside is held back: our own `app.exit(0)` carries an exit code, so the tray's Quit still works. Other
/// platforms have no such path (closing the window already hides it), and holding back a logoff would be wrong.
fn keep_running_while_serving(app: &AppHandle, api: tauri::ExitRequestApi) {
    if !cfg!(target_os = "macos") {
        return;
    }
    api.prevent_exit();
    let app = app.clone();
    let (launcher, remote) = (app.state::<AppState>().launcher.clone(), app.state::<AppState>().remote.clone());
    tauri::async_runtime::spawn(async move {
        // a scheduled backup that never runs because the program quit is as bad as a stopped proxy
        if launcher.platform().state().await.ok() == Some(RunState::Running) || remote.has_active_schedule() {
            if let Some(w) = app.get_webview_window("main") {
                let _ = w.hide();
            }
            set_dock_icon(&app, false);
        } else {
            app.exit(0);
        }
    });
}
