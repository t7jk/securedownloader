#!/usr/bin/env bash
#
# Ustawia prawidłowe uprawnienia dla WordPress w /var/www/html/wordpress/
# Uruchom jako root (np. sudo ./fix-wordpress-permissions.sh)
#
set -e

WP_ROOT="${1:-/var/www/html/wordpress}"
# Użytkownik serwera WWW (www-data na Debian/Ubuntu, apache na RHEL/Fedora)
WEB_USER="${2:-www-data}"

if [[ ! -d "$WP_ROOT" ]]; then
    echo "Błąd: Katalog nie istnieje: $WP_ROOT"
    exit 1
fi

echo "WordPress: $WP_ROOT"
echo "Właściciel: $WEB_USER"
echo "---"

# Właściciel: całe drzewo na użytkownika serwera WWW
chown -R "$WEB_USER:$WEB_USER" "$WP_ROOT"

# Katalogi: 755 (rwxr-xr-x)
find "$WP_ROOT" -type d -exec chmod 755 {} \;

# Pliki: 644 (rw-r--r--)
find "$WP_ROOT" -type f -exec chmod 644 {} \;

echo "Gotowe. Uprawnienia ustawione."
