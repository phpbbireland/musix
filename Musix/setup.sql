-- Musix schema (SQLite)
-- This is the desktop copy's schema. The XAMPP web copy still uses MariaDB.
-- Run this once by opening install.php in your browser, or with:
--     sqlite3 musix.sqlite < setup.sql
-- It is idempotent: re-running it on an existing DB only adds what's missing
-- (CREATE TABLE / CREATE INDEX use IF NOT EXISTS).
--
-- NOTE: install.php splits this file on ";\n", so every statement must end
-- with a semicolon followed by a newline.

CREATE TABLE IF NOT EXISTS artists (
    id   INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    UNIQUE (name)
);

CREATE TABLE IF NOT EXISTS genres (
    id   INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    UNIQUE (name)
);

CREATE TABLE IF NOT EXISTS albums (
    id         INTEGER PRIMARY KEY,
    artist_id  INTEGER NOT NULL,
    album_name TEXT NOT NULL,
    year       INTEGER,
    cover_art  TEXT,
    UNIQUE (artist_id, album_name),
    FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tracks (
    id          INTEGER PRIMARY KEY,
    album_id    INTEGER NOT NULL,
    artist_id   INTEGER NOT NULL,
    genre_id    INTEGER,
    track_name  TEXT NOT NULL,
    track_no    INTEGER,
    year        INTEGER,
    bitrate     INTEGER,
    duration    INTEGER,
    file_path   TEXT NOT NULL,
    play_count  INTEGER NOT NULL DEFAULT 0,
    last_played DATETIME,
    is_favorite INTEGER NOT NULL DEFAULT 0,
    excluded    INTEGER NOT NULL DEFAULT 0,
    added_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (file_path),
    FOREIGN KEY (album_id)  REFERENCES albums(id)  ON DELETE CASCADE,
    FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE,
    FOREIGN KEY (genre_id)  REFERENCES genres(id)  ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS playlists (
    id         INTEGER PRIMARY KEY,
    name       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (name)
);

CREATE TABLE IF NOT EXISTS playlist_tracks (
    playlist_id INTEGER NOT NULL,
    track_id    INTEGER NOT NULL,
    position    INTEGER NOT NULL,
    added_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (playlist_id, track_id),
    FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,
    FOREIGN KEY (track_id)    REFERENCES tracks(id)    ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS config (
    `key`   TEXT PRIMARY KEY,
    `value` TEXT
);

CREATE INDEX IF NOT EXISTS idx_track_album       ON tracks (album_id);
CREATE INDEX IF NOT EXISTS idx_track_artist      ON tracks (artist_id);
CREATE INDEX IF NOT EXISTS idx_track_genre       ON tracks (genre_id);
CREATE INDEX IF NOT EXISTS idx_track_year        ON tracks (year);
CREATE INDEX IF NOT EXISTS idx_track_play_count  ON tracks (play_count);
CREATE INDEX IF NOT EXISTS idx_track_last_played ON tracks (last_played);
CREATE INDEX IF NOT EXISTS idx_track_added_at    ON tracks (added_at);
CREATE INDEX IF NOT EXISTS idx_track_is_favorite ON tracks (is_favorite);
CREATE INDEX IF NOT EXISTS idx_track_excluded    ON tracks (excluded);
CREATE INDEX IF NOT EXISTS idx_pl_position       ON playlist_tracks (playlist_id, position);
CREATE INDEX IF NOT EXISTS idx_pl_track          ON playlist_tracks (track_id);

INSERT OR IGNORE INTO config (`key`, `value`) VALUES ('library_path', '');
INSERT OR IGNORE INTO config (`key`, `value`) VALUES ('external_player_name', 'VLC');
INSERT OR IGNORE INTO config (`key`, `value`) VALUES ('external_player_path', 'vlc');
INSERT OR IGNORE INTO config (`key`, `value`) VALUES ('allow_external_launch', '0');
