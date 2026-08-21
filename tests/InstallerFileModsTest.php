<?php
/**
 * Tests for the DISALLOW_FILE_MODS guard on write paths.
 *
 * @package Gitwire
 */

use Gitwire\Installer;
use PHPUnit\Framework\TestCase;

/**
 * Covers the site-level switch that has to hold even where no user is present.
 *
 * The REST capability gate handles requests, but cron auto-updates run with no
 * current user, so these paths carry the check themselves.
 *
 * @covers Gitwire\Installer
 */
class InstallerFileModsTest extends TestCase {

	protected function setUp(): void {
		gitwire_test_reset_options();
	}

	/**
	 * Turns file modifications off for the current test.
	 *
	 * DISALLOW_FILE_MODS cannot be defined mid-suite, so this uses the same
	 * filter core exposes on top of the constant.
	 *
	 * @return void
	 */
	private function disallow_file_mods(): void {
		add_filter( 'file_mod_allowed', static fn() => false );
	}

	public function test_file_mods_allowed_by_default(): void {
		$this->assertTrue( Installer::file_mods_allowed() );
	}

	public function test_filter_can_switch_file_mods_off(): void {
		$this->disallow_file_mods();

		$this->assertFalse( Installer::file_mods_allowed() );
	}

	public function test_install_plugin_refuses_when_file_mods_are_off(): void {
		$this->disallow_file_mods();

		$result = Installer::install_plugin( 'acme', 'widget', 'main' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'gitwire_file_mods_disabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_install_theme_refuses_when_file_mods_are_off(): void {
		$this->disallow_file_mods();

		$result = Installer::install_theme( 'acme', 'skin', 'main' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'gitwire_file_mods_disabled', $result->get_error_code() );
	}

	/**
	 * The refusal has to land before the lock is taken, or a blocked attempt
	 * leaves a lock row behind that stalls the repo for ten minutes.
	 *
	 * @return void
	 */
	public function test_refusal_does_not_leave_an_install_lock(): void {
		$this->disallow_file_mods();

		Installer::install_plugin( 'acme', 'widget', 'main' );

		$key = 'gitwire_lock_' . md5( 'github:acme/widget' );
		$this->assertArrayNotHasKey( $key, $GLOBALS['gitwire_test_options'] );
	}
}
