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
	const DB_VERSION = '1.3.0';

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

		// Username-only (unauthenticated) connections — free plugin source of truth.
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_public_connections (
				id VARCHAR(64) NOT NULL,
				provider VARCHAR(20) NOT NULL,
				identifier VARCHAR(255) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY provider (provider)
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

		// Installed repository records (replaces gitwire_installed option).
		// owner and repo are derived from full_name at read time; not stored here.
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_installed (
				provider VARCHAR(20) NOT NULL,
				full_name VARCHAR(255) NOT NULL,
				slug VARCHAR(255) NOT NULL DEFAULT '',
				branch VARCHAR(255) NOT NULL DEFAULT 'main',
				head VARCHAR(40) NOT NULL DEFAULT '',
				remote_head VARCHAR(40) NOT NULL DEFAULT '',
				type VARCHAR(10) NOT NULL DEFAULT 'plugin',
				subtype VARCHAR(10) NOT NULL DEFAULT '',
				install_path VARCHAR(1024) NOT NULL DEFAULT '',
				plugin_file VARCHAR(512) NOT NULL DEFAULT '',
				connection_id VARCHAR(64) NOT NULL DEFAULT '',
				installed_at INT UNSIGNED NOT NULL DEFAULT 0,
				updated_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (provider, full_name)
			) $charset;"
		);

		// Cached commit history per repo/branch (replaces gitwire_commits_* options).
		// data stores the JSON-encoded commit array returned by the provider API.
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_commits (
				provider VARCHAR(20) NOT NULL,
				full_name VARCHAR(255) NOT NULL,
				branch VARCHAR(255) NOT NULL,
				data MEDIUMTEXT NOT NULL,
				updated_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (provider, full_name, branch)
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
		foreach ( [ 'gitwire_public_connections', 'gitwire_connection_meta', 'gitwire_installed', 'gitwire_commits' ] as $table ) {
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_commits" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_installed" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_connection_meta" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_public_connections" );

		delete_option( self::VERSION_OPTION );
	}
}
