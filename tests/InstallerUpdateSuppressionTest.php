<?php
/**
 * Tests that core update offers never reach a Gitwire-managed install.
 *
 * @package Gitwire
 */

use Gitwire\Installer;
use PHPUnit\Framework\TestCase;

/**
 * Covers the slug-collision path: a repository installed into a directory that
 * happens to match a WordPress.org project would otherwise be "updated" with
 * that project's release, replacing the tracked code.
 *
 * @covers Gitwire\Installer
 */
class InstallerUpdateSuppressionTest extends TestCase {

	protected function setUp(): void {
		gitwire_test_reset_options();
		$GLOBALS['wpdb'] = new Fake_WPDB();
		gitwire_test_set_installations(
			[
				[
					'provider'     => 'github',
					'full_name'    => 'acme/cache',
					'owner'        => 'acme',
					'name'         => 'wp-super-cache',
					'type'         => 'plugin',
					'basename'     => 'wp-super-cache/wp-cache.php',
					'install_path' => WP_PLUGIN_DIR . '/wp-super-cache',
				],
				[
					'provider'     => 'github',
					'full_name'    => 'acme/skin',
					'owner'        => 'acme',
					'name'         => 'twentytwentyfour',
					'type'         => 'block-theme',
					'install_path' => WP_CONTENT_DIR . '/themes/twentytwentyfour',
				],
			]
		);
	}

	protected function tearDown(): void {
		gitwire_test_set_installations( [] );
	}

	private function plugin_transient(): object {
		return (object) [
			'response'  => [
				'wp-super-cache/wp-cache.php' => (object) [ 'new_version' => '9.9' ],
				'akismet/akismet.php'         => (object) [ 'new_version' => '5.0' ],
			],
			'no_update' => [
				'wp-super-cache/wp-cache.php' => (object) [ 'new_version' => '1.0' ],
				'hello.php'                   => (object) [ 'new_version' => '1.0' ],
			],
		];
	}

	public function test_managed_plugin_is_dropped_from_the_update_offer(): void {
		$out = Installer::suppress_plugin_updates( $this->plugin_transient() );

		$this->assertArrayNotHasKey( 'wp-super-cache/wp-cache.php', $out->response );
		$this->assertArrayNotHasKey( 'wp-super-cache/wp-cache.php', $out->no_update );
	}

	public function test_unmanaged_plugins_are_left_alone(): void {
		$out = Installer::suppress_plugin_updates( $this->plugin_transient() );

		$this->assertArrayHasKey( 'akismet/akismet.php', $out->response );
		$this->assertArrayHasKey( 'hello.php', $out->no_update );
	}

	/**
	 * A shared object cache can hand the same instance to the next reader, so the
	 * filter must not strip entries out of the caller's copy.
	 *
	 * @return void
	 */
	public function test_the_original_transient_is_not_mutated(): void {
		$original = $this->plugin_transient();

		Installer::suppress_plugin_updates( $original );

		$this->assertArrayHasKey( 'wp-super-cache/wp-cache.php', $original->response );
	}

	public function test_managed_theme_is_dropped_from_the_update_offer(): void {
		$transient = (object) [
			'response'  => [
				'twentytwentyfour' => [ 'new_version' => '2.0' ],
				'twentytwentyfive' => [ 'new_version' => '1.1' ],
			],
			'no_update' => [ 'twentytwentyfour' => [ 'new_version' => '1.0' ] ],
		];

		$out = Installer::suppress_theme_updates( $transient );

		$this->assertArrayNotHasKey( 'twentytwentyfour', $out->response );
		$this->assertArrayNotHasKey( 'twentytwentyfour', $out->no_update );
		$this->assertArrayHasKey( 'twentytwentyfive', $out->response );
	}

	public function test_a_theme_slug_does_not_suppress_a_plugin_of_the_same_name(): void {
		$transient = (object) [
			'response' => [ 'twentytwentyfour/plugin.php' => (object) [ 'new_version' => '2.0' ] ],
		];

		$out = Installer::suppress_plugin_updates( $transient );

		$this->assertArrayHasKey( 'twentytwentyfour/plugin.php', $out->response );
	}

	public function test_transient_passes_through_when_nothing_is_managed(): void {
		gitwire_test_set_installations( [] );
		$transient = $this->plugin_transient();

		$this->assertSame( $transient, Installer::suppress_plugin_updates( $transient ) );
	}

	public function test_non_object_transient_is_returned_untouched(): void {
		$this->assertFalse( Installer::suppress_plugin_updates( false ) );
	}

	public function test_auto_updater_is_refused_for_a_managed_plugin(): void {
		$item = (object) [ 'plugin' => 'wp-super-cache/wp-cache.php' ];

		$this->assertFalse( Installer::block_plugin_auto_update( true, $item ) );
	}

	public function test_auto_updater_is_untouched_for_an_unmanaged_plugin(): void {
		$item = (object) [ 'plugin' => 'akismet/akismet.php' ];

		$this->assertTrue( Installer::block_plugin_auto_update( true, $item ) );
	}

	public function test_auto_updater_is_refused_for_a_managed_theme(): void {
		$item = (object) [ 'theme' => 'twentytwentyfour' ];

		$this->assertFalse( Installer::block_theme_auto_update( true, $item ) );
	}

	public function test_auto_update_filter_defers_when_the_item_says_nothing(): void {
		$this->assertTrue( Installer::block_plugin_auto_update( true, (object) [] ) );
	}
}
