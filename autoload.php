<?php
/**
 * Autoloads classes for the Gitwire plugin.
 * Uses class mapping for fast, direct file loading.
 *
 * @package Gitwire
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
	'Gitwire\Admin'                  => 'includes/class-admin.php',
	'Gitwire\Connection_Resolver'    => 'includes/class-connection-resolver.php',
	'Gitwire\API'                    => 'includes/class-api.php',
	'Gitwire\Bitbucket_API'          => 'includes/class-bitbucket-api.php',
	'Gitwire\Constants'              => 'includes/class-constants.php',
	'Gitwire\GitLab_API'             => 'includes/class-gitlab-api.php',
	'Gitwire\Error_Handler'          => 'includes/class-error-handler.php',
	'Gitwire\Theme_Scraper'          => 'includes/class-theme-scraper.php',
	'Gitwire\Git_Provider_Interface' => 'includes/interface-git-provider.php',
	'Gitwire\Installer'              => 'includes/class-installer.php',
	'Gitwire\Plugin'                 => 'includes/class-plugin.php',
	'Gitwire\Provider_Factory'       => 'includes/class-provider-factory.php',
	'Gitwire\Repo_Cache'             => 'includes/class-repo-cache.php',
	'Gitwire\Repo_Detector'          => 'includes/class-repo-detector.php',
	'Gitwire\REST'                   => 'includes/class-rest.php',
	'Gitwire\Logger'                 => 'includes/class-logger.php',
	'Gitwire\Settings'               => 'includes/helper/class-settings.php',
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
