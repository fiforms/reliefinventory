//! RI Launcher desktop shell: a tray icon and a window around `ri-launcher-core`.
//!
//! Everything that installs, starts, stops, edits settings or touches backups lives in the core crate
//! (and is tested there); this file wires it to the window (commands), the tray, start-at-login,
//! single-instance behaviour and the elevated `--install` pass (Windows; a Mac installs in-process).

#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use std::path::PathBuf;
use std::sync::Arc;
use std::time::Duration;

use ri_launcher_core::backups::{self, BackupFile};
use ri_launcher_core::platform::{InstallOutcome, Platform, State as RunState};
use ri_launcher_core::service::{Launcher, Summary};
use ri_launcher_core::settings::{Mode, Settings};
use ri_launcher_core::status::{self, InstallStatus};
use ri_launcher_core::DefaultBackend;
use tauri::menu::{Menu, MenuItem};
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager, State, WindowEvent};
use tauri_plugin_autostart::{MacosLauncher, ManagerExt};
use tauri_plugin_opener::OpenerExt;

const TRAY_ID: &str = "main";

type L = Launcher<DefaultBackend>;

struct AppState {
    launcher: Arc<L>,
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

/// Install needs administrator rights, so it runs in an elevated copy of this program (`--install`),
/// which reports through the status file that `get_install_status` reads.
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

/// A Mac needs no administrator rights, so the install runs in this process — still reporting through the
/// status file, so the window shows progress exactly as it does on Windows.
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

/// `mode` is "lan" or "local"; the other fields are applied as given (None clears hostname/timezone).
#[tauri::command]
async fn apply_settings(
    state: State<'_, AppState>,
    mode: String,
    hostname: Option<String>,
    port: u16,
    timezone: Option<String>,
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
async fn backups_list(state: State<'_, AppState>) -> Result<Vec<BackupFile>, String> {
    backups::list(state.launcher.platform().as_ref()).await.map_err(err)
}

#[tauri::command]
async fn backup_create(state: State<'_, AppState>, scope: String) -> Result<(), String> {
    backups::create_now(state.launcher.platform().as_ref(), &scope).await.map_err(err)
}

/// Copies one backup to Documents\Relief Inventory Backups as a read-only file and shows it in the file manager.
#[tauri::command]
async fn backup_export(app: AppHandle, state: State<'_, AppState>, name: String) -> Result<String, String> {
    let p = state.launcher.platform();
    let all = backups::list(p.as_ref()).await.map_err(err)?;
    let b = all.iter().find(|b| b.name == name).ok_or("That backup no longer exists.")?;
    let dest = app
        .path()
        .document_dir()
        .map_err(err)?
        .join("Relief Inventory Backups");
    let saved = backups::export(p.as_ref(), b, &dest).await.map_err(err)?;
    let _ = app.opener().reveal_item_in_dir(&saved);
    Ok(saved.to_string_lossy().into_owned())
}

#[tauri::command]
async fn backup_import(state: State<'_, AppState>, path: String) -> Result<(), String> {
    backups::import(state.launcher.platform().as_ref(), std::path::Path::new(&path)).await.map_err(err)
}

#[cfg(windows)]
#[tauri::command]
fn restart_computer() -> Result<(), String> {
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
            get_summary, get_install_status, start_install, dismiss_install_status, start, stop, update, uninstall,
            get_settings, apply_settings, shareable_link, open_app, backups_list, backup_create, backup_export,
            backup_import, restart_computer, get_autostart, set_autostart, app_version
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
            app.manage(AppState { launcher: launcher.clone() });

            let open = MenuItem::with_id(app, "open_app", "Open Relief Inventory", true, None::<&str>)?;
            let show = MenuItem::with_id(app, "show", "Show RI Launcher", true, None::<&str>)?;
            let quit = MenuItem::with_id(app, "quit", "Quit (others can't connect until it's reopened)", true, None::<&str>)?;
            let menu = Menu::with_items(app, &[&open, &show, &quit])?;
            let l2 = launcher.clone();
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
                    "quit" => app.exit(0),
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
            let (handle, l4) = (app.handle().clone(), launcher.clone());
            tauri::async_runtime::spawn(async move {
                loop {
                    let s = l4.summary().await;
                    if let Some(tray) = handle.tray_by_id(TRAY_ID) {
                        let _ = tray.set_tooltip(Some(tooltip(&s)));
                        let _ = tray.set_icon(Some(tray_icon(s.state == RunState::Running)));
                    }
                    tokio::time::sleep(Duration::from_secs(10)).await;
                }
            });
            Ok(())
        })
        .build(tauri::generate_context!())
        .expect("error while running RI Launcher")
        .run(|_app, _event| {
            // clicking the Dock icon while the window is hidden brings it back
            #[cfg(target_os = "macos")]
            if let tauri::RunEvent::Reopen { .. } = _event {
                show_main(_app);
            }
        });
}
