<?php
/**
 * Persistent option-based cache for repository lists and type detections.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores browse-repo lists and detection results in the options table.
 */
class Repo_Cache {

	public const REPOS_OPTION = 'gitwire_repos_cache';
	public const TYPES_OPTION = 'gitwire_repo_types';
	public const REPOS_TTL    = 1800;
	public const TYPES_TTL    = DAY_IN_SECONDS;

	/**
	 * Returns a cached repos page payload when still fresh.
	 *
	 * @since 1.0.0
	 * @param string $connection_id Connection ID used for the fetch.
	 * @param int    $page          Page number.
	 * @return array<string, mixed>|null Cached payload or null when missing/stale.
	 */
	public static function get_repos_page( string $connection_id, int $page ): ?array {
		$cache = get_option( self::REPOS_OPTION, [] );
		if ( ! is_array( $cache ) || empty( $cache[ $connection_id ]['pages'][ (string) $page ] ) ) {
			return null;
		}

		$page_data = $cache[ $connection_id ]['pages'][ (string) $page ];
		$fetched   = (int) ( $page_data['fetched_at'] ?? 0 );
		if ( $fetched && ( time() - $fetched ) <= self::REPOS_TTL ) {
			return $page_data;
		}

		return null;
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
	public static function set_repos_page( string $connection_id, int $page, array $payload ): void {
		$cache = get_option( self::REPOS_OPTION, [] );
		if ( ! is_array( $cache ) ) {
			$cache = [];
		}
		if ( ! isset( $cache[ $connection_id ] ) || ! is_array( $cache[ $connection_id ] ) ) {
			$cache[ $connection_id ] = [
				'pages'      => [],
				'updated_at' => 0,
			];
		}

		$payload['fetched_at']                              = time();
		$cache[ $connection_id ]['pages'][ (string) $page ] = $payload;
		$cache[ $connection_id ]['updated_at']              = time();

		update_option( self::REPOS_OPTION, $cache, false );
	}

	/**
	 * Builds the cache key for a repo type detection entry.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider key.
	 * @param string $owner    Repository owner.
	 * @param string $repo     Repository name.
	 * @param string $branch   Branch name.
	 * @return string
	 */
	public static function type_key( string $provider, string $owner, string $repo, string $branch ): string {
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
	public static function get_type( string $provider, string $owner, string $repo, string $branch ): ?array {
		$key   = self::type_key( $provider, $owner, $repo, $branch );
		$cache = get_option( self::TYPES_OPTION, [] );
		if ( is_array( $cache ) && ! empty( $cache[ $key ] ) ) {
			$entry   = $cache[ $key ];
			$fetched = (int) ( $entry['fetched_at'] ?? 0 );
			if ( $fetched && ( time() - $fetched ) <= self::TYPES_TTL ) {
				return $entry;
			}
		}

		return null;
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
	public static function set_type( string $provider, string $owner, string $repo, string $branch, array $result ): void {
		$key   = self::type_key( $provider, $owner, $repo, $branch );
		$cache = get_option( self::TYPES_OPTION, [] );
		if ( ! is_array( $cache ) ) {
			$cache = [];
		}

		$result['fetched_at'] = time();
		$cache[ $key ]        = $result;

		update_option( self::TYPES_OPTION, $cache, false );
	}

	/**
	 * Clears cached repository list data.
	 *
	 * @since 1.0.0
	 * @param string|null $connection_id Optional connection ID to clear one slot only. Null clears all.
	 * @return void
	 */
	public static function clear_repos( ?string $connection_id = null ): void {
		if ( null === $connection_id ) {
			delete_option( self::REPOS_OPTION );
			return;
		}

		$cache = get_option( self::REPOS_OPTION, [] );
		if ( ! is_array( $cache ) ) {
			return;
		}

		unset( $cache[ $connection_id ] );
		update_option( self::REPOS_OPTION, $cache, false );
	}

	/**
	 * Clears cached detection data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function clear_types(): void {
		delete_option( self::TYPES_OPTION );
	}

	/**
	 * Clears all browse caches.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function clear_all(): void {
		self::clear_repos();
		self::clear_types();
	}

	/**
	 * Fetches repos from the API and stores them in the options cache.
	 *
	 * @since 1.0.0
	 * @param string $provider      Provider key.
	 * @param int    $page          Page number.
	 * @param string $connection_id Connection ID to use for credentials.
	 * @return array<string, mixed>|\WP_Error Stored payload on success.
	 */
	public static function fetch_repos_page( string $provider, int $page, string $connection_id ): array|\WP_Error {
		$cache_id = '' !== $connection_id ? $connection_id : 'public:' . $provider;
		$payload  = REST::build_repos_page( $provider, $page, $connection_id );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		self::set_repos_page( $cache_id, $page, $payload );

		return $payload;
	}

	/**
	 * Refreshes cached repo list pages for all stored connections and public browse accounts.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error True on success, WP_Error when every source fails.
	 */
	public static function cron_refresh_repos(): true|\WP_Error {
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
			$result = self::fetch_repos_page( $provider, 1, $id );
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
	 * Re-detects repository types for cached and installed repos.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error True on success, WP_Error when detection fails globally.
	 */
	public static function cron_refresh_types(): true|\WP_Error {
		$settings = Settings::get_raw();
		if ( ! $settings ) {
			return true;
		}

		$keys           = [];
		$connection_ids = [];
		$types          = get_option( self::TYPES_OPTION, [] );
		$records        = Installer::get_installed();

		if ( is_array( $types ) ) {
			foreach ( array_keys( $types ) as $key ) {
				$keys[ $key ] = true;
			}
		}

		foreach ( $records as $rec ) {
			$provider = $rec['provider'] ?? 'github';
			$owner    = $rec['owner'] ?? '';
			$repo     = $rec['repo'] ?? '';
			$branch   = $rec['branch'] ?? 'main';
			if ( $owner && $repo ) {
				$key          = self::type_key( $provider, $owner, $repo, $branch );
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

			self::set_type( $provider, $owner, $repo, $full_branch, $result );
		}

		return $last_err ?? true;
	}

	/**
	 * Refreshes browse caches immediately (manual refresh).
	 *
	 * @since 1.0.0
	 * @param bool $include_types Whether to refresh type detections too.
	 * @return true|\WP_Error
	 */
	public static function refresh_all( bool $include_types = true ) {
		$repos = self::cron_refresh_repos();
		if ( is_wp_error( $repos ) ) {
			return $repos;
		}

		if ( ! $include_types ) {
			return true;
		}

		$types = self::cron_refresh_types();
		if ( is_wp_error( $types ) ) {
			return $types;
		}

		return true;
	}
}
