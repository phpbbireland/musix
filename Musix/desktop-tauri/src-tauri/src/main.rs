// Musix desktop wrapper.
//
// This is a thin Tauri shell around the existing Musix PHP app. It does NOT
// reimplement Musix — at startup it:
//   1. finds a PHP interpreter on the machine,
//   2. picks a free TCP port,
//   3. starts PHP's built-in web server pointed at the Musix app folder,
//   4. waits for the server to accept connections,
//   5. opens a native window showing http://127.0.0.1:<port>/.
// When the app exits, the PHP server child process is killed.
//
// The PHP app folder defaults to /home/Mike/Music/Musix and can be overridden
// with the MUSIX_APP_DIR environment variable.

// Hide the extra console window on Windows release builds.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use std::net::{TcpListener, TcpStream};
use std::path::PathBuf;
use std::process::{Child, Command};
use std::sync::Mutex;
use std::time::Duration;

use tauri::{Manager, RunEvent, WebviewUrl, WebviewWindowBuilder};

/// Holds the PHP server child process so it can be killed when the app exits.
struct PhpServer(Mutex<Option<Child>>);

/// Folder containing the Musix PHP files (index.php, db.php, ...).
/// Override with MUSIX_APP_DIR if you move the app.
fn musix_app_dir() -> PathBuf {
    std::env::var("MUSIX_APP_DIR")
        .map(PathBuf::from)
        .unwrap_or_else(|_| PathBuf::from("/home/Mike/Music/Musix"))
}

/// Pick a PHP interpreter. Prefer one that has the `pdo_sqlite` extension
/// (Musix needs it); fall back to the first working PHP otherwise.
fn find_php() -> Option<String> {
    let mut fallback: Option<String> = None;
    for candidate in ["php", "/opt/lampp/bin/php"] {
        let Ok(output) = Command::new(candidate).arg("-m").output() else {
            continue;
        };
        if !output.status.success() {
            continue;
        }
        if fallback.is_none() {
            fallback = Some(candidate.to_string());
        }
        let modules = String::from_utf8_lossy(&output.stdout).to_lowercase();
        if modules.lines().any(|line| line.trim() == "pdo_sqlite") {
            return Some(candidate.to_string());
        }
    }
    fallback
}

/// Find a free TCP port, starting at 8675 (matches start-musix.sh).
fn free_port() -> u16 {
    let mut port: u16 = 8675;
    loop {
        if TcpListener::bind(("127.0.0.1", port)).is_ok() {
            return port;
        }
        port = port.checked_add(1).unwrap_or(8675);
    }
}

/// Block until the PHP server accepts a connection, or give up after ~10s.
fn wait_for_port(port: u16) -> bool {
    for _ in 0..40 {
        if TcpStream::connect(("127.0.0.1", port)).is_ok() {
            return true;
        }
        std::thread::sleep(Duration::from_millis(250));
    }
    false
}

fn main() {
    // WebKitGTK rendering workarounds for Linux. All must be set before the
    // webview is created, and are harmless on systems where the defaults
    // work fine.
    //
    // 1. DMABUF renderer fails on some GPU/driver combos at startup with
    //    "Could not create GBM EGL display: EGL_NOT_INITIALIZED" (common in
    //    VMs and on NVIDIA proprietary drivers).
    // 2. Accelerated compositing loses its GPU surface on window resize,
    //    leaving the webview as a blank white rectangle until something
    //    repaints. Disabling it falls back to a software compositor.
    // 3. Force libGL to the software rasteriser. Needed on NVIDIA Optimus
    //    laptops (Bumblebee/PRIME) where WebKit picks up the discrete GPU
    //    and the repaint pipeline breaks on resize. Software GL is fine
    //    for a static music browser — no perceptible slowdown.
    #[cfg(target_os = "linux")]
    {
        std::env::set_var("WEBKIT_DISABLE_DMABUF_RENDERER", "1");
        std::env::set_var("WEBKIT_DISABLE_COMPOSITING_MODE", "1");
        std::env::set_var("LIBGL_ALWAYS_SOFTWARE", "1");
    }

    tauri::Builder::default()
        .manage(PhpServer(Mutex::new(None)))
        .setup(|app| {
            let app_dir = musix_app_dir();
            if !app_dir.is_dir() {
                return Err(format!(
                    "Musix app folder not found: {}. Set MUSIX_APP_DIR to fix.",
                    app_dir.display()
                )
                .into());
            }

            let php = find_php().ok_or(
                "No PHP interpreter found. Looked for `php` on PATH and \
                 /opt/lampp/bin/php.",
            )?;

            let port = free_port();

            // Start the built-in PHP server. Output is discarded; add a log
            // file here if you want to debug server-side errors.
            let child = Command::new(&php)
                .arg("-S")
                .arg(format!("127.0.0.1:{port}"))
                .arg("-t")
                .arg(&app_dir)
                .spawn()
                .map_err(|e| format!("Could not start PHP server: {e}"))?;

            // Stash the child so the exit handler can kill it.
            app.state::<PhpServer>()
                .0
                .lock()
                .unwrap()
                .replace(child);

            if !wait_for_port(port) {
                // Server never came up — kill the child before bailing out.
                if let Some(mut child) = app.state::<PhpServer>().0.lock().unwrap().take() {
                    let _ = child.kill();
                }
                return Err("The PHP server did not start in time.".into());
            }

            let url = format!("http://127.0.0.1:{port}/");
            let window = WebviewWindowBuilder::new(
                app,
                "main",
                WebviewUrl::External(url.parse().expect("valid localhost URL")),
            )
            .title("Musix")
            .inner_size(1400.0, 1200.0)
            .min_inner_size(1100.0, 900.0)
            .center()

            .build()?;
            let _ = window.set_zoom(1.20);

            Ok(())
        })
        .build(tauri::generate_context!())
        .expect("failed to build the Musix desktop app")
        .run(|app, event| {
            // When the app is shutting down, stop the PHP server too.
            if matches!(event, RunEvent::Exit) {
                if let Some(mut child) = app.state::<PhpServer>().0.lock().unwrap().take() {
                    let _ = child.kill();
                }
            }
        });
}
