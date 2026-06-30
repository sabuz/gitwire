<?php
/**
 * Model for gitwire_repositories.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire\Models;

use Gitwire\Model_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read/write operations for the gitwire_repositories table.
 *
 * One row per repo per connection. Freshness is owned by cron; reads return
 * whatever is in the table regardless of age. type/type_meta are preserved
 * across cron refreshes via excluded ON DUPLICATE KEY UPDATE columns.
 */
class Repository extends Model_Base {

	protected function table(): string {
		return 'gitwire_repositories';
	}

	protected function columns(): array {
		return [
			'connection_id',
			'provider',
			'owner',
			'name',
			'full_name',
			'html_url',
			'private',
			'type',
			'type_meta',
			'default_branch',
			'last_activity_at',
			'updated_at',
		];
	}

	/**
	 * Returns a paginated repository list for one or more connections.
	 *
	 * Returns null when the table has no rows for the given connections (cache miss).
	 * Returns an empty-repositories array when rows exist but filters match nothing.
	 *
	 * @since 1.0.0
	 * @param array{
	 *     connection_ids: string[],
	 *     offset?: int,
	 *     search?: string,
	 *     per_page?: int,
	 *     excluded?: string[]
	 * } $filters Query options.
	 * @return array{repositories: array<int, array<string, mixed>>, has_more: bool, offset: int}|null
	 */
	public function get_paginated( array $filters ): ?array {
		global $wpdb;

		$connection_ids = (array) ( $filters['connection_ids'] ?? [] );
		$offset         = (int) ( $filters['offset'] ?? 0 );
		$search         = (string) ( $filters['search'] ?? '' );
		$per_page       = max( 1, (int) ( $filters['per_page'] ?? 50 ) );
		$excluded       = (array) ( $filters['excluded'] ?? [] );

		$empty = [
			'repositories' => [],
			'has_more'     => false,
			'offset'       => $offset,
		];

		if ( empty( $connection_ids ) ) {
			return $empty;
		}

		$table = $this->table_name();
		$phs   = implode( ', ', array_fill( 0, count( $connection_ids ), '%s' ) );
		$where = "WHERE connection_id IN ({$phs})";
		$args  = $connection_ids;

		if ( ! empty( $excluded ) ) {
			$ex_phs = implode( ', ', array_fill( 0, count( $excluded ), '%s' ) );
			$where .= " AND full_name NOT IN ({$ex_phs})"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$args   = array_merge( $args, $excluded );
		}

		if ( $search ) {
			$where .= ' AND (name LIKE %s OR owner LIKE %s)';
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$args[] = $like;
			$args[] = $like;
		}

		$limit  = $per_page + 1;
		$args[] = $limit;
		$args[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT connection_id, provider, full_name, owner, name, private, html_url, default_branch, last_activity_at, type, type_meta
				FROM `{$table}` {$where}
				ORDER BY last_activity_at DESC LIMIT %d OFFSET %d",
				...$args
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			// When filters are active, check if any rows exist for these connections.
			// If none exist, it's a cache miss (null). If rows exist, filters matched nothing.
			if ( ! empty( $excluded ) || $search ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsNumber
				$has_any = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1 FROM `{$table}` WHERE connection_id IN ({$phs}) LIMIT 1",
						...$connection_ids
					)
				);
				if ( ! $has_any ) {
					return null;
				}
				// Table has rows but filters matched nothing — fall through to return empty list.
			} else {
				return null;
			}
		}

		$rows     = $rows ?? [];
		$has_more = count( $rows ) > $per_page;
		if ( $has_more ) {
			array_pop( $rows );
		}

		return [
			'repositories' => array_map(
				static function ( array $row ) {
					if ( ! empty( $row['type_meta'] ) ) {
						$row['type_meta'] = json_decode( $row['type_meta'], true );
					}
					return $row;
				},
				$rows
			),
			'has_more'     => $has_more,
			'offset'       => $offset,
		];
	}

	/**
	 * Upserts a batch of repository rows for a connection.
	 *
	 * type and type_meta are excluded from the ON DUPLICATE KEY UPDATE clause
	 * so cached detection results survive across cron refreshes.
	 *
	 * @since 1.0.0
	 * @param string                           $connection_id Connection ID.
	 * @param array<int, array<string, mixed>> $repos Array of repo payloads from the provider API.
	 * @param string                           $provider      Provider key; overrides per-repo 'provider' when set.
	 * @return bool False only when $repos is empty.
	 */
	public function upsert_batch( string $connection_id, array $repos, string $provider = '' ): bool {
		if ( empty( $repos ) ) {
			return false;
		}

		global $wpdb;
		$table = $this->table_name();
		$now   = current_time( 'mysql' );

		foreach ( $repos as $repo ) {
			$full_name     = $repo['full_name'] ?? '';
			$repo_provider = $provider ?: ( $repo['provider'] ?? '' );
			if ( ! $full_name ) {
				continue;
			}
			$raw_at   = $repo['last_activity_at'] ?? '';
			$ts       = $raw_at ? (int) strtotime( $raw_at ) : 0;
			$last_act = $ts > 0 ? gmdate( 'Y-m-d H:i:s', $ts ) : '1970-01-01 00:00:00';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO `{$table}`
					(connection_id, provider, owner, name, full_name, private, html_url, default_branch, last_activity_at, updated_at)
					VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %s, %s)
					ON DUPLICATE KEY UPDATE
					  provider          = VALUES(provider),
					  owner             = VALUES(owner),
					  name              = VALUES(name),
					  private           = VALUES(private),
					  html_url          = VALUES(html_url),
					  default_branch    = VALUES(default_branch),
					  last_activity_at  = VALUES(last_activity_at),
					  updated_at        = VALUES(updated_at)",
					$connection_id,
					$repo_provider,
					$repo['owner'] ?? '',
					$repo['name'] ?? '',
					$full_name,
					(int) ( $repo['private'] ?? false ),
					$repo['html_url'] ?? '',
					$repo['default_branch'] ?? 'main',
					$last_act,
					$now
				)
			);
		}

		return true;
	}

	/**
	 * Returns the subset of $connection_ids that have at least one row in the table.
	 *
	 * @since 1.0.0
	 * @param string[] $connection_ids Connection IDs to check.
	 * @return string[] IDs that have cached rows.
	 */
	public function get_cached_ids( array $connection_ids ): array {
		if ( empty( $connection_ids ) ) {
			return [];
		}
		global $wpdb;
		$table = $this->table_name();
		$phs   = implode( ', ', array_fill( 0, count( $connection_ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT connection_id FROM `{$table}` WHERE connection_id IN ({$phs})",
				...$connection_ids
			)
		);
		return $rows ? $rows : [];
	}

	/**
	 * Returns the type detection result for a provider/repo pair, or null.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name (owner/repo).
	 * @return array<string, mixed>|null Decoded type_meta merged with `type`, or null when not detected.
	 */
	public function get_type( string $provider, string $full_name ): ?array {
		global $wpdb;
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT type, type_meta FROM `{$table}` WHERE provider = %s AND full_name = %s AND type_meta IS NOT NULL LIMIT 1",
				$provider,
				$full_name
			),
			ARRAY_A
		);
		if ( ! $row || empty( $row['type_meta'] ) ) {
			return null;
		}
		$meta = json_decode( $row['type_meta'], true );
		return is_array( $meta ) ? array_merge( $meta, [ 'type' => $row['type'] ] ) : null;
	}

	/**
	 * Stores a type detection result for a provider/repo pair.
	 *
	 * Updates all connection rows that share the full_name, then upserts a
	 * connection-agnostic fallback when no real browse-cache row exists yet (URL import path).
	 *
	 * @since 1.0.0
	 * @param string                    $provider  Git provider.
	 * @param string                    $full_name Repository full name (owner/repo).
	 * @param string                    $type      Detection type: 'plugin', 'block-theme', etc.
	 * @param array<string, mixed>|null $meta  Detection payload (confidence, name, key_files).
	 * @return bool
	 */
	public function set_type( string $provider, string $full_name, string $type, ?array $meta ): bool {
		global $wpdb;
		$table     = $this->table_name();
		$meta_json = $meta ? wp_json_encode( $meta ) : null;

		// Update any existing rows for this full_name across all connection_ids.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update(
			$this->table_name(),
			[
				'type'      => $type,
				'type_meta' => $meta_json,
			],
			[ 'full_name' => $full_name ],
			[ '%s', '%s' ],
			[ '%s' ]
		);

		// Upsert a connection-agnostic fallback when no real connection row exists (URL import path).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_real = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM `{$table}` WHERE full_name = %s AND connection_id != '' LIMIT 1",
				$full_name
			)
		);

		if ( ! $has_real ) {
			$parts = explode( '/', $full_name, 2 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO `{$table}` (connection_id, provider, owner, name, full_name, default_branch, type, type_meta, updated_at)
					VALUES ('', %s, %s, %s, %s, '', %s, %s, %s)
					ON DUPLICATE KEY UPDATE type = VALUES(type), type_meta = VALUES(type_meta)",
					$provider,
					$parts[0] ?? '',
					$parts[1] ?? '',
					$full_name,
					$type,
					$meta_json,
					current_time( 'mysql' )
				)
			);
		}

		return true;
	}

	/**
	 * Deletes all repository rows for one connection, or all rows when connection_id is empty.
	 *
	 * @since 1.0.0
	 * @param string $connection_id Connection ID. Empty string deletes all rows.
	 * @return bool
	 */
	public function clear( string $connection_id = '' ): bool {
		global $wpdb;
		$table = $this->table_name();
		if ( '' !== $connection_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return false !== $wpdb->delete( $this->table_name(), [ 'connection_id' => $connection_id ], [ '%s' ] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false !== $wpdb->query( "DELETE FROM `{$table}`" );
	}

	/**
	 * Resets all type detection data.
	 *
	 * Clears type/type_meta on connection-owned rows and removes URL-import fallback rows.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function clear_types(): bool {
		global $wpdb;
		$table = $this->table_name();

		// Reset type columns on all connection-specific rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET type = '', type_meta = NULL WHERE connection_id != %s",
				''
			)
		);

		// Remove URL-import fallback rows written by set_type() (connection_id = '').
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $this->table_name(), [ 'connection_id' => '' ], [ '%s' ] );

		return true;
	}

	/**
	 * Returns a batch of repository rows that have no type detection result.
	 *
	 * @since 1.0.0
	 * @param int $limit Maximum rows to return.
	 * @param int $offset Row offset for cursor-based pagination.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_untyped_batch( int $limit, int $offset = 0 ): array {
		global $wpdb;
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT connection_id, provider, owner, name, full_name, default_branch FROM `{$table}`
				WHERE type_meta IS NULL ORDER BY full_name ASC, connection_id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		) ?? [];
	}

	/**
	 * Deletes rows for a connection that are not in the provided full_name list.
	 *
	 * Intended for post-refresh cleanup: call after a full provider page sweep with
	 * the complete set of full_names returned by the API.
	 *
	 * @since 1.0.0
	 * @param string   $connection_id      Connection ID.
	 * @param string[] $current_full_names Full names to preserve.
	 * @return bool
	 */
	public function remove_stale( string $connection_id, array $current_full_names ): bool {
		if ( empty( $current_full_names ) ) {
			return $this->clear( $connection_id );
		}

		global $wpdb;
		$table = $this->table_name();
		$phs   = implode( ', ', array_fill( 0, count( $current_full_names ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsNumber
		return false !== $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE connection_id = %s AND full_name NOT IN ({$phs})",
				$connection_id,
				...$current_full_names
			)
		);
	}

	/**
	 * Returns the html_url for a repository, or empty string when not found.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @return string
	 */
	public function get_html_url( string $provider, string $full_name ): string {
		global $wpdb;
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT html_url FROM `{$table}` WHERE provider = %s AND full_name = %s LIMIT 1", $provider, $full_name )
		);
	}
}
