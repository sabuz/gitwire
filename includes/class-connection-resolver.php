<?php
/**
 * Unified connection resolver. Reads the shared gitwire_connections table directly;
 * filter hooks remain as extension points for third-party plugins.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

use Gitwire\Models\Connection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single point of truth for all connections (public and private).
 * Without Pro, every row has credentials IS NULL; Pro rows have encrypted credentials.
 */
class Connection_Resolver {

	/**
	 * Clears the request-scope cache after any write operation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function invalidate_cache(): void {
		Connection::instance()->invalidate_cache();
	}

	/**
	 * Returns all stored connection records, with public rows suppressed when a Pro
	 * row exists for the same provider + identifier.
	 *
	 * Suppression is resolver-only — the DB is never modified. When Pro is removed its
	 * rows are deleted and the public rows reappear automatically.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$rows = Connection::instance()->all();

		// Build a set of provider:identifier pairs covered by a Pro (credentialed) row.
		$private_keys = [];
		foreach ( $rows as $row ) {
			if ( ! empty( $row['credentials'] ) ) {
				$private_keys[ $row['provider'] . ':' . $row['identifier'] ] = true;
			}
		}

		// Drop public rows shadowed by a Pro row with the same provider + identifier.
		if ( ! empty( $private_keys ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn( $r ) => ! empty( $r['credentials'] )
						|| ! isset( $private_keys[ $r['provider'] . ':' . $r['identifier'] ] )
				)
			);
		}

		return (array) apply_filters( 'gitwire_connections_all', $rows );
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
				self::all(),
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

		return (array) apply_filters( 'gitwire_connections', $safe, get_current_user_id() );
	}

	/**
	 * Finds a single connection by ID.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $id ): ?array {
		$conn = apply_filters( 'gitwire_find_connection', Connection::instance()->find( $id ), $id );
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
		$conn = apply_filters( 'gitwire_connection_for_provider', Connection::instance()->find_by_provider( $provider ), $provider );
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
}
