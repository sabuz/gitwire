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
	const DB_VERSION = '1.1.0';

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

		// Provider-specific extras keyed by connection_id + meta_key.
		// Current uses: gitlab_url, avatar_url, name for GitLab public connections;
		// gitlab_url for Pro authenticated GitLab connections.
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_connection_meta (
				connection_id VARCHAR(64) NOT NULL,
				meta_key VARCHAR(100) NOT NULL,
				meta_value TEXT NOT NULL,
				PRIMARY KEY  (connection_id, meta_key)
			) $charset;"
		);

		// Profile + rate-limit data for all connections (public and authenticated).
		// Shared by both free and Pro — keyed by connection_id.
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_connections_metadata (
				connection_id VARCHAR(64) NOT NULL,
				provider VARCHAR(20) NOT NULL DEFAULT '',
				authenticated TINYINT(1) NOT NULL DEFAULT 0,
				username VARCHAR(255) NOT NULL DEFAULT '',
				name VARCHAR(255) NOT NULL DEFAULT '',
				avatar_url VARCHAR(500) NOT NULL DEFAULT '',
				rate_limit INT NOT NULL DEFAULT 0,
				rate_remaining INT NOT NULL DEFAULT 0,
				rate_reset INT NOT NULL DEFAULT 0,
				checked_at INT NOT NULL DEFAULT 0,
				error TEXT NULL,
				PRIMARY KEY  (connection_id)
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
		foreach ( [ 'gitwire_public_connections', 'gitwire_connection_meta', 'gitwire_connections_metadata' ] as $table ) {
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
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_connections_metadata" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_connection_meta" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_public_connections" );

		delete_option( self::VERSION_OPTION );
	}
}
