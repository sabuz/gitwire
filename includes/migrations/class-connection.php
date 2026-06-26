<?php
/**
 * Migration for gitwire_connections.
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
 * Manages schema creation and upgrades for the gitwire_connections table.
 */
class Connection extends Migration_Base {

	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'gitwire_connections_db_version';
	const TABLE             = 'gitwire_connections';

	/**
	 * Creates or upgrades the connections table.
	 *
	 * Pro-only columns (credentials, scope, email) are managed entirely by the Pro plugin
	 * via its activation/deactivation hooks — this migration does not touch them.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function migrate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $this->get_table_name( self::TABLE );
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			  id             VARCHAR(64) NOT NULL,
			  provider       VARCHAR(32) NOT NULL,
			  host_url       VARCHAR(512) NULL,
			  identifier     VARCHAR(255) NOT NULL DEFAULT '',
			  authenticated  TINYINT(1) NOT NULL DEFAULT 0,
			  error          TEXT NULL,
			  name           VARCHAR(255) NOT NULL DEFAULT '',
			  avatar_url     VARCHAR(512) NOT NULL DEFAULT '',
			  rate_limit     INT UNSIGNED NOT NULL DEFAULT 0,
			  rate_remaining INT UNSIGNED NOT NULL DEFAULT 0,
			  rate_reset     INT UNSIGNED NOT NULL DEFAULT 0,
			  created_at     DATETIME NOT NULL,
			  updated_at     DATETIME NOT NULL,
			  PRIMARY KEY  (id),
			  KEY provider (provider)
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
	 * Drops the connections table and removes its version option.
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
