<?php
/**
 * Plugin Name:       Gitwire
 * Plugin URI:        https://gitwire.app
 * Description:       Install and update plugins and themes from GitHub, GitLab, or Bitbucket. Switch branches, and roll back automatically if an update breaks the site.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.0
 * Author:            Gitwire
 * Author URI:        https://gitwire.app
 * License:           GPL-2.0-or-later
 * Text Domain:       gitwire
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
