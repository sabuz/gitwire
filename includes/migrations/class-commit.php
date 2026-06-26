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

	const TABLE = 'gitwire_commits';

	/**
	 * Creates or upgrades the commits table.
	 *
	 * @since 2.0.0
	 * @param string $from Previously stored plugin version; use for version_compare guards on future schema changes.
	 * @return void
	 */
	public function migrate( string $from = '' ): void {
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

	}

	/**
	 * Drops the commits table.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function drop_tables(): void {
		global $wpdb;
		$table = $this->get_table_name( self::TABLE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
}
