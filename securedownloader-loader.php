<?php
/**
 * Plugin Name: Secure Downloader
 * Plugin URI:  https://wordpress.org/plugins/secure-downloader/
 * Description: Wtyczka umożliwia menadżerom wgrywanie dokumentów, a klientom ich pobieranie po weryfikacji danych osobowych.
 * Version:     1.1.0
 * Author:      Tomasz Kalinowski
 * Author URI:  https://x.com/messages/compose?screen_name=tomas3man
 * Text Domain: securedownloader
 * Domain Path: /languages
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Loader: ładuje właściwą wtyczkę z podkatalogu securedownloader/.
 * Zainstaluj cały folder (np. PIT-downloader) w wp-content/plugins/ i aktywuj ten plik.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$securedownloader_main_file = dirname( __FILE__ ) . '/securedownloader/securedownloader.php';

if ( ! file_exists( $securedownloader_main_file ) ) {
	return;
}

require_once $securedownloader_main_file;

register_activation_hook( __FILE__, 'pit_activate_plugin' );
register_deactivation_hook( __FILE__, 'pit_deactivate_plugin' );

/**
 * Dodaje link „Ustawienia” na liście wtyczek (ekran Wtyczki).
 */
function pit_loader_plugin_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=securedownloader-settings' ) ),
		esc_html__( 'Settings', 'securedownloader' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pit_loader_plugin_action_links' );
