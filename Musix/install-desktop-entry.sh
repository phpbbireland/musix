#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# One-time installer for the Musix application-menu entry.
#
# Run this once:   bash install-desktop-entry.sh
# It makes the launcher executable and registers Musix in your apps menu.
# Re-run it any time you move the Musix folder.
# ---------------------------------------------------------------------------
set -eu

APPDIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
DEST="$HOME/.local/share/applications"
ENTRY="$DEST/Musix.desktop"

chmod +x "$APPDIR/start-musix.sh"
mkdir -p "$DEST"

# Write the entry with Exec/Path pointing at wherever this folder actually is,
# so it keeps working even if the folder was moved/renamed.
cat > "$ENTRY" <<EOF
[Desktop Entry]
Type=Application
Version=1.0
Name=Musix
GenericName=Music Library
Comment=Browse and play your music library
Exec=$APPDIR/start-musix.sh
Path=$APPDIR
Icon=multimedia-player
Terminal=true
Categories=AudioVideo;Audio;Player;
Keywords=music;audio;player;library;albums;
StartupNotify=false
EOF

chmod +x "$ENTRY"
command -v update-desktop-database >/dev/null 2>&1 && \
    update-desktop-database "$DEST" >/dev/null 2>&1 || true

echo "Installed: $ENTRY"
echo "Musix should now appear in your applications menu."
echo "You can also start it directly with:  $APPDIR/start-musix.sh"
