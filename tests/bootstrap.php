<?php
/**
 * PHPUnit bootstrap for Gitwire unit tests.
 *
 * These run without a WordPress runtime. WP functions the classes under test
 * reach for are stubbed in stubs.php; anything that needs wpdb or the REST
 * infrastructure is out of scope for this suite.
 *
 * @package Gitwire
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// A per-process sandbox so a failed run never leaks into the next one.
define( 'GITWIRE_TESTS_TMP', sys_get_temp_dir() . '/gitwire-tests-' . getmypid() );

define( 'WP_CONTENT_DIR', GITWIRE_TESTS_TMP . '/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );

define( 'GITWIRE_VERSION', '1.0.0' );
define( 'GITWIRE_FILE', dirname( __DIR__ ) . '/gitwire.php' );
define( 'GITWIRE_DIR', dirname( __DIR__ ) . '/' );
define( 'GITWIRE_URL', 'https://example.test/wp-content/plugins/gitwire/' );
define( 'GITWIRE_BASENAME', 'gitwire/gitwire.php' );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MB_IN_BYTES', 1048576 );

define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__ ) . '/autoload.php';
require_once __DIR__ . '/stubs.php';

foreach ( [ WP_PLUGIN_DIR, WP_CONTENT_DIR . '/themes', WP_CONTENT_DIR . '/uploads' ] as $gitwire_test_dir ) {
	if ( ! is_dir( $gitwire_test_dir ) ) {
		mkdir( $gitwire_test_dir, 0777, true );
	}
}
unset( $gitwire_test_dir );

register_shutdown_function(
	static function (): void {
		$rm = static function ( string $dir ) use ( &$rm ): void {
			if ( ! is_dir( $dir ) || is_link( $dir ) ) {
				@unlink( $dir );
				return;
			}
			foreach ( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) as $item ) {
				$path = $dir . '/' . $item;
				is_dir( $path ) && ! is_link( $path ) ? $rm( $path ) : @unlink( $path );
			}
			@rmdir( $dir );
		};
		$rm( GITWIRE_TESTS_TMP );
	}
);
