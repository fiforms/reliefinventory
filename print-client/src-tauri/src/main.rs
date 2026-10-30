//! RI Print desktop shell: a tray icon and a small window around `ri-print-core`'s engine.
//!
//! All protocol and printing behaviour lives in the core crate (and is tested there); this file only
//! wires the engine to the window (commands + a `status` event), the tray, start-at-login and
//! single-instance behaviour.

#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use std::path::PathBuf;
use std::sync::Arc;

use ri_print_core::backend::default_backend;
use ri_print_core::{Engine, Options, Status};
use tauri::menu::{Menu, MenuItem};
use tauri::path::BaseDirectory;
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Emitter, Manager, State, WindowEvent};
use tauri_plugin_autostart::{MacosLauncher, ManagerExt};
use tauri_plugin_opener::OpenerExt;

const TRAY_ID: &str = "main";

struct AppState {
    engine: Arc<Engine>,
}

#[tauri::command]
fn get_status(state: State<'_, AppState>) -> Status {
    state.engine.status()
}

/// Errors are already worded for the person typing the code.
#[tauri::command]
async fn pair(
    state: State<'_, AppState>,
    server_url: String,
    code: String,
    device_name: Option<String>,
) -> Result<(), String> {
    state.engine.pair(&server_url, &code, device_name.as_deref()).await
}

#[tauri::command]
fn unpair(state: State<'_, AppState>) {
    state.engine.unpair();
}

#[tauri::command]
fn poll_now(state: State<'_, AppState>) {
    state.engine.poll_now();
}

#[tauri::command]
fn open_portal_cmd(app: AppHandle) {
    open_portal(&app);
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

/// The only places the About screen may send the browser to; the window never opens arbitrary URLs.
const EXTERNAL_LINKS: &[&str] = &[
    "https://github.com/fiforms/reliefinventory",
    "https://github.com/fiforms/reliefinventory/releases",
    "https://www.gnu.org/licenses/gpl-3.0.html",
    "https://www.sumatrapdfreader.org",
    "https://github.com/sumatrapdfreader/sumatrapdf",
];

#[tauri::command]
fn app_version() -> String {
    // the release tag, or the commit hash for an untagged build (build.rs); the package version is only a number for installers
    env!("RI_BUILD_VERSION").to_string()
}

#[tauri::command]
fn open_external(app: AppHandle, url: String) -> Result<(), String> {
    if !EXTERNAL_LINKS.contains(&url.as_str()) {
        return Err("That link isn't allowed.".into());
    }
    app.opener().open_url(url, None::<&str>).map_err(|e| format!("Couldn't open the link: {e}"))
}

/// Open this OS's own printer management, which is different on each platform.
#[tauri::command]
fn open_printer_settings(app: AppHandle) -> Result<(), String> {
    use std::process::Command;
    let spawn = |prog: &str, args: &[&str]| Command::new(prog).args(args).spawn().is_ok();
    let opened = if cfg!(windows) {
        spawn("explorer.exe", &["ms-settings:printers"])
    } else if cfg!(target_os = "macos") {
        spawn("open", &["x-apple.systempreferences:com.apple.preference.printfax"])
    } else {
        // Whichever desktop's printer tool is installed, else CUPS's own web page (always there with CUPS).
        spawn("system-config-printer", &[])
            || spawn("gnome-control-center", &["printers"])
            || spawn("kcmshell6", &["kcm_printer_manager"])
            || spawn("kcmshell5", &["kcm_printer_manager"])
            || app.opener().open_url("http://localhost:631/printers/", None::<&str>).is_ok()
    };
    if opened { Ok(()) } else { Err("Couldn't open this computer's printer settings.".into()) }
}

fn show_main(app: &AppHandle) {
    if let Some(w) = app.get_webview_window("main") {
        let _ = w.show();
        let _ = w.unminimize();
        let _ = w.set_focus();
    }
}

/// Open the paired server's dashboard in the system's default browser.
fn open_portal(app: &AppHandle) {
    let Some(base) = app.state::<AppState>().engine.server_url() else { return };
    // `server_url` is normalised at pairing (http/https, no trailing slash).
    if let Err(e) = app.opener().open_url(format!("{base}/dashboard"), None::<&str>) {
        tracing::warn!("couldn't open the portal in a browser: {e}");
    }
}

/// Tray icon variants: the app icon plus a status dot. Embedded so nothing extra ships in the installer.
fn tray_icon(s: &Status) -> tauri::image::Image<'static> {
    use ri_print_core::types::PrinterState;
    let bytes: &[u8] = if !s.paired {
        include_bytes!("../icons/tray/idle.png")
    } else if s.last_error.is_some() {
        include_bytes!("../icons/tray/problem.png")
    } else if s.printers.iter().any(|p| matches!(p.state, PrinterState::Error | PrinterState::Offline)) {
        include_bytes!("../icons/tray/attention.png")
    } else {
        include_bytes!("../icons/tray/ok.png")
    };
    tauri::image::Image::from_bytes(bytes).expect("embedded tray icon is a valid PNG")
}

