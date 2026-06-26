<?php
/**
 * Base class for database migrations.
 *
 * @package Gitwire
 * @since 2.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class providing schema helpers for table migrations.
 */
abstract class Migration_Base {

	/**
	 * Returns the full table name with the WordPress base prefix.
	 *
	 * @since 2.0.0
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
	 * @since 2.0.0
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
	 * @since 2.0.0
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
	 * @since 2.0.0
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
}
