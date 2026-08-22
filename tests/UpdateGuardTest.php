<?php
/**
 * Tests native upgrader protection for Gitwire-managed installations.
 *
 * @package Gitwire
 */

use Gitwire\Update_Guard;
use PHPUnit\Framework\TestCase;

/**
 * Covers exact plugin and theme identities without modifying WordPress update data.
 *
 * @covers \Gitwire\Update_Guard
 */
class UpdateGuardTest extends TestCase {

	protected function setUp(): void {
		gitwire_test_reset_options();
		$GLOBALS['wpdb'] = new Fake_WPDB();
		gitwire_test_set_installations(
			[
				[
					'provider'     => 'github',
					'full_name'    => 'acme/widget',
					'name'         => 'widget',
					'type'         => 'plugin',
					'basename'     => 'widget/widget.php',
					'install_path' => WP_PLUGIN_DIR . '/widget',
				],
				[
					'provider'     => 'github',
					'full_name'    => 'acme/theme',
					'name'         => 'acme-theme',
					'type'         => 'block-theme',
					'install_path' => WP_CONTENT_DIR . '/themes/acme-theme',
				],
			]
		);
	}

	protected function tearDown(): void {
		gitwire_test_set_installations( [] );
	}

	public function test_init_registers_only_native_upgrader_guards(): void {
		Update_Guard::init();
		$actions = gitwire_test_actions();

		$this->assertSame(
			[
				'upgrader_pre_download',
				'upgrader_pre_install',
			],
			array_keys( $actions )
		);
	}

	public function test_tracked_plugin_is_blocked_before_download(): void {
		$result = Update_Guard::filter_pre_download(
			false,
			'https://example.com/update.zip',
			null,
			[
				'plugin' => 'widget/widget.php',
				'type'   => 'plugin',
			]
		);

		$this->assertBlocked( $result, 'plugin' );
	}

	public function test_tracked_plugin_is_blocked_before_install(): void {
		$result = Update_Guard::filter_pre_install(
			true,
			[
				'plugin' => 'widget/widget.php',
				'type'   => 'plugin',
			]
		);

		$this->assertBlocked( $result, 'plugin' );
	}

	public function test_tracked_theme_is_blocked_before_download(): void {
		$result = Update_Guard::filter_pre_download(
			false,
			'https://example.com/theme.zip',
			null,
			[
				'theme' => 'acme-theme',
				'type'  => 'theme',
			]
		);

		$this->assertBlocked( $result, 'theme' );
	}

	public function test_tracked_theme_is_blocked_before_install(): void {
		$result = Update_Guard::filter_pre_install(
			true,
			[
				'theme' => 'acme-theme',
				'type'  => 'theme',
			]
		);

		$this->assertBlocked( $result, 'theme' );
	}

	public function test_untracked_plugin_is_left_unchanged(): void {
		$value = new stdClass();

		$this->assertSame(
			$value,
			Update_Guard::filter_pre_install(
				$value,
				[
					'plugin' => 'other/other.php',
					'type'   => 'plugin',
				]
			)
		);
	}

	public function test_untracked_theme_is_left_unchanged(): void {
		$this->assertTrue(
			Update_Guard::filter_pre_install(
				true,
				[
					'theme' => 'other-theme',
					'type'  => 'theme',
				]
			)
		);
	}

	public function test_legacy_plugin_without_basename_is_blocked_by_its_directory(): void {
		gitwire_test_set_installations(
			[
				[
					'provider'     => 'github',
					'full_name'    => 'acme/legacy',
					'name'         => 'legacy',
					'type'         => 'plugin',
					'install_path' => WP_PLUGIN_DIR . '/legacy',
				],
			]
		);

		$result = Update_Guard::filter_pre_install(
			true,
			[
				'plugin' => 'legacy/entry.php',
				'type'   => 'plugin',
			]
		);

		$this->assertBlocked( $result, 'plugin' );
	}

	public function test_same_directory_with_a_different_plugin_basename_is_allowed(): void {
		$this->assertTrue(
			Update_Guard::filter_pre_install(
				true,
				[
					'plugin' => 'widget/other.php',
					'type'   => 'plugin',
				]
			)
		);
	}

	public function test_missing_identity_is_left_unchanged(): void {
		$this->assertFalse( Update_Guard::filter_pre_install( false, [ 'type' => 'plugin' ] ) );
	}

	public function test_core_and_translation_updates_are_left_unchanged(): void {
		$this->assertTrue(
			Update_Guard::filter_pre_install(
				true,
				[
					'plugin' => 'widget/widget.php',
					'type'   => 'core',
				]
			)
		);
		$this->assertTrue(
			Update_Guard::filter_pre_install(
				true,
				[
					'theme' => 'acme-theme',
					'type'  => 'translation',
				]
			)
		);
	}

	public function test_existing_errors_are_left_unchanged(): void {
		$error = new WP_Error( 'existing_error', 'Existing error.' );

		$this->assertSame(
			$error,
			Update_Guard::filter_pre_download(
				$error,
				'update.zip',
				null,
				[
					'plugin' => 'widget/widget.php',
					'type'   => 'plugin',
				]
			)
		);
		$this->assertSame(
			$error,
			Update_Guard::filter_pre_install(
				$error,
				[
					'theme' => 'acme-theme',
					'type'  => 'theme',
				]
			)
		);
	}

	/**
	 * @param mixed  $result Guard result.
	 * @param string $type   Expected update type.
	 * @return void
	 */
	private function assertBlocked( $result, string $type ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Update_Guard::ERROR_CODE, $result->get_error_code() );
		$this->assertStringContainsString( $type, strtolower( $result->get_error_message() ) );
		$this->assertStringNotContainsString( WP_PLUGIN_DIR, $result->get_error_message() );
		$this->assertStringNotContainsString( WP_CONTENT_DIR, $result->get_error_message() );
	}
}
