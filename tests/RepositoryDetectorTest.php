<?php
/**
 * Tests for the repository type detector.
 *
 * @package Gitwire
 */

use Gitwire\Repository_Detector;
use PHPUnit\Framework\TestCase;

/**
 * Covers the detection priority rules.
 *
 * This decides whether a downloaded archive is unpacked into plugins/ or
 * themes/, so a wrong answer puts code in the wrong place on a live site.
 *
 * @covers Gitwire\Repository_Detector
 */
class RepositoryDetectorTest extends TestCase {

	protected function setUp(): void {
		gitwire_test_reset_options();
	}

	/**
	 * Builds a root listing callable from a name => type map.
	 *
	 * @param array<string, string> $entries Name to 'file' or 'dir'.
	 * @return callable
	 */
	private function listing( array $entries ): callable {
		$items = [];
		foreach ( $entries as $name => $type ) {
			$items[] = [
				'name' => $name,
				'type' => $type,
			];
		}
		return static fn() => $items;
	}

	/**
	 * Builds a file-content callable from a path => contents map.
	 *
	 * @param array<string, string> $files Path to contents.
	 * @return callable
	 */
	private function contents( array $files ): callable {
		return static function ( $path ) use ( $files ) {
			return $files[ $path ] ?? new WP_Error( 'not_found', 'missing' );
		};
	}

	public function test_style_css_with_theme_name_and_theme_json_is_a_block_theme(): void {
		$result = Repository_Detector::detect(
			'my-theme',
			'main',
			$this->listing(
				[
					'style.css'  => 'file',
					'theme.json' => 'file',
				]
			),
			$this->contents( [ 'style.css' => "/*\nTheme Name: My Theme\n*/" ] )
		);

		$this->assertSame( 'block-theme', $result['type'] );
		$this->assertSame( 'high', $result['confidence'] );
		$this->assertSame( 'My Theme', $result['name'] );
	}

	public function test_theme_json_alone_is_not_a_block_theme(): void {
		// A plugin shipping theme.json for block styling must stay a plugin.
		$result = Repository_Detector::detect(
			'my-plugin',
			'main',
			$this->listing(
				[
					'theme.json'    => 'file',
					'my-plugin.php' => 'file',
				]
			),
			$this->contents( [ 'my-plugin.php' => "<?php\n/**\n * Plugin Name: My Plugin\n */" ] )
		);

		$this->assertSame( 'plugin', $result['type'] );
		$this->assertSame( 'My Plugin', $result['name'] );
	}

	public function test_style_css_with_templates_dir_is_a_block_theme(): void {
		$result = Repository_Detector::detect(
			'my-theme',
			'main',
			$this->listing(
				[
					'style.css' => 'file',
					'templates' => 'dir',
				]
			),
			$this->contents( [ 'style.css' => "/*\nTheme Name: Blocky\n*/" ] )
		);

		$this->assertSame( 'block-theme', $result['type'] );
	}

	public function test_templates_as_a_file_does_not_promote_to_block_theme(): void {
		$result = Repository_Detector::detect(
			'my-theme',
			'main',
			$this->listing(
				[
					'style.css' => 'file',
					'templates' => 'file',
				]
			),
			$this->contents( [ 'style.css' => "/*\nTheme Name: Classic\n*/" ] )
		);

		$this->assertSame( 'classic-theme', $result['type'] );
	}

	public function test_style_css_with_functions_php_is_a_high_confidence_classic_theme(): void {
		$result = Repository_Detector::detect(
			'my-theme',
			'main',
			$this->listing(
				[
					'style.css'     => 'file',
					'functions.php' => 'file',
				]
			),
			$this->contents( [ 'style.css' => "/*\nTheme Name: Classic\n*/" ] )
		);

		$this->assertSame( 'classic-theme', $result['type'] );
		$this->assertSame( 'high', $result['confidence'] );
	}

	public function test_style_css_without_theme_name_header_falls_through_to_plugin(): void {
		$result = Repository_Detector::detect(
			'my-plugin',
			'main',
			$this->listing(
				[
					'style.css'     => 'file',
					'my-plugin.php' => 'file',
				]
			),
			$this->contents(
				[
					'style.css'     => 'body { color: red; }',
					'my-plugin.php' => "<?php\n/**\n * Plugin Name: My Plugin\n */",
				]
			)
		);

		$this->assertSame( 'plugin', $result['type'] );
	}

	public function test_repo_named_php_file_is_checked_before_other_candidates(): void {
		$result = Repository_Detector::detect(
			'target',
			'main',
			$this->listing(
				[
					'aaa.php'    => 'file',
					'target.php' => 'file',
				]
			),
			$this->contents(
				[
					'aaa.php'    => "<?php\n// helper, no header",
					'target.php' => "<?php\n/**\n * Plugin Name: Target\n */",
				]
			)
		);

		$this->assertSame( 'plugin', $result['type'] );
		$this->assertSame( 'Target', $result['name'] );
	}

	public function test_functions_php_alone_is_a_medium_confidence_classic_theme(): void {
		$result = Repository_Detector::detect(
			'mystery',
			'main',
			$this->listing( [ 'functions.php' => 'file' ] ),
			$this->contents( [ 'functions.php' => "<?php\n// no headers here" ] )
		);

		$this->assertSame( 'classic-theme', $result['type'] );
		$this->assertSame( 'medium', $result['confidence'] );
	}

	public function test_bare_php_files_are_a_low_confidence_plugin(): void {
		$result = Repository_Detector::detect(
			'mystery',
			'main',
			$this->listing( [ 'thing.php' => 'file' ] ),
			$this->contents( [ 'thing.php' => "<?php\n// nothing identifying" ] )
		);

		$this->assertSame( 'plugin', $result['type'] );
		$this->assertSame( 'low', $result['confidence'] );
		$this->assertSame( [], $result['key_files'] );
	}

