<?php
/**
 * Persistent option-based cache for repository lists and type detections.
 *
 * @package Gitwire
 * @since 1.2.0
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
	 * @since 1.2.0
	 * @param string $provider Provider key.
	 * @param int    $page     Page number.
	 * @return array<string, mixed>|null Cached payload or null when missing/stale.
	 */
	public static function get_repos_page( string $provider, int $page ): ?array {
		$cache = get_option( self::REPOS_OPTION, [] );
		if ( ! is_array( $cache ) || empty( $cache[ $provider ]['pages'][ (string) $page ] ) ) {
			return null;
		}

		$page_data = $cache[ $provider ]['pages'][ (string) $page ];
		$fetched   = (int) ( $page_data['fetched_at'] ?? 0 );
		if ( $fetched && ( time() - $fetched ) <= self::REPOS_TTL ) {
			return $page_data;
		}

		return null;
	}

	/**
	 * Stores a repos page payload.
	 *
	 * @since 1.2.0
	 * @param string               $provider Provider key.
	 * @param int                  $page     Page number.
	 * @param array<string, mixed> $payload  Repos payload.
	 * @return void
	 */
	public static function set_repos_page( string $provider, int $page, array $payload ): void {
		$cache = get_option( self::REPOS_OPTION, [] );
		if ( ! is_array( $cache ) ) {
			$cache = [];
		}
		if ( ! isset( $cache[ $provider ] ) || ! is_array( $cache[ $provider ] ) ) {
			$cache[ $provider ] = [
				'pages'      => [],
				'updated_at' => 0,
			];
		}

		$payload['fetched_at']                         = time();
		$cache[ $provider ]['pages'][ (string) $page ] = $payload;
		$cache[ $provider ]['updated_at']              = time();

		update_option( self::REPOS_OPTION, $cache, false );
	}

	/**
	 * Builds the cache key for a repo type detection entry.
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
	 * @param string|null $provider Optional provider key to clear one provider only.
	 * @return void
	 */
	public static function clear_repos( ?string $provider = null ): void {
		if ( null === $provider ) {
			delete_option( self::REPOS_OPTION );
			return;
		}

		$cache = get_option( self::REPOS_OPTION, [] );
		if ( ! is_array( $cache ) ) {
			return;
		}

		unset( $cache[ $provider ] );
		update_option( self::REPOS_OPTION, $cache, false );
	}

	/**
	 * Clears cached detection data.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function clear_types(): void {
		delete_option( self::TYPES_OPTION );
	}

	/**
	 * Clears all browse caches.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function clear_all(): void {
		self::clear_repos();
		self::clear_types();
	}

	/**
	 * Fetches repos from the API and stores them in the options cache.
	 *
	 * @since 1.2.0
	 * @param string $provider Provider key.
	 * @param int    $page     Page number.
	 * @return array<string, mixed>|\WP_Error Stored payload on success.
	 */
	public static function fetch_repos_page( string $provider, int $page ) {
		$settings = (array) get_option( 'gitwire_settings', [] );
		$payload  = REST::build_repos_page( $settings, $provider, $page );

		if ( is_wp_error( $payload ) ) {
			self::clear_repos( $provider );
			return $payload;
		}

		self::set_repos_page( $provider, $page, $payload );

		return $payload;
	}

	/**
	 * Refreshes cached repo list pages for configured providers.
	 *
	 * @since 1.2.0
	 * @return true|\WP_Error True on success, WP_Error when all configured providers fail.
	 */
	public static function cron_refresh_repos() {
		$settings = (array) get_option( 'gitwire_settings', [] );
		$ran      = false;
		$last_err = null;

		if ( ! empty( $settings['username'] ) || ! empty( $settings['token'] ) ) {
			$ran    = true;
			$result = self::fetch_repos_page( 'github', 1 );
			if ( is_wp_error( $result ) ) {
				$last_err = $result;
			}
		}

		if ( ! empty( $settings['gitlab_token'] ) ) {
			$ran    = true;
			$result = self::fetch_repos_page( 'gitlab', 1 );
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
	 * @since 1.2.0
	 * @return true|\WP_Error True on success, WP_Error when detection fails globally.
	 */
	public static function cron_refresh_types() {
		$settings = Settings::get_raw();
		if ( ! $settings ) {
			return true;
		}

		$keys    = [];
		$types   = get_option( self::TYPES_OPTION, [] );
		$records = Installer::get_installed();

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
				$keys[ self::type_key( $provider, $owner, $repo, $branch ) ] = true;
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
			$name_parts  = explode( '/', $full_name, 2 );
			if ( count( $name_parts ) < 2 ) {
				continue;
			}

			$result = REST::detect_type_for_repo(
				$settings,
				$provider,
				$name_parts[0],
				$name_parts[1],
				$full_branch
			);
			if ( is_wp_error( $result ) ) {
				$last_err = $result;
				continue;
			}

			self::set_type( $provider, $name_parts[0], $name_parts[1], $full_branch, $result );
		}

		if ( $last_err && count( $keys ) === 1 ) {
			self::clear_types();
			return $last_err;
		}

		return true;
	}

	/**
	 * Refreshes browse caches immediately (manual refresh).
	 *
	 * @since 1.2.0
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
