<?php
/**
 * Plugin Name: Obsługa PIT
 * Plugin URI:  https://example.com/obsluga-pit
 * Description: Wtyczka umożliwia księgowym wgrywanie plików PDF PIT-11, a podatnikom ich pobieranie po weryfikacji danych osobowych.
 * Version:     1.0.0
 * Author:      Tomasz Kalinowski
 * Author URI:  https://example.com
 * Text Domain: obsluga-pit
 * Domain Path: /languages
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Definicje stałych wtyczki
define( 'PIT_VERSION',    '1.0.0' );
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

    // Zapisz wersję wtyczki
    update_option( 'pit_version', PIT_VERSION );
}

/**
 * Zwraca ścieżkę do katalogu uploads wtyczki.
 */
function pit_get_upload_dir(): string {
    return PIT_PLUGIN_DIR . 'uploads/';
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
 */
function pit_init_plugin(): void {
	PIT_Database::get_instance();
	PIT_Admin::get_instance();
	PIT_Accountant::get_instance();
	PIT_Client::get_instance();

	load_plugin_textdomain( 'obsluga-pit', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

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
        esc_url( admin_url( 'admin.php?page=obsluga-pit-settings' ) ),
        esc_html__( 'Ustawienia', 'obsluga-pit' )
    );
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pit_plugin_action_links' );
