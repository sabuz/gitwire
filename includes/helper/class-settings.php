<?php
/**
 * Settings helpers: smart_install and token masking.
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
	 * Request-scope cache for get_raw().
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Returns raw settings from the database.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function get_raw(): array {
		self::$cache ??= (array) get_option( 'gitwire_settings', [] );
		return self::$cache;
	}

	/**
	 * Clears the request-scope cache after a settings save.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function invalidate_cache(): void {
		self::$cache = null;
	}

	/**
	 * Every setting, its default, and how an incoming value is validated.
	 *
	 * Single source of truth: get_public(), merge_save(), and the activation
	 * defaults all read this. Keeping three hand-maintained copies is how the
	 * accepted values and the usable values drifted apart (#48, #49).
	 *
	 * @since 1.0.0
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		return [
			'smart_install'                     => [
				'type'    => 'bool',
				'default' => true,
			],
			'auto_detect_type'                  => [
				'type'    => 'bool',
				'default' => true,
			],
			// Page size is the detection bill: an unauthenticated GitHub gets 60 an hour.
			'repos_per_page'                    => [
				'type'    => 'int',
				'default' => 20,
				'min'     => 10,
				'max'     => 100,
			],
			'excluded_repos'                    => [
				'type'    => 'repo_list',
				'default' => [],
			],
			'repository_refresh_frequency'      => [
				'type'    => 'enum',
				'default' => 'daily',
				'values'  => [ 'hourly', 'twicedaily', 'daily', 'weekly' ],
			],
			'repository_type_refresh_frequency' => [
				'type'    => 'enum',
				'default' => 'weekly',
				'values'  => [ 'twicedaily', 'daily', 'weekly', 'never' ],
			],
			'background_type_detection'         => [
				'type'    => 'bool',
				'default' => false,
			],
			'shallow_detection'                 => [
				'type'    => 'bool',
				'default' => false,
			],
			'show_repo_label'                   => [
				'type'    => 'bool',
				'default' => true,
			],
			'block_commit_on_fatal'             => [
				'type'    => 'bool',
				'default' => true,
			],
			'update_check_interval'             => [
				'type'    => 'enum',
				'default' => 'halfhourly',
				'values'  => [ 'everyfiveminutes', 'halfhourly', 'hourly', 'twicedaily', 'daily', 'weekly', 'never' ],
			],
			'enable_logging'                    => [
				'type'    => 'bool',
				'default' => true,
			],
			'log_retention_days'                => [
				'type'    => 'enum',
				'default' => 7,
				'values'  => [ 7, 15, 30 ],
			],
			'log_level'                         => [
				'type'    => 'enum',
				'default' => 'activity',
				'values'  => [ 'activity', 'error' ],
			],
			'remove_data_on_uninstall'          => [
				'type'    => 'bool',
				'default' => false,
			],
		];
	}

	/**
	 * Returns the default value for every setting.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array_map( static fn( $rule ) => $rule['default'], self::schema() );
	}

	/**
	 * Returns a client-safe settings payload.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function get_public(): array {
		$stored = self::get_raw();
		$out    = [];

		foreach ( self::schema() as $key => $rule ) {
			$out[ $key ] = array_key_exists( $key, $stored )
				? self::coerce( $stored[ $key ], $rule )
				: $rule['default'];
		}

		/*
		 * Smart Install cannot decide anything without a detected type, so it implies
		 * detection. Enforced on read rather than on save so the browse UI, the cron
		 * gate, and the settings toggle can never disagree about a stored pair that
		 * says otherwise.
		 */
		if ( ! empty( $out['smart_install'] ) ) {
			$out['auto_detect_type'] = true;
		}

		return $out;
	}

	/**
	 * Merges incoming save params with stored settings.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $incoming Request body fields.
	 * @return array<string, mixed> Full settings array to persist.
	 */
	public static function merge_save( array $incoming ): array {
		$current = self::get_public();
		$out     = [];

		foreach ( self::schema() as $key => $rule ) {
			$has_value   = array_key_exists( $key, $incoming ) && null !== $incoming[ $key ];
			$out[ $key ] = $has_value
				? self::coerce( $incoming[ $key ], $rule )
				: $current[ $key ];
		}

		return $out;
	}

	/**
	 * Casts and validates one value against its schema rule.
	 *
	 * An unrecognised value falls back to the default rather than to whichever
	 * branch happened to be last, so "invalid" never means "most permissive".
	 *
	 * @since 1.0.0
	 * @param mixed                $value Raw value.
	 * @param array<string, mixed> $rule  Schema rule.
	 * @return mixed
	 */
	private static function coerce( $value, array $rule ) {
		switch ( $rule['type'] ) {
			case 'bool':
				return (bool) $value;

			case 'int':
				return max( (int) $rule['min'], min( (int) $rule['max'], (int) $value ) );

			case 'enum':
				// Numeric options arrive as strings from JSON; compare loosely then
				// return the canonical value from the allow-list.
				foreach ( $rule['values'] as $allowed ) {
					if ( is_int( $allowed ) && (string) $allowed === (string) $value ) {
						return $allowed;
					}
					if ( is_string( $allowed ) && $allowed === $value ) {
						return $allowed;
					}
				}
				return $rule['default'];

			case 'repo_list':
				if ( ! is_array( $value ) ) {
					return $rule['default'];
				}
				return array_values(
					array_filter(
						array_map( 'sanitize_text_field', $value ),
						static fn( $v ) => (bool) preg_match( '/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/', $v )
					)
				);
		}

		return $rule['default'];
	}

	/**
	 * Returns whether activity logging is currently enabled.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_logging_enabled(): bool {
		return (bool) self::get_public()['enable_logging'];
	}

	/**
	 * Returns the number of days to retain log entries.
	 *
	 * Always one of the schema values, so the trim window can never come back as 0
	 * and delete everything older than today.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public static function get_log_retention_days(): int {
		return (int) self::get_public()['log_retention_days'];
	}

	/**
	 * Returns the minimum log level to record ('activity' or 'error').
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_log_level(): string {
		return (string) self::get_public()['log_level'];
	}

	/**
	 * Returns the configured repositories cache refresh frequency.
	 *
	 * @since 1.0.0
	 * @return string WP cron recurrence: 'hourly', 'daily', or 'weekly'.
	 */
	public static function get_repository_refresh_frequency(): string {
		return (string) self::get_public()['repository_refresh_frequency'];
	}

	/**
	 * Returns whether repository type detection is wanted at all.
	 *
	 * Smart Install is already folded into auto_detect_type by get_public(), so this is
	 * the one flag every caller should ask, rather than re-deriving the pair.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_type_detection_enabled(): bool {
		return ! empty( self::get_public()['auto_detect_type'] );
	}

	/**
	 * Returns the configured repository type re-detection frequency.
	 *
	 * @since 1.0.0
	 * @return string WP cron recurrence, or 'never' when re-detection is off.
	 */
	public static function get_repository_type_refresh_frequency(): string {
		return (string) self::get_public()['repository_type_refresh_frequency'];
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
	 * Validates at parse time. Call this again at HTTP-request time inside the
	 * API client to narrow the DNS-rebinding race window.
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

		return self::is_safe_remote_url( $url );
	}

	/**
	 * Returns true only for an https URL whose host resolves to a publicly routable address.
	 *
	 * Also applied to redirect targets, not just configured base URLs: a self-hosted GitLab
	 * answers archive requests with a 30x whose Location it chooses freely, so an unvalidated
	 * follow is a blind GET against whatever internal host that Location names.
	 *
	 * @since 1.0.0
	 * @param string $url Absolute URL to check.
	 * @return bool
	 */
	public static function is_safe_remote_url( string $url ): bool {
		$parts = wp_parse_url( trim( $url ) );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}

		$host = self::normalize_host( $parts['host'] ?? '' );
		if ( '' === $host ) {
			return false;
		}

		if ( 'localhost' === $host ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_safe_ip( $host );
		}

		/*
		 * Resolve IPv4 and check the returned address.
		 * Both functions live in ext/standard but can be blocked via disable_functions.
		 */
		if ( function_exists( 'gethostbyname' ) ) {
			$ipv4 = gethostbyname( $host );
			if ( $ipv4 !== $host && ! self::is_safe_ip( $ipv4 ) ) {
				return false;
			}
		}

		// Resolve IPv6 (gethostbyname only covers A records).
		if ( function_exists( 'dns_get_record' ) ) {
			$aaaa = dns_get_record( $host, DNS_AAAA );
			if ( is_array( $aaaa ) ) {
				foreach ( $aaaa as $record ) {
					if ( ! empty( $record['ipv6'] ) && ! self::is_safe_ip( $record['ipv6'] ) ) {
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Lowercases a URL host and unwraps an IPv6 literal.
	 *
	 * RFC 3986 brackets survive wp_parse_url() on an IPv6 host, and filter_var()
	 * rejects that form, so leaving them on lets https://[::1] slip past every check
	 * below as if it were an unresolvable hostname.
	 *
	 * @since 1.0.0
	 * @param string $host Raw host from wp_parse_url().
	 * @return string
	 */
	public static function normalize_host( string $host ): string {
		$host = strtolower( trim( $host ) );

		if ( str_starts_with( $host, '[' ) && str_ends_with( $host, ']' ) ) {
			return substr( $host, 1, -1 );
		}

		return $host;
	}

	/**
	 * Returns true only for publicly routable IP addresses.
	 *
	 * @since 1.0.0
	 * @param string $ip IPv4 or IPv6 address.
	 * @return bool
	 */
	public static function is_safe_ip( string $ip ): bool {
		$ip = strtolower( trim( $ip ) );

		// IPv6 loopback and unspecified.
		if ( in_array( $ip, [ '::1', '::' ], true ) ) {
			return false;
		}

		/*
		 * Unwrap IPv4-mapped and IPv4-compatible IPv6 before anything else. PHP's
		 * range flags only learned to see through the wrapper in 8.5, so on 8.0, the
		 * supported floor, ::ffff:127.0.0.1 validated as a public address. Unwrapping
		 * the packed bytes rather than the text form covers every spelling of the same
		 * address: ::ffff:7f00:1 and 0:0:0:0:0:ffff:127.0.0.1 are both 127.0.0.1.
		 */
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed address is a plain false return here, not a condition worth warning about.
		$packed = @inet_pton( $ip );

		if ( false !== $packed && 16 === strlen( $packed ) ) {
			$prefix = substr( $packed, 0, 12 );

			if ( str_repeat( "\0", 12 ) === $prefix || str_repeat( "\0", 10 ) . "\xff\xff" === $prefix ) {
				return self::is_safe_ip( inet_ntop( substr( $packed, 12 ) ) );
			}
		}

		// 127.0.0.0/8 loopback range, not covered by FILTER_FLAG_NO_RES_RANGE.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && str_starts_with( $ip, '127.' ) ) {
			return false;
		}

		// Cloud metadata service IPs (link-local IPv4 and AWS IPv6).
		if ( in_array( $ip, [ '169.254.169.254', 'fd00:ec2::254' ], true ) ) {
			return false;
		}

		return (bool) filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
