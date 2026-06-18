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

require_once plugin_dir_path( __FILE__ ) . 'autoload.php';

$options = [
	'gitwire_settings',
	'gitwire_installed',
	'gitwire_running_task',
	'gitwire_pending_message',
	'gitwire_orphan_queue',
];

foreach ( $options as $option ) {
	delete_option( $option );
}

\Gitwire\Schema::uninstall();

wp_clear_scheduled_hook( 'gitwire_maintenance' );
wp_clear_scheduled_hook( 'gitwire_trim_logs' );
wp_clear_scheduled_hook( 'gitwire_refresh_repo_list' );
wp_clear_scheduled_hook( 'gitwire_refresh_connections' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_gitwire_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_gitwire_' ) . '%',
		$wpdb->esc_like( 'gitwire_commits_' ) . '%',
		$wpdb->esc_like( 'gitwire_repo_list_' ) . '%',
		$wpdb->esc_like( 'gitwire_repo_type_' ) . '%'
	)
);
