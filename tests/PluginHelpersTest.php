<?php
/**
 * Unit tests for shared plugin helpers.
 *
 * @package Gitwire
 */

use Gitwire\Repository_Detector;
use Gitwire\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Repository_Detector and Settings helpers.
 *
 * @covers Gitwire\Repository_Detector
 * @covers Gitwire\Settings
 */
class PluginHelpersTest extends TestCase {

	/**
	 * Masks the middle of a token while keeping prefix and suffix visible.
	 */
	public function test_mask_token_hides_middle(): void {
		$this->assertSame(
			'ghp_••••••••••••abcd',
			Settings::mask_token( 'ghp_xxxxxxxxxxxxabcd' )
		);
	}

	/**
	 * Returns an empty string when masking an empty token.
	 */
	public function test_mask_token_empty(): void {
		$this->assertSame( '', Settings::mask_token( '' ) );
	}

	/**
	 * Detects a Plugin Name header in PHP file content.
	 */
	public function test_has_header_finds_plugin_name(): void {
		$content = "<?php\n/**\n * Plugin Name: Git\n */";
		$this->assertTrue( Repository_Detector::has_header( $content, 'Plugin Name' ) );
	}

	/**
	 * Extracts a Theme Name header value from PHP file content.
	 */
	public function test_extract_header_returns_value(): void {
		$content = "<?php\n/**\n * Theme Name: Demo Theme\n */";
		$this->assertSame( 'Demo Theme', Repository_Detector::extract_header( $content, 'Theme Name' ) );
	}

	/**
	 * Allows public HTTPS GitLab hosts.
	 */
	public function test_gitlab_url_allows_https_public_host(): void {
		$this->assertTrue( Settings::is_allowed_gitlab_url( 'https://gitlab.com' ) );
	}

	/**
	 * Blocks localhost GitLab URLs.
	 */
	public function test_gitlab_url_blocks_localhost(): void {
		$this->assertFalse( Settings::is_allowed_gitlab_url( 'https://localhost' ) );
	}

	/**
	 * Blocks private-network GitLab URLs.
	 */
	public function test_gitlab_url_blocks_private_ip(): void {
		$this->assertFalse( Settings::is_allowed_gitlab_url( 'https://192.168.1.10' ) );
	}
}
