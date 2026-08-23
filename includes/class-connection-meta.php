<?php
/**
 * Profile cache manager: reads and writes connection profile columns.
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
 * Manages the profile-cache columns on gitwire_connections and fetches live
 * provider profile data for public (no-token) connections.
 *
 * Profile columns: authenticated, name, avatar_url, rate_limit, rate_remaining,
 * rate_reset, error, updated_at. Identity/auth columns (identifier, email,
 * host_url, credentials) are owned by Public_Connections and Pro's Connections.
 */
class Connection_Meta {

	/**
	 * Returns all connection profile data keyed by connection ID.
	 *
	 * Used for the boot-data connections_metadata payload consumed by the admin JS.
	 *
	 * @since 1.0.0
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_connection_cache(): array {
		$rows = Connection::instance()->all();

		$result = [];
		foreach ( $rows as $row ) {
			$result[ $row['id'] ] = self::format_profile( $row );
		}

		return $result;
	}

	/**
	 * Returns the profile data for a single connection, or null when not found.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_public_connections_metadata( string $id ): ?array {
		$row = Connection::instance()->find( $id );
		return $row ? self::format_profile( $row ) : null;
	}

	/**
	 * Formats a raw connections row into the profile shape expected by the admin JS.
	 *
	 * Returns updated_at as a Unix timestamp (converted from the DB datetime string).
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $row Raw row from gitwire_connections.
	 * @return array<string, mixed>
	 */
	private static function format_profile( array $row ): array {
		$provider = $row['provider'] ?? '';
		return [
			'connection_id'  => $row['id'],
			'provider'       => $provider,
			'authenticated'  => (int) ( $row['authenticated'] ?? 0 ),
			'username'       => 'bitbucket' === $provider ? '' : ( $row['identifier'] ?? '' ),
			'workspace'      => 'bitbucket' === $provider ? ( $row['identifier'] ?? '' ) : '',
			'email'          => $row['email'] ?? '',
			'name'           => $row['name'] ?? '',
			'avatar_url'     => $row['avatar_url'] ?? '',
			'rate_limit'     => (int) ( $row['rate_limit'] ?? 0 ),
			'rate_remaining' => (int) ( $row['rate_remaining'] ?? 0 ),
			'rate_reset'     => (int) ( $row['rate_reset'] ?? 0 ),
			'updated_at'     => '' !== ( $row['updated_at'] ?? '' ) ? (int) strtotime( $row['updated_at'] ) : 0,
			'error'          => ( '' !== ( $row['error'] ?? '' ) && null !== $row['error'] ) ? $row['error'] : null,
		];
	}

	/**
	 * Updates profile cache columns for a connection.
	 *
	 * Only touches authenticated, name, avatar_url, rate_limit, rate_remaining,
	 * rate_reset, error, and updated_at. Identity/auth columns are not modified.
	 * Unrecognised keys in $data (provider, updated_at, workspace, etc.) are ignored.
	 *
	 * @since 1.0.0
	 * @param string               $id   Connection ID.
	 * @param array<string, mixed> $data Profile fields to store.
	 * @return void
	 */
	public static function save_public_connection_metadata( string $id, array $data ): void {
		$normalized = [];

		if ( array_key_exists( 'authenticated', $data ) ) {
			$normalized['authenticated'] = (int) (bool) $data['authenticated'];
		}
		foreach ( [ 'name', 'avatar_url' ] as $col ) {
			if ( array_key_exists( $col, $data ) ) {
				$normalized[ $col ] = (string) ( $data[ $col ] ?? '' );
			}
		}
		foreach ( [ 'rate_limit', 'rate_remaining', 'rate_reset' ] as $col ) {
			if ( array_key_exists( $col, $data ) ) {
				$normalized[ $col ] = (int) $data[ $col ];
			}
		}
		if ( array_key_exists( 'error', $data ) ) {
			$err                 = ( '' !== ( $data['error'] ?? '' ) && null !== $data['error'] ) ? (string) $data['error'] : null;
			$normalized['error'] = $err;
		}

		if ( ! empty( $normalized ) ) {
			Connection::instance()->save_metadata( $id, $normalized );
		}
	}

