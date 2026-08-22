<?php
/**
 * WordPress stubs for the unit suite.
 *
 * Only what the classes under test actually reach for. Anything that would pull
 * in a real WordPress runtime (the REST layer, wpdb-backed models) is out of
 * scope here and belongs in an integration suite instead.
 *
 * Excluded from PHPStan: these intentionally redeclare functions the WordPress
 * stub package already defines.
 *
 * @package Gitwire
 */

/**
 * In-memory option store backing the get_option/update_option stubs.
 *
 * @var array<string, mixed>
 */
$GLOBALS['gitwire_test_options'] = [];

require_once __DIR__ . '/class-wp-error.php';
require_once __DIR__ . '/class-fake-wpdb.php';
require_once __DIR__ . '/dns-stubs.php';

/**
 * Resets all stub state between tests.
 *
 * @return void
 */
function gitwire_test_reset_options(): void {
	$GLOBALS['gitwire_test_options']    = [];
	$GLOBALS['gitwire_test_actions']    = [];
	$GLOBALS['gitwire_test_is_admin']   = false;
	$GLOBALS['gitwire_test_doing_cron'] = false;
	$GLOBALS['gitwire_dns_calls']       = [];
	\Gitwire\Settings::invalidate_cache();
}

/**
 * Seeds the option store.
 *
 * @param string $name  Option name.
 * @param mixed  $value Option value.
 * @return void
 */
function gitwire_test_set_option( string $name, $value ): void {
	$GLOBALS['gitwire_test_options'][ $name ] = $value;
	\Gitwire\Settings::invalidate_cache();
}

/**
 * Seeds the rows Installer::get_installed() will read.
 *
 * Expects the caller to have put a Fake_WPDB in place already, the same way
 * every other model-backed test sets one up.
 *
 * @param array<int, array<string, mixed>> $rows Installation rows.
 * @return void
 */
