<?php
/**
 * Plugin Name: Obsługa dokumentów księgowych
 * Plugin URI:  https://example.com/obsluga-dokumentow-ksiegowych
 * Description: Wtyczka umożliwia księgowym wgrywanie dokumentów księgowych, a podatnikom ich pobieranie po weryfikacji danych osobowych.
 * Version:     1.0.0
 * Author:      Tomasz Kalinowski
 * Author URI:  https://example.com
 * Text Domain: obsluga-dokumentow-ksiegowych
 * Domain Path: /languages
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Definicje stałych wtyczki
define( 'PIT_VERSION',    '1.1.0' );
define( 'PIT_BUILD',       6); // Zwiększ przy każdym wydaniu (widoczne w Narzędzia → Obsługa dokumentów księgowych).
define( 'PIT_UPLOAD_CHUNK_SIZE', 5 ); // Maks. plików w jednym żądaniu (omija limit PHP max_file_uploads).
define( 'PIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Dołączenie plików klas
require_once PIT_PLUGIN_DIR . 'includes/class-database.php';
require_once PIT_PLUGIN_DIR . 'includes/class-admin.php';
require_once PIT_PLUGIN_DIR . 'includes/class-accountant.php';
require_once PIT_PLUGIN_DIR . 'includes/class-client.php';

/**
 * Funkcja aktywacji wtyczki.
 */
function pit_activate_plugin(): void {
    // Utwórz tabele w bazie danych
    PIT_Database::activate();

    // Utwórz folder na pliki
    pit_create_upload_directory();

    // Dodaj capability dla administratorów
    $admin = get_role( 'administrator' );
    if ( $admin ) {
        $admin->add_cap( 'pit_upload_documents' );
    }

    // Dodaj opcje domyślne
    if ( get_option( 'pit_company_name' ) === false ) {
        add_option( 'pit_company_name', 'Twoja Firma' );
    }
    if ( get_option( 'pit_company_address' ) === false ) {
        add_option( 'pit_company_address', 'ul. dowolna 1' );
    }
    if ( get_option( 'pit_company_nip' ) === false ) {
        add_option( 'pit_company_nip', '1123334455' );
    }
    if ( get_option( 'pit_accountant_users' ) === false ) {
        add_option( 'pit_accountant_users', [] );
    }
    if ( get_option( 'pit_accountant_page_url' ) === false ) {
        add_option( 'pit_accountant_page_url', home_url( '/ksiegowy' ) );
    }
    if ( get_option( 'pit_client_page_url' ) === false ) {
        add_option( 'pit_client_page_url', home_url( '/podatnik' ) );
    }
    if ( get_option( 'pit_filename_filters' ) === false ) {
        add_option( 'pit_filename_filters', pit_get_default_filename_filters() );
    }

    // Zapisz wersję wtyczki
    update_option( 'pit_version', PIT_VERSION );
}

/**
 * Zwraca domyślne filtry nazw plików.
 *
 * @return string[]
 */
function pit_get_default_filename_filters(): array {
	return [
		'{NAZWISKO}/ /{IMIĘ}/ - PIT-11 (29) - rok /{RRRR}/.pdf/',
		'/Informacja roczna dla /{NAZWISKO}/ /{IMIĘ}/.pdf/',
		'/PIT-11_rok_/{RRRR}/_/{IMIĘ}/_/{NAZWISKO}/_/{PPPPPPPPPPP}/.pdf/',
	];
}

/**
 * Zwraca ścieżkę do katalogu uploads wtyczki.
 */
function pit_get_upload_dir(): string {
    return PIT_PLUGIN_DIR . 'uploads/';
}

/**
 * Normalizuje imię i nazwisko do porównań (trim, pojedyncze spacje).
 *
 * @param string $full_name Imię i nazwisko.
 * @return string Znormalizowana postać.
 */
function pit_normalize_full_name( string $full_name ): string {
    $s = trim( preg_replace( '/\s+/', ' ', $full_name ) );
    return $s;
}

/**
 * Zwraca full_name w kolejności „Imię Nazwisko” do wyświetlania (w bazie jest „Nazwisko Imię”).
 *
 * @param string $full_name Imię i nazwisko (w formacie z bazy: Nazwisko Imię).
 * @return string Postać do wyświetlenia: Imię Nazwisko.
 */
