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
	 * Validates and normalizes public connection input.
	 *
	 * Single source of truth shared by the free REST endpoint and Gitwire Pro,
	 * so credential validation cannot drift between the two plugins.
	 *
	 * @since 1.0.0
	 * @param string $provider   Provider key.
	 * @param string $username   GitHub/GitLab username or Bitbucket workspace slug.
	 * @param string $gitlab_url Raw self-hosted GitLab instance URL.
	 * @return array{username: string, gitlab_url: string}|\WP_Error Normalized fields, or WP_Error on invalid input.
	 */
	public static function validate( string $provider, string $username, string $gitlab_url = '' ): array|\WP_Error {
		$username = sanitize_text_field( $username );
		if ( '' === $username ) {
			$message = 'bitbucket' === $provider
				? __( 'Workspace is required.', 'gitwire' )
				: __( 'Username is required.', 'gitwire' );
			return new \WP_Error( 'missing_username', $message, [ 'status' => 400 ] );
		}

		$normalized_url = '';
		if ( 'gitlab' === $provider && '' !== $gitlab_url ) {
			$normalized_url = esc_url_raw( $gitlab_url );
			if ( '' !== $normalized_url && ! Settings::is_allowed_gitlab_url( $normalized_url ) ) {
				return new \WP_Error(
					'invalid_gitlab_url',
					__( 'GitLab URL must use HTTPS and cannot point to a private network address.', 'gitwire' ),
					[ 'status' => 400 ]
				);
			}
		}

		return [
			'username'   => $username,
			'gitlab_url' => $normalized_url,
		];
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
			$profile            = self::resolve_gitlab_profile( $username, $gitlab_url );
			if ( '' !== ( $profile['avatar_url'] ?? '' ) ) {
				$conn['avatar_url'] = $profile['avatar_url'];
			}
			if ( '' !== ( $profile['name'] ?? '' ) ) {
				$conn['name'] = $profile['name'];
			}
		}

		$stored[] = $conn;
		self::save( $stored );

		return self::enrich( $conn );
	}

	/**
	 * Fetches profile fields for a GitLab user via the unauthenticated API.
	 *
	 * Returns an empty array when the request fails or the user is not found.
	 *
	 * @since 1.5.0
	 * @param string $username   GitLab username.
	 * @param string $gitlab_url Self-hosted instance URL, or empty for gitlab.com.
	 * @return array<string, string> Keys: avatar_url, name.
	 */
	private static function resolve_gitlab_profile( string $username, string $gitlab_url ): array {
		$base     = rtrim( $gitlab_url ? $gitlab_url : 'https://gitlab.com', '/' );
		$response = wp_remote_get(
			$base . '/api/v4/users?username=' . rawurlencode( $username ) . '&per_page=1',
			[ 'timeout' => 5 ]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data[0] ) ) {
			return [];
		}
		return [
			'avatar_url' => (string) ( $data[0]['avatar_url'] ?? '' ),
			'name'       => (string) ( $data[0]['name'] ?? '' ),
		];
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
