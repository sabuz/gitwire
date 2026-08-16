<?php
/**
 * Tests for the activity logger.
 *
 * @package Gitwire
 */

use Gitwire\Filesystem_Guard;
use Gitwire\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Covers writing, parsing, filtering, retention, and directory protection.
 *
 * @covers Gitwire\Logger
 */
class LoggerTest extends TestCase {

	protected function setUp(): void {
		gitwire_test_reset_options();
		gitwire_test_set_option(
			'gitwire_settings',
			[
				'enable_logging'     => true,
				'log_level'          => 'activity',
				'log_retention_days' => 7,
			]
		);
		Logger::get_instance()->clear();
	}

	protected function tearDown(): void {
		/*
		 * The uninstall test deletes the directory the singleton already resolved,
		 * so put it back rather than making test order load-bearing.
		 */
		if ( ! is_dir( $this->log_dir() ) ) {
			mkdir( $this->log_dir(), 0777, true );
			Filesystem_Guard::protect_directory( $this->log_dir() );
		}
		Logger::get_instance()->clear();
	}

	/**
	 * Returns the directory the logger writes to.
	 *
	 * @return string
	 */
	private function log_dir(): string {
		return WP_CONTENT_DIR . '/uploads/gitwire-logs';
	}

	public function test_log_writes_an_entry_that_reads_back(): void {
		Logger::log( 'Installed acme/widgets as plugin on branch main' );

		$entries = Logger::get_instance()->get_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'activity', $entries[0]['level'] );
		$this->assertSame( 'Installed acme/widgets as plugin on branch main', $entries[0]['message'] );
	}

	public function test_log_records_the_acting_user(): void {
		Logger::log( 'Did a thing' );

		$entries = Logger::get_instance()->get_entries();

		$this->assertSame( '@testadmin', $entries[0]['actor'] );
	}

	public function test_entries_come_back_newest_first(): void {
		Logger::log( 'first' );
		Logger::log( 'second' );
		Logger::log( 'third' );

		$entries = Logger::get_instance()->get_entries();

		$this->assertSame( [ 'third', 'second', 'first' ], array_column( $entries, 'message' ) );
	}

	public function test_nothing_is_written_when_logging_is_disabled(): void {
		gitwire_test_set_option( 'gitwire_settings', [ 'enable_logging' => false ] );

		Logger::log( 'should not appear' );

		$this->assertSame( [], Logger::get_instance()->get_entries() );
	}

	public function test_error_level_silences_activity_entries(): void {
		gitwire_test_set_option(
			'gitwire_settings',
			[
				'enable_logging' => true,
				'log_level'      => 'error',
			]
		);

		Logger::log( 'routine activity' );
		Logger::log( 'something broke', 'error' );

		$entries = Logger::get_instance()->get_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'something broke', $entries[0]['message'] );
	}

	public function test_entries_can_be_filtered_by_level(): void {
		Logger::log( 'routine' );
		Logger::log( 'broke', 'error' );

		$errors = Logger::get_instance()->get_entries( '', '', 'error' );

		$this->assertCount( 1, $errors );
		$this->assertSame( 'broke', $errors[0]['message'] );
	}

	public function test_entries_can_be_filtered_by_actor(): void {
		Logger::log( 'by testadmin' );

		$this->assertCount( 1, Logger::get_instance()->get_entries( '', '', '', [ 'testadmin' ] ) );
		$this->assertCount( 0, Logger::get_instance()->get_entries( '', '', '', [ 'someone-else' ] ) );
	}

	public function test_entries_can_be_filtered_by_date_range(): void {
		Logger::log( 'today' );
		$today = gmdate( 'Y-m-d' );

		$this->assertCount( 1, Logger::get_instance()->get_entries( $today, $today ) );
		$this->assertCount( 0, Logger::get_instance()->get_entries( '2000-01-01', '2000-01-02' ) );
	}

	public function test_a_multi_line_message_does_not_corrupt_the_next_entry(): void {
		Logger::log( "line one\nline two injected" );
		Logger::log( 'clean entry' );

		$entries = Logger::get_instance()->get_entries();

		// The injected line has no timestamp prefix, so the parser drops it rather
		// than turning it into a fake entry.
		$this->assertSame( 'clean entry', $entries[0]['message'] );
		$this->assertSame( 'line one', $entries[1]['message'] );
		$this->assertCount( 2, $entries );
	}

	public function test_clear_empties_the_log(): void {
		Logger::log( 'something' );

		$this->assertTrue( Logger::get_instance()->clear() );
		$this->assertSame( [], Logger::get_instance()->get_entries() );
	}

	public function test_trim_drops_entries_past_the_retention_window(): void {
		$file = $this->log_dir() . '/' . $this->log_basename();

		$old   = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$fresh = gmdate( 'Y-m-d H:i:s' );
		file_put_contents(
			$file,
			"[{$old}] [activity] [@testadmin] ancient entry\n[{$fresh}] [activity] [@testadmin] recent entry\n"
		);

		Logger::get_instance()->trim_old_entries();

		$messages = array_column( Logger::get_instance()->get_entries(), 'message' );

		$this->assertContains( 'recent entry', $messages );
		$this->assertNotContains( 'ancient entry', $messages );
	}

	public function test_the_log_directory_is_protected_from_web_access(): void {
		Logger::log( 'create the directory' );

		$dir = $this->log_dir();

		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertFileExists( $dir . '/index.php' );
		$this->assertFileExists( $dir . '/web.config' );

		$htaccess = (string) file_get_contents( $dir . '/.htaccess' );
		$this->assertStringContainsString( 'Require all denied', $htaccess, 'Apache 2.4 syntax' );
		$this->assertStringContainsString( 'Deny from all', $htaccess, 'Apache 2.2 fallback' );
	}

	public function test_the_log_filename_is_not_guessable(): void {
		Logger::log( 'create the file' );

		$name = $this->log_basename();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}\.log$/', $name );
	}

	public function test_purge_removes_strays_but_keeps_the_guard_files_and_current_log(): void {
		Logger::log( 'create the directory' );

		$dir = $this->log_dir();
		file_put_contents( $dir . '/stale-from-a-salt-rotation.log', 'old' );
		file_put_contents( $dir . '/interrupted.log.tmp', 'partial' );

		Logger::purge_log_dir();

		$this->assertFileDoesNotExist( $dir . '/stale-from-a-salt-rotation.log' );
		$this->assertFileDoesNotExist( $dir . '/interrupted.log.tmp' );
		$this->assertFileExists( $dir . '/' . $this->log_basename() );
		$this->assertFileExists( $dir . '/index.php' );
		$this->assertFileExists( $dir . '/web.config' );
	}

	public function test_uninstall_removes_the_log_directory(): void {
		Logger::log( 'create the directory' );
		$this->assertDirectoryExists( $this->log_dir() );

		Logger::uninstall();

		$this->assertDirectoryDoesNotExist( $this->log_dir() );
	}

	/**
	 * Resolves the current log filename without exposing the private property.
	 *
	 * @return string
	 */
	private function log_basename(): string {
		return substr( hash( 'sha256', wp_salt( 'auth' ) . 'gitwire-log' ), 0, 32 ) . '.log';
	}
}
