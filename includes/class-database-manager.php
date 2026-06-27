<?php
/**
 * Orchestrates all database migrations.
 *
 * @package Gitwire
 * @since 2.0.0
 */

namespace Gitwire;

use Gitwire\Migrations\Connection as Connection_Migration;
use Gitwire\Migrations\Installation as Installation_Migration;
use Gitwire\Migrations\Repository as Repository_Migration;
use Gitwire\Migrations\Commit as Commit_Migration;

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

	const DB_VERSION_OPTION = 'gitwire_db_version';

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
		$from = (string) get_option( self::DB_VERSION_OPTION, '' );
		Connection_Migration::instance()->migrate( $from );
		Installation_Migration::instance()->migrate( $from );
		Repository_Migration::instance()->migrate( $from );
		Commit_Migration::instance()->migrate( $from );
		update_option( self::DB_VERSION_OPTION, GITWIRE_VERSION, false );
	}

	/**
	 * Returns true when the stored version is behind the current plugin version.
	 *
	 * @since 2.0.0
	 * @return bool
	 */
	public function needs_migrate(): bool {
		return version_compare( (string) get_option( self::DB_VERSION_OPTION, '' ), GITWIRE_VERSION, '<' );
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
		Repository_Migration::instance()->drop_tables();
		Commit_Migration::instance()->drop_tables();
		Installation_Migration::instance()->drop_tables();
		Connection_Migration::instance()->drop_tables();
		delete_option( self::DB_VERSION_OPTION );
	}
}
