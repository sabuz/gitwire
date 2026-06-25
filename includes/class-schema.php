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
	const DB_VERSION = '2.0.0';

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

		// Unified connection store — identity, auth config, profile cache, and timestamps.
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_connections (
				id             VARCHAR(64) NOT NULL,
				provider       VARCHAR(32) NOT NULL,
				identifier     VARCHAR(255) NOT NULL DEFAULT '',
				email          VARCHAR(255) NULL,
				credentials    TEXT NULL,
				host_url       VARCHAR(512) NULL,
				scope          VARCHAR(16) NOT NULL DEFAULT 'all',
				authenticated  TINYINT(1) NOT NULL DEFAULT 0,
				name           VARCHAR(255) NOT NULL DEFAULT '',
				avatar_url     VARCHAR(512) NOT NULL DEFAULT '',
				rate_limit     INT UNSIGNED NOT NULL DEFAULT 0,
				rate_remaining INT UNSIGNED NOT NULL DEFAULT 0,
				rate_reset     INT UNSIGNED NOT NULL DEFAULT 0,
				error          TEXT NULL,
				created_at     DATETIME NOT NULL,
				updated_at     DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY provider (provider)
			) $charset;"
		);

		// Installed repository records.
		// type stores the flat detection value: 'plugin', 'block-theme', 'classic-theme'.
		// auto_update: 'disabled' | 'current' (branch-locked) | 'any'.
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_installations (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				connection_id VARCHAR(64) NOT NULL DEFAULT '',
				provider VARCHAR(32) NOT NULL,
				owner VARCHAR(128) NOT NULL DEFAULT '',
				name VARCHAR(255) NOT NULL DEFAULT '',
				full_name VARCHAR(255) NOT NULL DEFAULT '',
				type VARCHAR(16) NOT NULL DEFAULT 'plugin',
				branch VARCHAR(255) NOT NULL DEFAULT 'main',
				head VARCHAR(40) NOT NULL DEFAULT '',
				remote_head VARCHAR(40) NOT NULL DEFAULT '',
				install_path VARCHAR(1024) NOT NULL DEFAULT '',
				html_url VARCHAR(512) NOT NULL DEFAULT '',
				plugin_file VARCHAR(512) DEFAULT NULL,
				auto_update VARCHAR(16) NOT NULL DEFAULT 'disabled',
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY repo (provider, full_name),
				KEY connection_id (connection_id),
				KEY conn_type (connection_id, type)
			) $charset;"
		);

		// Cached commit history per installed repo/branch.
		// data stores the JSON-encoded commit array returned by the provider API.
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_commits (
				installation_id BIGINT UNSIGNED NOT NULL,
				branch VARCHAR(255) NOT NULL DEFAULT 'main',
				data MEDIUMTEXT NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (installation_id, branch)
			) $charset;"
		);

		// Repository listing fetched from each connection — one row per repo.
		// last_activity_at: last activity from the provider API.
		// updated_at:       when this row was last refreshed by cron.
		// type:             flat detection value ('plugin','block-theme','classic-theme','unknown','').
		// type_meta:        detection payload JSON (confidence, name, key_files).
		dbDelta(
			"CREATE TABLE {$prefix}gitwire_repositories (
				connection_id    VARCHAR(64) NOT NULL,
				provider         VARCHAR(32) NOT NULL,
				owner            VARCHAR(128) NOT NULL DEFAULT '',
				name             VARCHAR(255) NOT NULL DEFAULT '',
				full_name        VARCHAR(255) NOT NULL DEFAULT '',
				private          TINYINT(1) NOT NULL DEFAULT 0,
				default_branch   VARCHAR(255) NOT NULL DEFAULT 'main',
				html_url         VARCHAR(512) NOT NULL DEFAULT '',
				type             VARCHAR(16) NOT NULL DEFAULT '',
				type_meta        TEXT DEFAULT NULL,
				last_activity_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
				updated_at       DATETIME NOT NULL,
				PRIMARY KEY  (connection_id, full_name),
				KEY provider_full_name (provider, full_name),
				KEY type (type),
				KEY conn_last_activity (connection_id, last_activity_at)
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
		return ! self::tables_exist() || version_compare( (string) get_option( self::VERSION_OPTION, '1.0.0' ), self::DB_VERSION, '<' );
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
		foreach ( [ 'gitwire_connections', 'gitwire_installations', 'gitwire_commits', 'gitwire_repositories' ] as $table ) {
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
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_repositories" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_commits" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_installations" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}gitwire_connections" );

		delete_option( self::VERSION_OPTION );
	}
}
