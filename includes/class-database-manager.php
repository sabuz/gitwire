<?php
/**
 * Orchestrates all database migrations.
 *
 * @package Gitwire
 * @since 2.0.0
 */

namespace Gitwire;

use Gitwire\Migrations\Connection as ConnectionMigration;
use Gitwire\Migrations\Installation as InstallationMigration;
use Gitwire\Migrations\Repository as RepositoryMigration;
use Gitwire\Migrations\Commit as CommitMigration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central entry point for plugin schema management.
 *
 * Instantiate from Plugin::__construct() — not boot()/plugins_loaded — so the
 * activation hook registers before the main plugin file finishes loading.
 *
 * uninstall.php exists and takes precedence; do NOT add register_uninstall_hook().
 */
class Database_Manager {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers the activation and update hooks.
	 *
	 * @since 2.0.0
	 */
	private function __construct() {
		register_activation_hook( GITWIRE_FILE, [ $this, 'migrate' ] );
		add_action( 'upgrader_process_complete', [ $this, 'maybe_migrate' ], 10, 2 );
	}

	/**
	 * Runs all pending migrations in dependency order.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function migrate(): void {
		ConnectionMigration::instance()->migrate();
		InstallationMigration::instance()->migrate();
		RepositoryMigration::instance()->migrate();
		CommitMigration::instance()->migrate();
		delete_option( 'gitwire_db_version' );
	}

	/**
	 * Returns true when any table is missing or behind version.
	 *
	 * @since 2.0.0
	 * @return bool
	 */
	public function needs_migrate(): bool {
		return ConnectionMigration::instance()->needs_migrate()
			|| InstallationMigration::instance()->needs_migrate()
			|| RepositoryMigration::instance()->needs_migrate()
			|| CommitMigration::instance()->needs_migrate();
	}

	/**
	 * Fires on WP_Upgrader completion; migrates only when this plugin updated.
	 *
	 * Handles both single-plugin ('plugin' key) and bulk ('plugins' key) upgrader
	 * payloads — the WP core upgrader uses different keys depending on context.
	 *
	 * @since 2.0.0
	 * @param mixed $upgrader  WP_Upgrader instance (unused).
	 * @param array $hook_extra Upgrader context data.
	 * @return void
	 */
	public function maybe_migrate( $upgrader, array $hook_extra ): void {
		if ( ( $hook_extra['action'] ?? '' ) !== 'update' || ( $hook_extra['type'] ?? '' ) !== 'plugin' ) {
			return;
		}

		$plugins = array_filter( [
			$hook_extra['plugin'] ?? '',
			...( (array) ( $hook_extra['plugins'] ?? [] ) ),
		] );

		if ( ! in_array( plugin_basename( GITWIRE_FILE ), $plugins, true ) ) {
			return;
		}

		$this->migrate();
	}

	/**
	 * Drops all plugin tables. Called from uninstall.php.
	 *
	 * Tables are dropped in reverse dependency order to avoid FK-style issues.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public static function uninstall(): void {
		RepositoryMigration::instance()->drop_tables();
		CommitMigration::instance()->drop_tables();
		InstallationMigration::instance()->drop_tables();
		ConnectionMigration::instance()->drop_tables();
		delete_option( 'gitwire_db_version' );
	}
}
