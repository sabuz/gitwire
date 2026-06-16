<?php
/**
 * Filter-backed seam for connections. Free supplies public connections as defaults;
 * Pro hooks in to add private (token) connections via the same filters.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves connections through extension filters. Without Pro every method
 * returns free public connections; Pro merges its private connections on top.
 */
class Connection_Resolver {

	/**
	 * Returns all stored connection records (public + Pro private).
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		return (array) apply_filters( 'gitwire_connections_all', Public_Connections::all() );
	}

	/**
	 * Returns a public-safe connection list for the current user.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_public_list(): array {
		return (array) apply_filters( 'gitwire_connections', Public_Connections::all(), get_current_user_id() );
	}

	/**
	 * Finds a single connection by ID.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $id ): ?array {
		$default = Public_Connections::find( $id );
		$conn    = apply_filters( 'gitwire_find_connection', $default, $id );
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
		$default = Public_Connections::get_first_for_provider( $provider );
		$conn    = apply_filters( 'gitwire_connection_for_provider', $default, $provider );
		return is_array( $conn ) ? $conn : null;
	}

	/**
	 * Returns credentials for a connection. Public connections return username/workspace;
	 * Pro private connections return decrypted token credentials.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null Null when the connection does not exist.
	 */
	public static function get_credentials( string $id ): ?array {
		$pub     = Public_Connections::find( $id );
		$default = $pub ? Public_Connections::to_credentials( $pub ) : null;
		$creds   = apply_filters( 'gitwire_get_credentials', $default, $id );
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
		$pub     = Public_Connections::get_first_for_provider( $provider );
		$default = $pub ? Public_Connections::to_credentials( $pub ) : null;
		$creds   = apply_filters( 'gitwire_provider_credentials', $default, $provider );
		return is_array( $creds ) ? $creds : null;
	}
}
