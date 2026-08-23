<?php
/**
 * Validates an active theme bootstrap using WordPress core loopback scraping.
 *
 * Mirrors wp_edit_theme_plugin_file() so bad theme code is rejected and reverted
 * in the same request, like the theme file editor.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loopback scraper for active theme fatal-error detection.
 */
class Theme_Scraper {

	/**
	 * Bootstraps the active theme in wp-admin, then on the frontend.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error True when both loopbacks succeed, WP_Error when rejected.
	 */
	public static function scrape_bootstrap(): bool|\WP_Error {
		$result = self::run_scrape_requests(
			[
				admin_url( 'themes.php' ),
				home_url( '/' ),
			]
		);

		if ( true === $result ) {
			return true;
		}

		return self::to_wp_error( $result, 'update' );
	}

	/**
	 * Validates a newly activated theme in wp-admin, then on the frontend.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error True when both loopbacks succeed, WP_Error when rejected.
	 */
	public static function scrape_activation(): bool|\WP_Error {
		$result = self::run_scrape_requests(
			[
				admin_url( 'themes.php' ),
				home_url( '/' ),
			]
		);

		if ( true === $result ) {
			return true;
		}

		return self::to_wp_error( $result, 'activation' );
	}

	/**
	 * Bootstraps the active plugin in wp-admin, then on the frontend.
	 *
	 * Active plugins are already loaded in the current request, so core's
	 * include_once sandbox cannot re-run replaced code. A fresh loopback
	 * request loads the new code and surfaces any fatal it introduces.
	 *
	 * @since 1.0.0
	 * @return true|\WP_Error True when both loopbacks succeed, WP_Error when rejected.
	 */
	public static function scrape_plugin_bootstrap(): bool|\WP_Error {
		$result = self::run_scrape_requests(
			[
				admin_url( 'plugins.php' ),
				home_url( '/' ),
			]
		);

		if ( true === $result ) {
			return true;
		}

		return self::to_wp_error( $result, 'update', 'plugin' );
	}

	/**
	 * Returns whether a scrape payload reports a PHP fatal from the sandbox.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $result Scrape failure payload.
	 * @return bool
	 */
	public static function is_php_fatal_result( array $result ): bool {
		if ( ! isset( $result['type'], $result['message'] ) ) {
			return false;
		}

		$fatal_types = [
			E_ERROR,
			E_PARSE,
			E_CORE_ERROR,
			E_COMPILE_ERROR,
			E_USER_ERROR,
			E_RECOVERABLE_ERROR,
		];

		return in_array( (int) $result['type'], $fatal_types, true );
	}

	/**
	 * Returns whether a scrape payload indicates loopback infrastructure failure.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $result Scrape failure payload.
	 * @return bool
	 */
	public static function is_infrastructure_failure( array $result ): bool {
		$code = $result['code'] ?? '';

		return in_array( $code, [ 'loopback_request_failed', 'json_parse_error', 'scrape_nonce_failure' ], true );
	}

	/**
	 * Runs WordPress-style loopback scrape requests against one or more URLs.
	 *
	 * Admin is scraped first; the homepage is only checked when admin succeeds,
	 * matching wp_edit_theme_plugin_file().
	 *
	 * @since 1.0.0
	 * @param array<int, string> $urls Absolute URLs to scrape.
	 * @return true|array<string, mixed> True on success, scrape error payload on failure.
	 */
	private static function run_scrape_requests( array $urls ): bool|array {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			require_once ABSPATH . 'wp-includes/http.php';
		}

		$scrape_key   = md5( (string) wp_rand() );
		$transient    = 'scrape_key_' . $scrape_key;
		$scrape_nonce = (string) wp_rand();
		set_transient( $transient, $scrape_nonce, MINUTE_IN_SECONDS );

		$cookies = self::get_loopback_cookies();
		$headers = self::get_loopback_headers();

		// Keep the PHP process alive until all loopback requests return.
		if ( function_exists( 'set_time_limit' ) ) {
			// Core uses the same function for its loopback scrape.

			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			set_time_limit( 5 * MINUTE_IN_SECONDS );
		}

