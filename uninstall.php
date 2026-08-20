<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Gitwire
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$gitwire_settings = get_option( 'gitwire_settings', [] );
if ( empty( $gitwire_settings['remove_data_on_uninstall'] ) ) {
	return;
}

require_once plugin_dir_path( __FILE__ ) . 'autoload.php';

$gitwire_options = [
	'gitwire_settings',
	'gitwire_running_task',
	'gitwire_pending_message',
	'gitwire_pending_deactivate',
	'gitwire_orphan_queue',
	'gitwire_detection_cursor',
	'gitwire_refresh_state',
	'gitwire_version_cache',
];

foreach ( $gitwire_options as $gitwire_option ) {
	delete_option( $gitwire_option );
}

\Gitwire\Database_Manager::uninstall();

wp_clear_scheduled_hook( 'gitwire_maintenance' );
wp_clear_scheduled_hook( 'gitwire_trim_logs' );
wp_clear_scheduled_hook( 'gitwire_refresh_repositories' );
wp_clear_scheduled_hook( 'gitwire_refresh_connections' );
wp_clear_scheduled_hook( 'gitwire_update_check' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_gitwire_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_gitwire_' ) . '%',
		$wpdb->esc_like( 'gitwire_commits_' ) . '%',
		$wpdb->esc_like( 'gitwire_repo_type_' ) . '%',
		$wpdb->esc_like( 'gitwire_lock_' ) . '%'
	)
);

\Gitwire\Installer::purge_orphaned_backups();
\Gitwire\Installer::remove_backup_base_dir();
\Gitwire\Logger::uninstall();
