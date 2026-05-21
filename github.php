<?php
/**
 * Plugin Name: GitHub for WordPress
 * Plugin URI:  https://github.com/sabuz/ghwp
 * Description: Pull GitHub repositories directly into WordPress as plugins or themes. Switch branches and auto-recover from fatal errors.
 * Version:     1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author:      Nazmul Sabuz
 * Author URI:  https://profiles.wordpress.org/nazsabuz
 * License:     GPL-2.0-or-later
 * Text Domain: ghwp
 *
 * @package GitHub_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GHWP_VERSION', '1.0.0' );
define( 'GHWP_FILE', __FILE__ );
define( 'GHWP_DIR', plugin_dir_path( __FILE__ ) );
define( 'GHWP_URL', plugin_dir_url( __FILE__ ) );
define( 'GHWP_BASENAME', plugin_basename( __FILE__ ) );

require_once trailingslashit( __DIR__ ) . 'autoload.php';

use GitHub_WP\Admin;
use GitHub_WP\Error_Handler;
use GitHub_WP\Installer;
use GitHub_WP\REST;

// Register shutdown handler as early as possible so it catches fatal errors.
Error_Handler::register();

// Register a custom 30-minute cron interval.
add_filter(
	'cron_schedules',
	static function ( array $schedules ): array {
		$schedules['ghwp_half_hourly'] = [
			'interval' => 1800,
			'display'  => __( 'Every 30 minutes', 'ghwp' ),
		];
		return $schedules;
	}
);

// Cron callback — silently refresh the cached connection status.
add_action(
	'ghwp_auto_check_connection',
	static function () {
		if ( get_option( 'ghwp_settings' ) ) {
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
		if ( ! wp_next_scheduled( 'ghwp_auto_check_connection' ) ) {
			wp_schedule_event( time(), 'ghwp_half_hourly', 'ghwp_auto_check_connection' );
		}
	}
);

register_activation_hook(
	GHWP_FILE,
	static function () {
		if ( ! get_option( 'ghwp_settings' ) ) {
			add_option(
				'ghwp_settings',
				[
					'token'         => '',
					'username'      => '',
					'smart_install' => true,
				]
			);
		}
		set_transient( 'ghwp_first_activation', true, 60 );
		wp_schedule_event( time(), 'ghwp_half_hourly', 'ghwp_auto_check_connection' );
	}
);

register_deactivation_hook(
	GHWP_FILE,
	static function () {
		delete_transient( 'ghwp_repos_cache' );
		wp_clear_scheduled_hook( 'ghwp_auto_check_connection' );
	}
);