	public function test_docs_only_repository_is_unknown(): void {
		$result = Repository_Detector::detect(
			'docs',
			'main',
			$this->listing(
				[
					'README.md'    => 'file',
					'package.json' => 'file',
					'docs'         => 'dir',
				]
			),
			$this->contents( [] )
		);

		$this->assertSame( 'unknown', $result['type'] );
		$this->assertSame( 'none', $result['confidence'] );
	}

	public function test_ignored_directories_do_not_count_as_php_content(): void {
		$result = Repository_Detector::detect(
			'docs',
			'main',
			$this->listing(
				[
					'vendor'       => 'dir',
					'node_modules' => 'dir',
					'tests'        => 'dir',
					'README.md'    => 'file',
				]
			),
			$this->contents( [] )
		);

		$this->assertSame( 'unknown', $result['type'] );
	}

	public function test_listing_error_is_returned_to_the_caller(): void {
		$result = Repository_Detector::detect(
			'x',
			'main',
			static fn() => new WP_Error( 'gitwire_api_error', 'rate limited' ),
			$this->contents( [] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rate limited', $result->get_error_message() );
	}

	public function test_shallow_recheck_reuses_the_cached_result_when_the_listing_is_unchanged(): void {
		gitwire_test_set_option( 'gitwire_settings', [ 'shallow_detection' => true ] );

		$listing = $this->listing(
			[
				'style.css'  => 'file',
				'theme.json' => 'file',
			]
		);

		// Detect once to obtain a cache entry carrying the listing fingerprint.
		$cached = Repository_Detector::detect(
			'my-theme',
			'main',
			$listing,
			$this->contents( [ 'style.css' => "/*\nTheme Name: Cached\n*/" ] )
		);

		$fetches = 0;
		$counter = function () use ( &$fetches ) {
			++$fetches;
			return "/*\nTheme Name: Cached\n*/";
		};

		$result = Repository_Detector::detect( 'my-theme', 'main', $listing, $counter, $cached );

		$this->assertSame( $cached, $result );
		$this->assertSame( 0, $fetches, 'shallow re-check should not fetch file contents' );
	}

	public function test_shallow_recheck_re_detects_when_the_listing_changed(): void {
		gitwire_test_set_option( 'gitwire_settings', [ 'shallow_detection' => true ] );

		$cached = Repository_Detector::detect(
			'my-repo',
			'main',
			$this->listing(
				[
					'style.css'  => 'file',
					'theme.json' => 'file',
				]
			),
			$this->contents( [ 'style.css' => "/*\nTheme Name: Was A Theme\n*/" ] )
		);
		$this->assertSame( 'block-theme', $cached['type'] );

		/*
		 * Same key files still present, but the repo gained a plugin bootstrap. The
		 * old check passed on key_files alone and kept returning block-theme.
		 */
		$result = Repository_Detector::detect(
			'my-repo',
			'main',
			$this->listing(
				[
					'style.css'   => 'file',
					'theme.json'  => 'file',
					'my-repo.php' => 'file',
				]
			),
			$this->contents(
				[
					'style.css'   => 'body { color: red; }',
					'my-repo.php' => "<?php\n/**\n * Plugin Name: Now A Plugin\n */",
				]
			),
			$cached
		);

		$this->assertSame( 'plugin', $result['type'] );
		$this->assertSame( 'Now A Plugin', $result['name'] );
	}

	public function test_shallow_recheck_re_detects_when_a_key_file_disappears(): void {
		gitwire_test_set_option( 'gitwire_settings', [ 'shallow_detection' => true ] );

		$cached = [
			'type'       => 'block-theme',
			'confidence' => 'high',
			'name'       => 'Was A Theme',
			'key_files'  => [ 'style.css', 'theme.json' ],
		];

		$result = Repository_Detector::detect(
			'my-plugin',
			'main',
			$this->listing( [ 'my-plugin.php' => 'file' ] ),
			$this->contents( [ 'my-plugin.php' => "<?php\n/**\n * Plugin Name: Now A Plugin\n */" ] ),
			$cached
		);

		$this->assertSame( 'plugin', $result['type'] );
		$this->assertSame( 'Now A Plugin', $result['name'] );
	}

	public function test_shallow_recheck_is_skipped_when_the_setting_is_off(): void {
		$fetches = 0;
		$counter = function () use ( &$fetches ) {
			++$fetches;
			return "/*\nTheme Name: Fresh\n*/";
		};

		Repository_Detector::detect(
			'my-theme',
			'main',
			$this->listing(
				[
					'style.css'  => 'file',
					'theme.json' => 'file',
				]
			),
			$counter,
			[
				'type'      => 'block-theme',
				'key_files' => [ 'style.css', 'theme.json' ],
			]
		);

		$this->assertSame( 1, $fetches, 'detection should re-read style.css when shallow_detection is off' );
	}

	public function test_is_theme_recognises_every_theme_type(): void {
		$this->assertTrue( Repository_Detector::is_theme( 'theme' ) );
		$this->assertTrue( Repository_Detector::is_theme( 'block-theme' ) );
		$this->assertTrue( Repository_Detector::is_theme( 'classic-theme' ) );
		$this->assertFalse( Repository_Detector::is_theme( 'plugin' ) );
		$this->assertFalse( Repository_Detector::is_theme( 'unknown' ) );
		$this->assertFalse( Repository_Detector::is_theme( '' ) );
	}
}
