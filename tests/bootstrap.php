<?php
/**
 * PHPUnit bootstrap for Gitwire unit tests.
 *
 * @package Gitwire
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Minimal trailingslashit stub for unit tests.
	 *
	 * @param string $path Path to trail.
	 * @return string
	 */
	function trailingslashit( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}
}

require_once dirname( __DIR__ ) . '/autoload.php';

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Minimal get_option stub for unit tests.
	 *
	 * @param string $option Option name.
	 * @return array<string, mixed>
	 */
	function get_option( string $option ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return [];
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Minimal sanitize_text_field stub for unit tests.
	 *
	 * @param string $str String to sanitize.
	 * @return string
	 */
	function sanitize_text_field( string $str ): string {
		return trim( $str );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Minimal esc_url_raw stub for unit tests.
	 *
	 * @param string $url URL to sanitize.
	 * @return string
	 */
	function esc_url_raw( string $url ): string {
		return trim( $url );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Minimal wp_parse_url stub for unit tests.
	 *
	 * @param string $url URL to parse.
	 * @return array<string, string>|false
	 */
	function wp_parse_url( string $url ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return parse_url( $url );
	}
}
