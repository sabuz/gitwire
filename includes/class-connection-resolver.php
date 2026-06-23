<?php
/**
 * Unified connection resolver. Reads the shared gitwire_connections table directly;
 * filter hooks remain as extension points for third-party plugins.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single point of truth for all connections (public and private).
 * Without Pro, every row has credentials IS NULL; Pro rows have encrypted credentials.
 */
class Connection_Resolver {

	/**
	 * Request-scope cache for all_rows().
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $rows_cache = null;

	/**
	 * Clears the request-scope cache after any write operation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function invalidate_cache(): void {
		self::$rows_cache = null;
	}

	/**
	 * Returns all stored connection records.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		return (array) apply_filters( 'gitwire_connections_all', self::all_rows() );
	}

	/**
	 * Returns a public-safe, scope-filtered connection list for the current user.
	 *
	 * Scope stored in DB as 'all' (site-wide) or a WP user ID string. Translated to
	 * 'site'/'user' in the returned shape for JS consumption.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_public_list(): array {
		$uid  = (string) get_current_user_id();
		$rows = array_values(
			array_filter(
				self::all_rows(),
				static fn( $r ) => 'all' === ( $r['scope'] ?? 'all' ) || ( $r['scope'] ?? '' ) === $uid
			)
		);

		$safe = array_map(
			static function ( $r ) {
				unset( $r['credentials'] );
				$r['scope'] = 'all' === ( $r['scope'] ?? 'all' ) ? 'site' : 'user';
				return $r;
			},
			$rows
		);

		return (array) apply_filters( 'gitwire_connections', array_values( $safe ), get_current_user_id() );
	}

	/**
	 * Finds a single connection by ID.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->base_prefix . 'gitwire_connections WHERE id = %s', $id ),
			ARRAY_A
		);

		$conn = apply_filters( 'gitwire_find_connection', $row ?: null, $id );
		return is_array( $conn ) ? $conn : null;
	}

	/**
	 * Returns the first connection for a provider, or null if none exists.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider key: 'github', 'gitlab', or 'bitbucket'.
	 * @return array<string, mixed>|null
	 */
	public static function get_first_for_provider( string $provider ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->base_prefix . 'gitwire_connections WHERE provider = %s ORDER BY created_at ASC LIMIT 1',
				$provider
			),
			ARRAY_A
		);

		$conn = apply_filters( 'gitwire_connection_for_provider', $row ?: null, $provider );
		return is_array( $conn ) ? $conn : null;
	}

	/**
	 * Returns credentials for a connection.
	 *
	 * Public connections (credentials IS NULL) derive credentials from the identifier.
	 * Pro private connections return decrypted token credentials via filter.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null Null when the connection does not exist.
	 */
	public static function get_credentials( string $id ): ?array {
		$pub = Public_Connections::find( $id );
		if ( $pub ) {
			return Public_Connections::to_credentials( $pub );
		}

		$creds = apply_filters( 'gitwire_get_credentials', null, $id );
		return is_array( $creds ) ? $creds : null;
	}

	/**
	 * Returns credentials for the default connection of a provider.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider key.
	 * @return array<string, mixed>|null Null when no connection exists for the provider.
	 */
	public static function get_credentials_for_provider( string $provider ): ?array {
		$pub = Public_Connections::get_first_for_provider( $provider );
		if ( $pub ) {
			return Public_Connections::to_credentials( $pub );
		}

		$creds = apply_filters( 'gitwire_provider_credentials', null, $provider );
		return is_array( $creds ) ? $creds : null;
	}

	/**
	 * Queries all rows from the unified connections table.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	private static function all_rows(): array {
		if ( null !== self::$rows_cache ) {
			return self::$rows_cache;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . $wpdb->base_prefix . 'gitwire_connections ORDER BY created_at ASC',
			ARRAY_A
		);

		self::$rows_cache = $rows ?: [];
		return self::$rows_cache;
	}
}
