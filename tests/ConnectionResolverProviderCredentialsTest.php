<?php
/**
 * Tests for the default-connection credential lookup.
 *
 * @package Gitwire
 */

use Gitwire\Connection_Resolver;
use PHPUnit\Framework\TestCase;

/**
 * Covers which credentials win when both a public and a Pro connection exist
 * for the same provider.
 *
 * @covers Gitwire\Connection_Resolver
 */
class ConnectionResolverProviderCredentialsTest extends TestCase {

	/**
	 * Recording wpdb double.
	 *
	 * @var Fake_WPDB
	 */
	private Fake_WPDB $wpdb;

	protected function setUp(): void {
		gitwire_test_reset_options();
		$this->wpdb      = new Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	/**
	 * A public connection row as find_by_provider() would return it.
	 *
	 * @return array<string, mixed>
	 */
	private function public_row(): array {
		return [
			'id'          => 'pub_1',
			'provider'    => 'github',
			'identifier'  => 'octocat',
			'scope'       => 'all',
			'host_url'    => '',
			'credentials' => null,
		];
	}

	public function test_a_pro_connection_is_preferred_over_a_shadowed_public_row(): void {
		$this->wpdb->row_result = $this->public_row();

		add_filter(
			'gitwire_provider_credentials',
			static fn( $fallback, $provider ) => 'github' === $provider ? [ 'token' => 'pro-token' ] : $fallback,
			10,
			2
		);

		$creds = Connection_Resolver::get_credentials_for_provider( 'github' );

		$this->assertSame( [ 'token' => 'pro-token' ], $creds );
	}

	public function test_the_public_row_is_still_used_when_no_pro_connection_exists(): void {
		$this->wpdb->row_result = $this->public_row();

		$creds = Connection_Resolver::get_credentials_for_provider( 'github' );

		$this->assertSame( [ 'username' => 'octocat' ], $creds );
	}

	public function test_null_when_neither_a_pro_nor_a_public_connection_exists(): void {
		$this->wpdb->row_result = null;

		$this->assertNull( Connection_Resolver::get_credentials_for_provider( 'github' ) );
	}
}
