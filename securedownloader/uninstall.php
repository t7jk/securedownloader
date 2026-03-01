<?php
/**
 * Secure Downloader - Uninstall
 * Ten plik jest uruchamiany gdy wtyczka jest odinstalowywana.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

function pit_uninstall_rmdir( $dir ) {
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
			pit_uninstall_rmdir( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

// Katalog uploads wtyczki (plugins/securedownloader/uploads/)
$pit_upload_dir = dirname( __FILE__ ) . '/uploads/';
if ( is_dir( $pit_upload_dir ) ) {
	pit_uninstall_rmdir( $pit_upload_dir );
}

// Stara lokalizacja w wp-content/uploads (na wypadek migracji)
$upload_dir     = wp_upload_dir();
$legacy_pit_dir = $upload_dir['basedir'] . '/securedownloader/';
if ( is_dir( $legacy_pit_dir ) ) {
	pit_uninstall_rmdir( $legacy_pit_dir );
}

$table_files     = $wpdb->prefix . 'pit_files';
$table_downloads = $wpdb->prefix . 'pit_downloads';

$wpdb->query( "DROP TABLE IF EXISTS {$table_downloads}" );
$wpdb->query( "DROP TABLE IF EXISTS {$table_files}" );

delete_option( 'pit_db_version' );
delete_option( 'pit_accountant_users' );
delete_option( 'pit_accountant_page_url' );
delete_option( 'pit_client_page_url' );
delete_option( 'pit_company_name' );
delete_option( 'pit_company_address' );
delete_option( 'pit_company_nip' );
delete_option( 'pit_filename_filters' );
delete_option( 'pit_import_patterns' );
delete_option( 'pit_pesel_search_rules' );
delete_option( 'pit_version' );
delete_option( 'pit_enabled' );
delete_option( 'pit_developer_mode' );

$admin = get_role( 'administrator' );
if ( $admin ) {
	$admin->remove_cap( 'pit_upload_documents' );
}
