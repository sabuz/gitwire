<?php
/**
 * Plugin Name: Gitwire
 * Plugin URI:  https://gitwire.app
 * Description: Install and update plugins and themes from any Git repository. Switch branches and auto-recover from fatal errors.
 * Version:     1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author:      Nazmul Sabuz
 * Author URI:  https://profiles.wordpress.org/nazsabuz
 * License:     GPL-2.0-or-later
 * Text Domain: gitwire
 * Domain Path: /languages
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