fn tooltip(s: &Status) -> String {
    if !s.paired {
        return "RI Print — not paired".into();
    }
    let n = s.printers.len();
    let problems = s
        .printers
        .iter()
        .filter(|p| matches!(p.state, ri_print_core::types::PrinterState::Error | ri_print_core::types::PrinterState::Offline))
        .count();
    let mut t = format!("RI Print — {n} printer{}", if n == 1 { "" } else { "s" });
    if problems > 0 {
        t.push_str(&format!(", {problems} need attention"));
    }
    if let Some(e) = &s.last_error {
        t.push_str(&format!(" · {e}"));
    }
    t
}

/// SumatraPDF ships next to the app on Windows only (see `src-tauri/resources/README.md`).
fn sumatra_path(app: &AppHandle) -> Option<PathBuf> {
    if !cfg!(windows) {
        return None;
    }
    app.path().resolve("resources/SumatraPDF.exe", BaseDirectory::Resource).ok().filter(|p| p.exists())
}

fn main() {
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::try_from_default_env().unwrap_or_else(|_| "info".into()))
        .init();

    tauri::Builder::default()
        // A second launch (double-clicking the icon again) just brings up the first one's window.
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| show_main(app)))
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_autostart::init(MacosLauncher::LaunchAgent, Some(vec!["--hidden"])))
        .invoke_handler(tauri::generate_handler![get_status, pair, unpair, poll_now, open_portal_cmd, get_autostart, set_autostart, app_version, open_external, open_printer_settings])
        .on_window_event(|window, event| {
            // The window is just a view; closing it leaves the print server running in the tray.
            if let WindowEvent::CloseRequested { api, .. } = event {
                api.prevent_close();
                let _ = window.hide();
            }
        })
        .setup(|app| {
            let data_dir = app.path().app_data_dir()?;
            let backend = default_backend(sumatra_path(app.handle()));
            let engine = Engine::new(data_dir, backend, Options::default())?;
            app.manage(AppState { engine: engine.clone() });

            // Tray: left-click opens the window; the menu has the portal link, Open and Quit.
            // The portal item needs a paired server to point at, so it's greyed out until then.
            let portal =
                MenuItem::with_id(app, "portal", "Relief Inventory Portal", engine.is_paired(), None::<&str>)?;
            let open = MenuItem::with_id(app, "open", "Open RI Print", true, None::<&str>)?;
            let quit = MenuItem::with_id(app, "quit", "Quit", true, None::<&str>)?;
            let menu = Menu::with_items(app, &[&portal, &open, &quit])?;
            let mut tray = TrayIconBuilder::<tauri::Wry>::with_id(TRAY_ID)
                .tooltip(tooltip(&engine.status()))
                .menu(&menu)
                .show_menu_on_left_click(false)
                .on_menu_event(|app, e| match e.id.as_ref() {
                    "portal" => open_portal(app),
                    "open" => show_main(app),
                    "quit" => app.exit(0),
                    _ => {}
                })
                .on_tray_icon_event(|tray, event| {
                    if let TrayIconEvent::Click { button: MouseButton::Left, button_state: MouseButtonState::Up, .. } = event {
                        show_main(tray.app_handle());
                    }
                });
            tray = tray.icon(tray_icon(&engine.status()));
            tray.build(app)?;

            // Launched at login on an already-paired machine: stay out of the way. Windows/macOS start
            // hidden in the tray. Linux can't rely on a tray (stock GNOME shows none), so there the window
            // opens minimized instead, still reachable from the taskbar/dock. Unpaired, the window always
            // opens: there's a pairing code to enter.
            let at_login = std::env::args().any(|a| a == "--hidden") && engine.is_paired();
            if !(at_login && !cfg!(target_os = "linux")) {
                show_main(app.handle());
                if at_login {
                    if let Some(w) = app.get_webview_window("main") {
                        let _ = w.minimize();
                    }
                }
            }

            // Run the engine, and mirror its status to the window and the tray tooltip.
            tauri::async_runtime::spawn(engine.clone().run());
            let handle = app.handle().clone();
            let mut rx = engine.subscribe();
            tauri::async_runtime::spawn(async move {
                while rx.changed().await.is_ok() {
                    let status = rx.borrow().clone();
                    let _ = portal.set_enabled(status.paired);
                    if let Some(tray) = handle.tray_by_id(TRAY_ID) {
                        let _ = tray.set_tooltip(Some(tooltip(&status)));
                        let _ = tray.set_icon(Some(tray_icon(&status)));
                    }
                    let _ = handle.emit("status", status);
                }
            });
            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("error while running RI Print");
}
