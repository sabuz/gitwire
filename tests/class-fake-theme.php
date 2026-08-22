<?php
/**
 * Minimal WP_Theme double.
 *
 * The class is safe to analyse; only the wp_get_theme() that returns it is not,
 * which is why that lives in theme-stubs.php instead.
 *
 * @package Gitwire
 */

/**
 * Provides only the theme behavior required by the tests.
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
