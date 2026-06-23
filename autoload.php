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
	'Gitwire\Schema'                 => 'includes/class-schema.php',
	'Gitwire\Connection_Resolver'    => 'includes/class-connection-resolver.php',
	'Gitwire\GitHub_API'             => 'includes/class-github-api.php',
	'Gitwire\Bitbucket_API'          => 'includes/class-bitbucket-api.php',
	'Gitwire\Constants'              => 'includes/class-constants.php',
	'Gitwire\GitLab_API'             => 'includes/class-gitlab-api.php',
	'Gitwire\Error_Handler'          => 'includes/class-error-handler.php',
	'Gitwire\Theme_Scraper'          => 'includes/class-theme-scraper.php',
	'Gitwire\Git_Provider_Interface' => 'includes/interface-git-provider.php',
	'Gitwire\Installer'              => 'includes/class-installer.php',
	'Gitwire\Plugin'                 => 'includes/class-plugin.php',
	'Gitwire\Public_Connections'     => 'includes/class-public-connections.php',
	'Gitwire\Provider_Factory'       => 'includes/class-provider-factory.php',
	'Gitwire\Repositories'       => 'includes/class-repositories.php',
	'Gitwire\Repository_Detector'   => 'includes/class-repository-detector.php',
	'Gitwire\REST'                   => 'includes/class-rest.php',
	'Gitwire\REST_Connection_Cache'  => 'includes/class-rest-connection-cache.php',
	'Gitwire\REST_Connections'       => 'includes/class-rest-connections.php',
	'Gitwire\REST_Settings'          => 'includes/class-rest-settings.php',
	'Gitwire\REST_Repositories'      => 'includes/class-rest-repositories.php',
	'Gitwire\REST_Installer'         => 'includes/class-rest-installer.php',
	'Gitwire\REST_Logs'              => 'includes/class-rest-logs.php',
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
