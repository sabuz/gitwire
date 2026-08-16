<?php
/**
 * Migration for gitwire_repositories.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire\Migrations;

use Gitwire\Migration_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages schema creation and upgrades for the gitwire_repositories table.
 */
class Repository extends Migration_Base {

	const TABLE = 'gitwire_repositories';

	/**
	 * Creates or upgrades the repositories table.
	 *
	 * @since 1.0.0
	 * @param string $from Previously stored plugin version; use for version_compare guards on future schema changes.
	 * @return bool True when the schema is in the expected state after the call.
	 */
	public function migrate( string $from = '' ): bool {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$table   = $this->get_table_name( self::TABLE );
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			  connection_id    VARCHAR(64) NOT NULL,
			  provider         VARCHAR(32) NOT NULL,
			  owner            VARCHAR(255) NOT NULL DEFAULT '',
			  name             VARCHAR(255) NOT NULL DEFAULT '',
			  full_name        VARCHAR(255) NOT NULL DEFAULT '',
			  html_url         VARCHAR(512) NOT NULL DEFAULT '',
			  private          TINYINT(1) NOT NULL DEFAULT 0,
			  type             VARCHAR(16) NOT NULL DEFAULT '',
			  type_meta        TEXT DEFAULT NULL,
			  default_branch   VARCHAR(255) NOT NULL DEFAULT 'main',
			  last_activity_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			  updated_at       DATETIME NOT NULL,
			  PRIMARY KEY  (connection_id, full_name),
			  KEY provider_full_name (provider, full_name),
			  KEY type (type),
			  KEY conn_last_activity (connection_id, last_activity_at)
			) {$charset};"
		);

		return $this->table_is_usable( self::TABLE );
	}

	/**
	 * Drops the repositories table.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function drop_tables(): void {
		global $wpdb;
		$table = $this->get_table_name( self::TABLE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
}