	/**
	 * Resets all profile cache columns to their defaults for a connection.
	 *
	 * Called when a connection is deleted or its token is revoked, so stale
	 * profile data does not outlive the connection record.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return void
	 */
	public static function clear_public_connection_metadata( string $id ): void {
		Connection::instance()->clear_metadata( $id );
	}

	/**
	 * Returns a Gravatar identicon URL for a given identifier.
	 *
	 * @since 1.0.0
	 * @param string $identifier Provider handle (username, workspace slug, etc.).
	 * @return string
	 */
	private static function gravatar_url( string $identifier ): string {
		return 'https://www.gravatar.com/avatar/' . md5( strtolower( trim( $identifier ) ) ) . '?s=96&d=identicon';
	}

	/**
	 * Fetches rate limit and display name for a GitHub username without auth.
	 *
	 * Returns null when the API call fails so the caller can decide how to handle it.
	 *
	 * @since 1.0.0
	 * @param string $username   GitHub username.
	 * @param string $avatar_url Stored avatar URL; falls back to the deterministic GitHub URL.
	 * @return array<string, mixed>|null
	 */
	private static function fetch_github_profile( string $username, string $avatar_url = '' ): ?array {
		$headers  = [ 'User-Agent' => 'Gitwire/' . GITWIRE_VERSION ];
		$rate_res = wp_remote_get(
			'https://api.github.com/rate_limit',
			[
				'headers' => $headers,
				'timeout' => 5,
			]
		);

		if ( is_wp_error( $rate_res ) || 200 !== wp_remote_retrieve_response_code( $rate_res ) ) {
			return null;
		}

		$rate_data = json_decode( wp_remote_retrieve_body( $rate_res ), true );
		$core      = $rate_data['resources']['core'] ?? null;

		if ( empty( $core ) ) {
			return null;
		}

		$name     = '';
		$user_res = wp_remote_get(
			'https://api.github.com/users/' . rawurlencode( $username ),
			[
				'headers' => $headers,
				'timeout' => 5,
			]
		);
		if ( ! is_wp_error( $user_res ) && 200 === wp_remote_retrieve_response_code( $user_res ) ) {
			$user_data = json_decode( wp_remote_retrieve_body( $user_res ), true );
			$name      = (string) ( $user_data['name'] ?? '' );
			if ( '' === $avatar_url ) {
				$avatar_url = (string) ( $user_data['avatar_url'] ?? '' );
			}
		}

		if ( '' === $avatar_url && '' !== $username ) {
			$avatar_url = 'https://avatars.githubusercontent.com/' . rawurlencode( $username );
		}

		return [
			'authenticated'  => false,
			'name'           => $name,
			'avatar_url'     => $avatar_url,
			'rate_limit'     => (int) $core['limit'],
			'rate_remaining' => (int) $core['remaining'],
			'rate_reset'     => (int) $core['reset'],
		];
	}

	/**
	 * Fetches profile fields for a GitLab user via the unauthenticated API.
	 *
	 * Returns null when the request fails or the user is not found.
	 *
	 * @since 1.0.0
	 * @param string $username GitLab username.
	 * @param string $host_url Self-hosted instance URL, or empty for gitlab.com.
	 * @return array<string, mixed>|null
	 */
	private static function fetch_gitlab_profile( string $username, string $host_url = '' ): ?array {
		$base = rtrim( $host_url ? $host_url : 'https://gitlab.com', '/' );

		// Revalidate at request time because DNS can change after the setting is saved.
		if ( $host_url && ! Settings::is_allowed_gitlab_url( $base ) ) {
			return null;
		}

		$response = wp_remote_get(
			$base . '/api/v4/users?username=' . rawurlencode( $username ) . '&per_page=1',
			[ 'timeout' => 5 ]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data[0] ) ) {
			return null;
		}

