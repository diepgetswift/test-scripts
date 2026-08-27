#!/usr/bin/env bash
#
# add_robots_header.sh
#
# Inserts:
#   add_header X-Robots-Tag "noindex, nofollow" always;
# right after every "server {" opening brace in every config file
# under /etc/nginx/sites-enabled, unless that file already contains
# an X-Robots-Tag header. Backs up each modified file, then tests
# and (optionally) reloads nginx.
#
# NOTE on nginx behavior: add_header directives are inherited by child
# blocks (location, if, etc.) ONLY if that child block defines no
# add_header of its own. If a location block already sets its own
# add_header, this header will NOT apply there unless you add it to
# that location block too.

set -euo pipefail

SITES_DIR="/etc/nginx/sites-enabled"
HEADER_LINE='    add_header X-Robots-Tag "noindex, nofollow" always;'
BACKUP_DIR="/root/nginx-sites-enabled-backup-$(date +%Y%m%d-%H%M%S)"
DRY_RUN="${DRY_RUN:-0}"   # set DRY_RUN=1 to preview without changing files

if [[ $EUID -ne 0 ]]; then
    echo "This script must be run as root (sudo)." >&2
    exit 1
fi

if [[ ! -d "$SITES_DIR" ]]; then
    echo "Directory $SITES_DIR not found." >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR"
echo "Backups will be stored in: $BACKUP_DIR"

changed_any=0

shopt -s nullglob
for file in "$SITES_DIR"/*; do
    # Resolve symlinks (sites-enabled entries are usually symlinks to sites-available)
    target="$(readlink -f "$file")"

    [[ -f "$target" ]] || continue

    if grep -q "X-Robots-Tag" "$target"; then
        echo "SKIP (already has header): $target"
        continue
    fi

    if ! grep -qE '^\s*server\s*\{' "$target"; then
        echo "SKIP (no 'server {' block found): $target"
        continue
    fi

    echo "UPDATING: $target"

    # Backup preserving a flat, uniquely named copy
    cp -a "$target" "$BACKUP_DIR/$(basename "$target").bak"

    if [[ "$DRY_RUN" == "1" ]]; then
        echo "  (dry run) would insert header after each 'server {' line"
        continue
    fi

    # Insert the header line right after every "server {" line
    sed -i -E "/^\s*server\s*\{/a\\
${HEADER_LINE}" "$target"

    changed_any=1
done
shopt -u nullglob

if [[ "$DRY_RUN" == "1" ]]; then
    echo "Dry run complete. No files were modified."
    exit 0
fi

if [[ "$changed_any" -eq 0 ]]; then
    echo "No files needed changes."
    exit 0
fi

echo
echo "Testing nginx configuration..."
if nginx -t; then
    echo "Config test passed."
    read -r -p "Reload nginx now? [y/N] " answer
    if [[ "$answer" =~ ^[Yy]$ ]]; then
        systemctl reload nginx
        echo "nginx reloaded."
    else
        echo "Skipped reload. Run 'systemctl reload nginx' when ready."
    fi
else
    echo "Config test FAILED. Restoring from backups in $BACKUP_DIR ..." >&2
    for bak in "$BACKUP_DIR"/*.bak; do
        base="$(basename "$bak" .bak)"
        target="$(readlink -f "$SITES_DIR/$base" 2>/dev/null || echo "$SITES_DIR/$base")"
        cp -a "$bak" "$target"
    done
    echo "Restore complete. Please investigate the config manually." >&2
    exit 1
fi
