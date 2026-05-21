<?php
/**
 * Plugin Name: Git
 * Plugin URI:  https://github.com/sabuz/gwp
 * Description: Pull GitHub and GitLab repositories directly into WordPress as plugins or themes. Switch branches and auto-recover from fatal errors.
 * Version:     1.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author:      Nazmul Sabuz
 * Author URI:  https://profiles.wordpress.org/nazsabuz
 * License:     GPL-2.0-or-later
 * Text Domain: git
 *
 * @package Git_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GWP_VERSION', '1.1.0' );
define( 'GWP_FILE', __FILE__ );
define( 'GWP_DIR', plugin_dir_path( __FILE__ ) );
define( 'GWP_URL', plugin_dir_url( __FILE__ ) );
define( 'GWP_BASENAME', plugin_basename( __FILE__ ) );

require_once trailingslashit( __DIR__ ) . 'autoload.php';

use Git_WP\Admin;
use Git_WP\Error_Handler;
use Git_WP\Installer;
use Git_WP\REST;

// Register shutdown handler as early as possible so it catches fatal errors.
Error_Handler::register();

// One-time migration: rename option keys from ghwp_* to gwp_*.
add_action(
	'init',
	static function () {
		if ( get_option( 'gwp_migrated_from_ghwp' ) ) {
			return;
		}
		$map = [
			'ghwp_settings'         => 'gwp_settings',
			'ghwp_connection_cache' => 'gwp_connection_cache',
			'ghwp_installed'        => 'gwp_installed',
			'ghwp_pending_update'   => 'gwp_pending_update',
			'ghwp_fatal_notice'     => 'gwp_fatal_notice',
		];
		foreach ( $map as $old => $new ) {
			$value = get_option( $old );
			if ( false !== $value && false === get_option( $new ) ) {
				add_option( $new, $value );
				delete_option( $old );
			}
		}
		add_option( 'gwp_migrated_from_ghwp', true );
	},
	1
);

// Register a custom 30-minute cron interval.
add_filter(
	'cron_schedules',
	static function ( array $schedules ): array {
		$schedules['gwp_half_hourly'] = [
			'interval' => 1800,
			'display'  => __( 'Every 30 minutes', 'git' ),
		];
		return $schedules;
	}
);

// Cron callback — silently refresh the cached connection status.
add_action(
	'gwp_auto_check_connection',
	static function () {
		if ( get_option( 'gwp_settings' ) ) {
			REST::test_connection();
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		Installer::init();
		REST::init();
		if ( is_admin() ) {
			Admin::init();
		}

		// Re-schedule if the event was cleared without a full deactivation.
		if ( ! wp_next_scheduled( 'gwp_auto_check_connection' ) ) {
			wp_schedule_event( time(), 'gwp_half_hourly', 'gwp_auto_check_connection' );
		}
	}
);

register_activation_hook(
	GWP_FILE,
	static function () {
		if ( ! get_option( 'gwp_settings' ) ) {
			add_option(
				'gwp_settings',
				[
					'token'         => '',
					'username'      => '',
					'smart_install' => true,
				]
			);
		}
		set_transient( 'gwp_first_activation', true, 60 );
		wp_schedule_event( time(), 'gwp_half_hourly', 'gwp_auto_check_connection' );
	}
);

register_deactivation_hook(
	GWP_FILE,
	static function () {
		delete_transient( 'gwp_repos_cache' );
		wp_clear_scheduled_hook( 'gwp_auto_check_connection' );
	}
);
