#!/usr/bin/env bash
#
# parse_nginx_sites.sh
# Scan /etc/nginx/sites-available/ and list each server block's
# domains (server_name), ports (listen), and destination (root or proxy_pass).
#
# Usage:
#   ./parse_nginx_sites.sh [dir]        # default dir: /etc/nginx/sites-available
#   ./parse_nginx_sites.sh -f csv [dir] # output as CSV instead of a table

set -euo pipefail

DIR="/etc/nginx/sites-available"
FORMAT="table"

while getopts "f:h" opt; do
  case "$opt" in
    f) FORMAT="$OPTARG" ;;
    h)
      echo "Usage: $0 [-f table|csv] [dir]"
      exit 0
      ;;
    *) ;;
  esac
done
shift $((OPTIND - 1))

if [[ $# -ge 1 ]]; then
  DIR="$1"
fi

if [[ ! -d "$DIR" ]]; then
  echo "Error: directory not found: $DIR" >&2
  exit 1
fi

# Strip comments and collapse whitespace from a config file, then print it
# so we can grep multi-line "server { ... }" blocks safely.
strip_comments() {
  sed -e 's/#.*$//' "$1"
}

if [[ "$FORMAT" == "csv" ]]; then
  echo "file,server_name,listen,type,target"
fi

shopt -s nullglob
found_any=0

for file in "$DIR"/*; do
  [[ -f "$file" ]] || continue
  found_any=1
  fname="$(basename "$file")"

  clean="$(strip_comments "$file")"

  # Split into server { ... } blocks using awk (handles nested braces roughly
  # by tracking brace depth starting at each "server" keyword).
  awk -v fname="$fname" '
    BEGIN { depth = 0; in_server = 0; block = ""; }
    {
      line = $0
      if (!in_server) {
        if (line ~ /^[[:space:]]*server[[:space:]]*\{/) {
          in_server = 1
          depth = 1
          block = ""
          next
        }
      } else {
        # count braces on this line
        n_open = gsub(/\{/, "{", line)
        n_close = gsub(/\}/, "}", line)
        depth += n_open - n_close
        if (depth <= 0) {
          in_server = 0
          print "----BLOCK-START----"
          printf "%s", block
          print "----BLOCK-END----"
          block = ""
          next
        }
        block = block line "\n"
      }
    }
  ' <<<"$clean" > /tmp/.nginx_block.$$

  # Now parse each captured block for server_name / listen / root / proxy_pass
  awk -v fname="$fname" -v fmt="$FORMAT" '
    /^----BLOCK-START----$/ { in_block=1; server_names=""; listens=""; root=""; proxy="" ; next }
    /^----BLOCK-END----$/ {
      in_block=0
      target = (proxy != "") ? proxy : root
      ttype  = (proxy != "") ? "proxy_pass" : "root"
      if (server_names == "") server_names = "(none)"
      if (listens == "") listens = "(none)"
      if (target == "") target = "(none)"
      if (fmt == "csv") {
        gsub(/"/, "\"\"", server_names); gsub(/"/, "\"\"", listens); gsub(/"/, "\"\"", target)
        printf "\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"\n", fname, server_names, listens, ttype, target
      } else {
        printf "File:        %s\n", fname
        printf "  server_name: %s\n", server_names
        printf "  listen:      %s\n", listens
        printf "  %s: %s\n\n", ttype, target
      }
      next
    }
    in_block {
      line = $0
      gsub(/^[ \t]+|[ \t]+$/, "", line)
      if (line ~ /^server_name[ \t]/) {
        sub(/^server_name[ \t]+/, "", line)
        sub(/;[ \t]*$/, "", line)
        server_names = (server_names == "" ? line : server_names " " line)
      } else if (line ~ /^listen[ \t]/) {
        sub(/^listen[ \t]+/, "", line)
        sub(/;[ \t]*$/, "", line)
        listens = (listens == "" ? line : listens ", " line)
      } else if (line ~ /^root[ \t]/) {
        sub(/^root[ \t]+/, "", line)
        sub(/;[ \t]*$/, "", line)
        root = line
      } else if (line ~ /^proxy_pass[ \t]/) {
        sub(/^proxy_pass[ \t]+/, "", line)
        sub(/;[ \t]*$/, "", line)
        proxy = line
      }
    }
  ' /tmp/.nginx_block.$$

  rm -f /tmp/.nginx_block.$$
done

if [[ "$found_any" -eq 0 ]]; then
  echo "No files found in $DIR" >&2
  exit 1
fi
