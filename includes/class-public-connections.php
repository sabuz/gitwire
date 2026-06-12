<?php
/**
 * Manages public (no-token) browse accounts for the free plugin.
 *
 * @package Gitwire
 * @since 1.5.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and retrieves public browse accounts from the options table.
 */
class Public_Connections {

	/**
	 * WordPress option key.
	 *
	 * @var string
	 */
	private const OPTION = 'gitwire_public_connections';

	/**
	 * Returns all stored public connections, enriched with derived fields.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, string>>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		return array_map( [ self::class, 'enrich' ], $stored );
	}

	/**
	 * Injects derived fields that do not need to be persisted.
	 *
	 * GitHub avatar URLs are deterministic from the username, so we compute
	 * them here rather than storing them, which means existing connections
	 * get the field without any migration.
	 *
	 * @since 1.5.0
	 * @param array<string, string> $conn Stored connection record.
	 * @return array<string, string>
	 */
	private static function enrich( array $conn ): array {
		if ( 'github' === ( $conn['provider'] ?? '' ) && empty( $conn['avatar_url'] ) ) {
			$conn['avatar_url'] = 'https://avatars.githubusercontent.com/' . rawurlencode( $conn['username'] ?? '' );
		}
		return $conn;
	}

	/**
	 * Finds a single connection by ID, or null when not found.
	 *
	 * @since 1.5.0
	 * @param string $id Connection ID.
	 * @return array<string, string>|null
	 */
	public static function find( string $id ): ?array {
		foreach ( self::all() as $conn ) {
			if ( ( $conn['id'] ?? '' ) === $id ) {
				return $conn;
			}
		}
		return null;
	}

	/**
	 * Returns the first connection for a provider, or null when none exists.
	 *
	 * @since 1.5.0
	 * @param string $provider Provider key: 'github', 'gitlab', or 'bitbucket'.
	 * @return array<string, string>|null
	 */
	public static function get_first_for_provider( string $provider ): ?array {
		foreach ( self::all() as $conn ) {
			if ( ( $conn['provider'] ?? '' ) === $provider ) {
				return $conn;
			}
		}
		return null;
	}

	/**
	 * Converts a public connection record to the credential format used by API clients.
	 *
	 * @since 1.5.0
	 * @param array<string, string> $conn Public connection record.
	 * @return array<string, string>
	 */
	public static function to_credentials( array $conn ): array {
		$username = $conn['username'] ?? '';

		if ( 'gitlab' === $conn['provider'] ) {
			return [
				'username'   => $username,
				'gitlab_url' => $conn['gitlab_url'] ?? '',
			];
		}

		if ( 'bitbucket' === $conn['provider'] ) {
			return [ 'workspace' => $username ];
		}

		return [ 'username' => $username ];
	}

	/**
	 * Adds a new public connection and returns the stored record.
	 *
	 * @since 1.5.0
	 * @param string $provider   Provider key.
	 * @param string $username   GitHub/GitLab username or Bitbucket workspace slug.
	 * @param string $gitlab_url Optional self-hosted GitLab instance URL.
	 * @return array<string, string> The new connection record.
	 */
	public static function add( string $provider, string $username, string $gitlab_url = '' ): array {
		// Re-read stored connections directly to avoid the enrich() layer.
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		$conn = [
			'id'       => uniqid( 'pub_', true ),
			'provider' => $provider,
			'username' => $username,
		];

		if ( 'gitlab' === $provider ) {
			$conn['gitlab_url'] = $gitlab_url;
			$avatar             = self::resolve_gitlab_avatar( $username, $gitlab_url );
			if ( '' !== $avatar ) {
				$conn['avatar_url'] = $avatar;
			}
		}

		$stored[] = $conn;
		self::save( $stored );

		return self::enrich( $conn );
	}

	/**
	 * Fetches the avatar URL for a GitLab user via the unauthenticated API.
	 *
	 * Returns an empty string when the request fails or the user is not found.
	 *
	 * @since 1.5.0
	 * @param string $username   GitLab username.
	 * @param string $gitlab_url Self-hosted instance URL, or empty for gitlab.com.
	 * @return string
	 */
	private static function resolve_gitlab_avatar( string $username, string $gitlab_url ): string {
		$base     = rtrim( $gitlab_url ? $gitlab_url : 'https://gitlab.com', '/' );
		$response = wp_remote_get(
			$base . '/api/v4/users?username=' . rawurlencode( $username ) . '&per_page=1',
			[ 'timeout' => 5 ]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return (string) ( $data[0]['avatar_url'] ?? '' );
	}

	/**
	 * Removes a connection by ID.
	 *
	 * @since 1.5.0
	 * @param string $id Connection ID.
	 * @return bool True when the connection was found and removed.
	 */
	public static function delete( string $id ): bool {
		// Work from raw stored data so ephemeral fields from enrich() are never persisted.
		$stored   = get_option( self::OPTION, [] );
		$stored   = is_array( $stored ) ? $stored : [];
		$filtered = array_values(
			array_filter( $stored, static fn( $c ) => ( $c['id'] ?? '' ) !== $id )
		);

		if ( count( $filtered ) === count( $stored ) ) {
			return false;
		}

		self::save( $filtered );
		Repo_Cache::clear_repos( $id );

		return true;
	}

	/**
	 * Persists the connections array.
	 *
	 * @since 1.5.0
	 * @param array<int, array<string, string>> $connections Connections to store.
	 * @return void
	 */
	private static function save( array $connections ): void {
		update_option( self::OPTION, $connections, false );
	}
}
