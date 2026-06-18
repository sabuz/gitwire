<?php
/**
 * Manages public (no-token) browse accounts for the free plugin.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and retrieves public browse accounts from the custom DB table.
 */
class Public_Connections {

	/**
	 * Returns the connections table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'gitwire_public_connections';
	}

	/**
	 * Returns the connection meta table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function meta_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'gitwire_connection_meta';
	}

	/**
	 * Returns all stored public connections, enriched with meta and derived fields.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, string>>
	 */
	public static function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . self::table() . ' ORDER BY created_at ASC',
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		$ids          = array_column( $rows, 'id' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT connection_id, meta_key, meta_value FROM ' . self::meta_table() . " WHERE connection_id IN ($placeholders)",
				...$ids
			),
			ARRAY_A
		);

		$meta_by_id = [];
		foreach ( $meta_rows as $meta ) {
			$meta_by_id[ $meta['connection_id'] ][ $meta['meta_key'] ] = $meta['meta_value'];
		}

		return array_values(
			array_map(
				static function ( $row ) use ( $meta_by_id ) {
					return self::enrich( array_merge( $row, $meta_by_id[ $row['id'] ] ?? [] ) );
				},
				$rows
			)
		);
	}

	/**
	 * Injects derived fields that do not need to be persisted.
	 *
	 * GitHub avatar URLs are deterministic from the username, so we compute
	 * them rather than store them.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $conn Connection row merged with meta.
	 * @return array<string, string>
	 */
	private static function enrich( array $conn ): array {
		if ( 'github' === ( $conn['provider'] ?? '' ) && empty( $conn['avatar_url'] ) ) {
			$conn['avatar_url'] = 'https://avatars.githubusercontent.com/' . rawurlencode( $conn['identifier'] ?? '' );
		}
		return $conn;
	}

	/**
	 * Finds a single connection by ID, or null when not found.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return array<string, string>|null
	 */
	public static function find( string $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %s', $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM ' . self::meta_table() . ' WHERE connection_id = %s',
				$id
			),
			ARRAY_A
		);

		foreach ( $meta_rows as $meta ) {
			$row[ $meta['meta_key'] ] = $meta['meta_value'];
		}

		return self::enrich( $row );
	}

	/**
	 * Returns the first connection for a provider, or null when none exists.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider key: 'github', 'gitlab', or 'bitbucket'.
	 * @return array<string, string>|null
	 */
	public static function get_first_for_provider( string $provider ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE provider = %s ORDER BY created_at ASC LIMIT 1',
				$provider
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM ' . self::meta_table() . ' WHERE connection_id = %s',
				$row['id']
			),
			ARRAY_A
		);

		foreach ( $meta_rows as $meta ) {
			$row[ $meta['meta_key'] ] = $meta['meta_value'];
		}

		return self::enrich( $row );
	}

	/**
	 * Converts a public connection record to the credential format used by API clients.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $conn Public connection record.
	 * @return array<string, string>
	 */
	public static function to_credentials( array $conn ): array {
		$identifier = $conn['identifier'] ?? '';

		if ( 'gitlab' === $conn['provider'] ) {
			return [
				'username'   => $identifier,
				'gitlab_url' => $conn['gitlab_url'] ?? '',
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
	 * @param string $gitlab_url Raw self-hosted GitLab instance URL.
	 * @return array{identifier: string, gitlab_url: string}|\WP_Error Normalized fields, or WP_Error on invalid input.
	 */
	public static function validate( string $provider, string $identifier, string $gitlab_url = '' ): array|\WP_Error {
		$identifier = sanitize_text_field( $identifier );
		if ( '' === $identifier ) {
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
	 * @param string $gitlab_url Optional self-hosted GitLab instance URL.
	 * @return array<string, string> The new connection record.
	 */
	public static function add( string $provider, string $identifier, string $gitlab_url = '' ): array {
		global $wpdb;

		$id = uniqid( 'pub_', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table(),
			[
				'id'         => $id,
				'provider'   => $provider,
				'identifier' => $identifier,
				'created_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		if ( 'gitlab' === $provider && '' !== $gitlab_url ) {
			self::set_meta( $id, 'gitlab_url', $gitlab_url );
		}

		return self::find( $id ) ?? [ 'id' => $id, 'provider' => $provider, 'identifier' => $identifier ];
	}

	/**
	 * Upserts a single meta value for a connection.
	 *
	 * @since 1.0.0
	 * @param string $connection_id Connection ID.
	 * @param string $meta_key      Meta key.
	 * @param string $meta_value    Meta value.
	 * @return void
	 */
	private static function set_meta( string $connection_id, string $meta_key, string $meta_value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->replace(
			self::meta_table(),
			[
				'connection_id' => $connection_id,
				'meta_key'      => $meta_key,
				'meta_value'    => $meta_value,
			],
			[ '%s', '%s', '%s' ]
		);
	}

	/**
	 * Removes a connection and its meta by ID.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return bool True when the connection was found and removed.
	 */
	public static function delete( string $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->delete( self::table(), [ 'id' => $id ], [ '%s' ] );

		if ( $deleted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( self::meta_table(), [ 'connection_id' => $id ], [ '%s' ] );
			Repo_Cache::clear_repo_list( $id );
		}

		return (bool) $deleted;
	}

}
