#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Musix launcher — "cheapest path" desktop wrapper.
#
# Runs the Musix web app with PHP's built-in server and opens it in your
# default browser. Closing this window (or Ctrl+C) stops the server.
#
# It does NOT touch the XAMPP web copy at /opt/lampp/htdocs/musix — that
# install keeps working independently. This desktop copy uses its own local
# SQLite database (musix.sqlite), so no MariaDB / XAMPP server is needed.
# ---------------------------------------------------------------------------
set -u

# Resolve the folder this script lives in (the app root).
APPDIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
HOST=127.0.0.1
PORT=8675

# --- pick a PHP binary ---
# Prefer a PHP that has pdo_sqlite; fall back to the first one found.
has_sqlite() { "$1" -m 2>/dev/null | grep -qi '^pdo_sqlite$'; }
PHP_BIN=""
PHP_FALLBACK=""
for c in "$(command -v php 2>/dev/null)" /opt/lampp/bin/php; do
    if [ -n "$c" ] && [ -x "$c" ]; then
        [ -z "$PHP_FALLBACK" ] && PHP_FALLBACK="$c"
        if has_sqlite "$c"; then PHP_BIN="$c"; break; fi
    fi
done
if [ -z "$PHP_BIN" ]; then PHP_BIN="$PHP_FALLBACK"; fi
if [ -z "$PHP_BIN" ]; then
    echo "ERROR: no PHP interpreter found."
    echo "Looked for 'php' on your PATH and /opt/lampp/bin/php."
    read -rp "Press Enter to close..."; exit 1
fi

# --- the PDO SQLite extension is required ---
if ! "$PHP_BIN" -m 2>/dev/null | grep -qi '^pdo_sqlite$'; then
    echo "WARNING: $PHP_BIN has no pdo_sqlite extension — Musix needs it to"
    echo "         open its database. If pages error out, install the PHP"
    echo "         SQLite extension (e.g. 'sudo apt install php-sqlite3')."
fi

# --- find a free TCP port, starting at $PORT ---
port_in_use() { (exec 3<>/dev/tcp/127.0.0.1/"$1") 2>/dev/null; }
while port_in_use "$PORT"; do PORT=$((PORT + 1)); done

URL="http://$HOST:$PORT/"
echo "-----------------------------------------------"
echo " Musix"
echo "   app : $APPDIR"
echo "   php : $PHP_BIN"
echo "   url : $URL"
echo "-----------------------------------------------"

# --- start the built-in server ---
"$PHP_BIN" -S "$HOST:$PORT" -t "$APPDIR" >/tmp/musix-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null' EXIT INT TERM

# wait for it to accept connections
for _ in $(seq 1 20); do
    port_in_use "$PORT" && break
    sleep 0.3
done

# --- open the default browser ---
if command -v xdg-open >/dev/null 2>&1; then
    xdg-open "$URL" >/dev/null 2>&1 &
elif command -v gio >/dev/null 2>&1; then
    gio open "$URL" >/dev/null 2>&1 &
else
    echo "Open this address in your browser: $URL"
fi

echo
echo "Musix is running. Close this window or press Ctrl+C to stop."
echo "(server log: /tmp/musix-server.log)"
wait "$SERVER_PID"
