<?php
/**
 * Base class for database models.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class providing common CRUD helpers for table models.
 *
 * Concrete models expose public domain methods (find, all, delete_by_id, etc.)
 * that delegate to these protected base methods. NULL values in $where arrays
 * are not supported; use custom SQL in the concrete model for IS NULL conditions.
 *
 * @phpstan-consistent-constructor
 */
abstract class Model_Base {

	/**
	 * Singleton instances keyed by class name.
	 *
	 * @var array<string, static>
	 */
	private static array $instances = [];

	/**
	 * Returns the singleton instance for the concrete class.
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
	 * Table name without prefix.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	abstract protected function table(): string;

	/**
	 * Allowed column names for this table.
	 *
	 * All keys in $where and $data arrays are validated against this list
	 * before any SQL is built.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	abstract protected function columns(): array;

	/**
	 * Returns the full table name with the WordPress base prefix.
	 *
	 * The result is never request data: table() returns a hardcoded literal in every
	 * concrete model. Interpolating it into SQL is safe, which is why the methods that
	 * build queries from it silence PluginCheck.Security.DirectDB.UnescapedDBParameter.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	protected function table_name(): string {
		global $wpdb;
		return $wpdb->base_prefix . $this->table();
	}

	/**
	 * Strips keys not in columns() from an array.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $data Input array.
	 * @return array<string, mixed>
	 */
	protected function filter_columns( array $data ): array {
		return array_intersect_key( $data, array_flip( $this->columns() ) );
	}

	/**
	 * Returns a single matching row, or null.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $where Column => value conditions.
	 * @return array<string, mixed>|null
	 */
	protected function get_row( array $where ): ?array {
		global $wpdb;
		$where = $this->filter_columns( $where );
		if ( empty( $where ) ) {
			return null;
		}
		$table      = $this->table_name();
		$conditions = implode( ' AND ', array_map( fn( $col ) => "`{$col}` = %s", array_keys( $where ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$conditions} LIMIT 1", array_values( $where ) ), ARRAY_A ) ?? null;
	}

	/**
	 * Returns all rows matching the given conditions.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $where Column => value conditions. Empty returns all rows.
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_rows( array $where = [] ): array {
		global $wpdb;
		$table = $this->table_name();
		$where = $this->filter_columns( $where );
		if ( empty( $where ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ) ?? [];
		}
		$conditions = implode( ' AND ', array_map( fn( $col ) => "`{$col}` = %s", array_keys( $where ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$conditions}", array_values( $where ) ), ARRAY_A ) ?? [];
	}

	/**
	 * Inserts a row.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $data Column => value data.
	 * @return bool
	 */
	protected function insert_row( array $data ): bool {
		global $wpdb;
		$data = $this->filter_columns( $data );
		if ( empty( $data ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false !== $wpdb->insert( $this->table_name(), $data );
	}

	/**
	 * Updates rows matching the given conditions.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $data  Column => value data to set.
	 * @param array<string, mixed> $where Column => value conditions.
	 * @return bool
	 */
	protected function update_rows( array $data, array $where ): bool {
		global $wpdb;
		$data  = $this->filter_columns( $data );
		$where = $this->filter_columns( $where );
		if ( empty( $data ) || empty( $where ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( $this->table_name(), $data, $where );
	}

	/**
	 * Deletes rows matching the given conditions.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $where Column => value conditions.
	 * @return bool
	 */
	protected function delete_rows( array $where ): bool {
		global $wpdb;
		$where = $this->filter_columns( $where );
		if ( empty( $where ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->delete( $this->table_name(), $where );
	}

	/**
	 * Inserts a row or updates it on duplicate key.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $data Column => value data.
	 * @return bool
	 */
	protected function upsert_row( array $data ): bool {
		global $wpdb;
		$data = $this->filter_columns( $data );
		if ( empty( $data ) ) {
			return false;
		}
		$table        = $this->table_name();
		$columns      = implode( ', ', array_map( fn( $col ) => "`{$col}`", array_keys( $data ) ) );
		$placeholders = implode( ', ', array_fill( 0, count( $data ), '%s' ) );
		$updates      = implode( ', ', array_map( fn( $col ) => "`{$col}` = VALUES(`{$col}`)", array_keys( $data ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return false !== $wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$updates}", array_values( $data ) ) );
	}
}