		$timeout = 100;

		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}

		$admin_url = $urls[0] ?? admin_url( 'themes.php' );

		// This is a WordPress core filter.

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$sslverify = apply_filters( 'https_local_ssl_verify', false, $admin_url );

		$parsed = self::scrape_url_with_fallbacks(
			$admin_url,
			$scrape_key,
			$scrape_nonce,
			$cookies,
			$headers,
			$timeout,
			$sslverify
		);
		if ( true !== $parsed ) {
			delete_transient( $transient );
			return $parsed;
		}

		if ( isset( $urls[1] ) ) {
			$parsed = self::scrape_url_with_fallbacks(
				$urls[1],
				$scrape_key,
				$scrape_nonce,
				$cookies,
				$headers,
				$timeout,
				$sslverify
			);
			if ( true !== $parsed ) {
				delete_transient( $transient );
				return $parsed;
			}
		}

		delete_transient( $transient );

		return true;
	}

	/**
	 * Builds cookies for loopback requests, including auth for the current user.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	private static function get_loopback_cookies(): array {
		$cookies = wp_unslash( $_COOKIE );

		if ( ! is_user_logged_in() ) {
			return $cookies;
		}

		if ( ! function_exists( 'wp_generate_auth_cookie' ) ) {
			require_once ABSPATH . 'wp-includes/pluggable.php';
		}

		if ( ! defined( 'AUTH_COOKIE' ) ) {
			wp_cookie_constants();
		}

		$user_id = get_current_user_id();
		// This is a WordPress core filter.

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$expiration = time() + (int) apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $user_id, false );

		$cookies[ AUTH_COOKIE ]      = wp_generate_auth_cookie( $user_id, $expiration, 'auth' );
		$cookies[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in' );

		if ( defined( 'SECURE_AUTH_COOKIE' ) ) {
			$cookies[ SECURE_AUTH_COOKIE ] = wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth' );
		}

		if ( defined( 'SECURE_LOGGED_IN_COOKIE' ) ) {
			$cookies[ SECURE_LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in' );
		}

		return $cookies;
	}

	/**
	 * Builds headers for loopback requests.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	private static function get_loopback_headers(): array {
		$headers = [
			'Cache-Control' => 'no-cache',
		];

		if ( isset( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			// Remove CR, LF, and null bytes before encoding the header.

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$user = str_replace( [ "\r", "\n", "\0" ], '', wp_unslash( $_SERVER['PHP_AUTH_USER'] ) );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$pass = str_replace( [ "\r", "\n", "\0" ], '', wp_unslash( $_SERVER['PHP_AUTH_PW'] ) );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$headers['Authorization'] = 'Basic ' . base64_encode( $user . ':' . $pass );
		}

		return $headers;
	}

	/**
	 * Attempts a scrape request, retrying with alternate local URLs when needed.
	 *
	 * @since 1.0.0
	 * @param string                $url          Absolute URL to scrape.
	 * @param string                $scrape_key   Scrape session key.
	 * @param string                $scrape_nonce Scrape session nonce.
	 * @param array<string, string> $cookies      Request cookies.
	 * @param array<string, string> $headers      Request headers.
	 * @param int                   $timeout      Request timeout in seconds.
	 * @param bool                  $sslverify    Whether to verify SSL certificates.
	 * @return true|array<string, mixed> True on success, scrape error payload on failure.
	 */
	private static function scrape_url_with_fallbacks(
		string $url,
		string $scrape_key,
		string $scrape_nonce,
		array $cookies,
		array $headers,
		int $timeout,
		bool $sslverify
	): bool|array {
		$candidates = self::get_loopback_url_candidates( $url );

		$last_result = [
			'code'    => 'loopback_request_failed',
			'message' => __(
				'Unable to communicate back with the site to check for fatal errors, so the change was reverted.',
				'gitwire'
			),
		];

		foreach ( $candidates as $candidate ) {
			$result = self::scrape_url(
				$candidate,
				$scrape_key,
				$scrape_nonce,
				$cookies,
				$headers,
				$timeout,
				$sslverify
			);

			if ( true === $result ) {
				return true;
			}

			$last_result = $result;

			if ( self::is_php_fatal_result( $result ) || ! self::is_infrastructure_failure( $result ) ) {
				return $result;
			}
		}

		return $last_result;
	}

	/**
	 * Returns URL variants to try for local loopback requests.
	 *
	 * @since 1.0.0
	 * @param string $url Original absolute URL.
	 * @return array<int, string|array{url: string, headers: array<string, string>}>
	 */
	private static function get_loopback_url_candidates( string $url ): array {
		$candidates = [ $url ];
		$parts      = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $candidates;
		}

		$host     = $parts['host'];
		$is_local = in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true )
			|| substr( $host, -5 ) === '.test'
			|| substr( $host, -6 ) === '.local';

		if ( ! $is_local ) {
			return $candidates;
		}

		$path     = $parts['path'] ?? '/';
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
		$port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		foreach ( [ '127.0.0.1', 'localhost' ] as $loopback_host ) {
			if ( $loopback_host === $host ) {
				continue;
			}

			$scheme       = $parts['scheme'] ?? 'http';
			$variant      = $scheme . '://' . $loopback_host . $port . $path . $query . $fragment;
			$headers      = [
				'Host' => $host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ),
			];
			$candidates[] = [
				'url'     => $variant,
				'headers' => $headers,
			];
		}

		return $candidates;
	}

	/**
	 * Performs a single loopback scrape request.
	 *
	 * @since 1.0.0
	 * @param string|array<string, mixed> $url          Absolute URL or candidate array.
	 * @param string                      $scrape_key   Scrape session key.
	 * @param string                      $scrape_nonce Scrape session nonce.
	 * @param array<string, string>       $cookies      Request cookies.
	 * @param array<string, string>       $headers      Request headers.
	 * @param int                         $timeout      Request timeout in seconds.
	 * @param bool                        $sslverify    Whether to verify SSL certificates.
	 * @return true|array<string, mixed> True on success, scrape error payload on failure.
	 */
	private static function scrape_url(
		$url,
		string $scrape_key,
		string $scrape_nonce,
		array $cookies,
		array $headers,
		int $timeout,
		bool $sslverify
	): bool|array {
		$extra_headers = [];
		if ( is_array( $url ) ) {
			$extra_headers = $url['headers'] ?? [];
			$url           = $url['url'] ?? '';
		}

		if ( ! is_string( $url ) || ! $url ) {
			return [
				'code'    => 'loopback_request_failed',
				'message' => __(
					'Unable to communicate back with the site to check for fatal errors, so the change was reverted.',
					'gitwire'
				),
			];
		}

		$needle_start = "###### wp_scraping_result_start:$scrape_key ######";
		$needle_end   = "###### wp_scraping_result_end:$scrape_key ######";
		$request_url  = add_query_arg(
			[
				'wp_scrape_key'   => $scrape_key,
				'wp_scrape_nonce' => $scrape_nonce,
			],
			$url
		);

		$response = wp_remote_get(
			$request_url,
			[
				'cookies'     => $cookies,
				'headers'     => array_merge( $headers, $extra_headers ),
				'timeout'     => $timeout,
				'sslverify'   => $sslverify,
				'redirection' => 5,
			]
		);

		return self::parse_scrape_response( $response, $needle_start, $needle_end );
	}

	/**
	 * Parses a loopback scrape HTTP response.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed>|\WP_Error $response     HTTP response.
	 * @param string                         $needle_start Result start marker.
	 * @param string                         $needle_end   Result end marker.
	 * @return true|array<string, mixed> True on success, scrape error payload on failure.
	 */
	private static function parse_scrape_response( $response, string $needle_start, string $needle_end ): bool|array {
		if ( is_wp_error( $response ) ) {
			return [
				'code'    => 'loopback_request_failed',
				'message' => __(
					'Unable to communicate back with the site to check for fatal errors, so the change was reverted.',
					'gitwire'
				),
			];
		}

		$body                   = wp_remote_retrieve_body( $response );
		$scrape_result_position = strpos( $body, $needle_start );

		if ( false === $scrape_result_position ) {
			return [
				'code'    => 'loopback_request_failed',
				'message' => __(
					'Unable to communicate back with the site to check for fatal errors, so the change was reverted.',
					'gitwire'
				),
			];
		}

		$error_output = substr( $body, $scrape_result_position + strlen( $needle_start ) );
		$end_position = strpos( $error_output, $needle_end );
		if ( false === $end_position ) {
			return [
				'code' => 'json_parse_error',
			];
		}

		$error_output = substr( $error_output, 0, $end_position );
		$result       = json_decode( trim( $error_output ), true );

		if ( empty( $result ) && ! is_bool( $result ) ) {
			return [
				'code' => 'json_parse_error',
			];
		}

		if ( true === $result ) {
			return true;
		}

		return is_array( $result ) ? $result : [ 'code' => 'json_parse_error' ];
	}

	/**
	 * Strips the stack trace PHP embeds in error_get_last() for an uncaught throwable.
	 *
	 * @since 1.0.0
	 * @param string $message Raw fatal message from the sandbox.
	 * @return string
	 */
	private static function trim_fatal_message( string $message ): string {
		$trace_pos = strpos( $message, 'Stack trace:' );

		return false === $trace_pos ? $message : rtrim( substr( $message, 0, $trace_pos ) );
	}

	/**
	 * Converts a scrape failure payload into a REST-friendly WP_Error.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $result  Scrape failure payload.
	 * @param string               $context Guard context: activation or update.
	 * @param string               $subject Installation subject: theme or plugin.
	 * @return \WP_Error
	 */
	private static function to_wp_error( array $result, string $context, string $subject = 'theme' ): \WP_Error {
		$code      = $result['code'] ?? '';
		$is_plugin = 'plugin' === $subject;

		if ( self::is_php_fatal_result( $result ) ) {
			$detail = self::trim_fatal_message( $result['message'] );
			if ( 'activation' === $context ) {
				$message = $is_plugin
					? sprintf(
						/* translators: %s: PHP error detail */
						__( 'Plugin could not be activated because it triggered a fatal error: %s', 'gitwire' ),
						$detail
					)
					: sprintf(
						/* translators: %s: PHP error detail */
						__( 'Theme could not be activated because it triggered a fatal error: %s', 'gitwire' ),
						$detail
					);
			} else {
				$message = $is_plugin
					? sprintf(
						/* translators: %s: PHP error detail */
						__( 'Plugin could not be updated because it triggered a fatal error: %s', 'gitwire' ),
						$detail
					)
					: sprintf(
						/* translators: %s: PHP error detail */
						__( 'Theme could not be updated because it triggered a fatal error: %s', 'gitwire' ),
						$detail
					);
			}
		} elseif ( 'loopback_request_failed' === $code ) {
			$message = $result['message'] ?? __(
				'Unable to communicate back with the site to check for fatal errors, so the change was reverted.',
				'gitwire'
			);
		} elseif ( 'scrape_nonce_failure' === $code ) {
			$message = $is_plugin
				? __( 'Could not verify the plugin update because the loopback check failed. The change was reverted. Please try again.', 'gitwire' )
				: __( 'Could not verify the theme update because the loopback check failed. The change was reverted. Please try again.', 'gitwire' );
		} elseif ( 'json_parse_error' === $code ) {
			if ( 'activation' === $context ) {
				$message = $is_plugin
					? __( 'The plugin was not activated because the validation response was invalid.', 'gitwire' )
					: __( 'The theme was not activated because the validation response was invalid.', 'gitwire' );
			} else {
				$message = __( 'The update was not applied because the validation response was invalid, so the change was reverted.', 'gitwire' );
			}
		} elseif ( isset( $result['message'] ) && is_string( $result['message'] ) ) {
			$detail = $result['message'];
			if ( 'activation' === $context ) {
				$message = $is_plugin
					? sprintf(
						/* translators: %s: error detail */
						__( 'The plugin was not activated: %s', 'gitwire' ),
						$detail
					)
					: sprintf(
						/* translators: %s: error detail */
						__( 'The theme was not activated: %s', 'gitwire' ),
						$detail
					);
			} else {
				$message = sprintf(
					/* translators: %s: error detail */
					__( 'The update was not applied: %s', 'gitwire' ),
					$detail
				);
			}
		} elseif ( 'activation' === $context ) {
			$message = $is_plugin
				? __( 'The plugin was not activated because validation failed.', 'gitwire' )
				: __( 'The theme was not activated because validation failed.', 'gitwire' );
		} else {
			$message = $is_plugin
				? __( 'The update was not applied because plugin validation failed, so the change was reverted.', 'gitwire' )
				: __( 'The update was not applied because theme validation failed, so the change was reverted.', 'gitwire' );
		}

		return new \WP_Error(
			'gitwire_theme_scrape_failed',
			$message,
			[
				'status' => 500,
				'scrape' => $result,
			]
		);
	}
}
