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
	 * @since 2.0.0
	 * @return array<string, mixed>
	 */
	public static function get_public(): array {
		$s = self::get_raw();
		return [
			'smart_install' => $s['smart_install'] ?? true,
		];
	}

	/**
	 * Merges incoming save params with stored settings.
	 *
	 * @since 2.0.0
	 * @param array<string, mixed> $incoming Request body fields.
	 * @return array<string, mixed> Full settings array to persist.
	 */
	public static function merge_save( array $incoming ): array {
		$current = self::get_raw();

		$smart_install = $current['smart_install'] ?? true;
		if ( array_key_exists( 'smart_install', $incoming ) && null !== $incoming['smart_install'] ) {
			$smart_install = (bool) $incoming['smart_install'];
		}

		return compact( 'smart_install' );
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
