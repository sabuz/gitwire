<?php
/**
 * Creates and drops the plugin's custom database tables.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the plugin's custom database tables.
 */
class Schema {

	/**
	 * Schema version stored in options to detect when tables need updating.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option key used to track the installed schema version.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'gitwire_db_version';

	/**
	 * Creates or upgrades all plugin tables via dbDelta.
	 *
	 * Safe to call on every activation and on boot when the version differs.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		$prefix  = $wpdb->base_prefix;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Unified connection store — public (credentials IS NULL) and private (encrypted JSON).
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_connections (
				id VARCHAR(64) NOT NULL,
				provider VARCHAR(20) NOT NULL,
				identifier VARCHAR(255) NOT NULL DEFAULT '',
				credentials TEXT NULL,
				scope VARCHAR(20) NOT NULL DEFAULT 'all',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY provider (provider),
				KEY scope (scope)
			) $charset;"
		);

		// Key-value store for all connection extras: gitlab_url (config) and
		// profile cache fields (provider, username, avatar_url, rate data, etc.).
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_connection_meta (
				connection_id VARCHAR(64) NOT NULL,
				meta_key VARCHAR(100) NOT NULL,
				meta_value TEXT NOT NULL,
				PRIMARY KEY  (connection_id, meta_key)
			) $charset;"
		);

		// Installed repository records.
		// type stores the flat detection value: 'plugin', 'block-theme', 'classic-theme'.
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_installed (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				provider VARCHAR(20) NOT NULL,
				full_name VARCHAR(255) NOT NULL,
				slug VARCHAR(255) NOT NULL DEFAULT '',
				branch VARCHAR(255) NOT NULL DEFAULT 'main',
				head VARCHAR(40) NOT NULL DEFAULT '',
				remote_head VARCHAR(40) NOT NULL DEFAULT '',
				type VARCHAR(20) NOT NULL DEFAULT 'plugin',
				install_path VARCHAR(1024) NOT NULL DEFAULT '',
				plugin_file VARCHAR(512) NOT NULL DEFAULT '',
				connection_id VARCHAR(64) NOT NULL DEFAULT '',
				installed_at INT UNSIGNED NOT NULL DEFAULT 0,
				updated_at INT UNSIGNED NOT NULL DEFAULT 0,
				auto_update TINYINT(1) NOT NULL DEFAULT 0,
				auto_update_scope VARCHAR(10) NOT NULL DEFAULT 'current',
				PRIMARY KEY  (id),
				UNIQUE KEY repo (provider, full_name)
			) $charset;"
		);

		// Cached commit history per installed repo/branch.
		// data stores the JSON-encoded commit array returned by the provider API.
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_commits (
				installed_id BIGINT UNSIGNED NOT NULL,
				branch VARCHAR(255) NOT NULL DEFAULT 'main',
				data MEDIUMTEXT NOT NULL,
				updated_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (installed_id, branch)
			) $charset;"
		);

		// Cached repository list and type detection — one row per repo per connection.
		// last_activity_at: repo's last activity timestamp from the provider (ISO 8601).
		// updated_at:    when this cache row was last written by cron (Unix timestamp).
		// type:          flat detection value ('plugin','block-theme','classic-theme','unknown','').
		// type_meta:     detection payload JSON (confidence, name, key_files).
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_repo_cache (
				connection_id  VARCHAR(64) NOT NULL,
				provider       VARCHAR(20) NOT NULL,
				owner          VARCHAR(128) NOT NULL DEFAULT '',
				name           VARCHAR(128) NOT NULL DEFAULT '',
				full_name      VARCHAR(255) NOT NULL,
				private        TINYINT(1) NOT NULL DEFAULT 0,
				html_url       VARCHAR(512) NOT NULL DEFAULT '',
				default_branch VARCHAR(255) NOT NULL DEFAULT 'main',
				last_activity_at  VARCHAR(32) NOT NULL DEFAULT '',
				type           VARCHAR(20) NOT NULL DEFAULT '',
				type_meta      TEXT DEFAULT NULL,
				updated_at     INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (connection_id, full_name),
				KEY provider_full_name (provider, full_name),
				KEY type (type)
			) $charset;"
		);

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Returns true when any plugin table is missing or the schema version is behind.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function needs_install(): bool {
		return ! self::tables_exist() || get_option( self::VERSION_OPTION ) !== self::DB_VERSION;
	}

	/**
	 * Returns true when all plugin tables exist in the database.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private static function tables_exist(): bool {
		global $wpdb;
		$prefix = $wpdb->base_prefix;
		foreach ( [ 'gitwire_connections', 'gitwire_connection_meta', 'gitwire_installed', 'gitwire_commits', 'gitwire_repo_cache' ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . $table ) ) !== $prefix . $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Drops all plugin tables and removes the version option.
	 *
	 * Called only when the user has opted to remove all data on uninstall.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;

		$prefix = $wpdb->base_prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_repo_cache" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_commits" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_installed" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_connection_meta" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_connections" );

		delete_option( self::VERSION_OPTION );
	}
}
