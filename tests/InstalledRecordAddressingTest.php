<?php
/**
 * Tests that an installation stays addressable when its namespace is nested.
 *
 * @package Gitwire
 */

use Gitwire\Installer;
use PHPUnit\Framework\TestCase;

/**
 * GitLab subgroup paths can contain multiple segments, so repository names must be
 * split at the last slash to keep the owner and repository unambiguous.
 *
 * @covers Gitwire\Installer
 */
class InstalledRecordAddressingTest extends TestCase {

	protected function setUp(): void {
		gitwire_test_reset_options();
		$GLOBALS['wpdb'] = new Fake_WPDB();
		gitwire_test_set_installations(
			[
				[
					'id'        => 1,
					'provider'  => 'gitlab',
					'full_name' => 'acme/widget',
					'owner'     => 'acme',
					'name'      => 'widget',
					'type'      => 'plugin',
					'branch'    => 'main',
				],
				[
					'id'        => 2,
					'provider'  => 'gitlab',
					'full_name' => 'acme/team/widget',
					'owner'     => 'acme/team',
					'name'      => 'widget-gitlab',
					'type'      => 'plugin',
					'branch'    => 'main',
				],
				[
					'id'        => 3,
					'provider'  => 'gitlab',
					'full_name' => 'acme/team/sub/deep',
					'owner'     => 'acme/team/sub',
					'name'      => 'deep',
					'type'      => 'block-theme',
					'branch'    => 'main',
				],
			]
		);
	}

	protected function tearDown(): void {
		gitwire_test_set_installations( [] );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function namespaces(): array {
		return [
			'flat'            => [ 'acme/widget', 'acme', 'widget' ],
			'subgroup'        => [ 'acme/team/widget', 'acme/team', 'widget' ],
			'nested subgroup' => [ 'acme/team/sub/deep', 'acme/team/sub', 'deep' ],
		];
	}

	/**
	 * @dataProvider namespaces
	 * @param string $full_name Stored full name.
	 * @param string $owner     Expected owner.
	 * @param string $repository      Expected repository.
	 */
	public function test_owner_and_repository_split_on_the_last_slash( string $full_name, string $owner, string $repository ): void {
		$rec = Installer::get_record( 'gitlab', $full_name );

		$this->assertNotNull( $rec, "record for {$full_name} should exist" );
		$this->assertSame( $owner, $rec['owner'] );
		$this->assertSame( $repository, $rec['repository'] );
	}

	/**
	 * @dataProvider namespaces
	 * @param string $full_name Stored full name.
	 */
	public function test_owner_and_repository_recombine_into_full_name( string $full_name ): void {
		$rec = Installer::get_record( 'gitlab', $full_name );

		$this->assertSame( $full_name, $rec['owner'] . '/' . $rec['repository'] );
	}

	/**
	 * The pair is what switch_branch() and run_auto_updates() hand to the provider
	 * as a project path, so a pair that does not round-trip means the wrong project.
	 *
	 * @dataProvider namespaces
	 * @param string $full_name Stored full name.
	 */
	public function test_the_recombined_pair_still_finds_its_own_record( string $full_name ): void {
		$rec = Installer::get_record( 'gitlab', $full_name );

		$this->assertNotNull( Installer::get_record( 'gitlab', $rec['owner'] . '/' . $rec['repository'] ) );
	}

	public function test_lookup_by_id_returns_the_right_record(): void {
		$this->assertSame( 'acme/team/widget', Installer::get_record_by_id( 2 )['full_name'] );
		$this->assertSame( 'acme/widget', Installer::get_record_by_id( 1 )['full_name'] );
	}

	public function test_lookup_by_unknown_or_invalid_id_is_null(): void {
		$this->assertNull( Installer::get_record_by_id( 999 ) );
		$this->assertNull( Installer::get_record_by_id( 0 ) );
		$this->assertNull( Installer::get_record_by_id( -1 ) );
	}

	/**
	 * The id is what the installed routes now key on, so it has to survive the
	 * annotation pass the REST layer runs before handing records to the client.
	 *
	 * @return void
	 */
	public function test_id_survives_annotation(): void {
		$annotated = \Gitwire\REST_Installer::annotate_installed( Installer::get_installed() );

		$ids = array_column( $annotated, 'id' );
		sort( $ids );

		$this->assertSame( [ 1, 2, 3 ], $ids );
	}
}
