<?php
/**
 * wp_get_theme() stub returning the Fake_Theme double.
 *
 * PHPStan excludes this file. Analysed, this declaration shadows the real
 * wp_get_theme() project-wide and narrows WP_Theme to the double, which makes
 * every theme method the installer calls read as undefined. The class itself is
 * safe to analyse and lives in class-fake-theme.php.
 *
 * @package Gitwire
 */

if ( ! function_exists( 'wp_get_theme' ) ) {
	/**
	 * @param string $stylesheet Theme slug.
	 * @return Fake_Theme
	 */
	function wp_get_theme( string $stylesheet = '' ): Fake_Theme {
		return new Fake_Theme( $stylesheet );
	}
}
