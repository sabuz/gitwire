<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Git_WP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = [
	'gwp_settings',
	'gwp_connection_cache',
	'gwp_installed',
	'gwp_pending_update',
	'gwp_fatal_notice',
	'gwp_migrated_from_ghwp',
];

foreach ( $options as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'gwp_auto_check_connection' );
wp_clear_scheduled_hook( 'gwp_maintenance' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_gwp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_gwp_' ) . '%'
	)
);
