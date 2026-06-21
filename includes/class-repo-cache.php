<?php
/**
 * Database table cache for repository lists and type detections.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache for repository lists and type detections.
 *
 * Both are stored in the gitwire_repositories table — one row per repo per connection.
 * Cron owns freshness; reads return whatever is in the table regardless of age.
 * updated_at is a cron-cycle marker used only for stale-row cleanup after each refresh.
 */
class Repo_Cache {

	/**
	 * Returns the repo cache table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function cache_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'gitwire_repositories';
	}

	/**
	 * Returns a combined, sorted repository page from all given connections.
	 *
	 * Returns null only when the table has no rows for these connections at all
	 * (first run before cron or a manual refresh). Fetches one extra row to
	 * detect has_more without a second COUNT query.
	 *
	 * @since 1.0.0
	 * @param string[] $connection_ids Connection IDs to query across.
	 * @param int      $offset         Row offset for pagination.
	 * @param string   $search         Optional name/owner search filter.
	 * @return array<string, mixed>|null Cached payload or null when cache is empty.
	 */
	public static function get_repo_list( array $connection_ids, int $offset = 0, string $search = '' ): ?array {
		global $wpdb;

		if ( empty( $connection_ids ) ) {
			return null;
		}

		$settings = Settings::get_public();
		$per_page = (int) ( $settings['repos_per_page'] ?? 50 );
		$excluded = (array) ( $settings['excluded_repos'] ?? [] );

		$placeholders = implode( ', ', array_fill( 0, count( $connection_ids ), '%s' ) );
		$where        = 'WHERE connection_id IN (' . $placeholders . ')';
		$args         = $connection_ids;

		if ( ! empty( $excluded ) ) {
			$ex_phs = implode( ', ', array_fill( 0, count( $excluded ), '%s' ) );
			$where .= ' AND full_name NOT IN (' . $ex_phs . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT connection_id, provider, full_name, owner, name, private, html_url, default_branch, last_activity_at, type, type_meta FROM ' . self::cache_table() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				' ' . $where . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				' ORDER BY last_activity_at DESC LIMIT %d OFFSET %d',
				...$args
			)
		);

