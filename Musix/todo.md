# Musix — desktop conversion progress

Goal: a "cheapest path" installable desktop wrapper for the Musix web app,
without changing the existing XAMPP web copy at `/opt/lampp/htdocs/musix`.

## Done

- [x] Copied all app code to `/home/Mike/Music/Musix/` — verbatim from the
      XAMPP install.
- [x] XAMPP web copy at `/opt/lampp/htdocs/musix` left untouched and still working.
- [x] `cache/covers` is its own real folder, writable by Mike.
- [x] `cover.php` patched to also read covers from the XAMPP cache.
- [x] `start-musix.sh` launcher + `Musix.desktop` menu entry + installer script.
- [x] All PHP files pass `php -l`; shell scripts pass `bash -n`.

## SQLite migration — DONE (this session)

The desktop copy now uses a local **SQLite** database (`musix.sqlite`) instead
of MariaDB. The XAMPP web copy keeps MariaDB, untouched. After migration the
two copies are fully independent databases.

- [x] `setup.sql` rewritten as SQLite DDL (7 tables, 11 indexes, 4 config rows).
- [x] `db_config.php` — now just `DB_PATH` (the SQLite file path).
- [x] `db.php` — `db()` opens `sqlite:` PDO; sets `foreign_keys`, WAL,
      `busy_timeout`. `cfg_set()` uses `ON CONFLICT ... DO UPDATE`.
- [x] `migrate_to_sqlite.php` — NEW. Reads the MariaDB data and copies every
      table into a fresh `musix.sqlite`. Run once (see below).
- [x] `install.php` — connects to SQLite, runs the schema.
- [x] MySQL-specific SQL converted to SQLite in: `scan.php`, `track_edit.php`,
      `playlist_add.php`, `bump_play.php`, `diagnose_flac.php`,
      `fix_numeric_genres.php`, `tools.php`.
- [x] `tools.php` — backup/restore, empty, reset all SQLite-aware; the old
      "repair auto-increment" card is now "Optimise database" (VACUUM/ANALYZE).
- [x] `start-musix.sh` — checks for `pdo_sqlite`; MariaDB-startup block removed.
- [x] Verified: schema loads in sqlite3, all upserts / `INSERT OR IGNORE` /
      numeric-genre GLOB tested, backup->restore roundtrip works, all files lint.

## To do next (on Mike's machine)

- [x] Ran the data migration with `/opt/lampp/bin/php migrate_to_sqlite.php`
      — 184 artists, 61 genres, 323 albums, 1903 tracks, 7 config rows;
      foreign-key check passed (2478 rows total).
- [x] Installed the `.deb` and confirmed the app launches, pages load,
      album art shows, and resize behaves correctly.
- [x] Installed the GStreamer plugin set so `<audio>` playback works:
      `sudo apt install gstreamer1.0-plugins-base gstreamer1.0-plugins-good
      gstreamer1.0-plugins-bad gstreamer1.0-plugins-ugly gstreamer1.0-libav`.
- [ ] Delete leftover `musix.sqlite.bak-*` files once happy.

## Tauri desktop wrapper — built and installed (this session)

A Tauri v2 project lives in `desktop-tauri/`. It's a thin shell that spawns
the PHP server and shows Musix in a native window (uses installed PHP).
See `desktop-tauri/README.md` for details.

- [x] Toolchain installed (Rust, Node, Tauri's Linux libs).
- [x] `npm install` + `npm run icon` done.
- [x] `main.rs` sets three Linux WebKitGTK env vars at startup:
      `WEBKIT_DISABLE_DMABUF_RENDERER`, `WEBKIT_DISABLE_COMPOSITING_MODE`,
      `LIBGL_ALWAYS_SOFTWARE`. The first fixes a "GBM EGL display" crash on
      VMs / NVIDIA drivers; the other two were defensive add-ons during
      the AppImage debugging and are harmless on the working `.deb` path.
- [x] `main.rs` enables `.zoom_hotkeys_enabled(true)` and sets a
      default `window.set_zoom(1.2)` (WebKit hotkeys are flaky on Linux,
      so the launch zoom is the reliable lever; tune by editing the
      `1.2` and rebuilding).
- [x] `npm run build` produces a `.deb` and an AppImage in
      `src-tauri/target/release/bundle/`. (`tauri.conf.json` `category`
      must be a Tauri enum value — used "Music", not freedesktop
      "AudioVideo".)
- [x] Installed `Musix_0.1.3_amd64.deb` with `sudo dpkg -i ...` and
      confirmed it works: launches from the menu, pages render, resize
      behaves cleanly. Version bumps live in both `Cargo.toml` and
      `tauri.conf.json` — keep them in sync.

### Known AppImage caveat

On this machine (NVIDIA Optimus / Bumblebee), the AppImage white-screens
or hangs on window resize, while the `.deb` works fine. Cause is the
WebKitGTK that the AppImage bundles vs. the system WebKitGTK the `.deb`
uses. Workaround: use the `.deb`. The AppImage stays in the build
targets in case it's useful for sharing to a machine without WebKitGTK
installed — drop it from `tauri.conf.json` (`"targets": ["deb"]`) if
you'd rather skip producing it.

## Deferred / maybe later

- [ ] `favicon.ico` was not copied (binary) — purely cosmetic, optional.
- [ ] Optionally bundle a static PHP binary as a Tauri sidecar to make the
      desktop app fully standalone (installable on a machine with no PHP).
