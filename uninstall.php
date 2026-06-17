<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Gitwire
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'gitwire_settings', [] );
if ( empty( $settings['remove_data_on_uninstall'] ) ) {
	return;
}

$options = [
	'gitwire_settings',
	'gitwire_public_connections',
	'gitwire_installed',
	'gitwire_pending_update',
	'gitwire_fatal_notice',
	'gitwire_update_success',
	'gitwire_activation_success',
	'gitwire_recently_deleted',
	'gitwire_repos_cache',
	'gitwire_repo_types',
	'gitwire_remote_heads',
];

foreach ( $options as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'gitwire_maintenance' );
wp_clear_scheduled_hook( 'gitwire_refresh_repos_cache' );
wp_clear_scheduled_hook( 'gitwire_refresh_repo_types' );
wp_clear_scheduled_hook( 'gitwire_refresh_connections' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_gitwire_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_gitwire_' ) . '%',
		$wpdb->esc_like( 'gitwire_commits_' ) . '%'
	)
);
