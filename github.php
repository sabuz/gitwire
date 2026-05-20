<?php
/**
 * Plugin Name: GitHub for WordPress
 * Plugin URI:  https://fastlinemedia.com
 * Description: Pull GitHub repositories directly into WordPress as plugins or themes. Switch branches and auto-recover from fatal errors.
 * Version:     1.0.0
 * Author:      Nazmul Sabuz
 * Author URI:  https://profiles.wordpress.org/nazsabuz
 * License:     GPL-2.0-or-later
 * Text Domain: ghwp
 */

defined( 'ABSPATH' ) || exit;

define( 'GHWP_VERSION',  '1.0.0' );
define( 'GHWP_FILE',     __FILE__ );
define( 'GHWP_DIR',      plugin_dir_path( __FILE__ ) );
define( 'GHWP_URL',      plugin_dir_url( __FILE__ ) );
define( 'GHWP_BASENAME', plugin_basename( __FILE__ ) );

require_once GHWP_DIR . 'includes/class-ghwp-error-handler.php';
require_once GHWP_DIR . 'includes/class-ghwp-api.php';
require_once GHWP_DIR . 'includes/class-ghwp-installer.php';
require_once GHWP_DIR . 'includes/class-ghwp-admin.php';
require_once GHWP_DIR . 'includes/class-ghwp-rest.php';

// Register shutdown handler as early as possible so it catches
// fatal errors introduced by any plugin we install or update.
GHWP_Error_Handler::register();

add_action( 'plugins_loaded', static function () {
	GHWP_REST::init();
	if ( is_admin() ) {
		GHWP_Admin::init();
	}
} );

register_activation_hook( GHWP_FILE, static function () {
	if ( ! get_option( 'ghwp_settings' ) ) {
		add_option( 'ghwp_settings', [ 'token' => '', 'username' => '', 'smart_install' => true ] );
	}
} );

register_deactivation_hook( GHWP_FILE, static function () {
	delete_transient( 'ghwp_repos_cache' );
} );
