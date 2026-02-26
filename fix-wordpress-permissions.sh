#!/usr/bin/env bash
#
# Ustawia prawidłowe uprawnienia dla WordPress w /var/www/html/wordpress/
# Automatycznie wykrywa dystrybucję i dobiera użytkownika www (www-data / apache)
#
# Uruchom jako root:   sudo ./fix-wordpress-permissions.sh
#                      sudo ./fix-wordpress-permissions.sh /inna/sciezka/do/wordpress
#

set -e

WP_ROOT="${1:-/var/www/html/wordpress}"

# ────────────────────────────────────────────────
#  Automatyczne wykrywanie użytkownika web-serwera
# ────────────────────────────────────────────────
if [[ -f /etc/debian_version ]] || grep -qiE 'ubuntu|debian' /etc/os-release 2>/dev/null; then
    WEB_USER="www-data"
elif [[ -f /etc/redhat-release ]] || grep -qiE 'fedora|rhel|centos|rocky|almalinux' /etc/os-release 2>/dev/null; then
    WEB_USER="apache"
else
    # Fallback – można zmienić lub zapytać użytkownika
    WEB_USER="www-data"
    echo "Uwaga: Nieznana dystrybucja. Używam domyślnego użytkownika www-data."
    echo "Jeśli używasz innej dystrybucji (np. Arch, openSUSE), zmień ręcznie zmienną WEB_USER."
fi

# ────────────────────────────────────────────────
#  Sprawdzenie czy katalog istnieje
# ────────────────────────────────────────────────
if [[ ! -d "$WP_ROOT" ]]; then
    echo "Błąd: Katalog nie istnieje: $WP_ROOT" >&2
    exit 1
fi

echo "WordPress katalog:   $WP_ROOT"
echo "Wykryty użytkownik:  $WEB_USER"
echo "Ustawiam uprawnienia..."
echo "──────────────────────────────────────────"

# 1. Właściciel – całe drzewo
chown -R "${WEB_USER}:${WEB_USER}" "$WP_ROOT"

# 2. Katalogi → 755
find "$WP_ROOT" -type d -exec chmod 0755 {} +

# 3. Pliki → 644
find "$WP_ROOT" -type f -exec chmod 0644 {} +

# ────────────────────────────────────────────────
#  Najczęściej polecane dodatkowe uprawnienia (bezpieczniejsze niż 777)
# ────────────────────────────────────────────────
echo "Dodatkowe zalecane zmiany (bezpieczniejsze niż 777):"

# wp-config.php → 640 (tylko właściciel może czytać)
[ -f "$WP_ROOT/wp-config.php" ] && chmod 640 "$WP_ROOT/wp-config.php"

# katalogi uploads, upgrade, wp-content (czasami 775 lub 755 + grupa)
find "$WP_ROOT/wp-content" -type d -name "uploads"  -exec chmod 0775 {} + 2>/dev/null || true
find "$WP_ROOT/wp-content" -type d -name "upgrade"  -exec chmod 0775 {} + 2>/dev/null || true
find "$WP_ROOT/wp-content" -type d                  -exec chmod 0755 {} +  # ← najczęściej wystarczy

echo ""
echo "Gotowe."
echo "Sprawdź czy WordPress działa prawidłowo."
echo "Jeśli nadal potrzebujesz zapisu → rozważ 775 na katalog wp-content/uploads (grupa)."
echo ""

