<?php
/**
 * Settings helpers — smart_install and token masking.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes gitwire_settings. Credentials live in gitwire_connections.
 */
class Settings {

	/**
	 * Returns raw settings from the database.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function get_raw(): array {
		return (array) get_option( 'gitwire_settings', [] );
	}

	/**
	 * Returns a client-safe settings payload.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function get_public(): array {
		$s = self::get_raw();
		return [
			'smart_install'               => $s['smart_install'] ?? true,
			'auto_detect_type'            => $s['auto_detect_type'] ?? true,
			'repos_per_page'              => $s['repos_per_page'] ?? 50,
			'excluded_repos'              => $s['excluded_repos'] ?? [],
			'max_repos_per_source'        => $s['max_repos_per_source'] ?? 'unlimited',
			'repo_list_refresh_frequency' => $s['repo_list_refresh_frequency'] ?? 'daily',
			'background_type_detection'   => $s['background_type_detection'] ?? false,
			'detection_batch_size'        => $s['detection_batch_size'] ?? 'auto',
			'shallow_detection'           => $s['shallow_detection'] ?? false,
			'show_repo_label'             => $s['show_repo_label'] ?? true,
			'block_on_fatal'              => $s['block_on_fatal'] ?? true,
			'update_check_interval'       => $s['update_check_interval'] ?? 'daily',
			'enable_logging'              => $s['enable_logging'] ?? false,
			'log_retention_days'          => $s['log_retention_days'] ?? 7,
			'log_level'                   => $s['log_level'] ?? 'activity',
			'remove_data_on_uninstall'    => $s['remove_data_on_uninstall'] ?? false,
		];
	}

	/**
	 * Merges incoming save params with stored settings.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $incoming Request body fields.
	 * @return array<string, mixed> Full settings array to persist.
	 */
	public static function merge_save( array $incoming ): array {
		$current = self::get_raw();

		$smart_install = $current['smart_install'] ?? true;
		if ( array_key_exists( 'smart_install', $incoming ) && null !== $incoming['smart_install'] ) {
			$smart_install = (bool) $incoming['smart_install'];
		}

		$auto_detect_type = $current['auto_detect_type'] ?? true;
		if ( array_key_exists( 'auto_detect_type', $incoming ) && null !== $incoming['auto_detect_type'] ) {
			$auto_detect_type = (bool) $incoming['auto_detect_type'];
		}

		$repos_per_page = (int) ( $current['repos_per_page'] ?? 50 );
		if ( array_key_exists( 'repos_per_page', $incoming ) && null !== $incoming['repos_per_page'] ) {
			$val            = (int) $incoming['repos_per_page'];
			$repos_per_page = max( 10, min( 100, $val ) );
		}

		$excluded_repos = $current['excluded_repos'] ?? [];
		if ( array_key_exists( 'excluded_repos', $incoming ) && is_array( $incoming['excluded_repos'] ) ) {
			$excluded_repos = array_values(
				array_filter(
					array_map( 'sanitize_text_field', $incoming['excluded_repos'] ),
					static fn( $v ) => (bool) preg_match( '/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/', $v )
				)
			);
		}

		$max_repos_per_source = $current['max_repos_per_source'] ?? 'unlimited';
		if ( array_key_exists( 'max_repos_per_source', $incoming ) && null !== $incoming['max_repos_per_source'] ) {
			$val = $incoming['max_repos_per_source'];
			if ( 'unlimited' === $val ) {
				$max_repos_per_source = 'unlimited';
			} else {
				$int                  = (int) $val;
				$max_repos_per_source = in_array( $int, [ 100, 250, 500 ], true ) ? $int : 'unlimited';
			}
		}

		$repo_list_refresh_frequency = $current['repo_list_refresh_frequency'] ?? 'daily';
		if ( array_key_exists( 'repo_list_refresh_frequency', $incoming ) && null !== $incoming['repo_list_refresh_frequency'] ) {
			$val                         = (string) $incoming['repo_list_refresh_frequency'];
			$repo_list_refresh_frequency = in_array( $val, [ 'hourly', 'daily', 'weekly' ], true ) ? $val : 'hourly';
		}

		$background_type_detection = $current['background_type_detection'] ?? false;
		if ( array_key_exists( 'background_type_detection', $incoming ) && null !== $incoming['background_type_detection'] ) {
			$background_type_detection = (bool) $incoming['background_type_detection'];
		}

		$detection_batch_size = $current['detection_batch_size'] ?? 'auto';
		if ( array_key_exists( 'detection_batch_size', $incoming ) && null !== $incoming['detection_batch_size'] ) {
			$val = $incoming['detection_batch_size'];
			if ( 'auto' === $val ) {
				$detection_batch_size = 'auto';
			} else {
				$int                  = (int) $val;
				$detection_batch_size = ( $int >= 10 && $int <= 200 ) ? $int : 'auto';
			}
		}

		$shallow_detection = $current['shallow_detection'] ?? false;
		if ( array_key_exists( 'shallow_detection', $incoming ) && null !== $incoming['shallow_detection'] ) {
			$shallow_detection = (bool) $incoming['shallow_detection'];
		}

		$show_repo_label = $current['show_repo_label'] ?? true;
		if ( array_key_exists( 'show_repo_label', $incoming ) && null !== $incoming['show_repo_label'] ) {
			$show_repo_label = (bool) $incoming['show_repo_label'];
		}

		$block_on_fatal = $current['block_on_fatal'] ?? true;
		if ( array_key_exists( 'block_on_fatal', $incoming ) && null !== $incoming['block_on_fatal'] ) {
			$block_on_fatal = (bool) $incoming['block_on_fatal'];
		}

		$update_check_interval = $current['update_check_interval'] ?? 'daily';
		if ( array_key_exists( 'update_check_interval', $incoming ) && null !== $incoming['update_check_interval'] ) {
			$val                   = (string) $incoming['update_check_interval'];
			$update_check_interval = in_array( $val, [ 'hourly', '6hours', 'daily', 'weekly', 'never' ], true ) ? $val : 'daily';
		}

		$enable_logging = $current['enable_logging'] ?? false;
		if ( array_key_exists( 'enable_logging', $incoming ) && null !== $incoming['enable_logging'] ) {
			$enable_logging = (bool) $incoming['enable_logging'];
		}

		$log_retention_days = (int) ( $current['log_retention_days'] ?? 7 );
		if ( array_key_exists( 'log_retention_days', $incoming ) && null !== $incoming['log_retention_days'] ) {
			$val                = (int) $incoming['log_retention_days'];
			$log_retention_days = in_array( $val, [ 7, 15, 30 ], true ) ? $val : 30;
		}

		$log_level = $current['log_level'] ?? 'activity';
		if ( array_key_exists( 'log_level', $incoming ) && null !== $incoming['log_level'] ) {
			$val       = (string) $incoming['log_level'];
			$log_level = in_array( $val, [ 'activity', 'error' ], true ) ? $val : 'activity';
		}

		$remove_data_on_uninstall = (bool) ( $current['remove_data_on_uninstall'] ?? false );
		if ( array_key_exists( 'remove_data_on_uninstall', $incoming ) && null !== $incoming['remove_data_on_uninstall'] ) {
			$remove_data_on_uninstall = (bool) $incoming['remove_data_on_uninstall'];
		}

		return compact(
			'smart_install',
			'auto_detect_type',
			'repos_per_page',
			'excluded_repos',
			'max_repos_per_source',
			'repo_list_refresh_frequency',
			'background_type_detection',
			'detection_batch_size',
			'shallow_detection',
			'show_repo_label',
			'block_on_fatal',
			'update_check_interval',
			'enable_logging',
			'log_retention_days',
			'log_level',
			'remove_data_on_uninstall'
		);
	}