function gitwire_test_set_installations( array $rows ): void {
	if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof Fake_WPDB ) {
		$GLOBALS['wpdb']->results = $rows;
	}
	\Gitwire\Installer::invalidate_installed_cache();
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option  Option name.
	 * @param mixed  $default_value Fallback when unset.
	 * @return mixed
	 */
	function get_option( string $option, $default_value = false ) {
		return $GLOBALS['gitwire_test_options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @param mixed  $autoload Ignored.
	 * @return bool
	 */
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['gitwire_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( string $option ): bool {
		unset( $GLOBALS['gitwire_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * @param string $path Path to trail.
	 * @return string
	 */
	function trailingslashit( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * @param string $path Path to untrail.
	 * @return string
	 */
	function untrailingslashit( string $path ): string {
		return rtrim( $path, '/\\' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str String to sanitize.
	 * @return string
	 */
	function sanitize_text_field( string $str ): string {
		return trim( wp_strip_all_tags( $str ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * @param string $title Title to sanitize into a slug.
	 * @return string
	 */
	function sanitize_title( string $title ): string {
		$title = strtolower( trim( $title ) );
		$title = (string) preg_replace( '/[^a-z0-9_\-]+/', '-', $title );
		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $text Text to strip.
	 * @return string
	 */
	function wp_strip_all_tags( string $text ): string {
		return (string) preg_replace( '/<[^>]*>/', '', $text );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string $url URL to sanitize.
	 * @return string
	 */
	function esc_url_raw( string $url ): string {
		return trim( $url );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url       URL to parse.
	 * @param int    $component Component to return, or -1 for all.
	 * @return array<string, string>|string|int|null|false
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * @param string $path Path to normalize.
	 * @return string
	 */
	function wp_normalize_path( string $path ): string {
		return str_replace( '\\', '/', $path );
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * @param string $target Directory to create.
	 * @return bool
	 */
	function wp_mkdir_p( string $target ): bool {
		if ( file_exists( $target ) ) {
			return is_dir( $target );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged
		return @mkdir( $target, 0777, true ) || is_dir( $target );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	/**
	 * @param string $file File to remove.
	 * @return void
	 */
	function wp_delete_file( string $file ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $file );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * @param mixed $time Ignored.
	 * @param mixed $create_dir Ignored.
	 * @return array<string, string>
	 */
	function wp_upload_dir( $time = null, $create_dir = true ): array {
		return [ 'basedir' => WP_CONTENT_DIR . '/uploads' ];
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * @return bool
	 */
	function is_admin(): bool {
		return (bool) ( $GLOBALS['gitwire_test_is_admin'] ?? false );
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	/**
	 * @return bool
	 */
	function wp_doing_cron(): bool {
		return (bool) ( $GLOBALS['gitwire_test_doing_cron'] ?? false );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param string $hook     Hook name.
	 * @param mixed  $callback Callback.
	 * @param int    $priority Priority.
	 * @param int    $args     Accepted args.
	 * @return bool
	 */
	function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
		$GLOBALS['gitwire_test_actions'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param string $hook     Hook name.
	 * @param mixed  $callback Callback.
	 * @param int    $priority Priority.
	 * @param int    $args     Accepted args.
	 * @return bool
	 */
	function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
		$GLOBALS['gitwire_test_actions'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'register_shutdown_function_stub' ) ) {
	/**
	 * @return array<string, array<int, mixed>>
	 */
	function gitwire_test_actions(): array {
		return $GLOBALS['gitwire_test_actions'] ?? [];
	}
}

if ( ! function_exists( 'current_datetime' ) ) {
	/**
	 * @return DateTimeImmutable
	 */
	function current_datetime(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * @param string $scheme Salt scheme.
	 * @return string
	 */
	function wp_salt( string $scheme = 'auth' ): string {
		return 'gitwire-test-salt-' . $scheme;
	}
}

if ( ! function_exists( 'get_theme_root' ) ) {
	/**
	 * @return string
	 */
	function get_theme_root(): string {
		return WP_CONTENT_DIR . '/themes';
	}
}

if ( ! function_exists( 'get_plugin_data' ) ) {
	/**
	 * Reads only the Plugin Name header, which is all find_plugin_file() needs.
	 *
	 * @param string $file          Plugin file.
	 * @param bool   $markup        Ignored.
	 * @param bool   $translate     Ignored.
	 * @return array<string, string>
	 */
	function get_plugin_data( string $file, bool $markup = true, bool $translate = true ): array {
		if ( ! is_readable( $file ) ) {
			return [ 'Name' => '' ];
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = (string) file_get_contents( $file, false, null, 0, 8192 );
		$name     = preg_match( '/^[ \t\/*#@]*Plugin Name:(.*)$/mi', $contents, $m ) ? trim( $m[1] ) : '';
		return [ 'Name' => $name ];
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	/**
	 * @return object
	 */
	function wp_get_current_user() {
		return new class() {
			/**
			 * Current user login.
			 *
			 * @var string
			 */
			public $user_login = 'testadmin';

			/**
			 * Whether the user exists.
			 *
			 * @return bool
			 */
			public function exists(): bool {
				return true;
			}
		};
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Ignored.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Runs any callbacks registered via add_filter() for this hook, in registration order.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Extra args.
	 * @return mixed
	 */
	function apply_filters( string $hook_name, $value, ...$args ) {
		foreach ( $GLOBALS['gitwire_test_actions'][ $hook_name ] ?? [] as $callback ) {
			$value = call_user_func( $callback, $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	/**
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	function is_plugin_active( string $plugin ): bool {
		return in_array( $plugin, (array) ( $GLOBALS['gitwire_test_active_plugins'] ?? [] ), true );
	}
}

if ( ! function_exists( 'get_stylesheet' ) ) {
	/**
	 * @return string
	 */
	function get_stylesheet(): string {
		return (string) ( $GLOBALS['gitwire_test_stylesheet'] ?? 'twentytwentyfour' );
	}
}

if ( ! function_exists( 'get_template' ) ) {
	/**
	 * @return string
	 */
	function get_template(): string {
		return (string) ( $GLOBALS['gitwire_test_template'] ?? get_stylesheet() );
	}
}

if ( ! function_exists( 'wp_is_file_mod_allowed' ) ) {
	/**
	 * Mirrors core: the constant, then the filter that can override it.
	 *
	 * @param string $context Usage context.
	 * @return bool
	 */
	function wp_is_file_mod_allowed( string $context ): bool {
		return (bool) apply_filters(
			'file_mod_allowed',
			! defined( 'DISALLOW_FILE_MODS' ) || ! DISALLOW_FILE_MODS,
			$context
		);
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value to test.
	 * @return bool
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
