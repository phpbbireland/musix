<?php
// Database settings for the desktop copy of Musix.
// This copy uses a local SQLite file — no MariaDB / XAMPP DB dependency.
// The XAMPP web copy at /opt/lampp/htdocs/musix still uses MariaDB.
declare(strict_types=1);

// Path to the SQLite database file (created by install.php / migrate_to_sqlite.php).
const DB_PATH = __DIR__ . '/musix.sqlite';
