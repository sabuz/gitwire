<?php
/**
 * Tests for the repository cache write path.
 *
 * @package Gitwire
 */

use Gitwire\Models\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Covers batching, row skipping, and the cycle-marker stale sweep.
 *
 * @covers Gitwire\Models\Repository
 */
class RepositoryCacheTest extends TestCase {

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
	 * Builds n plausible repository payloads.
	 *
	 * @param int $count How many.
	 * @return array<int, array<string, mixed>>
	 */
	private function repositories( int $count ): array {
		$out = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = [
				'full_name'        => "acme/repo-{$i}",
				'owner'            => 'acme',
				'name'             => "repo-{$i}",
				'private'          => false,
				'html_url'         => "https://github.com/acme/repo-{$i}",
				'default_branch'   => 'main',
				'last_activity_at' => '2026-08-01T00:00:00Z',
			];
		}
		return $out;
	}

	public function test_a_single_page_is_written_in_one_statement(): void {
		Repository::instance()->upsert_batch( 'conn-1', $this->repositories( 100 ), 'github' );

		$this->assertCount( 1, $this->wpdb->queries_matching( 'INSERT INTO' ) );
	}

	public function test_writes_are_chunked_at_one_hundred_rows(): void {
		Repository::instance()->upsert_batch( 'conn-1', $this->repositories( 250 ), 'github' );

		$inserts = $this->wpdb->queries_matching( 'INSERT INTO' );

		$this->assertCount( 3, $inserts, '250 rows should be 3 statements, not 250' );
	}

	public function test_a_large_account_does_not_produce_one_query_per_repository(): void {
		Repository::instance()->upsert_batch( 'conn-1', $this->repositories( 5000 ), 'github' );

		$inserts = $this->wpdb->queries_matching( 'INSERT INTO' );

		$this->assertCount( 50, $inserts );
	}

	/**
	 * Counts value tuples via default_branch, which every row carries exactly once.
	 *
	 * @param string $sql Prepared statement.
	 * @return int
	 */
	private function row_count( string $sql ): int {
		return substr_count( $sql, "'main'" );
	}

	public function test_every_row_in_a_chunk_reaches_the_statement(): void {
		Repository::instance()->upsert_batch( 'conn-1', $this->repositories( 3 ), 'github' );

		$sql = $this->wpdb->queries_matching( 'INSERT INTO' )[0];

		$this->assertStringContainsString( "'acme/repo-0'", $sql );
		$this->assertStringContainsString( "'acme/repo-1'", $sql );
		$this->assertStringContainsString( "'acme/repo-2'", $sql );
		$this->assertSame( 3, $this->row_count( $sql ) );
	}

	public function test_type_columns_stay_out_of_the_update_clause(): void {
		Repository::instance()->upsert_batch( 'conn-1', $this->repositories( 2 ), 'github' );

		$sql    = $this->wpdb->queries_matching( 'INSERT INTO' )[0];
		$update = substr( $sql, (int) strpos( $sql, 'ON DUPLICATE KEY UPDATE' ) );

		// Cached detection results must survive a cron refresh.
		$this->assertStringNotContainsString( 'type', $update );
		$this->assertStringNotContainsString( 'type_meta', $update );
	}

	public function test_a_row_without_a_full_name_is_skipped_without_shifting_the_rest(): void {
		$repositories   = $this->repositories( 2 );
		$repositories[] = [ 'owner' => 'acme' ];
		$repositories[] = [
			'full_name' => 'acme/last',
			'owner'     => 'acme',
			'name'      => 'last',
		];

		Repository::instance()->upsert_batch( 'conn-1', $repositories, 'github' );

		$sql = $this->wpdb->queries_matching( 'INSERT INTO' )[0];

		// Three valid rows, and the last one still lands with its own values.
		$this->assertSame( 3, $this->row_count( $sql ) );
		$this->assertStringContainsString( "'acme/last'", $sql );
	}

	public function test_an_empty_payload_writes_nothing(): void {
		$this->assertFalse( Repository::instance()->upsert_batch( 'conn-1', [], 'github' ) );
		$this->assertSame( [], $this->wpdb->queries_matching( 'INSERT INTO' ) );
	}

	public function test_a_payload_of_only_invalid_rows_writes_nothing(): void {
		$this->assertFalse( Repository::instance()->upsert_batch( 'conn-1', [ [ 'owner' => 'acme' ] ], 'github' ) );
		$this->assertSame( [], $this->wpdb->queries_matching( 'INSERT INTO' ) );
	}

	public function test_the_cycle_stamp_is_used_when_supplied(): void {
		Repository::instance()->upsert_batch( 'conn-1', $this->repositories( 1 ), 'github', '2026-01-02 03:04:05' );

		$this->assertStringContainsString( '2026-01-02 03:04:05', $this->wpdb->queries_matching( 'INSERT INTO' )[0] );
	}

	/**
	 * Reads one row back out through get_paginated().
	 *
	 * @param array<string, mixed> $overrides Column values to set on the row.
	 * @return array<string, mixed>
	 */
	private function read_row( array $overrides = [] ): array {
		$this->wpdb->results = [
			array_merge(
				[
					'connection_id'    => 'conn-1',
					'provider'         => 'github',
					'full_name'        => 'acme/repo',
					'owner'            => 'acme',
					'name'             => 'repo',
					'private'          => '0',
					'html_url'         => '',
					'default_branch'   => 'main',
					'last_activity_at' => '2026-08-01 09:30:00',
					'type'             => '',
					'type_meta'        => null,
				],
				$overrides
			),
		];

		$page = Repository::instance()->get_paginated(
			[
				'connection_ids' => [ 'conn-1' ],
				'per_page'       => 50,
			]
		);

		return $page['repositories'][0];
	}

	public function test_activity_dates_leave_as_iso8601_utc(): void {
		/*
		 * The column uses UTC, so return an ISO 8601 value that JavaScript can parse
		 * without applying the viewer's local time zone.
		 */
		$this->assertSame( '2026-08-01T09:30:00Z', $this->read_row()['last_activity_at'] );
	}

	public function test_the_epoch_sentinel_is_not_handed_to_the_client_as_a_date(): void {
		$row = $this->read_row( [ 'last_activity_at' => '1970-01-01 00:00:00' ] );

		$this->assertSame( '', $row['last_activity_at'] );
	}

	public function test_pagination_order_has_a_tiebreaker(): void {
		Repository::instance()->get_paginated(
			[
				'connection_ids' => [ 'conn-1' ],
				'per_page'       => 50,
			]
		);

		// Rows sharing a timestamp could otherwise reorder between LIMIT/OFFSET pages.
		$this->assertStringContainsString(
			'ORDER BY last_activity_at DESC, full_name ASC',
			$this->wpdb->queries_matching( 'SELECT connection_id' )[0]
		);
	}

	public function test_stale_sweep_targets_one_connection_by_cycle_stamp(): void {
		Repository::instance()->remove_stale_since( 'conn-1', '2026-01-02 03:04:05' );

		$sql = $this->wpdb->queries_matching( 'DELETE FROM' )[0];

		$this->assertStringContainsString( "connection_id = 'conn-1'", $sql );
		$this->assertStringContainsString( "updated_at < '2026-01-02 03:04:05'", $sql );
	}

	public function test_stale_sweep_refuses_to_run_without_a_cycle_stamp(): void {
		// An empty stamp would delete every row for the connection.
		$this->assertFalse( Repository::instance()->remove_stale_since( 'conn-1', '' ) );
		$this->assertSame( [], $this->wpdb->queries_matching( 'DELETE FROM' ) );
	}

	public function test_stale_sweep_uses_one_bound_value_not_one_per_repository(): void {
		Repository::instance()->remove_stale_since( 'conn-1', '2026-01-02 03:04:05' );

		$sql = $this->wpdb->queries_matching( 'DELETE FROM' )[0];

		$this->assertStringNotContainsString( 'NOT IN', $sql );
	}
}
