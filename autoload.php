<?php
/**
 * Autoloads classes for the Git for WordPress plugin.
 * Uses class mapping for fast, direct file loading.
 *
 * @package Git_WP
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class mapping array for fast autoloading.
 *
 * @var array<string, string> $class_map
 */
$class_map = [
	'Git_WP\Admin'         => 'includes/class-admin.php',
	'Git_WP\API'           => 'includes/class-api.php',
	'Git_WP\GitLab_API'    => 'includes/class-gitlab-api.php',
	'Git_WP\Error_Handler' => 'includes/class-error-handler.php',
	'Git_WP\Installer'     => 'includes/class-installer.php',
	'Git_WP\REST'          => 'includes/class-rest.php',
];

spl_autoload_register(
	function ( $class_name ) use ( $class_map ) {
		if ( isset( $class_map[ $class_name ] ) ) {
			$file_path = trailingslashit( __DIR__ ) . $class_map[ $class_name ];

			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			}
		}
	}
);
