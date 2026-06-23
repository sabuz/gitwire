<?php
/**
 * Connection profile cache — EAV meta table reads/writes and public profile fetching.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the gitwire_connection_meta profile-cache rows and fetches public provider profiles.
 *
 * The same meta table stores both profile-cache keys (listed in PROFILE_KEYS) and config
 * keys like gitlab_url. Only PROFILE_KEYS rows are touched by this class; config keys
 * are owned by their respective writers and are never cleared here.
 */
class REST_Connection_Cache {

	/**
	 * Meta keys that belong to the profile cache.
	 *
	 * @var string[]
	 */
	const PROFILE_KEYS = [ 'provider', 'authenticated', 'username', 'name', 'avatar_url', 'rate_limit', 'rate_remaining', 'rate_reset', 'checked_at', 'error' ];

	/**
	 * Returns the connection meta table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function connection_meta_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'gitwire_connection_meta';
	}

	/**
	 * Returns all profile-cache metadata keyed by connection_id.
	 *
	 * Reads only PROFILE_KEYS rows; config keys like gitlab_url are excluded.
	 * Pro's get_connection_cache() delegates here and filters by authenticated = '1'.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function get_connection_cache(): array {
		global $wpdb;

		$in_sql = implode( ', ', array_fill( 0, count( self::PROFILE_KEYS ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT connection_id, meta_key, meta_value FROM ' . self::connection_meta_table() . ' WHERE meta_key IN (' . $in_sql . ')',
				...self::PROFILE_KEYS
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		$grouped = [];
		foreach ( $rows as $row ) {
			$grouped[ $row['connection_id'] ][ $row['meta_key'] ] = $row['meta_value'];
		}

		$result = [];
		foreach ( $grouped as $id => $meta ) {
			if ( ! isset( $meta['provider'] ) ) {
				continue;
			}
			$result[ $id ] = self::cast_meta( $meta, $id );
		}

		return $result;
	}

	/**
	 * Returns a single connection's profile cache, or null when not cached yet.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_public_connections_metadata( string $id ): ?array {
		global $wpdb;

		$in_sql = implode( ', ', array_fill( 0, count( self::PROFILE_KEYS ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM ' . self::connection_meta_table() . ' WHERE connection_id = %s AND meta_key IN (' . $in_sql . ')',
				$id,
				...self::PROFILE_KEYS
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return null;
		}

		$meta = array_column( $rows, 'meta_value', 'meta_key' );

		if ( ! isset( $meta['provider'] ) ) {
			return null;
		}

		return self::cast_meta( $meta, $id );
	}

	/**
	 * Casts raw EAV string values to the correct PHP types for profile cache fields.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $meta Flat key-value map from the meta table.
	 * @param string                $id   Connection ID.
	 * @return array<string, mixed>
	 */
	private static function cast_meta( array $meta, string $id ): array {
		return [
			'connection_id'  => $id,
			'provider'       => $meta['provider'] ?? '',
			'authenticated'  => (int) ( $meta['authenticated'] ?? 0 ),
			'username'       => $meta['username'] ?? '',
			'name'           => $meta['name'] ?? '',
			'avatar_url'     => $meta['avatar_url'] ?? '',
			'rate_limit'     => (int) ( $meta['rate_limit'] ?? 0 ),
			'rate_remaining' => (int) ( $meta['rate_remaining'] ?? 0 ),
			'rate_reset'     => (int) ( $meta['rate_reset'] ?? 0 ),
			'checked_at'     => (int) ( $meta['checked_at'] ?? 0 ),
			'error'          => ( '' !== ( $meta['error'] ?? '' ) ) ? $meta['error'] : null,
		];
	}

	/**
	 * Upserts all profile cache fields for a connection in a single SQL query.
	 *
	 * @since 1.0.0
	 * @param string               $id   Connection ID.
	 * @param array<string, mixed> $data Metadata fields to store.
	 * @return void
	 */
	public static function save_public_connection_metadata( string $id, array $data ): void {
		global $wpdb;

		$defaults = [
			'provider'       => '',
			'authenticated'  => 0,
			'username'       => '',
			'name'           => '',
			'avatar_url'     => '',
			'rate_limit'     => 0,
			'rate_remaining' => 0,
			'rate_reset'     => 0,
			'checked_at'     => 0,
			'error'          => '',
		];
		$meta     = array_intersect_key( array_merge( $defaults, $data ), $defaults );

		$value_parts = [];
		$params      = [];

		foreach ( $meta as $key => $value ) {
			$value_parts[] = '(%s, %s, %s)';
			$params[]      = $id;
			$params[]      = $key;
			$params[]      = match ( true ) {
				is_bool( $value ) => $value ? '1' : '0',
				null === $value   => '',
				default           => (string) $value,
			};
		}

		$sql = 'INSERT INTO ' . self::connection_meta_table() . ' (connection_id, meta_key, meta_value) VALUES '
			. implode( ', ', $value_parts )
			. ' ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$wpdb->query( $wpdb->prepare( $sql, ...$params ) );
	}

