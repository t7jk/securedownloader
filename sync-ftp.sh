#!/usr/bin/env bash

HOST="mindcloudsiedlce.pl"
USER="debug@mindcloudsiedlce.pl"
PASS="Qweasdzxc77&&"                 

SRC_DIR="/home/t7jk/Code/PIT-downloader/securedownloader"
REMOTE_DIR="/"

# ────────────────────────────────────────────────

# Najlepsze podejście – użycie lftp (bardzo polecane do tego typu zadań)

if ! command -v lftp &> /dev/null; then
    echo "Brak programu lftp. Zainstaluj go:"
    echo "  Ubuntu/Debian:     sudo apt install lftp"
    echo "  Fedora:            sudo dnf install lftp"
    exit 1
fi

echo "Starting mirror loop → ${SRC_DIR} → ftp://${HOST}${REMOTE_DIR}"
echo "Co ~5 sekund – tylko zmienione pliki"
echo "Ctrl+C aby zakończyć"
echo

while true; do
    clear  # opcjonalne – czyści ekran przy każdej iteracji

    echo "[$(date '+%Y-%m-%d %H:%M:%S')]  Synchronizacja..."

    lftp -u "${USER},${PASS}" "ftp://${HOST}" << EOF
    set ftp:list-options -a
    set mirror:use-pget-n 3
    set mirror:parallel-directories true
    set mirror:parallel-transfer-count 2
    mirror --verbose --only-newer --delete-first --no-perms \
           --exclude-glob .git* \
           --exclude-glob *.swp \
           --exclude-glob *~ \
           "${SRC_DIR}/" "${REMOTE_DIR}/"
    quit
EOF

    echo "──────────────────────────────"
    sleep 5
done
