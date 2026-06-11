<?php
/**
 * Filter-backed seam for token connections supplied by Gitwire Pro.
 *
 * @package Gitwire
 * @since 1.4.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves connections through extension filters. Without Pro every method
 * returns its empty default and callers fall back to public (username) mode.
 */
class Connection_Resolver {

	/**
	 * Returns all stored connection records.
	 *
	 * @since 1.4.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		return (array) apply_filters( 'gitwire_connections_all', [] );
	}

	/**
	 * Returns a public-safe connection list for the current user.
	 *
	 * @since 1.4.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_public_list(): array {
		return (array) apply_filters( 'gitwire_connections', [], get_current_user_id() );
	}

	/**
	 * Finds a single connection by ID.
	 *
	 * @since 1.4.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $id ): ?array {
		$conn = apply_filters( 'gitwire_find_connection', null, $id );
		return is_array( $conn ) ? $conn : null;
	}

	/**
	 * Returns the default connection for a provider, or null if none exists.
	 *
	 * @since 1.4.0
	 * @param string $provider Provider key: 'github', 'gitlab', or 'bitbucket'.
	 * @return array<string, mixed>|null
	 */
	public static function get_first_for_provider( string $provider ): ?array {
		$conn = apply_filters( 'gitwire_connection_for_provider', null, $provider );
		return is_array( $conn ) ? $conn : null;
	}

	/**
	 * Returns decrypted credentials for a connection.
	 *
	 * @since 1.4.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null Null when the connection does not exist.
	 */
	public static function get_credentials( string $id ): ?array {
		$creds = apply_filters( 'gitwire_get_credentials', null, $id );
		return is_array( $creds ) ? $creds : null;
	}

	/**
	 * Returns decrypted credentials for the default connection of a provider.
	 *
	 * @since 1.4.0
	 * @param string $provider Provider key.
	 * @return array<string, mixed>|null Null when no connection exists for the provider.
	 */
	public static function get_credentials_for_provider( string $provider ): ?array {
		$creds = apply_filters( 'gitwire_provider_credentials', null, $provider );
		return is_array( $creds ) ? $creds : null;
	}
}