	/**
	 * Deletes all profile cache keys for a connection; leaves config keys intact.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return void
	 */
	public static function clear_public_connection_metadata( string $id ): void {
		global $wpdb;

		$in_sql = implode( ', ', array_fill( 0, count( self::PROFILE_KEYS ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::connection_meta_table() . ' WHERE connection_id = %s AND meta_key IN (' . $in_sql . ')',
				$id,
				...self::PROFILE_KEYS
			)
		);
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
			'provider'       => 'github',
			'authenticated'  => false,
			'username'       => $username,
			'name'           => $name,
			'avatar_url'     => $avatar_url,
			'rate_limit'     => (int) $core['limit'],
			'rate_remaining' => (int) $core['remaining'],
			'rate_reset'     => (int) $core['reset'],
			'checked_at'     => time(),
		];
	}

	/**
	 * Fetches profile fields for a GitLab user via the unauthenticated API.
	 *
	 * Returns null when the request fails or the user is not found.
	 *
	 * @since 1.0.0
	 * @param string $username   GitLab username.
	 * @param string $gitlab_url Self-hosted instance URL, or empty for gitlab.com.
	 * @return array<string, mixed>|null
	 */
	private static function fetch_gitlab_profile( string $username, string $gitlab_url = '' ): ?array {
		$base     = rtrim( $gitlab_url ? $gitlab_url : 'https://gitlab.com', '/' );
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
			'provider'       => 'gitlab',
			'authenticated'  => false,
			'username'       => $username,
			'name'           => (string) ( $data[0]['name'] ?? '' ),
			'avatar_url'     => (string) ( $data[0]['avatar_url'] ?? '' ),
			'rate_limit'     => 0,
			'rate_remaining' => 0,
			'rate_reset'     => 0,
			'checked_at'     => time(),
		];
	}

	/**
	 * Returns cached-or-fresh GitHub rate data for a public connection.
	 *
	 * Single source of truth for the 15-minute rate transient. Pro delegates here
	 * for its public connections rather than re-fetching from GitHub.
	 *
	 * @since 1.0.0
	 * @param string $id         Public connection ID.
	 * @param string $username   GitHub username.
	 * @param string $avatar_url Stored avatar URL (GitHub avatars are derived, not stored).
	 * @return array<string, mixed>|null Null when the GitHub request fails.
	 */
	public static function get_public_github_rate( string $id, string $username, string $avatar_url = '' ): ?array {
		$cached = self::get_public_connections_metadata( $id );
		if ( null !== $cached ) {
			return $cached;
		}

		$payload = self::fetch_github_profile( $username, $avatar_url );
		if ( null === $payload ) {
			return null;
		}

		self::save_public_connection_metadata( $id, $payload );

		return $payload;
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
	 * @param string $gitlab_url Self-hosted GitLab URL, or empty for gitlab.com.
	 * @return void
	 */
	public static function write_public_metadata( string $id, string $provider, string $identifier, string $gitlab_url = '' ): void {
		if ( 'gitlab' === $provider ) {
			$profile = self::fetch_gitlab_profile( $identifier, $gitlab_url );
			self::save_public_connection_metadata(
				$id,
				$profile ?? [
					'provider'       => 'gitlab',
					'authenticated'  => false,
					'username'       => $identifier,
					'name'           => '',
					'avatar_url'     => self::gravatar_url( $identifier ),
					'rate_limit'     => 0,
					'rate_remaining' => 0,
					'rate_reset'     => 0,
					'checked_at'     => time(),
				]
			);
		} elseif ( 'bitbucket' === $provider ) {
			self::save_public_connection_metadata(
				$id,
				[
					'provider'       => 'bitbucket',
					'authenticated'  => false,
					'username'       => $identifier,
					'name'           => '',
					'avatar_url'     => self::gravatar_url( $identifier ),
					'rate_limit'     => 0,
					'rate_remaining' => 0,
					'rate_reset'     => 0,
					'checked_at'     => time(),
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
				self::write_public_metadata( $id, $provider, $username, $conn['gitlab_url'] ?? '' );
			}
		}
	}
}
