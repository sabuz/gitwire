<?php
/**
 * Tests for fatal-error classification and directory hardening.
 *
 * @package Gitwire
 */

use Gitwire\Filesystem_Guard;
use Gitwire\Installer;
use Gitwire\Theme_Scraper;
use PHPUnit\Framework\TestCase;

/**
 * Covers how a scrape result is classified.
 *
 * Getting this wrong either reverts a working update or leaves a fatal live, so
 * both directions matter.
 *
 * @covers Gitwire\Theme_Scraper
 * @covers Gitwire\Filesystem_Guard
 * @covers Gitwire\Installer
 */
class FatalDetectionTest extends TestCase {

	protected function setUp(): void {
		gitwire_test_reset_options();
	}

	/**
	 * @dataProvider fatal_error_types
	 * @param int $type PHP error constant that counts as fatal.
	 */
	public function test_php_fatal_types_are_recognised( int $type ): void {
		$this->assertTrue(
			Theme_Scraper::is_php_fatal_result(
				[
					'type'    => $type,
					'message' => 'Call to undefined function',
				]
			)
		);
	}

	/**
	 * @return array<string, array<int, int>>
	 */
	public static function fatal_error_types(): array {
		return [
			'E_ERROR'             => [ E_ERROR ],
			'E_PARSE'             => [ E_PARSE ],
			'E_CORE_ERROR'        => [ E_CORE_ERROR ],
			'E_COMPILE_ERROR'     => [ E_COMPILE_ERROR ],
			'E_USER_ERROR'        => [ E_USER_ERROR ],
			'E_RECOVERABLE_ERROR' => [ E_RECOVERABLE_ERROR ],
		];
	}

	public function test_a_warning_is_not_treated_as_fatal(): void {
		$this->assertFalse(
			Theme_Scraper::is_php_fatal_result(
				[
					'type'    => E_WARNING,
					'message' => 'Undefined variable',
				]
			)
		);
	}

	public function test_a_notice_is_not_treated_as_fatal(): void {
		$this->assertFalse(
			Theme_Scraper::is_php_fatal_result(
				[
					'type'    => E_NOTICE,
					'message' => 'Undefined index',
				]
			)
		);
	}

	public function test_a_payload_without_type_and_message_is_not_fatal(): void {
		$this->assertFalse( Theme_Scraper::is_php_fatal_result( [] ) );
		$this->assertFalse( Theme_Scraper::is_php_fatal_result( [ 'type' => E_ERROR ] ) );
		$this->assertFalse( Theme_Scraper::is_php_fatal_result( [ 'message' => 'boom' ] ) );
	}

	public function test_loopback_failures_are_classified_as_infrastructure(): void {
		$this->assertTrue( Theme_Scraper::is_infrastructure_failure( [ 'code' => 'loopback_request_failed' ] ) );
		$this->assertTrue( Theme_Scraper::is_infrastructure_failure( [ 'code' => 'json_parse_error' ] ) );
		$this->assertTrue( Theme_Scraper::is_infrastructure_failure( [ 'code' => 'scrape_nonce_failure' ] ) );
	}

	public function test_a_real_fatal_is_not_an_infrastructure_failure(): void {
		$this->assertFalse(
			Theme_Scraper::is_infrastructure_failure(
				[
					'type'    => E_ERROR,
					'message' => 'boom',
				]
			)
		);
	}

	public function test_known_fatal_head_message_names_the_short_sha_and_subject(): void {
		$message = Installer::known_fatal_head_message( 'plugin', 'abc1234def5678' );

		$this->assertStringContainsString( 'abc1234', $message );
		$this->assertStringNotContainsString( 'abc1234def5678', $message, 'the full sha is too noisy for a notice' );
		$this->assertStringContainsString( 'plugin', $message );
	}

	public function test_known_fatal_head_message_uses_theme_wording_for_themes(): void {
		$this->assertStringContainsString( 'theme', Installer::known_fatal_head_message( 'block-theme', 'abc1234def' ) );
		$this->assertStringContainsString( 'theme', Installer::known_fatal_head_message( 'classic-theme', 'abc1234def' ) );
	}

	public function test_protect_directory_writes_deny_rules_for_apache_and_iis(): void {
		$dir = GITWIRE_TESTS_TMP . '/guard-' . uniqid();
		mkdir( $dir, 0777, true );

		Filesystem_Guard::protect_directory( $dir );

		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $dir . '/.htaccess' ) );
		$this->assertStringContainsString( 'Deny from all', (string) file_get_contents( $dir . '/.htaccess' ) );
		$this->assertStringContainsString( 'deny users', (string) file_get_contents( $dir . '/web.config' ) );
		$this->assertStringContainsString( '<?php', (string) file_get_contents( $dir . '/index.php' ) );

		Installer::rmdir_recursive( $dir );
	}

	public function test_protect_directory_does_not_overwrite_existing_files(): void {
		$dir = GITWIRE_TESTS_TMP . '/guard-' . uniqid();
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/.htaccess', 'custom host rules' );

		Filesystem_Guard::protect_directory( $dir );

		$this->assertSame( 'custom host rules', file_get_contents( $dir . '/.htaccess' ) );

		Installer::rmdir_recursive( $dir );
	}

	public function test_protect_directory_on_a_missing_dir_is_a_no_op(): void {
		$dir = GITWIRE_TESTS_TMP . '/never-created';

		Filesystem_Guard::protect_directory( $dir );

		$this->assertDirectoryDoesNotExist( $dir );
	}
}
