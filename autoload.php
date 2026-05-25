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
	'Git_WP\Admin'                  => 'includes/class-admin.php',
	'Git_WP\API'                    => 'includes/class-api.php',
	'Git_WP\Constants'              => 'includes/class-constants.php',
	'Git_WP\GitLab_API'             => 'includes/class-gitlab-api.php',
	'Git_WP\Error_Handler'          => 'includes/class-error-handler.php',
	'Git_WP\Theme_Scraper'          => 'includes/class-theme-scraper.php',
	'Git_WP\Git_Provider_Interface' => 'includes/interface-git-provider.php',
	'Git_WP\Installer'              => 'includes/class-installer.php',
	'Git_WP\Plugin'                 => 'includes/class-plugin.php',
	'Git_WP\Provider_Factory'       => 'includes/class-provider-factory.php',
	'Git_WP\Repo_Cache'             => 'includes/class-repo-cache.php',
	'Git_WP\Repo_Detector'          => 'includes/class-repo-detector.php',
	'Git_WP\REST'                   => 'includes/class-rest.php',
	'Git_WP\Settings'               => 'includes/helper/class-settings.php',
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
