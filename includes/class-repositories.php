<?php
/**
 * Database table cache for repository lists and type detections.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

use Gitwire\Models\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data layer for the gitwire_repositories table.
 *
 * One row per repo per connection. Cron owns freshness; reads return whatever is in
 * the table regardless of age. updated_at is a cron-cycle marker used only for
 * stale-row cleanup after each refresh.
 */
class Repositories {

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
	public static function get_repositories( array $connection_ids, int $offset = 0, string $search = '' ): ?array {
		if ( empty( $connection_ids ) ) {
			return null;
		}

		$settings = Settings::get_public();

		return Repository::instance()->get_paginated(
			[
				'connection_ids' => $connection_ids,
				'offset'         => $offset,
				'search'         => $search,
				'per_page'       => (int) ( $settings['repos_per_page'] ?? 50 ),
				'excluded'       => (array) ( $settings['excluded_repos'] ?? [] ),
			]
		);
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
	public static function set_repositories( string $connection_id, string $provider, array $payload ): void {
		Repository::instance()->upsert_batch( $connection_id, $payload['repositories'] ?? [], $provider );
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
	public static function repository_type_key( string $provider, string $owner, string $repo, string $branch ): string {
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
	public static function get_repository_type( string $provider, string $owner, string $repo, string $branch ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return Repository::instance()->get_type( $provider, $owner . '/' . $repo );
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
	public static function set_repository_type( string $provider, string $owner, string $repo, string $branch, array $result ): void {
		$type = $result['type'] ?? '';
		$meta = array_diff_key( $result, [ 'type' => true ] );
		Repository::instance()->set_type( $provider, $owner . '/' . $repo, $type, ! empty( $meta ) ? $meta : null );
	}

	/**
	 * Clears cached repository list data.
	 *
	 * @since 1.0.0
	 * @param string|null $connection_id Optional connection ID to clear one slot only. Null clears all.
	 * @return void
	 */
	public static function clear_repositories( ?string $connection_id = null ): void {
		Repository::instance()->clear( $connection_id ?? '' );
	}

	/**
	 * Clears all cached type detection data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function clear_repository_types(): void {
		Repository::instance()->clear_types();

		// One-time cleanup of legacy gitwire_repo_type_* options from sites that ran an older build.
		global $wpdb;
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
		self::clear_repositories();
		self::clear_repository_types();
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
	public static function fetch_repositories( string $provider, int $page, string $connection_id ): array|\WP_Error {
		$cache_id = '' !== $connection_id ? $connection_id : 'public:' . $provider;
		$payload  = REST_Repositories::build_repositories( $provider, $page, $connection_id );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		self::set_repositories( $cache_id, $provider, $payload );

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
	private static function refresh_repositories(): true|\WP_Error {
		$last_err    = null;
		$max_setting = Settings::get_public()['max_repos_per_source'] ?? 'unlimited';
		$max         = 'unlimited' === $max_setting ? PHP_INT_MAX : (int) $max_setting;

		foreach ( Connection_Resolver::all() as $conn ) {
			$id       = $conn['id'] ?? '';
			$provider = $conn['provider'] ?? '';
			if ( ! $id || ! in_array( $provider, [ 'github', 'gitlab', 'bitbucket' ], true ) ) {
				continue;
			}

			$page               = 1;
			$conn_err           = null;
			$total_fetched      = 0;
			$fetched_full_names = [];

			do {
				$result = self::fetch_repositories( $provider, $page, $id );
				if ( is_wp_error( $result ) ) {
					$conn_err = $result;
					$last_err = $result;
					break;
				}
				foreach ( $result['repositories'] ?? [] as $repo ) {
					if ( ! empty( $repo['full_name'] ) ) {
						$fetched_full_names[] = $repo['full_name'];
					}
				}
				$total_fetched += count( $result['repositories'] ?? [] );
				$has_more       = ( $result['has_more'] ?? false ) && $total_fetched < $max;
				++$page;
			} while ( $has_more );

			if ( ! $conn_err ) {
				Repository::instance()->remove_stale( $id, $fetched_full_names );
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
		$result = self::refresh_repositories();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::cron_refresh_repository_types();
	}

	/**
	 * Re-detects repository types for installed repositories.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error True on success, WP_Error when detection fails globally.
	 */
	public static function cron_refresh_repository_types(): true|\WP_Error {
		$keys           = [];
		$connection_ids = [];
		$records        = Installer::get_installed();

		foreach ( $records as $rec ) {
			$provider = $rec['provider'] ?? 'github';
			$owner    = $rec['owner'] ?? '';
			$repo     = $rec['repo'] ?? '';
			$branch   = $rec['branch'] ?? 'main';
			if ( $owner && $repo ) {
				$key          = self::repository_type_key( $provider, $owner, $repo, $branch );
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

			$result = REST_Repositories::detect_type_for_repo(
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

			self::set_repository_type( $provider, $owner, $repo, $full_branch, $result );
		}

		// Background detection: process a batch of cache rows that haven't been typed yet.
		$settings = Settings::get_public();
		if ( ! ( $settings['background_type_detection'] ?? false ) ) {
			return $last_err ?? true;
		}

		$batch_size  = (int) apply_filters( 'gitwire_detection_batch_size', 25 );
		$cursor      = (int) get_option( 'gitwire_detection_cursor', 0 );
		$batch_start = time();

		$untyped = Repository::instance()->get_untyped_batch( $batch_size, $cursor );

		if ( empty( $untyped ) ) {
			delete_option( 'gitwire_detection_cursor' );
		} else {
			$processed = 0;
			foreach ( $untyped as $row ) {
				// 25 s guard — leave time for the next item on the queue.
				if ( time() - $batch_start > 25 ) {
					break;
				}

				$branch        = $row['default_branch'] ? $row['default_branch'] : 'HEAD';
				$connection_id = $row['connection_id'] ? $row['connection_id'] : null;

				$result = REST_Repositories::detect_type_for_repo(
					$row['provider'],
					$row['owner'],
					$row['name'],
					$branch,
					$connection_id
				);
				if ( ! is_wp_error( $result ) ) {
					self::set_repository_type( $row['provider'], $row['owner'], $row['name'], $branch, $result );
				}
				++$processed;
			}

			$fetched    = count( $untyped );
			$new_cursor = $cursor + $processed;
			// Last (partial) batch or time guard exhausted the batch — wrap to 0 so newly inserted rows aren't skipped.
			if ( $processed < $fetched || $fetched < $batch_size ) {
				delete_option( 'gitwire_detection_cursor' );
			} else {
				update_option( 'gitwire_detection_cursor', $new_cursor, false );
			}
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
		$result = self::refresh_repositories();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::cron_refresh_repository_types();
	}
}
