<?php
/**
 * Migration for gitwire_commits.
 *
 * @package Gitwire
 * @since 2.0.0
 */

namespace Gitwire\Migrations;

use Gitwire\Migration_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages schema creation and upgrades for the gitwire_commits table.
 */
class Commit extends Migration_Base {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'gitwire_commits_db_version';
	const TABLE             = 'gitwire_commits';

	/**
	 * Creates or upgrades the commits table.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function migrate(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$table   = $this->get_table_name( self::TABLE );
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			  installation_id BIGINT UNSIGNED NOT NULL,
			  branch          VARCHAR(255) NOT NULL DEFAULT 'main',
			  data            MEDIUMTEXT NOT NULL,
			  updated_at      DATETIME NOT NULL,
			  PRIMARY KEY  (installation_id, branch)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Returns true when the table is missing or the stored version is behind.
	 *
	 * @since 2.0.0
	 * @return bool
	 */
	public function needs_migrate(): bool {
		return ! $this->table_exists( self::TABLE )
			|| version_compare( (string) get_option( self::DB_VERSION_OPTION, '' ), self::DB_VERSION, '<' );
	}

	/**
	 * Drops the commits table and removes its version option.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function drop_tables(): void {
		global $wpdb;
		$table = $this->get_table_name( self::TABLE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		delete_option( self::DB_VERSION_OPTION );
	}
}