		if ( ! $rows && 0 === $offset ) {
			// If filters are active, verify the table itself is populated before returning null.
			if ( $excluded || $search ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$has_any = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT 1 FROM ' . self::cache_table() . ' WHERE connection_id IN (' . $placeholders . ') LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						...$connection_ids
					)
				);
				if ( ! $has_any ) {
					return null;
				}
			} else {
				return null;
			}
		}

		$has_more = count( $rows ) > $per_page;
		if ( $has_more ) {
			array_pop( $rows );
		}

		return [
			'repositories' => array_map(
				static function ( $row ) {
					$repo = [
						'connection_id'    => $row->connection_id,
						'provider'         => $row->provider,
						'full_name'        => $row->full_name,
						'owner'            => $row->owner,
						'name'             => $row->name,
						'private'          => (bool) $row->private,
						'html_url'         => $row->html_url,
						'default_branch'   => $row->default_branch,
						'last_activity_at' => $row->last_activity_at,
						'type'             => $row->type,
					];
					if ( $row->type_meta ) {
						$repo['type_meta'] = json_decode( $row->type_meta, true );
					}
					return $repo;
				},
				$rows
			),
			'has_more'     => $has_more,
			'offset'       => $offset,
		];
	}

	/**
	 * Stores a repository page payload using upsert.
	 *
	 * Omits type_meta and type from ON DUPLICATE KEY UPDATE intentionally —
	 * cached detection results must survive across cron refreshes.
	 *
	 * @since 1.0.0
	 * @param string               $connection_id Connection ID or 'public:{provider}'.
	 * @param string               $provider      Provider key.
	 * @param array<string, mixed> $payload       Repos payload.
	 * @return void
	 */
	public static function set_repo_list( string $connection_id, string $provider, array $payload ): void {
		global $wpdb;
		$table = self::cache_table();
		$now   = current_time( 'mysql' );

		foreach ( $payload['repositories'] ?? [] as $repo ) {
			$full_name = $repo['full_name'] ?? '';
			if ( ! $full_name ) {
				continue;
			}
			$raw_at   = $repo['last_activity_at'] ?? '';
			$ts       = $raw_at ? (int) strtotime( $raw_at ) : 0;
			$last_act = $ts > 0 ? gmdate( 'Y-m-d H:i:s', $ts ) : '1970-01-01 00:00:00';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $table . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					'(connection_id, provider, owner, name, full_name, private, html_url, default_branch, last_activity_at, updated_at)
					VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %s, %s)
					ON DUPLICATE KEY UPDATE
					  provider       = VALUES(provider),
					  owner          = VALUES(owner),
					  name           = VALUES(name),
					  private        = VALUES(private),
					  html_url       = VALUES(html_url),
					  default_branch = VALUES(default_branch),
					  last_activity_at  = VALUES(last_activity_at),
					  updated_at     = VALUES(updated_at)',
					$connection_id,
					$provider,
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
	}

	/**
	 * Builds the canonical key for a repo type detection entry.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider key.
	 * @param string $owner    Repository owner.
	 * @param string $repo     Repository name.
	 * @param string $branch   Branch name.
	 * @return string
	 */
	public static function repo_type_key( string $provider, string $owner, string $repo, string $branch ): string {
		return $provider . ':' . $owner . '/' . $repo . ':' . $branch;
	}

	/**
	 * Returns a cached detection result.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider key.
	 * @param string $owner    Repository owner.
	 * @param string $repo     Repository name.
	 * @param string $branch   Branch name.
	 * @return array<string, mixed>|null Cached detection or null when missing.
	 */
	public static function get_repo_type( string $provider, string $owner, string $repo, string $branch ): ?array {
		global $wpdb;
		$full_name = $owner . '/' . $repo;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT type, type_meta FROM ' . self::cache_table() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				' WHERE provider = %s AND full_name = %s AND type_meta IS NOT NULL LIMIT 1',
				$provider,
				$full_name
			)
		);

		if ( $row && $row->type_meta ) {
			$meta = json_decode( $row->type_meta, true );
			if ( is_array( $meta ) ) {
				return array_merge( $meta, [ 'type' => $row->type ] );
			}
		}

		return null;
	}

	/**
	 * Stores a detection result.
	 *
	 * Updates all matching browse-cache rows, then upserts a connection-agnostic row so
	 * repos imported directly from a URL (not yet in the browse cache) are also covered.
	 * The result array must include 'type'; remaining fields go into type_meta.
	 *
	 * @since 1.0.0
	 * @param string               $provider Provider key.
	 * @param string               $owner    Repository owner.
	 * @param string               $repo     Repository name.
	 * @param string               $branch   Branch name.
	 * @param array<string, mixed> $result   Detection payload (must include 'type').
	 * @return void
	 */
	public static function set_repo_type( string $provider, string $owner, string $repo, string $branch, array $result ): void {
		global $wpdb;
		$full_name = $owner . '/' . $repo;
		$type      = $result['type'] ?? '';
		$meta      = array_diff_key( $result, [ 'type' => true ] );
		$meta_json = wp_json_encode( $meta );

		// Update all browse-cache rows for this repo (may match multiple connection_ids).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			self::cache_table(),
			[
				'type'      => $type,
				'type_meta' => $meta_json,
			],
			[
				'provider'  => $provider,
				'full_name' => $full_name,
			],
			[ '%s', '%s' ],
			[ '%s', '%s' ]
		);

		// Upsert a connection-agnostic row so get_repo_type() hits the table even when
		// the repo was never listed in the browse panel (e.g. direct URL import).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::cache_table() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				' (connection_id, provider, owner, name, full_name, default_branch, type, type_meta)
				VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE type = VALUES(type), type_meta = VALUES(type_meta)',
				'',
				$provider,
				$owner,
				$repo,
				$full_name,
				'',
				$type,
				$meta_json
			)
		);
	}

	/**
	 * Clears cached repository list data.
	 *
	 * @since 1.0.0
	 * @param string|null $connection_id Optional connection ID to clear one slot only. Null clears all.
	 * @return void
	 */
	public static function clear_repo_list( ?string $connection_id = null ): void {
		global $wpdb;
		if ( null !== $connection_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( self::cache_table(), [ 'connection_id' => $connection_id ], [ '%s' ] );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . self::cache_table() );
	}

	/**
	 * Clears all cached type detection data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function clear_repo_types(): void {
		global $wpdb;

		// Reset type columns on all connection-specific rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::cache_table() . " SET type = '', type_meta = NULL WHERE connection_id != %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				''
			)
		);

		// Remove connection-agnostic fallback rows written by set_repo_type() for URL imports.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( self::cache_table(), [ 'connection_id' => '' ], [ '%s' ] );

		// One-time cleanup of legacy gitwire_repo_type_* options from sites that ran an older build.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'gitwire_repo_type_' ) . '%'
			)
		);
	}

	/**
	 * Clears all browse caches.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function clear_all(): void {
		self::clear_repo_list();
		self::clear_repo_types();
	}

	/**
	 * Fetches repositories from the provider API and stores them in the cache table.
	 *
	 * @since 1.0.0
	 * @param string $provider      Provider key.
	 * @param int    $page          Page number.
	 * @param string $connection_id Connection ID to use for credentials.
	 * @return array<string, mixed>|\WP_Error Stored payload on success.
	 */
	public static function fetch_repo_list( string $provider, int $page, string $connection_id ): array|\WP_Error {
		$cache_id = '' !== $connection_id ? $connection_id : 'public:' . $provider;
		$payload  = REST::build_repo_list( $provider, $page, $connection_id );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		self::set_repo_list( $cache_id, $provider, $payload );

		return $payload;
	}

	/**
	 * Fetches all pages of the repository list for every known connection.
	 *
	 * After each connection completes successfully, rows not touched in this cycle
	 * (repos removed from the provider) are deleted.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error True on success, WP_Error when any source fails.
	 */
	private static function refresh_repo_lists(): true|\WP_Error {
		global $wpdb;

		$refresh_started = current_time( 'mysql' );
		$last_err        = null;
		$max_setting     = Settings::get_public()['max_repos_per_source'] ?? 'unlimited';
		$max             = 'unlimited' === $max_setting ? PHP_INT_MAX : (int) $max_setting;

		foreach ( Connection_Resolver::all() as $conn ) {
			$id       = $conn['id'] ?? '';
			$provider = $conn['provider'] ?? '';
			if ( ! $id || ! in_array( $provider, [ 'github', 'gitlab', 'bitbucket' ], true ) ) {
				continue;
			}

			$page          = 1;
			$conn_err      = null;
			$total_fetched = 0;

			do {
				$result = self::fetch_repo_list( $provider, $page, $id );
				if ( is_wp_error( $result ) ) {
					$conn_err = $result;
					$last_err = $result;
					break;
				}
				$total_fetched += count( $result['repositories'] ?? [] );
				$has_more       = ( $result['has_more'] ?? false ) && $total_fetched < $max;
				++$page;
			} while ( $has_more );

			if ( ! $conn_err ) {
				// Remove repos no longer in the provider's list.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query(
					$wpdb->prepare(
						'DELETE FROM ' . self::cache_table() . ' WHERE connection_id = %s AND updated_at < %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						$id,
						$refresh_started
					)
				);
			}
		}

		return $last_err ?? true;
	}

	/**
	 * Scheduled cron callback: refreshes repo lists then re-detects types.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error
	 */
	public static function scheduled_refresh(): true|\WP_Error {
		$result = self::refresh_repo_lists();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::cron_refresh_repo_types();
	}

	/**
	 * Re-detects repository types for installed repositories.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error True on success, WP_Error when detection fails globally.
	 */
	public static function cron_refresh_repo_types(): true|\WP_Error {
		global $wpdb;

		$keys           = [];
		$connection_ids = [];
		$records        = Installer::get_installed();

		foreach ( $records as $rec ) {
			$provider = $rec['provider'] ?? 'github';
			$owner    = $rec['owner'] ?? '';
			$repo     = $rec['repo'] ?? '';
			$branch   = $rec['branch'] ?? 'main';
			if ( $owner && $repo ) {
				$key          = self::repo_type_key( $provider, $owner, $repo, $branch );
				$keys[ $key ] = true;
				if ( ! empty( $rec['connection_id'] ) ) {
					$connection_ids[ $key ] = $rec['connection_id'];
				}
			}
		}

		$last_err = null;
		foreach ( array_keys( $keys ) as $key ) {
			$parts = explode( ':', $key, 2 );
			if ( count( $parts ) < 2 ) {
				continue;
			}
			$provider = $parts[0];
			$rest     = $parts[1];
			$at       = strrpos( $rest, ':' );
			if ( false === $at ) {
				continue;
			}
			$full_branch = substr( $rest, $at + 1 );
			$full_name   = substr( $rest, 0, $at );
			$slash       = strrpos( $full_name, '/' );
			if ( false === $slash ) {
				continue;
			}
			$owner = substr( $full_name, 0, $slash );
			$repo  = substr( $full_name, $slash + 1 );

			$result = REST::detect_type_for_repo(
				$provider,
				$owner,
				$repo,
				$full_branch,
				$connection_ids[ $key ] ?? null
			);
			if ( is_wp_error( $result ) ) {
				$last_err = $result;
				continue;
			}

			self::set_repo_type( $provider, $owner, $repo, $full_branch, $result );
		}

		// Background detection: process a batch of cache rows that haven't been typed yet.
		$settings = Settings::get_public();
		if ( ! ( $settings['background_type_detection'] ?? false ) ) {
			return $last_err ?? true;
		}

		$batch_size  = (int) apply_filters( 'gitwire_detection_batch_size', 25 );
		$cursor      = (int) get_option( 'gitwire_detection_cursor', 0 );
		$batch_start = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$untyped = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT connection_id, provider, owner, name, full_name, default_branch FROM ' . self::cache_table() . // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				' WHERE type_meta IS NULL ORDER BY full_name ASC, connection_id ASC LIMIT %d OFFSET %d',
				$batch_size,
				$cursor
			),
			ARRAY_A
		);

		if ( empty( $untyped ) ) {
			delete_option( 'gitwire_detection_cursor' );
		} else {
			foreach ( $untyped as $row ) {
				// 25 s guard — leave time for the next item on the queue.
				if ( time() - $batch_start > 25 ) {
					break;
				}

				$branch        = $row['default_branch'] ? $row['default_branch'] : 'HEAD';
				$connection_id = $row['connection_id'] ? $row['connection_id'] : null;

				$result = REST::detect_type_for_repo(
					$row['provider'],
					$row['owner'],
					$row['name'],
					$branch,
					$connection_id
				);
				if ( ! is_wp_error( $result ) ) {
					self::set_repo_type( $row['provider'], $row['owner'], $row['name'], $branch, $result );
				}
			}
			update_option( 'gitwire_detection_cursor', $cursor + count( $untyped ), false );
		}

		return $last_err ?? true;
	}

	/**
	 * Forces an immediate full refresh outside the cron cycle.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error
	 */
	public static function force_refresh(): true|\WP_Error {
		$result = self::refresh_repo_lists();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::cron_refresh_repo_types();
	}
}
