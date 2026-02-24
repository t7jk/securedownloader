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
 *
 * Loader: ładuje właściwą wtyczkę z podkatalogu obsluga-dokumentow-ksiegowych/.
 * Zainstaluj cały folder (np. PIT-downloader) w wp-content/plugins/ i aktywuj ten plik.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$obsluga_pit_file = dirname( __FILE__ ) . '/obsluga-dokumentow-ksiegowych/obsluga-dokumentow-ksiegowych.php';

if ( ! file_exists( $obsluga_pit_file ) ) {
	return;
}

require_once $obsluga_pit_file;

register_activation_hook( __FILE__, 'pit_activate_plugin' );
register_deactivation_hook( __FILE__, 'pit_deactivate_plugin' );

/**
 * Dodaje link „Ustawienia” na liście wtyczek (ekran Wtyczki).
 */
function pit_loader_plugin_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=obsluga-dokumentow-ksiegowych-settings' ) ),
		esc_html__( 'Settings', 'obsluga-dokumentow-ksiegowych' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pit_loader_plugin_action_links' );