	/**
	 * Returns whether activity logging is currently enabled.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_logging_enabled(): bool {
		$s = self::get_raw();
		return (bool) ( $s['enable_logging'] ?? false );
	}

	/**
	 * Returns the number of days to retain log entries (0 = unlimited).
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public static function get_log_retention_days(): int {
		$s = self::get_raw();
		return (int) ( $s['log_retention_days'] ?? 30 );
	}

	/**
	 * Returns the minimum log level to record ('activity' or 'error').
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_log_level(): string {
		$s   = self::get_raw();
		$val = $s['log_level'] ?? 'activity';
		return in_array( $val, [ 'activity', 'error' ], true ) ? $val : 'activity';
	}

	/**
	 * Returns the configured repositories cache refresh frequency.
	 *
	 * @since 1.0.0
	 * @return string WP cron recurrence: 'hourly', 'daily', or 'weekly'.
	 */
	public static function get_repo_list_refresh_frequency(): string {
		$s   = self::get_raw();
		$val = $s['repo_list_refresh_frequency'] ?? 'daily';
		return in_array( $val, [ 'hourly', 'daily', 'weekly' ], true ) ? $val : 'hourly';
	}

	/**
	 * Returns the max age in seconds before a cached repositories page is considered stale.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public static function get_repositories_max_age(): int {
		return match ( self::get_repo_list_refresh_frequency() ) {
			'daily'  => DAY_IN_SECONDS,
			'weekly' => WEEK_IN_SECONDS,
			default  => HOUR_IN_SECONDS,
		};
	}

	/**
	 * Masks a secret for display (first 4 + last 4 characters).
	 *
	 * @since 1.0.0
	 * @param string $token Raw token.
	 * @return string Masked preview or empty string.
	 */
	public static function mask_token( string $token ): string {
		$token = trim( $token );
		if ( '' === $token ) {
			return '';
		}
		if ( strlen( $token ) <= 8 ) {
			return str_repeat( '•', strlen( $token ) );
		}

		return substr( $token, 0, 4 ) . str_repeat( '•', min( 12, strlen( $token ) - 8 ) ) . substr( $token, -4 );
	}

	/**
	 * Blocks private/reserved hosts for self-hosted GitLab URLs.
	 *
	 * @since 1.0.0
	 * @param string $url GitLab instance URL.
	 * @return bool
	 */
	public static function is_allowed_gitlab_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return true;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] ?? '' );
		if ( '' === $host ) {
			return false;
		}

		if ( in_array( $host, [ 'localhost', '127.0.0.1', '0.0.0.0' ], true ) ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}

		return true;
	}
}