function pit_display_full_name( string $full_name ): string {
	$s = pit_normalize_full_name( $full_name );
	if ( $s === '' ) {
		return '';
	}
	$parts = preg_split( '/\s+/', $s, 2 );
	if ( count( $parts ) === 2 ) {
		return $parts[1] . ' ' . $parts[0];
	}
	return $s;
}

/**
 * Normalizuje string do porównań: polskie znaki diakrytyczne → ASCII (np. Ł→L, Ń→N).
 * Używane przy dopasowaniu imion i nazwisk, żeby „KALACIŃSKI” i „KALACINSKI” były uznane za to samo.
 *
 * @param string $s Dowolny ciąg.
 * @return string Postać do porównań (bez polskich znaków).
 */
function pit_normalize_name_for_compare( string $s ): string {
	$map = [
		'ą' => 'a', 'Ą' => 'A', 'ę' => 'e', 'Ę' => 'E', 'ł' => 'l', 'Ł' => 'L',
		'ó' => 'o', 'Ó' => 'O', 'ś' => 's', 'Ś' => 'S', 'ć' => 'c', 'Ć' => 'C',
		'ź' => 'z', 'Ź' => 'Z', 'ż' => 'z', 'Ż' => 'Z', 'ń' => 'n', 'Ń' => 'N',
	];
	return strtr( $s, $map );
}

/**
 * Klucz do uznawania „tej samej osoby” przy szukaniu PESEL (np. „Ambrozik Ewelina 29” i „dla Ambrozik Ewelina” → ta sama osoba).
 *
 * @param string $full_name Imię i nazwisko z bazy lub formularza.
 * @return string Klucz do porównań.
 */
function pit_person_match_key( string $full_name ): string {
    $s = pit_normalize_full_name( $full_name );
    $s = preg_replace( '/^dla\s+/iu', '', $s );
    $s = preg_replace( '/\s*\(\s*29\s*\)\s*$/', '', $s );
    $s = preg_replace( '/\s*29\s*$/', '', $s );
    return pit_normalize_full_name( $s );
}

/**
 * Sprawdza sumę kontrolną PESEL (11 cyfr). Wagi pozycji 1–10: 1,3,7,9,1,3,7,9,1,3.
 *
 * @param string $pesel Ciąg 11 cyfr.
 * @return bool True jeśli suma kontrolna się zgadza.
 */
function pit_validate_pesel_checksum( string $pesel ): bool {
    if ( ! preg_match( '/^\d{11}$/', $pesel ) ) {
        return false;
    }
    $weights = [ 1, 3, 7, 9, 1, 3, 7, 9, 1, 3 ];
    $sum     = 0;
    for ( $i = 0; $i < 10; $i++ ) {
        $sum += (int) $pesel[ $i ] * $weights[ $i ];
    }
    $check = ( 10 - ( $sum % 10 ) ) % 10;
    return (int) $pesel[10] === $check;
}

/**
 * Zapisuje linię do pliku debugowego i error_log.
 * Włączone gdy WP_DEBUG jest true lub gdy PIT_DEBUG_LOG jest true w wp-config.php.
 * Plik: wp-content/pit-debug.log; WP_DEBUG_LOG → wp-content/debug.log.
 *
 * @param string $message Tekst do zapisania.
 */
function pit_debug_log( string $message ): void {
    $enabled = ( defined( 'PIT_DEBUG_LOG' ) && PIT_DEBUG_LOG )
        || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
    if ( ! $enabled ) {
        return;
    }
    $line = '[PIT] ' . gmdate( 'Y-m-d H:i:s' ) . ' ' . $message;
    if ( defined( 'WP_CONTENT_DIR' ) ) {
        $file = WP_CONTENT_DIR . '/pit-debug.log';
        @file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX );
    }
    if ( function_exists( 'error_log' ) ) {
        error_log( $line );
    }
}

/**
 * Przekierowanie z fallbackiem: jeśli nagłówki już wysłane, wysyła HTML z meta refresh i JS.
 * Zapobiega białemu ekranowi (200 OK bez Location), gdy coś wypisze output przed wp_redirect().
 *
 * @param string $url Docelowy URL.
 */
function pit_redirect_safe( string $url ): void {
    $url = esc_url_raw( $url );
    if ( ! headers_sent() ) {
        wp_redirect( $url );
        exit;
    }
    pit_debug_log( 'pit_redirect_safe: headers already sent, using fallback for ' . $url );
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    echo '<meta http-equiv="Refresh" content="0; url=' . esc_attr( $url ) . '">';
    echo '<script>location.replace(' . wp_json_encode( $url ) . ');</script>';
    echo '</head><body><p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Przejdź', 'obsluga-dokumentow-ksiegowych' ) . '</a></p></body></html>';
    exit;
}

