<?php
/**
 * Plugin Name:       Gitwire
 * Plugin URI:        https://gitwire.app
 * Description:       Install and update WordPress plugins and themes from Git repositories. Switch branches when needed, and recover safely if an update causes a fatal error.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Gitwire
 * Author URI:        https://gitwire.app
 * License:           GPL-2.0-or-later
 * Text Domain:       gitwire
 * Domain Path:       /languages
 *
 * @package Gitwire
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once trailingslashit( __DIR__ ) . 'autoload.php';

use Gitwire\Constants;
use Gitwire\Plugin;

$constants = Constants::instance( __FILE__ );

define( 'GITWIRE_VERSION', $constants->version );
define( 'GITWIRE_FILE', $constants->file );
define( 'GITWIRE_DIR', $constants->dir );
define( 'GITWIRE_URL', $constants->url );
define( 'GITWIRE_BASENAME', $constants->basename );

Plugin::instance( __FILE__ );
