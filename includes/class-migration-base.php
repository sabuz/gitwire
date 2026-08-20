<?php
/**
 * Base class for database migrations.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class providing schema helpers for table migrations.
 *
 * @phpstan-consistent-constructor
 */
abstract class Migration_Base {

	/**
	 * Per-class singleton instances.
	 *
	 * @var array<string, static>
	 */
	private static array $instances = [];

	/**
	 * Returns the singleton instance for the concrete migration class.
	 *
	 * @since 1.0.0
	 * @return static
	 */
	public static function instance(): static {
		$class = static::class;
		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();
		}
		return self::$instances[ $class ];
	}

	/**
	 * Creates or upgrades the table(s) owned by this migration.
	 *
	 * @since 1.0.0
	 * @param string $from Previously stored plugin version.
	 * @return bool True when all schema changes were applied successfully.
	 */
	abstract public function migrate( string $from = '' ): bool;

	/**
	 * Drops the table(s) owned by this migration.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	abstract public function drop_tables(): void;

	/**
	 * Returns the full table name with the WordPress base prefix.
	 *
	 * Callers always pass a class TABLE constant, never request data, so interpolating
	 * the result into SQL is safe. That is why the queries in the concrete migrations
	 * silence PluginCheck.Security.DirectDB.UnescapedDBParameter.
	 *
	 * @since 1.0.0
	 * @param string $table_name Table name without prefix.
	 * @return string
	 */
	protected function get_table_name( string $table_name ): string {
		global $wpdb;
		return $wpdb->base_prefix . $table_name;
	}

	/**
	 * Returns true when the table exists in the database.
	 *
	 * @since 1.0.0
	 * @param string $table_name Table name without prefix.
	 * @return bool
	 */
	protected function table_exists( string $table_name ): bool {
		global $wpdb;
		$full = $wpdb->base_prefix . $table_name;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full;
	}

	/**
	 * Returns true when the column exists in the given table.
	 *
	 * @since 1.0.0
	 * @param string $table_name  Table name without prefix.
	 * @param string $column_name Column name.
	 * @return bool
	 */
	protected function column_exists( string $table_name, string $column_name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$wpdb->dbname,
				$wpdb->base_prefix . $table_name,
				$column_name
			)
		);
	}

	/**
	 * Returns true when the index exists on the given table.
	 *
	 * @since 1.0.0
	 * @param string $table_name Table name without prefix.
	 * @param string $index_name Index name.
	 * @return bool
	 */
	protected function index_exists( string $table_name, string $index_name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				$wpdb->dbname,
				$wpdb->base_prefix . $table_name,
				$index_name
			)
		);
	}

	/**
	 * Returns the column list of an index, in index order.
	 *
	 * @since 1.0.0
	 * @param string $table_name Table name without prefix.
	 * @param string $index_name Index name.
	 * @return string[] Empty when the index does not exist.
	 */
	protected function index_columns( string $table_name, string $index_name ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cols = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX ASC',
				$wpdb->dbname,
				$wpdb->base_prefix . $table_name,
				$index_name
			)
		);

		return is_array( $cols ) ? $cols : [];
	}

	/**
	 * Returns true when the named table has at least one column.
	 *
	 * Confirms a CREATE TABLE actually landed: dbDelta reports its intentions, not
	 * whether MySQL accepted them.
	 *
	 * @since 1.0.0
	 * @param string $table_name Table name without prefix.
	 * @return bool
	 */
	protected function table_is_usable( string $table_name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$wpdb->base_prefix . $table_name
			)
		);
	}
}
