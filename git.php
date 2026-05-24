<?php
/**
 * Plugin Name: Git
 * Plugin URI:  https://github.com/sabuz/gwp
 * Description: Pull GitHub and GitLab repositories directly into WordPress as plugins or themes. Switch branches and auto-recover from fatal errors.
 * Version:     1.2.0
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

require_once trailingslashit( __DIR__ ) . 'autoload.php';

use Git_WP\Constants;
use Git_WP\Plugin;

$constants = Constants::instance( __FILE__ );

define( 'GWP_VERSION', $constants->version );
define( 'GWP_FILE', $constants->file );
define( 'GWP_DIR', $constants->dir );
define( 'GWP_URL', $constants->url );
define( 'GWP_BASENAME', $constants->basename );

Plugin::instance( __FILE__ );
