<?php
/**
 * Transient-based cache for repository lists and type detections.
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
 * Both repos and types are stored as options (no TTL layer); cron refreshes
 * them at the configured frequency, and on-demand fetch fills gaps.
 */
class Repo_Cache {

	/**
	 * Returns the option key for a connection's repo pages.
	 *
	 * @since 1.0.0
	 * @param string $connection_id Connection ID or 'public:{provider}'.
	 * @return string
	 */
	private static function repo_list_option_key( string $connection_id ): string {
		return 'gitwire_repo_list_' . md5( $connection_id );
	}

	/**
	 * Returns the option key for a type detection result.
	 *
	 * @since 1.0.0
	 * @param string $type_key Canonical type key from repo_type_key().
	 * @return string
	 */
	private static function repo_type_option_key( string $type_key ): string {
		return 'gitwire_repo_type_' . md5( $type_key );
	}

	/**
	 * Returns a cached repos page payload when still fresh.
	 *
	 * @since 1.0.0
	 * @param string $connection_id Connection ID used for the fetch.
	 * @param int    $page          Page number.
	 * @return array<string, mixed>|null Cached payload or null when missing/stale.
	 */
	public static function get_repo_list( string $connection_id, int $page ): ?array {
		$data = get_option( self::repo_list_option_key( $connection_id ) );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$page_data = $data[ (string) $page ] ?? null;
		if ( ! is_array( $page_data ) ) {
			return null;
		}

		$updated = (int) ( $page_data['updated_at'] ?? 0 );
		if ( ! $updated || ( time() - $updated ) > Settings::get_repos_max_age() ) {
			return null;
		}

		return $page_data;
	}

	/**
	 * Stores a repos page payload.
	 *
	 * @since 1.0.0
	 * @param string               $connection_id Connection ID used for the fetch.
	 * @param int                  $page          Page number.
	 * @param array<string, mixed> $payload       Repos payload.
	 * @return void
	 */
	public static function set_repo_list( string $connection_id, int $page, array $payload ): void {
		$key  = self::repo_list_option_key( $connection_id );
		$data = get_option( $key );
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		$payload['updated_at']  = time();
		$data[ (string) $page ] = $payload;

		update_option( $key, $data, false );
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
	 * Returns a cached detection result when still fresh.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider key.
	 * @param string $owner    Repository owner.
	 * @param string $repo     Repository name.
	 * @param string $branch   Branch name.
	 * @return array<string, mixed>|null Cached detection or null when missing/stale.
	 */
	public static function get_repo_type( string $provider, string $owner, string $repo, string $branch ): ?array {
		$stored = get_option( self::repo_type_option_key( self::repo_type_key( $provider, $owner, $repo, $branch ) ) );
		if ( ! is_array( $stored ) ) {
			return null;
		}
		$updated = (int) ( $stored['updated_at'] ?? 0 );
		if ( ! $updated || ( time() - $updated ) > Settings::get_repos_max_age() ) {
			return null;
		}
		return is_array( $stored['data'] ?? null ) ? $stored['data'] : null;
	}

	/**
	 * Stores a detection result.
	 *
	 * @since 1.0.0
	 * @param string               $provider Provider key.
	 * @param string               $owner    Repository owner.
	 * @param string               $repo     Repository name.
	 * @param string               $branch   Branch name.
	 * @param array<string, mixed> $result   Detection payload.
	 * @return void
	 */
	public static function set_repo_type( string $provider, string $owner, string $repo, string $branch, array $result ): void {
		update_option(
			self::repo_type_option_key( self::repo_type_key( $provider, $owner, $repo, $branch ) ),
			[ 'updated_at' => time(), 'data' => $result ],
			false
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
		if ( null !== $connection_id ) {
			delete_option( self::repo_list_option_key( $connection_id ) );
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'gitwire_repo_list_' ) . '%'
			)
		);
	}

	/**
	 * Clears all cached detection data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function clear_repo_types(): void {
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
		self::clear_repo_list();
		self::clear_repo_types();
	}

	/**
	 * Fetches repos from the API and stores them in the transient cache.
	 *
	 * @since 1.0.0
	 * @param string $provider      Provider key.
	 * @param int    $page          Page number.
	 * @param string $connection_id Connection ID to use for credentials.
	 * @return array<string, mixed>|\WP_Error Stored payload on success.
	 */
	public static function fetch_repo_list( string $provider, int $page, string $connection_id ): array|\WP_Error {
		$cache_id = '' !== $connection_id ? $connection_id : 'public:' . $provider;
		$payload  = REST::build_repos_page( $provider, $page, $connection_id );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		self::set_repo_list( $cache_id, $page, $payload );

		return $payload;
	}

	/**
	 * Fetches page 1 of the repo list for every known connection.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error True on success, WP_Error when every source fails.
	 */
	private static function refresh_repo_lists(): true|\WP_Error {
		$sources = [];

		foreach ( Connection_Resolver::all() as $conn ) {
			$id       = $conn['id'] ?? '';
			$provider = $conn['provider'] ?? '';
			if ( $id && in_array( $provider, [ 'github', 'gitlab', 'bitbucket' ], true ) ) {
				$sources[] = [ $provider, $id ];
			}
		}

		$ran      = false;
		$last_err = null;

		foreach ( $sources as [ $provider, $id ] ) {
			$ran    = true;
			$result = self::fetch_repo_list( $provider, 1, $id );
			if ( is_wp_error( $result ) ) {
				$last_err = $result;
			}
		}

		if ( ! $ran ) {
			return true;
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
	 * Re-detects repository types for cached and installed repos.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error True on success, WP_Error when detection fails globally.
	 */
	public static function cron_refresh_repo_types(): true|\WP_Error {
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

		if ( ! $keys ) {
			return true;
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
