<?php
/**
 * Tests that the SSRF host guard does not resolve the hardcoded default.
 *
 * @package Gitwire
 */

use Gitwire\GitLab_API;
use PHPUnit\Framework\TestCase;

/**
 * dns_get_record() takes no timeout argument and blocks on the system resolver.
 * A stalled AAAA lookup for gitlab.com was reaching max_execution_time and
 * killing the request, from inside the guard meant to make requests safer.
 *
 * The lookups are recorded by tests/dns-stubs.php, which shadows the two
 * resolver functions inside the Gitwire namespace, so these assert whether a
 * lookup happened at all rather than how long one took.
 *
 * @covers Gitwire\GitLab_API
 */
class GitLabHostGuardTest extends TestCase {

	protected function setUp(): void {
		gitwire_test_reset_options();

		// The guard memoises per host for the life of the request.
		$cache = new ReflectionProperty( GitLab_API::class, 'host_checked' );
		// Required on the PHP 8.0 floor, a deprecated no-op from 8.1 onwards.
		if ( PHP_VERSION_ID < 80100 ) {
			$cache->setAccessible( true );
		}
		$cache->setValue( null, [] );
	}

	/**
	 * @param string $base_url Instance URL, empty for the default.
	 * @return bool|WP_Error
	 */
	private function guard( string $base_url ) {
		$api    = new GitLab_API( '', $base_url );
		$method = new ReflectionMethod( GitLab_API::class, 'assert_base_url_safe' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		return $method->invoke( $api );
	}

	/**
	 * @return array<int, string>
	 */
	private function lookups(): array {
		return $GLOBALS['gitwire_dns_calls'] ?? [];
	}

	public function test_default_host_is_allowed_without_any_dns_lookup(): void {
		$this->assertTrue( $this->guard( '' ) );
		$this->assertSame( [], $this->lookups() );
	}

	public function test_gitlab_com_typed_explicitly_also_skips_the_lookup(): void {
		$this->assertTrue( $this->guard( 'https://gitlab.com' ) );
		$this->assertSame( [], $this->lookups() );
	}

	public function test_self_hosted_host_still_gets_resolved(): void {
		$this->assertTrue( $this->guard( 'https://git.example.com' ) );
		$this->assertSame(
			[ 'A:git.example.com', 'AAAA:git.example.com' ],
			$this->lookups()
		);
	}

	public function test_self_hosted_result_is_memoised_for_the_request(): void {
		$this->guard( 'https://git.example.com' );
		$this->guard( 'https://git.example.com' );

		$this->assertCount( 2, $this->lookups(), 'the second call should reuse the memo' );
	}

	public function test_localhost_is_still_refused(): void {
		$this->assertInstanceOf( WP_Error::class, $this->guard( 'https://localhost' ) );
		$this->assertSame( [], $this->lookups(), 'refused before any lookup' );
	}
}
