<?php
/**
 * Model for gitwire_connections.
 *
 * @package Gitwire
 * @since 2.0.0
 */

namespace Gitwire\Database\Connections;

use Gitwire\Model_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read/write operations for the gitwire_connections table.
 *
 * Consolidates class-connection-resolver.php, class-public-connections.php,
 * and class-connection-meta.php. Pro-only columns (email, credentials, scope)
 * are not in the free schema and therefore not in columns().
 */
class Model extends Model_Base {

	private static ?self $instance = null;

	/**
	 * Request-scope cache for all().
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $all_cache = null;

	/**
	 * Profile-cache columns that save_metadata() is allowed to write.
	 *
	 * Identity columns (id, provider, identifier, host_url) are explicitly excluded.
	 */
	private const METADATA_COLUMNS = [
		'authenticated',
		'name',
		'avatar_url',
		'rate_limit',
		'rate_remaining',
		'rate_reset',
		'error',
		'updated_at',
	];

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	protected function table(): string {
		return 'gitwire_connections';
	}

	protected function columns(): array {
		return [
			'id',
			'provider',
			'host_url',
			'identifier',
			'authenticated',
			'error',
			'name',
			'avatar_url',
			'rate_limit',
			'rate_remaining',
			'rate_reset',
			'created_at',
			'updated_at',
		];
	}

	/**
	 * Clears the request-scope read cache after a write.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function invalidate_cache(): void {
		self::$all_cache = null;
	}

	/**
	 * Returns all connection rows ordered by created_at.
	 *
	 * @since 2.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		if ( null !== self::$all_cache ) {
			return self::$all_cache;
		}
		global $wpdb;
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$all_cache = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY created_at ASC", ARRAY_A ) ?? [];
		return self::$all_cache;
	}

	/**
	 * Returns a single connection by ID, or null.
	 *
	 * @since 2.0.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null
	 */
	public function find( string $id ): ?array {
		return $this->get_row( [ 'id' => $id ] );
	}

	/**
	 * Returns the first connection for a provider ordered by created_at, or null.
	 *
	 * @since 2.0.0
	 * @param string $provider Provider key.
	 * @return array<string, mixed>|null
	 */
	public function find_by_provider( string $provider ): ?array {
		global $wpdb;
		$table = $this->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE provider = %s ORDER BY created_at ASC LIMIT 1", $provider ),
			ARRAY_A
		) ?? null;
	}

	/**
	 * Returns the first connection matching provider and identifier, or null.
	 *
	 * @since 2.0.0
	 * @param string $provider   Provider key.
	 * @param string $identifier Username or workspace slug.
	 * @return array<string, mixed>|null
	 */
	public function find_by_identifier( string $provider, string $identifier ): ?array {
		if ( '' === $identifier ) {
			return null;
		}
		return $this->get_row( [ 'provider' => $provider, 'identifier' => $identifier ] );
	}

	/**
	 * Inserts a new connection row.
	 *
	 * @since 2.0.0
	 * @param array<string, mixed> $data Connection fields.
	 * @return bool
	 */
	public function insert( array $data ): bool {
		$ok = $this->insert_row( $data );
		$this->invalidate_cache();
		return $ok;
	}

	/**
	 * Deletes a connection by ID.
	 *
	 * @since 2.0.0
	 * @param string $id Connection ID.
	 * @return bool True when a row was deleted.
	 */
	public function delete_by_id( string $id ): bool {
		$ok = $this->delete_rows( [ 'id' => $id ] );
		$this->invalidate_cache();
		return $ok;
	}

	/**
	 * Updates profile cache columns for a connection.
	 *
	 * Only touches authenticated, name, avatar_url, rate_limit, rate_remaining,
	 * rate_reset, error, and updated_at. Identity columns are never modified.
	 * updated_at defaults to now when not present in $data.
	 *
	 * @since 2.0.0
	 * @param string               $id   Connection ID.
	 * @param array<string, mixed> $data Profile fields to store.
	 * @return bool
	 */
	public function save_metadata( string $id, array $data ): bool {
		$data = array_intersect_key( $data, array_flip( self::METADATA_COLUMNS ) );
		if ( empty( $data ) ) {
			return false;
		}
		if ( ! array_key_exists( 'updated_at', $data ) ) {
			$data['updated_at'] = current_time( 'mysql' );
		}
		$ok = $this->update_rows( $data, [ 'id' => $id ] );
		$this->invalidate_cache();
		return $ok;
	}

	/**
	 * Resets all profile cache columns to their defaults for a connection.
	 *
	 * @since 2.0.0
	 * @param string $id Connection ID.
	 * @return bool
	 */
	public function clear_metadata( string $id ): bool {
		$ok = $this->update_rows(
			[
				'authenticated'  => 0,
				'name'           => '',
				'avatar_url'     => '',
				'rate_limit'     => 0,
				'rate_remaining' => 0,
				'rate_reset'     => 0,
				'error'          => null,
				'updated_at'     => current_time( 'mysql' ),
			],
			[ 'id' => $id ]
		);
		$this->invalidate_cache();
		return $ok;
	}
}
