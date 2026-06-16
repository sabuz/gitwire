<?php
/**
 * Settings helpers — smart_install and token masking.
 *
 * @package Gitwire
 * @since 1.2.0
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
	 * @since 1.2.0
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
			'smart_install'            => $s['smart_install'] ?? true,
			'show_repo_label'          => $s['show_repo_label'] ?? true,
			'enable_logging'           => $s['enable_logging'] ?? false,
			'log_retention_days'       => $s['log_retention_days'] ?? 30,
			'log_level'                => $s['log_level'] ?? 'activity',
			'remove_data_on_uninstall' => $s['remove_data_on_uninstall'] ?? false,
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

		$show_repo_label = $current['show_repo_label'] ?? true;
		if ( array_key_exists( 'show_repo_label', $incoming ) && null !== $incoming['show_repo_label'] ) {
			$show_repo_label = (bool) $incoming['show_repo_label'];
		}

		$enable_logging = $current['enable_logging'] ?? false;
		if ( array_key_exists( 'enable_logging', $incoming ) && null !== $incoming['enable_logging'] ) {
			$enable_logging = (bool) $incoming['enable_logging'];
		}

		$log_retention_days = (int) ( $current['log_retention_days'] ?? 30 );
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

		return compact( 'smart_install', 'show_repo_label', 'enable_logging', 'log_retention_days', 'log_level', 'remove_data_on_uninstall' );
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
	 * Masks a secret for display (first 4 + last 4 characters).
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
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
