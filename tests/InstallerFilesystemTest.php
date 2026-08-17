<?php
/**
 * Tests for the installer's filesystem and rollback primitives.
 *
 * @package Gitwire
 */

use Gitwire\Installer;
use PHPUnit\Framework\TestCase;

/**
 * Covers backup, restore, and directory removal against a real filesystem.
 *
 * These are the paths that run while a site is mid-update, so a failure here
 * loses a working install rather than just showing the wrong thing.
 *
 * @covers Gitwire\Installer
 */
class InstallerFilesystemTest extends TestCase {

	/**
	 * Sandbox for the current test.
	 *
	 * @var string
	 */
	private string $root;

	protected function setUp(): void {
		gitwire_test_reset_options();
		$this->root = GITWIRE_TESTS_TMP . '/fs-' . uniqid();
		mkdir( $this->root, 0777, true );
	}

	protected function tearDown(): void {
		Installer::rmdir_recursive( $this->root );
	}

	/**
	 * Creates a directory tree from a path => contents map.
	 *
	 * @param string                $dir   Base directory.
	 * @param array<string, string> $files Relative path to contents.
	 * @return string
	 */
	private function tree( string $dir, array $files ): string {
		foreach ( $files as $rel => $contents ) {
			$path = $dir . '/' . $rel;
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, $contents );
		}
		return $dir;
	}

	public function test_rmdir_recursive_removes_a_nested_tree(): void {
		$dir = $this->tree(
			$this->root . '/victim',
			[
				'a.php'          => '<?php',
				'sub/b.php'      => '<?php',
				'sub/deep/c.txt' => 'x',
			]
		);

		Installer::rmdir_recursive( $dir );

		$this->assertDirectoryDoesNotExist( $dir );
	}

	public function test_rmdir_recursive_deletes_a_symlink_without_touching_its_target(): void {
		$target = $this->tree( $this->root . '/precious', [ 'keep.txt' => 'do not delete' ] );
		$link   = $this->root . '/link';
		symlink( $target, $link );

		Installer::rmdir_recursive( $link );

		$this->assertFalse( is_link( $link ), 'the symlink itself should be gone' );
		$this->assertFileExists( $target . '/keep.txt', 'the target must survive' );
	}

	public function test_rmdir_recursive_does_not_follow_a_nested_symlink(): void {
		$target = $this->tree( $this->root . '/outside', [ 'keep.txt' => 'do not delete' ] );
		$dir    = $this->tree( $this->root . '/victim', [ 'a.php' => '<?php' ] );
		symlink( $target, $dir . '/escape' );

		Installer::rmdir_recursive( $dir );

		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertFileExists( $target . '/keep.txt', 'a nested symlink must not leak the delete' );
	}

	public function test_rmdir_recursive_on_a_missing_path_is_a_no_op(): void {
		Installer::rmdir_recursive( $this->root . '/nope' );
		$this->assertDirectoryExists( $this->root );
	}

	public function test_restore_backup_puts_the_previous_version_back(): void {
		$install = $this->tree( $this->root . '/plugin', [ 'main.php' => 'broken' ] );
		$backup  = $this->tree( $this->root . '/plugin--gitwire-bak-1', [ 'main.php' => 'working' ] );

		$this->assertTrue( Installer::restore_backup( $install, $backup ) );

		$this->assertSame( 'working', file_get_contents( $install . '/main.php' ) );
		$this->assertDirectoryDoesNotExist( $backup, 'the backup is consumed by the restore' );
	}

	public function test_restore_backup_removes_files_the_broken_version_added(): void {
		$install = $this->tree(
			$this->root . '/plugin',
			[
				'main.php'  => 'broken',
				'extra.php' => 'added by the bad update',
			]
		);
		$backup  = $this->tree( $this->root . '/plugin--gitwire-bak-1', [ 'main.php' => 'working' ] );

		Installer::restore_backup( $install, $backup );

		$this->assertFileExists( $install . '/main.php' );
		$this->assertFileDoesNotExist( $install . '/extra.php' );
	}

	public function test_restore_backup_works_when_the_install_dir_is_already_gone(): void {
		$install = $this->root . '/plugin';
		$backup  = $this->tree( $this->root . '/plugin--gitwire-bak-1', [ 'main.php' => 'working' ] );

		$this->assertTrue( Installer::restore_backup( $install, $backup ) );
		$this->assertSame( 'working', file_get_contents( $install . '/main.php' ) );
	}

	public function test_restore_backup_reports_failure_when_there_is_no_backup(): void {
		$install = $this->tree( $this->root . '/plugin', [ 'main.php' => 'current' ] );

		$this->assertFalse( Installer::restore_backup( $install, null ) );
		$this->assertFalse( Installer::restore_backup( $install, $this->root . '/missing' ) );
		$this->assertSame( 'current', file_get_contents( $install . '/main.php' ), 'install must be left alone' );
	}

	public function test_restore_backup_preserves_nested_directories(): void {
		$install = $this->tree( $this->root . '/theme', [ 'style.css' => 'broken' ] );
		$backup  = $this->tree(
			$this->root . '/theme--gitwire-bak-1',
			[
				'style.css'            => 'working',
				'parts/header.html'    => '<header>',
				'templates/index.html' => '<main>',
			]
		);

		Installer::restore_backup( $install, $backup );

		$this->assertSame( 'working', file_get_contents( $install . '/style.css' ) );
		$this->assertFileExists( $install . '/parts/header.html' );
		$this->assertFileExists( $install . '/templates/index.html' );
	}

	public function test_find_orphaned_backup_returns_the_newest_match(): void {
		$install = $this->root . '/plugins/acme';
		mkdir( $install, 0777, true );

		foreach ( [ '100', '300', '200' ] as $stamp ) {
			mkdir( $this->root . '/plugins/acme--gitwire-bak-' . $stamp, 0777, true );
		}

		$found = Installer::find_orphaned_backup( $install );

		$this->assertSame( $this->root . '/plugins/acme--gitwire-bak-300', $found );
	}

	public function test_find_orphaned_backup_returns_null_when_there_is_none(): void {
		$install = $this->root . '/plugins/acme';
		mkdir( $install, 0777, true );

		$this->assertNull( Installer::find_orphaned_backup( $install ) );
	}

	public function test_find_plugin_file_prefers_the_slug_named_file(): void {
		$slug = 'acme-tools';
		$dir  = $this->tree(
			WP_PLUGIN_DIR . '/' . $slug,
			[
				'acme-tools.php' => "<?php\n/**\n * Plugin Name: Acme Tools\n */",
				'aaa-other.php'  => "<?php\n/**\n * Plugin Name: Decoy\n */",
			]
		);

		$this->assertSame( $slug . '/acme-tools.php', Installer::find_plugin_file( $dir, $slug ) );

		Installer::rmdir_recursive( $dir );
	}

	public function test_find_plugin_file_scans_when_the_slug_named_file_has_no_header(): void {
		$slug = 'acme-two';
		$dir  = $this->tree(
			WP_PLUGIN_DIR . '/' . $slug,
			[
				'acme-two.php'  => "<?php\n// no header here",
				'bootstrap.php' => "<?php\n/**\n * Plugin Name: Acme Two\n */",
			]
		);

		$this->assertSame( $slug . '/bootstrap.php', Installer::find_plugin_file( $dir, $slug ) );

		Installer::rmdir_recursive( $dir );
	}

	public function test_find_plugin_file_returns_null_when_no_file_has_a_header(): void {
		$slug = 'acme-three';
		$dir  = $this->tree(
			WP_PLUGIN_DIR . '/' . $slug,
			[ 'thing.php' => "<?php\n// nothing" ]
		);

		$this->assertNull( Installer::find_plugin_file( $dir, $slug ) );

		Installer::rmdir_recursive( $dir );
	}

	public function test_find_plugin_file_returns_null_for_a_missing_directory(): void {
		$this->assertNull( Installer::find_plugin_file( $this->root . '/nope', 'nope' ) );
	}

	public function test_purge_orphaned_backups_clears_strays_and_keeps_real_installs(): void {
		mkdir( WP_PLUGIN_DIR . '/real-plugin', 0777, true );
		mkdir( WP_PLUGIN_DIR . '/real-plugin--gitwire-bak-123', 0777, true );
		mkdir( WP_PLUGIN_DIR . '/other--gitwire-failed-456', 0777, true );

		Installer::purge_orphaned_backups();

		$this->assertDirectoryExists( WP_PLUGIN_DIR . '/real-plugin' );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/real-plugin--gitwire-bak-123' );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/other--gitwire-failed-456' );

		Installer::rmdir_recursive( WP_PLUGIN_DIR . '/real-plugin' );
	}

	public function test_purge_orphaned_backups_does_not_follow_a_symlinked_stray(): void {
		$target = $this->tree( $this->root . '/precious', [ 'keep.txt' => 'do not delete' ] );
		$stray  = WP_PLUGIN_DIR . '/evil--gitwire-bak-999';
		symlink( $target, $stray );

		Installer::purge_orphaned_backups();

		$this->assertFalse( is_link( $stray ) );
		$this->assertFileExists( $target . '/keep.txt' );
	}

	public function test_remove_backup_base_dir_deletes_the_directory_and_its_guard_files(): void {
		$base = WP_CONTENT_DIR . '/upgrade-temp-backup/gitwire';
		$this->tree( $base, [ '.htaccess' => 'deny from all' ] );

		Installer::remove_backup_base_dir();

		$this->assertDirectoryDoesNotExist( $base );
	}
}
