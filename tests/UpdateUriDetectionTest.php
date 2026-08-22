<?php
/**
 * Tests the flag that warns when WordPress.org could update over an install.
 *
 * @package Gitwire
 */

use Gitwire\REST_Installer;
use PHPUnit\Framework\TestCase;

/**
 * A plugin at a directory-hosted slug without an Update URI header may receive a
 * WordPress.org update, while a non-WordPress.org value prevents that update. A
 * missing header can therefore replace the installation, so the record carries a
 * flag for the UI to display a warning.
 *
 * @covers Gitwire\REST_Installer
 */
class UpdateUriDetectionTest extends TestCase {

	protected function setUp(): void {
		gitwire_test_reset_options();
		$GLOBALS['wpdb']                 = new Fake_WPDB();
		$GLOBALS['gitwire_test_plugins'] = [];
		$GLOBALS['gitwire_test_themes']  = [];
	}

	protected function tearDown(): void {
		gitwire_test_set_installations( [] );
		$GLOBALS['gitwire_test_plugins'] = [];
		$GLOBALS['gitwire_test_themes']  = [];
	}

	/**
	 * @param array<string, mixed> $record Installation row.
	 * @return bool
	 */
	private function flagFor( array $record ): bool {
		gitwire_test_set_installations( [ $record ] );
		$annotated = REST_Installer::annotate_installed( \Gitwire\Installer::get_installed() );

		return (bool) reset( $annotated )['update_uri_missing'];
	}

	/**
	 * @param string $uri Update URI header value.
	 * @return array<string, mixed>
	 */
	private function pluginRecord( string $uri ): array {
		$GLOBALS['gitwire_test_plugins'] = [
			'wp-super-cache/wp-cache.php' => [
				'Name'      => 'Cache',
				'UpdateURI' => $uri,
			],
		];

		return [
			'id'        => 1,
			'provider'  => 'github',
			'full_name' => 'acme/cache',
			'name'      => 'wp-super-cache',
			'type'      => 'plugin',
			'basename'  => 'wp-super-cache/wp-cache.php',
			'branch'    => 'main',
		];
	}

	public function test_plugin_without_the_header_is_flagged(): void {
		$this->assertTrue( $this->flagFor( $this->pluginRecord( '' ) ) );
	}

	public function test_plugin_with_the_header_is_not_flagged(): void {
		$this->assertFalse( $this->flagFor( $this->pluginRecord( 'https://github.com/acme/cache' ) ) );
	}

	/**
	 * The literal `false` claims a slug without naming a host, like any other
	 * non-WordPress.org value.
	 *
	 * @return void
	 */
	public function test_the_literal_false_counts_as_claiming_the_slug(): void {
		$this->assertFalse( $this->flagFor( $this->pluginRecord( 'false' ) ) );
	}

	public function test_whitespace_only_header_is_treated_as_absent(): void {
		$this->assertTrue( $this->flagFor( $this->pluginRecord( '   ' ) ) );
	}

	public function test_theme_without_the_header_is_flagged(): void {
		$GLOBALS['gitwire_test_themes'] = [ 'acme-theme' => [ 'UpdateURI' => '' ] ];

		$this->assertTrue(
			$this->flagFor(
				[
					'id'        => 2,
					'provider'  => 'github',
					'full_name' => 'acme/theme',
					'name'      => 'acme-theme',
					'type'      => 'block-theme',
					'branch'    => 'main',
				]
			)
		);
	}

	public function test_theme_with_the_header_is_not_flagged(): void {
		$GLOBALS['gitwire_test_themes'] = [ 'acme-theme' => [ 'UpdateURI' => 'https://example.com' ] ];

		$this->assertFalse(
			$this->flagFor(
				[
					'id'        => 3,
					'provider'  => 'github',
					'full_name' => 'acme/theme',
					'name'      => 'acme-theme',
					'type'      => 'block-theme',
					'branch'    => 'main',
				]
			)
		);
	}

	/**
	 * A record whose files are not on disk has nothing to read a header from, so
	 * it must not be reported as at risk.
	 *
	 * @return void
	 */
	public function test_plugin_missing_from_disk_is_not_flagged(): void {
		$this->assertFalse(
			$this->flagFor(
				[
					'id'        => 4,
					'provider'  => 'github',
					'full_name' => 'acme/gone',
					'name'      => 'gone',
					'type'      => 'plugin',
					'basename'  => 'gone/gone.php',
					'branch'    => 'main',
				]
			)
		);
	}
}
