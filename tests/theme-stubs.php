<?php
/**
 * Minimal WP_Theme double, and the wp_get_theme() that returns it.
 *
 * Excluded from PHPStan on purpose. Analysed, this wp_get_theme() shadows the
 * real one project-wide and narrows its return type to this double, so every
 * WP_Theme method the installer calls reads as undefined. The dns-stubs.php
 * header explains the same trap.
 *
 * @package Gitwire
 */

/**
 * Answers only what the code under test asks a theme for.
 */
class Fake_Theme {

	/**
	 * Theme slug.
	 *
	 * @var string
	 */
	private string $stylesheet;

	/**
	 * @param string $stylesheet Theme slug.
	 */
	public function __construct( string $stylesheet ) {
		$this->stylesheet = $stylesheet;
	}

	/**
	 * @return bool
	 */
	public function exists(): bool {
		return isset( $GLOBALS['gitwire_test_themes'][ $this->stylesheet ] );
	}

	/**
	 * @param string $header Header name.
	 * @return string
	 */
	public function get( string $header ): string {
		return (string) ( $GLOBALS['gitwire_test_themes'][ $this->stylesheet ][ $header ] ?? '' );
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	/**
	 * @param string $stylesheet Theme slug.
	 * @return Fake_Theme
	 */
	function wp_get_theme( string $stylesheet = '' ): Fake_Theme {
		return new Fake_Theme( $stylesheet );
	}
}