		return [
			'authenticated'  => false,
			'name'           => (string) ( $data[0]['name'] ?? '' ),
			'avatar_url'     => (string) ( $data[0]['avatar_url'] ?? '' ),
			'rate_limit'     => 0,
			'rate_remaining' => 0,
			'rate_reset'     => 0,
		];
	}

	/**
	 * Returns cached-or-fresh GitHub rate data for a public connection.
	 *
	 * Returns cached data when it was refreshed within the last 5 minutes;
	 * otherwise fetches live from the GitHub API and updates the cache. $force
	 * skips that check: GitHub's own rate_limit endpoint does not count against
	 * the quota it reports, so there is no cost to always fetching live when
	 * the caller knows a human is looking at the result right now.
	 *
	 * @since 1.0.0
	 * @param string $id         Public connection ID.
	 * @param string $username   GitHub username.
	 * @param string $avatar_url Stored avatar URL.
	 * @param bool   $force      Bypass the cache and fetch live regardless of age.
	 * @return array<string, mixed>|null Null when the GitHub request fails.
	 */
	public static function get_public_github_rate( string $id, string $username, string $avatar_url = '', bool $force = false ): ?array {
		$cached = self::get_public_connections_metadata( $id );
		// A zero rate limit indicates a new row; always fetch fresh data.
		if ( ! $force && null !== $cached && ( $cached['rate_limit'] ?? 0 ) > 0 && ( time() - ( $cached['updated_at'] ?? 0 ) ) < 300 ) {
			return $cached;
		}

		$payload = self::fetch_github_profile( $username, $avatar_url );
		if ( null === $payload ) {
			return null;
		}

		self::save_public_connection_metadata( $id, $payload );

		return self::get_public_connections_metadata( $id );
	}

	/**
	 * Writes profile metadata for a public (no-token) connection.
	 *
	 * Fetches real profile data where an API is available (GitLab); falls back
	 * to a Gravatar identicon for providers with no accessible avatar API (Bitbucket).
	 * GitHub is handled separately via get_public_github_rate().
	 *
	 * @since 1.0.0
	 * @param string $id         Connection ID.
	 * @param string $provider   Provider key.
	 * @param string $identifier Username or workspace slug.
	 * @param string $host_url   Self-hosted GitLab URL, or empty for gitlab.com.
	 * @return void
	 */
	public static function write_public_metadata( string $id, string $provider, string $identifier, string $host_url = '' ): void {
		if ( 'gitlab' === $provider ) {
			$profile = self::fetch_gitlab_profile( $identifier, $host_url );
			self::save_public_connection_metadata(
				$id,
				$profile ?? [
					'authenticated'  => false,
					'name'           => '',
					'avatar_url'     => self::gravatar_url( $identifier ),
					'rate_limit'     => 0,
					'rate_remaining' => 0,
					'rate_reset'     => 0,
				]
			);
		} elseif ( 'bitbucket' === $provider ) {
			self::save_public_connection_metadata(
				$id,
				[
					'authenticated'  => false,
					'name'           => '',
					'avatar_url'     => self::gravatar_url( $identifier ),
					'rate_limit'     => 0,
					'rate_remaining' => 0,
					'rate_reset'     => 0,
				]
			);
		}
	}

	/**
	 * Cron handler: refreshes the cached profile for every public connection.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function refresh_public_connections(): void {
		foreach ( Public_Connections::all() as $conn ) {
			$id       = $conn['id'] ?? '';
			$provider = $conn['provider'] ?? '';
			$username = $conn['identifier'] ?? '';

			if ( '' === $id ) {
				continue;
			}

			if ( 'github' === $provider ) {
				$payload = self::fetch_github_profile( $username );
				if ( null !== $payload ) {
					self::save_public_connection_metadata( $id, $payload );
				}
			} else {
				self::write_public_metadata( $id, $provider, $username, $conn['host_url'] ?? '' );
			}
		}
	}
}
