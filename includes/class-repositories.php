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
	 * Returns the subset of $connection_ids that already have rows in the cache table.
	 *
	 * @since 1.0.0
	 * @param string[] $connection_ids Connection IDs to check.
	 * @return string[] IDs that have at least one cached row.
	 */
	public static function get_cached_connection_ids( array $connection_ids ): array {
		return Repository::instance()->get_cached_ids( $connection_ids );
	}

	/**
	 * Returns a cached detection result.
	 *
	 * Detections are stored per repository, not per branch: the browse cache holds one
	 * row per repo and every read path only ever asks about its default branch.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider key.
	 * @param string $owner    Repository owner.
	 * @param string $repo     Repository name.
	 * @return array<string, mixed>|null Cached detection or null when missing.
	 */
	public static function get_repository_type( string $provider, string $owner, string $repo ): ?array {
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
	 * @param array<string, mixed> $result   Detection payload (must include 'type').
	 * @return void
	 */
	public static function set_repository_type( string $provider, string $owner, string $repo, array $result ): void {
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

		// Drop any parked cursor too, or a deleted connection resumes from nowhere.
		$state = self::get_refresh_state();
		if ( null === $connection_id ) {
			$state = [];
		} else {
			unset( $state[ $connection_id ] );
		}
		self::save_refresh_state( $state );
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
	 * Clears cached type detection data for a single connection.
	 *
	 * @since 1.0.0
	 * @param string $connection_id Connection whose detections to clear.
	 * @return void
	 */
	public static function clear_repository_types_for_connection( string $connection_id ): void {
		Repository::instance()->clear_types_for_connection( $connection_id );
	}

	/**
	 * Drops rows for a connection that the current refresh cycle did not return.
	 *
	 * @since 1.0.0
	 * @param string $connection_id Connection ID whose rows to prune.
	 * @param string $cycle_start   Datetime the refresh began, in the site timezone.
	 * @return void
	 */
	public static function remove_stale_since( string $connection_id, string $cycle_start ): void {
		Repository::instance()->remove_stale_since( $connection_id, $cycle_start );
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
	 * @param string   $provider      Provider key.
	 * @param int      $page          Page number.
	 * @param string   $connection_id Connection ID to use for credentials.
	 * @param int|null $limit       Cap on rows to store from this page; null for all.
	 * @param string   $stamp       Cycle marker written to updated_at; empty for now.
	 * @return array<string, mixed>|\WP_Error Stored payload on success.
	 */
	public static function fetch_repositories( string $provider, int $page, string $connection_id, ?int $limit = null, string $stamp = '' ): array|\WP_Error {
		$cache_id = '' !== $connection_id ? $connection_id : 'public:' . $provider;
		$payload  = REST_Repositories::build_repositories( $provider, $page, $connection_id );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Trim before the write, not after: the cap is meant to bound what we store.
		if ( null !== $limit ) {
			$payload['repositories'] = array_slice( $payload['repositories'] ?? [], 0, max( 0, $limit ) );
		}

		Repository::instance()->upsert_batch( $cache_id, $payload['repositories'] ?? [], $provider, $stamp );

		return $payload;
	}

	/**
	 * Fetches all pages of the repository list for every known connection.
	 *
	 * After each connection completes successfully, rows not touched in this cycle
	 * (repos removed from the provider) are deleted. A sweep that runs out of its time
	 * budget parks a cursor and returns; the next tick resumes that connection
	 * mid-sweep, and its stale rows are left alone until the sweep actually finishes.
	 *
	 * Shared by the cron event and the Browse tab's manual refresh, so both walk every
	 * page and both honour max_repos_per_source. Fetching only page one and pruning
	 * anyway is what used to truncate large connections down to a single page.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>> One entry per connection that failed,
	 *                                          each with connection_id, provider, and message.
	 */
	public static function refresh_repositories(): array {
		$errors      = [];
		$max_setting = Settings::get_public()['max_repos_per_source'] ?? 'unlimited';
		$max         = 'unlimited' === $max_setting ? PHP_INT_MAX : (int) $max_setting;
		$state       = self::get_refresh_state();
		$started     = time();

		/**
		 * Filters how long one refresh tick may spend sweeping repository pages.
		 *
		 * Sweeps inherit php.ini max_execution_time because wp-cron.php never raises
		 * it, commonly 30s. Staying under that is what lets the cursor below survive
		 * to the next tick instead of the whole request being killed.
		 *
		 * @since 1.0.0
		 * @param int $budget Seconds per tick. Default 20.
		 * @return int
		 */
		$budget = (int) apply_filters( 'gitwire_refresh_time_budget', 20 );

		foreach ( Connection_Resolver::all() as $conn ) {
			$id       = $conn['id'] ?? '';
			$provider = $conn['provider'] ?? '';
			if ( ! $id || ! in_array( $provider, [ 'github', 'gitlab', 'bitbucket' ], true ) ) {
				continue;
			}

			$cursor      = $state[ $id ] ?? [];
			$page        = max( 1, (int) ( $cursor['page'] ?? 1 ) );
			$stored      = (int) ( $cursor['stored'] ?? 0 );
			$cycle_start = (string) ( $cursor['cycle_start'] ?? '' );

			if ( '' === $cycle_start ) {
				$cycle_start = current_datetime()->format( 'Y-m-d H:i:s' );
			}

			$exhausted = false;
			$conn_err  = null;

			do {
				if ( time() - $started >= $budget ) {
					$exhausted = true;
					break;
				}

				$result = self::fetch_repositories( $provider, $page, $id, $max - $stored, $cycle_start );
				if ( is_wp_error( $result ) ) {
					$conn_err = $result;
					$errors[] = [
						'connection_id' => $id,
						'provider'      => $provider,
						'message'       => $result->get_error_message(),
					];
					break;
				}

				$stored += count( $result['repositories'] ?? [] );
				++$page;

				$has_more = ( $result['has_more'] ?? false ) && $stored < $max;
			} while ( $has_more );

			if ( $exhausted ) {
				// Park the cursor and stop; the next tick resumes this connection mid-sweep.
				$state[ $id ] = [
					'page'        => $page,
					'stored'      => $stored,
					'cycle_start' => $cycle_start,
				];
				self::save_refresh_state( $state );
				return $errors;
			}

			/*
			 * Only prune once a sweep has actually seen every page. Doing it after a
			 * partial sweep would delete every repo the run never reached.
			 */
			if ( ! $conn_err ) {
				Repository::instance()->remove_stale_since( $id, $cycle_start );
			}

			unset( $state[ $id ] );
			self::save_refresh_state( $state );
		}

		return $errors;
	}

	/**
	 * Returns the per-connection resume cursors for an in-progress sweep.
	 *
	 * @since 1.0.0
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_refresh_state(): array {
		$state = get_option( 'gitwire_refresh_state', [] );
		return is_array( $state ) ? $state : [];
	}

	/**
	 * Persists the resume cursors, clearing the option once nothing is in flight.
	 *
	 * @since 1.0.0
	 * @param array<string, array<string, mixed>> $state Cursors keyed by connection ID.
	 * @return void
	 */
	private static function save_refresh_state( array $state ): void {
		if ( empty( $state ) ) {
			delete_option( 'gitwire_refresh_state' );
			return;
		}
		update_option( 'gitwire_refresh_state', $state, false );
	}

	/**
	 * Scheduled cron callback: refreshes repo lists only.
	 *
	 * All type detection, re-detecting known types and typing the untyped, runs on its
	 * own schedule (gitwire_refresh_repository_types) instead, since Repository Refresh
	 * Frequency and Repository Type Refresh Frequency are independent settings.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function scheduled_refresh(): void {
		self::refresh_repositories();
	}

	/**
	 * Scheduled cron callback for the repository type re-detection event.
	 *
	 * Checks the detection setting rather than trusting the schedule, so an event left
	 * behind from before detection was turned off doesn't keep spending API calls.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function scheduled_type_refresh(): void {
		if ( ! Settings::is_type_detection_enabled() ) {
			return;
		}

		self::refresh_installed_repository_types();
	}

	/**
	 * Scheduled cron callback: types cache rows that have no detection yet.
	 *
	 * Runs on its own fixed cadence, independent of both refresh frequencies, since it
	 * is opportunistic work (typing repos nobody has checked yet) rather than a
	 * freshness sweep, and the rate-limit guard inside already keeps it from competing
	 * with real API usage.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function scheduled_background_detection(): void {
		self::run_background_detection();
	}

	/**
	 * Re-detects repository types for every installed repository.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function refresh_installed_repository_types(): void {
		$targets = [];

		foreach ( Installer::get_installed() as $rec ) {
			$provider = $rec['provider'] ?? 'github';
			$owner    = $rec['owner'] ?? '';
			$repo     = $rec['repo'] ?? '';
			if ( ! $owner || ! $repo ) {
				continue;
			}

			// Keyed so two installs of the same repo are only detected once.
			$targets[ $provider . ':' . $owner . '/' . $repo ] = [
				'provider'      => $provider,
				'owner'         => $owner,
				'repo'          => $repo,
				'branch'        => $rec['branch'] ?? 'main',
				'connection_id' => ! empty( $rec['connection_id'] ) ? $rec['connection_id'] : null,
			];
		}

		foreach ( $targets as $target ) {
			if ( 'github' === $target['provider'] ) {
				$conn_key  = $target['connection_id'] ?? 'anon';
				$remaining = get_transient( 'gitwire_gh_rl_' . $conn_key );
				if ( false !== $remaining && (int) $remaining < 50 ) {
					break;
				}
			}

			$result = REST_Repositories::detect_type_for_repo(
				$target['provider'],
				$target['owner'],
				$target['repo'],
				$target['branch'],
				$target['connection_id']
			);
			if ( is_wp_error( $result ) ) {
				continue;
			}

			self::set_repository_type( $target['provider'], $target['owner'], $target['repo'], $result );
		}
	}

	/**
	 * Types a batch of cache rows that have no detection yet.
	 *
	 * No-op unless background_type_detection is on and type detection is wanted at all.
	 * The settings screen already switches the former off with the latter, but nothing
	 * stops a stored true from outliving that, and this cron runs on a fixed schedule.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function run_background_detection(): void {
		if ( ! Settings::is_type_detection_enabled() ) {
			return;
		}

		if ( ! ( Settings::get_public()['background_type_detection'] ?? false ) ) {
			return;
		}

		/**
		 * Filters the number of repositories to type-detect per cron cycle.
		 *
		 * Lower this on resource-constrained servers; raise it to speed up initial
		 * detection on large installs (at the cost of longer cron execution). Defaults
		 * to a larger batch when GitHub quota is sitting mostly idle.
		 *
		 * @since 1.0.0
		 * @param int $batch_size Repositories per cycle. Default 25, or 100 when GitHub is idle.
		 * @return int
		 */
		$batch_size = (int) apply_filters( 'gitwire_detection_batch_size', self::adaptive_batch_size() );

		/**
		 * Filters how long one background detection tick may spend calling providers.
		 *
		 * Ticks inherit php.ini max_execution_time because wp-cron.php never raises it,
		 * commonly 30s. Stopping short of that is what lets the cursor below be written
		 * instead of the whole request being killed mid-batch.
		 *
		 * @since 1.0.0
		 * @param int $budget Seconds per tick. Default 20.
		 * @return int
		 */
		$budget = (int) apply_filters( 'gitwire_detection_time_budget', 20 );

		$cursor      = (int) get_option( 'gitwire_detection_cursor', 0 );
		$batch_start = time();

		$untyped = Repository::instance()->get_untyped_batch( $batch_size, $cursor );

		if ( empty( $untyped ) ) {
			delete_option( 'gitwire_detection_cursor' );
		} else {
			$processed = 0;
			$stuck     = 0;
			foreach ( $untyped as $row ) {
				if ( time() - $batch_start >= $budget ) {
					break;
				}

				$branch        = $row['default_branch'] ? $row['default_branch'] : 'HEAD';
				$connection_id = $row['connection_id'] ? $row['connection_id'] : null;

				if ( 'github' === $row['provider'] ) {
					$conn_key  = $connection_id ?? 'anon';
					$remaining = get_transient( 'gitwire_gh_rl_' . $conn_key );
					if ( false !== $remaining && (int) $remaining < 50 ) {
						break;
					}
				}

				if ( 'gitlab' === $row['provider'] ) {
					$conn_key = $connection_id ?? 'anon';
					$cached   = get_transient( 'gitwire_gl_rl_' . $conn_key );
					if ( is_array( $cached ) && ( $cached['remaining'] ?? 0 ) < 50 ) {
						break;
					}
				}

				$result = REST_Repositories::detect_type_for_repo(
					$row['provider'],
					$row['owner'],
					$row['name'],
					$branch,
					$connection_id
				);
				if ( is_wp_error( $result ) ) {
					++$stuck;
				} else {
					self::set_repository_type( $row['provider'], $row['owner'], $row['name'], $result );
				}
				++$processed;
			}

			$fetched = count( $untyped );

			/*
			 * The IS NULL filter shifts left by every row we just typed, so the cursor may
			 * only advance past the ones that failed — otherwise each cycle skips a batch.
			 */
			if ( $processed < $fetched || $fetched < $batch_size || 0 === $stuck ) {
				delete_option( 'gitwire_detection_cursor' );
			} else {
				update_option( 'gitwire_detection_cursor', $cursor + $stuck, false );
			}
		}
	}

	/**
	 * Sizes the background-detection batch to GitHub's idle quota.
	 *
	 * GitHub's window is hourly and this cron ticks every 30 minutes, so a reading with
	 * most of the hour still unspent is real evidence that window is going unused and
	 * can absorb a bigger batch.
	 *
	 * Only GitHub qualifies. GitLab meters per minute, so a fresh minute always reads as
	 * idle no matter how much of the next half hour is already committed, and its
	 * headers expire long before the next tick reads them. Bitbucket reports nothing
	 * outside scaled-tier orgs. Both fall through to the fixed default, and the per-row
	 * floor checks in the batch loop still abort early whatever size is chosen.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	private static function adaptive_batch_size(): int {
		$remaining = self::min_github_remaining();

		return ( null !== $remaining && $remaining >= 3000 ) ? 100 : 25;
	}

	/**
	 * Returns the lowest cached GitHub rate-limit remaining across all GitHub connections.
	 *
	 * Null when the site has no GitHub connections, or none has a cached reading yet
	 * (nothing to size against, not necessarily low).
	 *
	 * @since 1.0.0
	 * @return int|null
	 */
	private static function min_github_remaining(): ?int {
		$lowest = null;

		foreach ( Connection_Resolver::all() as $conn ) {
			if ( 'github' !== ( $conn['provider'] ?? '' ) ) {
				continue;
			}

			$conn_key  = ! empty( $conn['id'] ) ? $conn['id'] : 'anon';
			$remaining = get_transient( 'gitwire_gh_rl_' . $conn_key );
			if ( false === $remaining ) {
				continue;
			}

			$remaining = (int) $remaining;
			if ( null === $lowest || $remaining < $lowest ) {
				$lowest = $remaining;
			}
		}

		return $lowest;
	}
}
