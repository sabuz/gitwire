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
 * @var array<string, string> $gitwire_class_map
 */
$gitwire_class_map = [
	'Gitwire\Admin'                   => 'includes/class-admin.php',
	'Gitwire\Migration_Base'          => 'includes/class-migration-base.php',
	'Gitwire\Model_Base'              => 'includes/class-model-base.php',
	'Gitwire\Database_Manager'        => 'includes/class-database-manager.php',
	'Gitwire\Models\Connection'       => 'includes/models/class-connection.php',
	'Gitwire\Models\Installation'     => 'includes/models/class-installation.php',
	'Gitwire\Models\Repository'       => 'includes/models/class-repository.php',
	'Gitwire\Models\Commit'           => 'includes/models/class-commit.php',
	'Gitwire\Migrations\Connection'   => 'includes/migrations/class-connection.php',
	'Gitwire\Migrations\Installation' => 'includes/migrations/class-installation.php',
	'Gitwire\Migrations\Repository'   => 'includes/migrations/class-repository.php',
	'Gitwire\Migrations\Commit'       => 'includes/migrations/class-commit.php',
	'Gitwire\Connection_Resolver'     => 'includes/class-connection-resolver.php',
	'Gitwire\GitHub_API'              => 'includes/class-github-api.php',
	'Gitwire\Bitbucket_API'           => 'includes/class-bitbucket-api.php',
	'Gitwire\Constants'               => 'includes/class-constants.php',
	'Gitwire\GitLab_API'              => 'includes/class-gitlab-api.php',
	'Gitwire\Error_Handler'           => 'includes/class-error-handler.php',
	'Gitwire\Filesystem_Guard'        => 'includes/class-filesystem-guard.php',
	'Gitwire\Theme_Scraper'           => 'includes/class-theme-scraper.php',
	'Gitwire\Git_Provider_Interface'  => 'includes/interface-git-provider.php',
	'Gitwire\Installer'               => 'includes/class-installer.php',
	'Gitwire\Plugin'                  => 'includes/class-plugin.php',
	'Gitwire\Public_Connections'      => 'includes/class-public-connections.php',
	'Gitwire\Provider_Factory'        => 'includes/class-provider-factory.php',
	'Gitwire\Repositories'            => 'includes/class-repositories.php',
	'Gitwire\Repository_Detector'     => 'includes/class-repository-detector.php',
	'Gitwire\REST'                    => 'includes/class-rest.php',
	'Gitwire\Connection_Meta'         => 'includes/class-connection-meta.php',
	'Gitwire\REST_Connections'        => 'includes/class-rest-connections.php',
	'Gitwire\REST_Settings'           => 'includes/class-rest-settings.php',
	'Gitwire\REST_Repositories'       => 'includes/class-rest-repositories.php',
	'Gitwire\REST_Installer'          => 'includes/class-rest-installer.php',
	'Gitwire\REST_Logs'               => 'includes/class-rest-logs.php',
	'Gitwire\Logger'                  => 'includes/class-logger.php',
	'Gitwire\Settings'                => 'includes/helper/class-settings.php',
];

spl_autoload_register(
	function ( $class_name ) use ( $gitwire_class_map ) {
		if ( isset( $gitwire_class_map[ $class_name ] ) ) {
			$file_path = trailingslashit( __DIR__ ) . $gitwire_class_map[ $class_name ];

			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			}
		}
	}
);
