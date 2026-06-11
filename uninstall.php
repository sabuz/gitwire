<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Gitwire
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings        = (array) get_option( 'gitwire_settings', [] );
$remove_all_data = (bool) ( $settings['remove_data_on_uninstall'] ?? false );

$options = [
	'gitwire_settings',
	'gitwire_connection_cache',
	'gitwire_installed',
	'gitwire_pending_update',
	'gitwire_fatal_notice',
	'gitwire_repos_cache',
	'gitwire_repo_types',
];

if ( $remove_all_data ) {
	$options[] = 'gitwire_connections';
}

foreach ( $options as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'gitwire_auto_check_connection' );
wp_clear_scheduled_hook( 'gitwire_maintenance' );
wp_clear_scheduled_hook( 'gitwire_refresh_repos_cache' );
wp_clear_scheduled_hook( 'gitwire_refresh_repo_types' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_gitwire_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_gitwire_' ) . '%'
	)
);