/**
 * Tworzy folder uploadu z zabezpieczeniami.
 */
function pit_create_upload_directory(): void {
    $target_dir = pit_get_upload_dir();

    if ( ! is_dir( $target_dir ) ) {
        wp_mkdir_p( $target_dir );
    }

    // Dodaj pusty index.php (zabezpieczenie przed listowaniem)
    $index_file = $target_dir . 'index.php';
    if ( ! file_exists( $index_file ) ) {
        file_put_contents( $index_file, '<?php // Silence is golden' );
    }

    // Dodaj .htaccess blokujący bezpośredni dostęp
    $htaccess_file = $target_dir . '.htaccess';
    if ( ! file_exists( $htaccess_file ) ) {
        $htaccess_content = "# PIT-11 Manager - Deny direct access\n";
        $htaccess_content .= "<IfModule mod_rewrite.c>\n";
        $htaccess_content .= "    RewriteEngine On\n";
        $htaccess_content .= "    RewriteCond %{REQUEST_FILENAME} -f\n";
        $htaccess_content .= "    RewriteRule .* - [F,L]\n";
        $htaccess_content .= "</IfModule>\n\n";
        $htaccess_content .= "# Deny access to all files\n";
        $htaccess_content .= "<FilesMatch \"(?i)(\\.pdf)$\">\n";
        $htaccess_content .= "    Order Allow,Deny\n";
        $htaccess_content .= "    Deny from all\n";
        $htaccess_content .= "</FilesMatch>\n";
        file_put_contents( $htaccess_file, $htaccess_content );
    }
}

/**
 * Funkcja deaktywacji wtyczki.
 */
function pit_deactivate_plugin(): void {
    // Wyczyść harmonogramy (jeśli są)
    wp_clear_scheduled_hook( 'pit_daily_cleanup' );
    
    // Dane pozostają zachowane
}

/**
 * Rekurencyjnie usuwa katalog.
 */
function pit_recursive_rmdir( string $dir ): void {
    if ( ! is_dir( $dir ) ) {
        return;
    }

    $objects = scandir( $dir );
    foreach ( $objects as $object ) {
        if ( $object === '.' || $object === '..' ) {
            continue;
        }

        $path = $dir . '/' . $object;

        if ( is_dir( $path ) ) {
            pit_recursive_rmdir( $path );
        } else {
            unlink( $path );
        }
    }

    rmdir( $dir );
}

// Rejestracja hooków
register_activation_hook( __FILE__, 'pit_activate_plugin' );
register_deactivation_hook( __FILE__, 'pit_deactivate_plugin' );

/**
 * Inicjalizacja klas wtyczki po załadowaniu wszystkich wtyczek WordPress.
 *
 * Na serwerze produkcyjnym: jeśli strona z shortcode [pit_accountant_panel] lub [pit_client_page]
 * jest pusta, sprawdź: (1) Czy w Ustawieniach wtyczki przełącznik „Włącz pobieranie” jest ON,
 * (2) Czy cache pełnostronicowy (wtyczka/CDN/serwer) nie serwuje starej wersji – wyklucz
 * ścieżki /ksiegowy i /podatnik z cache lub wyczyść cache po zmianach.
 */
function pit_init_plugin(): void {
	PIT_Database::get_instance();
	PIT_Admin::get_instance();
	PIT_Accountant::get_instance();
	PIT_Client::get_instance();

	load_plugin_textdomain( 'obsluga-dokumentow-ksiegowych', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	pit_sync_files();
}

/**
 * Synchronizuje bazę danych z plikami na dysku.
 */
function pit_sync_files(): void {
	$db = PIT_Database::get_instance();
	$db->sync_files();
}
add_action( 'plugins_loaded', 'pit_init_plugin' );

/**
 * Dodaje link do ustawień na liście wtyczek.
 */
function pit_plugin_action_links( array $links ): array {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url( admin_url( 'admin.php?page=obsluga-dokumentow-ksiegowych-settings' ) ),
        esc_html__( 'Settings', 'obsluga-dokumentow-ksiegowych' )
    );
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pit_plugin_action_links' );
