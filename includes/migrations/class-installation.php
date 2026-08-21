<?php
/**
 * Migration for gitwire_installations.
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
 * Manages schema creation and upgrades for the gitwire_installations table.
 */
class Installation extends Migration_Base {

	const TABLE = 'gitwire_installations';

	/**
	 * Creates or upgrades the installations table.
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
			  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			  connection_id VARCHAR(64) NOT NULL DEFAULT '',
			  provider      VARCHAR(32) NOT NULL,
			  owner         VARCHAR(255) NOT NULL DEFAULT '',
			  name          VARCHAR(255) NOT NULL DEFAULT '',
			  full_name     VARCHAR(255) NOT NULL DEFAULT '',
			  html_url      VARCHAR(512) NOT NULL DEFAULT '',
			  type          VARCHAR(16) NOT NULL DEFAULT 'plugin',
			  install_path  VARCHAR(1024) NOT NULL DEFAULT '',
			  basename      VARCHAR(512) DEFAULT NULL,
			  branch        VARCHAR(255) NOT NULL DEFAULT 'main',
			  auto_update   VARCHAR(16) NOT NULL DEFAULT 'disabled',
			  head          VARCHAR(64) NOT NULL DEFAULT '',
			  remote_head   VARCHAR(64) NOT NULL DEFAULT '',
			  updated_at    DATETIME NOT NULL,
			  PRIMARY KEY  (id),
			  KEY connection_id (connection_id),
			  KEY conn_type (connection_id, type),
			  KEY auto_update (auto_update)
			) {$charset};"
		);

		if ( ! $this->table_is_usable( self::TABLE ) ) {
			return false;
		}

		// rename plugin_file → basename on pre-1.0 installs (dbDelta cannot rename columns).
		if ( $this->column_exists( self::TABLE, 'plugin_file' ) && ! $this->column_exists( self::TABLE, 'basename' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( false === $wpdb->query( "ALTER TABLE `{$table}` CHANGE `plugin_file` `basename` VARCHAR(512) DEFAULT NULL" ) ) {
				return false;
			}
		}

		return $this->ensure_repo_key( $table );
	}

	/**
	 * Ensures the (provider, full_name) unique key exists, without a destructive rebuild.
	 *
	 * Installation::upsert() leans on this key entirely: lose it and every ON DUPLICATE
	 * KEY UPDATE turns into an INSERT. So the key is only dropped when it is genuinely
	 * wrong, and never before the replacement is known to be addable.
	 *
	 * @since 1.0.0
	 * @param string $table Fully-prefixed table name.
	 * @return bool
	 */
	private function ensure_repo_key( string $table ): bool {
		global $wpdb;

		$wanted = [ 'provider', 'full_name' ];

		if ( $this->index_columns( self::TABLE, 'repo' ) === $wanted ) {
			return true;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$duplicates = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (SELECT 1 FROM `{$table}` GROUP BY provider, full_name HAVING COUNT(*) > 1) d"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $duplicates > 0 ) {
			return false;
		}

		if ( $this->index_exists( self::TABLE, 'repo' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( false === $wpdb->query( "ALTER TABLE `{$table}` DROP KEY `repo`" ) ) {
				return false;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY `repo` (`provider`, `full_name`)" );

		return $this->index_columns( self::TABLE, 'repo' ) === $wanted;
	}

	/**
	 * Drops the installations table.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function drop_tables(): void {
		global $wpdb;
		$table = $this->get_table_name( self::TABLE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
}
