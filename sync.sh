#!/usr/bin/env bash
set -u
SRC="/home/t7jk/Code/securedownloader/"
DST="/var/www/html/wordpress/wp-content/plugins/securedownloader/"

[[ ! -d "$DST" ]] && sudo mkdir -p "$DST" && sudo chown apache:apache "$DST"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Start – pokazywane będą TYLKO rzeczywiste zmiany"

while true; do
    # Uruchamiamy rsync od razu (bez -n), zbieramy itemize output
    real_changes=$(sudo rsync -a --delete \
        --exclude='.git' \
        --exclude='node_modules' \
        --exclude='.claude' \
        --exclude='*.zip' \
        --exclude='*.csv' \
        --itemize-changes \
        --out-format='%i %n%L' \
        "$SRC" "$DST" 2>/dev/null \
        | grep -v '^\.[fd]' \
        | grep -v '^cd')

    if [[ -n "$real_changes" ]]; then
        echo ""
        echo -e "\033[1;33m[$(date '+%Y-%m-%d %H:%M:%S')] Zsynchronizowano:\033[0m"
        echo "$real_changes" | sed 's/^/  /'
        sudo chown -R apache:apache "$DST" 2>/dev/null
        echo ""
    fi

    sleep 2
done

