<?php
/**
 * Migration for gitwire_installations.
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
 * Manages schema creation and upgrades for the gitwire_installations table.
 */
class Installation extends Migration_Base {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'gitwire_installations_db_version';
	const TABLE             = 'gitwire_installations';

	/**
	 * Creates or upgrades the installations table.
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

		// rename plugin_file → basename on pre-1.0 installs (dbDelta cannot rename columns)
		if ( $this->column_exists( self::TABLE, 'plugin_file' ) && ! $this->column_exists( self::TABLE, 'basename' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` CHANGE `plugin_file` `basename` VARCHAR(512) DEFAULT NULL" );
		}

		// dbDelta cannot reliably manage key renames; handle the unique constraint explicitly.
		if ( $this->index_exists( self::TABLE, 'repo' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` DROP KEY `repo`" );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY `repo` (`provider`, `full_name`)" );

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
	 * Drops the installations table and removes its version option.
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
