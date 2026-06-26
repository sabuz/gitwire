<?php
/**
 * Model for gitwire_commits.
 *
 * @package Gitwire
 * @since 2.0.0
 */

namespace Gitwire\Database\Commits;

use Gitwire\Model_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read/write operations for the gitwire_commits table.
 */
class Model extends Model_Base {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	protected function table(): string {
		return 'gitwire_commits';
	}

	protected function columns(): array {
		return [ 'installation_id', 'branch', 'data', 'updated_at' ];
	}

	/**
	 * Returns the cached commit row for an installation/branch, or null.
	 *
	 * @since 2.0.0
	 * @param int    $installation_id Primary key of gitwire_installations.
	 * @param string $branch          Branch name.
	 * @return array<string, mixed>|null Raw row — caller decodes the `data` JSON field.
	 */
	public function find( int $installation_id, string $branch ): ?array {
		return $this->get_row( [ 'installation_id' => $installation_id, 'branch' => $branch ] );
	}

	/**
	 * Stores or replaces the commit cache for an installation/branch.
	 *
	 * @since 2.0.0
	 * @param int                              $installation_id Primary key of gitwire_installations.
	 * @param string                           $branch          Branch name.
	 * @param array<int, array<string, mixed>> $commits         Commit list from the provider API.
	 * @return bool
	 */
	public function upsert( int $installation_id, string $branch, array $commits ): bool {
		global $wpdb;
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false !== $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` (installation_id, branch, data, updated_at)
				VALUES (%d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)",
				$installation_id,
				$branch,
				wp_json_encode( $commits ),
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Deletes all commit rows for an installation.
	 *
	 * @since 2.0.0
	 * @param int $installation_id Primary key of gitwire_installations.
	 * @return bool
	 */
	public function delete_by_installation( int $installation_id ): bool {
		return $this->delete_rows( [ 'installation_id' => $installation_id ] );
	}
}
