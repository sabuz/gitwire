<?php
/**
 * Manages public (no-token) browse accounts in the unified connections table.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and retrieves public connections (credentials IS NULL) from the unified table.
 */
class Public_Connections {

	/**
	 * Returns the unified connections table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function connections_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'gitwire_connections';
	}

	/**
	 * Returns all public connections with profile data and derived fields.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . self::connections_table() . ' WHERE credentials IS NULL ORDER BY created_at ASC',
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		return array_values(
			array_map(
				static fn( $row ) => self::enrich( self::strip_credentials( $row ) ),
				$rows
			)
		);
	}

	/**
	 * Injects derived fields that do not need to be persisted.
	 *
	 * GitHub avatar URLs are deterministic from the username, so we compute
	 * them rather than store them when the profile fetch hasn't run yet.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $conn Connection row.
	 * @return array<string, mixed>
	 */
	private static function enrich( array $conn ): array {
		if ( 'github' === ( $conn['provider'] ?? '' ) && empty( $conn['avatar_url'] ) ) {
			$conn['avatar_url'] = 'https://avatars.githubusercontent.com/' . rawurlencode( $conn['identifier'] ?? '' );
		}
		return $conn;
	}

	/**
	 * Removes the credentials column before returning a row to callers.
	 *
	 * Public connections always have credentials IS NULL, but we unset the key
	 * to keep the returned shape clean.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $row Raw row from gitwire_connections.
	 * @return array<string, mixed>
	 */
	private static function strip_credentials( array $row ): array {
		unset( $row['credentials'] );
		return $row;
	}

	/**
	 * Finds a single public connection by ID, or null when not found.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::connections_table() . ' WHERE id = %s AND credentials IS NULL', $id ),
			ARRAY_A
		);

		return $row ? self::enrich( self::strip_credentials( $row ) ) : null;
	}

	/**
	 * Returns the first public connection for a provider, or null when none exists.
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
				'SELECT * FROM ' . self::connections_table() . ' WHERE provider = %s AND credentials IS NULL ORDER BY created_at ASC LIMIT 1',
				$provider
			),
			ARRAY_A
		);

		return $row ? self::enrich( self::strip_credentials( $row ) ) : null;
	}

	/**
	 * Returns an existing public connection matching provider and identifier, or null.
	 *
	 * @since 1.0.0
	 * @param string $provider   Provider key.
	 * @param string $identifier Identifier to match.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_identifier( string $provider, string $identifier ): ?array {
		global $wpdb;

		if ( '' === $identifier ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::connections_table() . ' WHERE provider = %s AND identifier = %s AND credentials IS NULL LIMIT 1',
				$provider,
				$identifier
			),
			ARRAY_A
		);

		return $row ? self::enrich( self::strip_credentials( $row ) ) : null;
	}

	/**
	 * Converts a public connection record to the credential format used by API clients.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $conn Public connection record.
	 * @return array<string, string>
	 */
	public static function to_credentials( array $conn ): array {
		$identifier = $conn['identifier'] ?? '';

		if ( 'gitlab' === $conn['provider'] ) {
			return [
				'username'   => $identifier,
				'gitlab_url' => $conn['host_url'] ?? '',
			];
		}

		if ( 'bitbucket' === $conn['provider'] ) {
			return [ 'workspace' => $identifier ];
		}

		return [ 'username' => $identifier ];
	}

	/**
	 * Validates and normalizes public connection input.
	 *
	 * Single source of truth shared by the free REST endpoint and Gitwire Pro,
	 * so credential validation cannot drift between the two plugins.
	 *
	 * @since 1.0.0
	 * @param string $provider   Provider key.
	 * @param string $identifier GitHub/GitLab username or Bitbucket workspace slug.
	 * @param string $host_url   Raw self-hosted GitLab instance URL.
	 * @return array{identifier: string, gitlab_url: string}|\WP_Error Normalized fields, or WP_Error on invalid input.
	 */
	public static function validate( string $provider, string $identifier, string $host_url = '' ): array|\WP_Error {
		$identifier = sanitize_text_field( $identifier );
		if ( '' === $identifier ) {
			$message = 'bitbucket' === $provider
				? __( 'Workspace is required.', 'gitwire' )
				: __( 'Username is required.', 'gitwire' );
			return new \WP_Error( 'missing_username', $message, [ 'status' => 400 ] );
		}

		$normalized_url = '';
		if ( 'gitlab' === $provider && '' !== $host_url ) {
			$normalized_url = esc_url_raw( $host_url );
			if ( '' !== $normalized_url && ! Settings::is_allowed_gitlab_url( $normalized_url ) ) {
				return new \WP_Error(
					'invalid_gitlab_url',
					__( 'GitLab URL must use HTTPS and cannot point to a private network address.', 'gitwire' ),
					[ 'status' => 400 ]
				);
			}
		}

		return [
			'identifier' => $identifier,
			'gitlab_url' => $normalized_url,
		];
	}

	/**
	 * Adds a new public connection and returns the stored record.
	 *
	 * @since 1.0.0
	 * @param string $provider   Provider key.
	 * @param string $identifier GitHub/GitLab username or Bitbucket workspace slug.
	 * @param string $host_url   Optional self-hosted GitLab instance URL.
	 * @return array<string, mixed> The new connection record.
	 */
	public static function add( string $provider, string $identifier, string $host_url = '' ): array {
		global $wpdb;

		$id    = 'pub_' . wp_generate_uuid4();
		$table = self::connections_table();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (id, provider, identifier, credentials, host_url, scope, created_at, updated_at) VALUES (%s, %s, %s, NULL, %s, 'all', %s, %s)",
				$id,
				$provider,
				$identifier,
				( '' !== $host_url ) ? $host_url : null,
				$now,
				$now
			)
		);

		return self::find( $id ) ?? [
			'id'         => $id,
			'provider'   => $provider,
			'identifier' => $identifier,
		];
	}

	/**
	 * Removes a public connection by ID.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return bool True when the connection was found and removed.
	 */
	public static function delete( string $id ): bool {
		global $wpdb;

		$table = self::connections_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id = %s AND credentials IS NULL", $id ) );

		if ( $deleted ) {
			Repositories::clear_repositories( $id );
		}

		return (bool) $deleted;
	}
}
