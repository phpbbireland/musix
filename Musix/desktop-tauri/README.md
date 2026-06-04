# Musix — Tauri desktop wrapper

A thin [Tauri](https://tauri.app) shell that runs the existing Musix PHP app in
its own native window, instead of a browser tab.

It does **not** reimplement Musix. On launch it starts PHP's built-in web
server pointed at the Musix app folder (the same thing `start-musix.sh` does),
waits for it to come up, and opens a window showing it. When you close the
window, the PHP server is shut down with it.

## Layout

```
desktop-tauri/
  package.json            npm project — just pulls in the Tauri CLI
  app-icon.png            1024x1024 source icon (the icon set is generated from this)
  src/index.html          placeholder page Tauri's bundler requires
  src-tauri/
    Cargo.toml            Rust crate definition
    build.rs              Tauri build script
    tauri.conf.json       Tauri configuration
    capabilities/         window permissions
    src/main.rs           the wrapper logic — spawn PHP, open the window
```

## Prerequisites

This is a Rust + system-webview app, so the build toolchain has to be present
on the machine. One-time install:

1. **Rust** — install via [rustup](https://rustup.rs):
   `curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh`
2. **Node.js + npm** — for the Tauri CLI (`sudo apt install nodejs npm`).
3. **Tauri's Linux system libraries:**
   ```
   sudo apt install libwebkit2gtk-4.1-dev build-essential curl wget file \
       libxdo-dev libssl-dev libayatana-appindicator3-dev librsvg2-dev
   ```
4. **PHP** — the wrapper relies on a PHP already installed (system `php` or
   `/opt/lampp/bin/php`); it does not bundle one. PHP needs the `pdo_sqlite`
   extension, since Musix now uses SQLite.

## First-time setup

From inside `desktop-tauri/`:

```
npm install            # fetches the Tauri CLI
npm run icon           # generates src-tauri/icons/ from app-icon.png
```

The icon step is required before the first build — `tauri.conf.json` references
the generated icon files and the Rust build embeds them.

## Run it (development)

```
npm run dev
```

This compiles the Rust wrapper (slow the first time, fast afterwards) and opens
the Musix window. Closing the window stops both the wrapper and the PHP server.

## Build an installable package

```
npm run build
```

Output lands in `src-tauri/target/release/bundle/`:

- `deb/musix-desktop_0.1.0_amd64.deb` — install with `sudo dpkg -i ...`
- `appimage/musix-desktop_0.1.0_amd64.AppImage` — run directly, no install

Both register a "Musix" entry in the applications menu, so once this is built
you can retire the older `Musix.desktop` + `start-musix.sh` launcher if you like.

## Configuration

The wrapper looks for the Musix PHP files at `/home/Mike/Music/Musix` by
default. If you move them, point it elsewhere with an environment variable:

```
MUSIX_APP_DIR=/some/other/path  ./musix-desktop
```

The default is set in `src-tauri/src/main.rs` (`musix_app_dir()`); change it
there if you want a different built-in default.

## How it works

`src-tauri/src/main.rs`, in Tauri's `setup` hook:

1. resolves the Musix app folder (`MUSIX_APP_DIR` or the default),
2. finds a PHP interpreter, preferring one with `pdo_sqlite`,
3. picks a free TCP port starting at 8675,
4. spawns `php -S 127.0.0.1:<port> -t <app dir>`,
5. waits (up to ~10s) for the port to accept connections,
6. opens a `WebviewWindow` pointing at `http://127.0.0.1:<port>/`.

The PHP `Child` handle is kept in Tauri-managed state and killed on the
`RunEvent::Exit` event, so no orphaned server process is left behind.

## Limitations / notes

- The machine still needs PHP installed — this wrapper is the "use installed
  PHP" approach, not a fully self-contained bundle. To make it standalone on a
  PHP-less machine you'd add a static PHP binary as a Tauri sidecar.
- The Musix pages are plain server-rendered PHP and use no Tauri JavaScript
  APIs, so the window's capability grants only `core:default`.
- Audio streaming and the external-VLC launch work unchanged — the webview
  plays `<audio>` natively and VLC is launched on the host by PHP.
