<?php
/**
 * Model for gitwire_installations.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire\Models;

use Gitwire\Model_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read/write operations for the gitwire_installations table.
 */
class Installation extends Model_Base {

	/**
	 * Request-scope cache for all().
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $all_cache = null;

	/**
	 * Returns the bare table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	protected function table(): string {
		return 'gitwire_installations';
	}

	/**
	 * Returns the allowed column names.
	 *
	 * @since 1.0.0
	 * @return string[]
	 */
	protected function columns(): array {
		return [
			'connection_id',
			'provider',
			'owner',
			'name',
			'full_name',
			'html_url',
			'type',
			'install_path',
			'basename',
			'branch',
			'auto_update',
			'head',
			'remote_head',
			'updated_at',
		];
	}

	/**
	 * Clears the request-scope read cache after a write.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function invalidate_cache(): void {
		self::$all_cache = null;
	}

	/**
	 * Returns all installation rows.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		if ( null !== self::$all_cache ) {
			return self::$all_cache;
		}
		self::$all_cache = $this->get_rows();
		return self::$all_cache;
	}

	/**
	 * Returns the installation for a given filesystem path, or null.
	 *
	 * @since 1.0.0
	 * @param string $install_path Absolute installation path.
	 * @return array<string, mixed>|null
	 */
	public function find_by_path( string $install_path ): ?array {
		global $wpdb;
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE install_path = %s LIMIT 1", $install_path ), ARRAY_A ) ?? null;
	}

	/**
	 * Returns the installation row for a provider/full_name pair, or null.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @return array<string, mixed>|null
	 */
	public function find_by_repo( string $provider, string $full_name ): ?array {
		return $this->get_row(
			[
				'provider'  => $provider,
				'full_name' => $full_name,
			]
		);
	}

	/**
	 * Inserts or updates an installation row.
	 *
	 * On conflict with the (provider, full_name) unique key:
	 * - head is preserved when the incoming value is empty (branch switches must not wipe it).
	 * - remote_head and auto_update are excluded from the UPDATE clause.
	 *   remote_head may have been set by the maintenance cron; auto_update is a user preference.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $data Row data. Do not pass `id` — it is AUTO_INCREMENT.
	 * @return bool
	 */
	public function upsert( array $data ): bool {
		global $wpdb;
		$table = $this->table_name();
		$data  = $this->filter_columns( $data );
		if ( empty( $data ) ) {
			return false;
		}
		$cols         = implode( ', ', array_map( fn( $c ) => "`{$c}`", array_keys( $data ) ) );
		$placeholders = implode( ', ', array_fill( 0, count( $data ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return false !== $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` ({$cols}) VALUES ({$placeholders})
				ON DUPLICATE KEY UPDATE
					connection_id = VALUES(connection_id),
					name          = VALUES(name),
					type          = VALUES(type),
					branch        = VALUES(branch),
					head          = IF(VALUES(head) != '', VALUES(head), head),
					install_path  = VALUES(install_path),
					html_url      = VALUES(html_url),
					basename      = VALUES(basename),
					updated_at    = VALUES(updated_at)",
				array_values( $data )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Updates the installed HEAD commit SHA.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @param string $sha       Full commit SHA.
	 * @return bool
	 */
	public function update_head( string $provider, string $full_name, string $sha ): bool {
		$ok = $this->update_rows(
			[ 'head' => $sha ],
			[
				'provider'  => $provider,
				'full_name' => $full_name,
			]
		);
		$this->invalidate_cache();
		return $ok;
	}

	/**
	 * Updates the latest remote HEAD commit SHA.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @param string $sha       Remote commit SHA.
	 * @return bool
	 */
	public function update_remote_head( string $provider, string $full_name, string $sha ): bool {
		$ok = $this->update_rows(
			[ 'remote_head' => $sha ],
			[
				'provider'  => $provider,
				'full_name' => $full_name,
			]
		);
		$this->invalidate_cache();
		return $ok;
	}

	/**
	 * Updates the plugin basename column.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @param string $basename  plugin_basename() value.
	 * @return bool
	 */
	public function update_basename( string $provider, string $full_name, string $basename ): bool {
		$ok = $this->update_rows(
			[ 'basename' => $basename ],
			[
				'provider'  => $provider,
				'full_name' => $full_name,
			]
		);
		$this->invalidate_cache();
		return $ok;
	}

	/**
	 * Updates the auto_update setting for an installation.
	 *
	 * @since 1.0.0
	 * @param string $provider    Git provider.
	 * @param string $full_name   Repository full name.
	 * @param string $auto_update New auto_update value.
	 * @return bool
	 */
	public function update_auto_update( string $provider, string $full_name, string $auto_update ): bool {
		$ok = $this->update_rows(
			[ 'auto_update' => $auto_update ],
			[
				'provider'  => $provider,
				'full_name' => $full_name,
			]
		);
		$this->invalidate_cache();
		return $ok;
	}

	/**
	 * Returns rows that share an install_path but belong to a different repo.
	 *
	 * Used during replace-installs to evict stale records that claimed the same directory.
	 *
	 * @since 1.0.0
	 * @param string $install_path Directory path to match.
	 * @param string $provider     Provider of the current repo to exclude.
	 * @param string $full_name    Full name of the current repo to exclude.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_others_by_path( string $install_path, string $provider, string $full_name ): array {
		global $wpdb;
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE install_path = %s AND NOT (provider = %s AND full_name = %s)", $install_path, $provider, $full_name ), ARRAY_A ) ?? [];
	}

	/**
	 * Deletes the installation row for a repository.
	 *
	 * Commits must be deleted separately via Commit::delete_by_installation().
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @return bool
	 */
	public function delete_by_repo( string $provider, string $full_name ): bool {
		$ok = $this->delete_rows(
			[
				'provider'  => $provider,
				'full_name' => $full_name,
			]
		);
		$this->invalidate_cache();
		return $ok;
	}
}
