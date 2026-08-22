<?php
/**
 * REST endpoints for installing, managing, and tracking repositories.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

use Gitwire\Models\Commit;
use Gitwire\Models\Installation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the /install and /installed REST routes.
 */
class REST_Installer {

	/**
	 * Request-scoped cache for the gitwire_running_task option.
	 *
	 * @var array<string, mixed>|null null = not yet loaded, absent option stored as null.
	 */
	private static mixed $pending_cache = null;

	/**
	 * Returns the pending update option, reading the DB at most once per request.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>|false
	 */
	private static function get_running_task(): mixed {
		if ( null === self::$pending_cache ) {
			self::$pending_cache = get_option( 'gitwire_running_task' );
		}
		return self::$pending_cache;
	}

	/**
	 * Returns an API client instance for the given provider and optional connection ID.
	 *
	 * @since 1.0.0
	 * @param string      $provider      Provider key: 'github', 'gitlab', or 'bitbucket'.
	 * @param string|null $connection_id Specific connection ID, or null for the default.
	 * @return Git_Provider_Interface Appropriate API client.
	 */
	private static function make_api( string $provider = 'github', ?string $connection_id = null ): Git_Provider_Interface {
		return Provider_Factory::make( $provider, $connection_id );
	}

	/**
	 * Returns a cached commit list from the DB, or null when not cached.
	 *
	 * @since 1.0.0
	 * @param int    $installation_id Primary key of the gitwire_installations row.
	 * @param string $branch          Branch name.
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function get_cached_commits( int $installation_id, string $branch ): ?array {
		$row = Commit::instance()->find( $installation_id, $branch );
		if ( ! $row ) {
			return null;
		}
		$commits = json_decode( $row['data'], true );
		return is_array( $commits ) && $commits ? $commits : null;
	}

	/**
	 * Writes or replaces the commit cache for a repo/branch.
	 *
	 * @since 1.0.0
	 * @param int                              $installation_id Primary key of the gitwire_installations row.
	 * @param string                           $branch          Branch name.
	 * @param array<int, array<string, mixed>> $commits         Commit list.
	 * @return void
	 */
	private static function save_cached_commits( int $installation_id, string $branch, array $commits ): void {
		Commit::instance()->upsert( $installation_id, $branch, $commits );
	}

	/**
	 * Flags commits that recently failed active-theme fatal validation.
	 *
	 * @since 1.0.0
	 * @param array<int, array<string, mixed>> $commits   Commit list.
	 * @param string                           $provider  Git provider.
	 * @param string                           $full_name Repository full name.
	 * @param string                           $branch    Branch name.
	 * @return array<int, array<string, mixed>>
	 */
	private static function annotate_commits_with_fatal( array $commits, string $provider, string $full_name, string $branch ): array {
		$known = Installer::get_known_fatal_remote_head( $provider, $full_name, $branch );
		if ( ! $known ) {
			return $commits;
		}

		foreach ( $commits as $i => $commit ) {
			if ( ( $commit['sha'] ?? '' ) === $known ) {
				$commits[ $i ]['has_fatal_error'] = true;
			}
		}

		return $commits;
	}

	/**
	 * Stores the head SHA and the commit list for a branch after an install or pull.
	 *
	 * Fire-and-forget: failures are ignored, the next sync re-resolves.
	 *
	 * @since 1.0.0
	 * @param string      $owner         Repository owner.
	 * @param string      $repo          Repository name.
	 * @param string      $branch        Branch name.
	 * @param string      $provider      Git provider: 'github', 'gitlab', or 'bitbucket'.
	 * @param string|null $connection_id Connection ID used for the install.
	 * @return void
	 */
	private static function store_head( string $owner, string $repo, string $branch, string $provider, ?string $connection_id = null ): void {
		$record = Installer::get_record( $provider, $owner . '/' . $repo );
		if ( ! $record ) {
			return;
		}

		$api     = self::make_api( $provider, $connection_id );
		$commits = $api->get_commits( $owner, $repo, $branch );
		if ( is_wp_error( $commits ) || empty( $commits ) ) {
			return;
		}

		// Use one fetch because its newest entry provides the requested head commit.
		Installer::set_head( $provider, $owner . '/' . $repo, $commits[0]['sha'] );
		self::save_cached_commits( $record['id'], $branch, $commits );
	}

	/**
	 * Registers /install and /installed routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		$namespace = REST::NAMESPACE;

		register_rest_route(
			$namespace,
			'/install',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'install' ],
				'permission_callback' => [ REST::class, 'can_install' ],
				'args'                => [
					'owner'         => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'repo'          => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'branch'        => [
						'type'              => 'string',
						'default'           => 'main',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'slug'          => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_file_name',
					],
					'replace'       => [
						'type'    => 'boolean',
						'default' => false,
					],
					'force_type'    => [
						'type'    => 'boolean',
						'default' => false,
					],
					'connection_id' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'type'          => [
						'type'    => 'string',
						'default' => 'plugin',
						'enum'    => [ 'plugin', 'theme', 'block-theme', 'classic-theme' ],
					],
					'provider'      => [
						'type'    => 'string',
						'default' => 'github',
						'enum'    => [ 'github', 'gitlab', 'bitbucket' ],
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/check-slug',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'check_slug' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
				'args'                => [
					'slug' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_file_name',
					],
					'type' => [
						'type'    => 'string',
						'default' => 'plugin',
						'enum'    => [ 'plugin', 'theme', 'block-theme', 'classic-theme' ],
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/installed/sync',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'sync_installed' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$namespace,
			'/installed',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_installed' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$namespace,
			'/installed/(?P<id>\\d+)/branch',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'switch_branch' ],
				'permission_callback' => [ REST::class, 'can_write_installed' ],
				'args'                => [
					'branch'        => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'connection_id' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/installed/(?P<id>\\d+)/activate',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'activate_installed' ],
				'permission_callback' => [ REST::class, 'can_activate' ],
			]
		);

		register_rest_route(
			$namespace,
			'/installed/(?P<id>\\d+)/deactivate',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'deactivate_installed' ],
				'permission_callback' => [ REST::class, 'can_activate' ],
			]
		);

		register_rest_route(
			$namespace,
			'/installed/(?P<id>\\d+)/commits',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_commits' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$namespace,
			'/installed/(?P<id>\\d+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'remove_installed' ],
				'permission_callback' => [ REST::class, 'can_delete_installed' ],
			]
		);

		register_rest_route(
			$namespace,
			'/installed/(?P<id>\\d+)/untrack',
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'untrack_installed' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$namespace,
			'/installed/(?P<id>\\d+)/auto-update',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'save_auto_update' ],
				'permission_callback' => [ REST::class, 'can_toggle_auto_update' ],
				'args'                => [
					'auto_update' => [
						'required' => true,
						'type'     => 'string',
						'enum'     => [ 'disabled', 'current', 'any' ],
					],
				],
			]
		);
	}

	/**
	 * Installs a repository as a plugin or theme.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error Installed record on success, WP_Error on failure.
	 */
	public static function install( \WP_REST_Request $req ): array|\WP_Error {
		$owner      = (string) $req->get_param( 'owner' );
		$repo       = (string) $req->get_param( 'repo' );
		$branch     = sanitize_text_field( $req->get_param( 'branch' ) ?? 'main' );
		$type       = (string) $req->get_param( 'type' );
		$slug       = sanitize_file_name( $req->get_param( 'slug' ) ?? '' );
		$replace    = (bool) $req->get_param( 'replace' );
		$force_type = (bool) $req->get_param( 'force_type' );

		if ( ! $owner || ! $repo ) {
			return new \WP_Error( 'missing_params', __( 'Missing owner or repo.', 'gitwire' ), [ 'status' => 400 ] );
		}

		$settings      = Settings::get_raw();
		$smart_install = $settings['smart_install'] ?? true;
		$connection_id = sanitize_text_field( $req->get_param( 'connection_id' ) ?? '' );
		$connection_id = '' !== $connection_id ? $connection_id : null;

		if ( null !== $connection_id ) {
			$scope_error = REST::assert_connection_scope( $connection_id );
			if ( null !== $scope_error ) {
				return $scope_error;
			}
			// Unknown or stale connection IDs use the public installation path.
			if ( ! Connection_Resolver::find( $connection_id ) ) {
				$connection_id = null;
			}
		}

		if ( $smart_install && ! $force_type ) {
			$provider = (string) $req->get_param( 'provider' );

			$api      = self::make_api( $provider, $connection_id );
			$detected = $api->detect_type( $owner, $repo, $branch );

			if ( is_wp_error( $detected ) ) {
				return new \WP_Error(
					'detect_failed',
					__( 'Could not verify repository type. Disable Smart Install or retry.', 'gitwire' ),
					[ 'status' => 400 ]
				);
			}

			$detected_type = $detected['type'] ?? 'unknown';
			if ( 'unknown' === $detected_type ) {
				return new \WP_Error(
					'unknown_type',
					__( 'This repository is not detected as a WordPress plugin or theme.', 'gitwire' ),
					[ 'status' => 400 ]
				);
			}

			$is_theme = static fn( string $t ) => in_array( $t, [ 'theme', 'block-theme', 'classic-theme' ], true );
			if ( $is_theme( $detected_type ) !== $is_theme( $type ) ) {
				return new \WP_Error(
					'type_mismatch',
					sprintf(
						/* translators: 1: detected type, 2: requested type */
						__( 'Repository detected as %1$s, not %2$s.', 'gitwire' ),
						$detected_type,
						$type
					),
					[ 'status' => 400 ]
				);
			}
		}

		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$provider = (string) $req->get_param( 'provider' );

		$method    = in_array( $type, [ 'theme', 'block-theme', 'classic-theme' ], true ) ? 'install_theme' : 'install_plugin';
		$is_update = null !== Installer::get_record( $provider, $owner . '/' . $repo );
		$result    = Installer::$method( $owner, $repo, $branch, $slug, $provider, $replace, $connection_id );

		if ( is_wp_error( $result ) ) {
			Logger::log( sprintf( '[%s] %s failed for %s/%s: %s', $provider, $is_update ? 'Update' : 'Install', $owner, $repo, $result->get_error_message() ), 'error' );
			return $result;
		}

		unset( $result['_evicted'] );

		self::store_head( $owner, $repo, $branch, $provider, $connection_id );

		if ( $is_update ) {
			Logger::log( sprintf( '[%s] Updated %s/%s (%s) on branch %s', $provider, $owner, $repo, $type, $branch ) );
		} else {
			Logger::log( sprintf( '[%s] Installed %s/%s as %s on branch %s', $provider, $owner, $repo, $type, $branch ) );
		}

		return $result;
	}

	/**
	 * Checks whether a directory slug is already occupied on the filesystem.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, bool> Whether the slug conflicts with an existing directory.
	 */
	public static function check_slug( \WP_REST_Request $req ): array {
		$slug = (string) $req->get_param( 'slug' );
		$type = $req->get_param( 'type' ) ?? 'plugin';

		$path = in_array( $type, [ 'theme', 'block-theme', 'classic-theme' ], true )
			? get_theme_root() . '/' . $slug
			: WP_PLUGIN_DIR . '/' . $slug;

		return [ 'conflict' => is_dir( $path ) ];
	}

	/**
	 * Returns all currently installed repository records.
	 *
	 * Not read-only despite being a GET: records whose directory has disappeared are
	 * pruned here, and the queued-orphan notices are consumed. Both are reported back
	 * so the client can tell the user what happened.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Installed records and any orphaned entries.
	 */
	public static function get_installed(): array {
		$records  = Installer::get_installed();
		$orphaned = [];

		foreach ( $records as $key => $rec ) {
			if ( ! empty( $rec['install_path'] ) && ! is_dir( $rec['install_path'] ) ) {
				$orphaned[] = [
					'full_name' => $rec['full_name'] ?? '',
					'provider'  => $rec['provider'] ?? 'github',
				];
				Installer::delete_record( $rec['provider'] ?? 'github', $rec['full_name'] ?? '' );
				unset( $records[ $key ] );
			}
		}

		$pending_orphans = get_option( 'gitwire_orphan_queue' );
		if ( is_array( $pending_orphans ) && $pending_orphans ) {
			delete_option( 'gitwire_orphan_queue' );
			foreach ( $pending_orphans as $item ) {
				$orphaned[] = $item;
			}
		}

		return [
			'installed' => self::annotate_installed( $records ),
			'orphaned'  => $orphaned,
		];
	}

	/**
	 * Prunes records whose directory is gone and re-finds missing plugin entry files.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Synced installed records and any orphaned entries.
	 */
	public static function sync_installed(): array {
		$records  = Installer::get_installed();
		$orphaned = [];

		foreach ( $records as $key => &$rec ) {
			if ( empty( $rec['provider'] ) || ! in_array( $rec['provider'], [ 'github', 'gitlab', 'bitbucket' ], true ) ) {
				$rec['provider'] = 'github';
			}

			if ( ! empty( $rec['install_path'] ) && ! is_dir( $rec['install_path'] ) ) {
				$orphaned[] = [
					'full_name' => $rec['full_name'],
					'provider'  => $rec['provider'],
				];
				Installer::delete_record( $rec['provider'], $rec['full_name'] );
				unset( $records[ $key ] );
				continue;
			}

			if ( 'plugin' === ( $rec['type'] ?? '' ) && empty( $rec['basename'] ) && ! empty( $rec['install_path'] ) ) {
				$found = Installer::find_plugin_file( $rec['install_path'], $rec['name'] ?? '' );
				if ( $found ) {
					$rec['basename'] = $found;
					Installer::set_plugin_file( $rec['provider'] ?? 'github', $rec['full_name'] ?? '', $found );
				}
			}
		}
		unset( $rec );

		return [
			'installed' => self::annotate_installed( $records ),
			'orphaned'  => $orphaned,
		];
	}

	/**
	 * Annotates installed records with live active state and update availability.
	 *
	 * @since 1.0.0
	 * @param array<string, array<string, mixed>> $records Raw installed records.
	 * @return array<string, array<string, mixed>>
	 */
	public static function annotate_installed( array $records ): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_theme  = get_stylesheet();
		$pending       = self::get_running_task();
		$pending_guard = is_array( $pending )
			&& in_array( $pending['context'] ?? '', [ 'activation', 'update' ], true );

		$all_connections = [];
		foreach ( Connection_Resolver::all() as $conn ) {
			$all_connections[ $conn['id'] ] = true;
		}

		foreach ( $records as $key => &$rec ) {
			if ( empty( $rec['provider'] ) || ! in_array( $rec['provider'], [ 'github', 'gitlab', 'bitbucket' ], true ) ) {
				$rec['provider'] = 'github';
			}

			// Without Pro connections, updates use the public installation path.
			$conn_id = $rec['connection_id'] ?? null;
			if ( $conn_id && ! empty( $all_connections ) && ! isset( $all_connections[ $conn_id ] ) ) {
				$rec['needs_reconnect'] = true;
			}

			if ( 'plugin' === ( $rec['type'] ?? '' ) ) {
				$rec['active'] = ! empty( $rec['basename'] ) && is_plugin_active( $rec['basename'] );
			} else {
				$rec['active'] = ( $rec['name'] ?? '' ) === $active_theme;
			}

			if (
				$pending_guard
				&& ( $pending['full_name'] ?? '' ) === ( $rec['full_name'] ?? '' )
			) {
				$rec['activation_pending'] = true;
				if (
					Repository_Detector::is_theme( $rec['type'] ?? '' )
					&& 'activation' === ( $pending['context'] ?? '' )
				) {
					$rec['active'] = false;
				}

				if (
					'update' === ( $pending['context'] ?? '' )
					&& is_array( $pending['prev_record'] ?? null )
				) {
					if ( ! empty( $pending['prev_record']['head'] ) ) {
						$rec['head'] = $pending['prev_record']['head'];
					}
					if ( ! empty( $pending['was_active_theme'] ) ) {
						$rec['active'] = true;
					}
					$rec['update_available'] = false;
					continue;
				}
			}

			$remote_head = $rec['remote_head'] ?? '';
			if ( '' !== $remote_head ) {
				$rec['update_available'] = ! empty( $rec['head'] ) && $remote_head !== $rec['head'];
				if (
					! empty( $rec['active'] )
					&& $rec['update_available']
				) {
					$known = Installer::get_known_fatal_remote_head(
						$rec['provider'],
						$rec['full_name'] ?? '',
						$rec['branch'] ?? ''
					);
					if ( $known && $known === $remote_head ) {
						$rec['known_fatal_head'] = $known;
					}
				}
			}
		}
		unset( $rec );

		return $records;
	}

	/**
	 * Activates an installed plugin or switches to an installed theme.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, bool>|\WP_Error Success data or WP_Error on failure.
	 */
	public static function activate_installed( \WP_REST_Request $req ): array|\WP_Error {
		$record = Installer::get_record_by_id( (int) $req->get_param( 'id' ) );

		if ( ! $record ) {
			return new \WP_Error( 'gitwire_not_found', __( 'Repository is not installed.', 'gitwire' ), [ 'status' => 404 ] );
		}

		$provider  = (string) $record['provider'];
		$full_name = (string) $record['full_name'];
		$owner     = (string) $record['owner'];
		$repo      = (string) $record['repo'];

		Error_Handler::clear_stale_activation_guard();

		try {
			$result = Installer::activate( $provider, $full_name );
		} catch ( \Throwable $e ) {
			// The guard was armed before activation, so clean up before returning.
			Error_Handler::abort_pending_guard();
			Logger::log(
				sprintf(
					'[%s] Activation failed for %s/%s: %s: %s in %s on line %d',
					$provider,
					$owner,
					$repo,
					get_class( $e ),
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				),
				'error'
			);
			return new \WP_Error(
				'gitwire_activation_fatal',
				__( 'Plugin could not be activated because it triggered a fatal error.', 'gitwire' ),
				[ 'status' => 500 ]
			);
		}

		if ( is_wp_error( $result ) ) {
			Logger::log( sprintf( '[%s] Activation failed for %s/%s: %s', $provider, $owner, $repo, $result->get_error_message() ), 'error' );
			return $result;
		}

		Logger::log( sprintf( '[%s] Activated %s/%s (%s)', $provider, $owner, $repo, $record['type'] ?? 'plugin' ) );

		return [ 'activated' => true ];
	}

	/**
	 * Deactivates an installed plugin.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, bool>|\WP_Error Success data or WP_Error on failure.
	 */
	public static function deactivate_installed( \WP_REST_Request $req ): array|\WP_Error {
		$record = Installer::get_record_by_id( (int) $req->get_param( 'id' ) );

		if ( ! $record ) {
			return new \WP_Error( 'gitwire_not_found', __( 'Repository is not installed.', 'gitwire' ), [ 'status' => 404 ] );
		}

		$provider  = (string) $record['provider'];
		$full_name = (string) $record['full_name'];
		$owner     = (string) $record['owner'];
		$repo      = (string) $record['repo'];
		$result    = Installer::deactivate( $provider, $full_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Logger::log( sprintf( '[%s] Deactivated %s/%s (%s)', $provider, $owner, $repo, $record['type'] ?? 'plugin' ) );

		return [ 'deactivated' => true ];
	}

	/**
	 * Switches the active branch for an installed repository.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error Updated record on success, WP_Error on failure.
	 */
	public static function switch_branch( \WP_REST_Request $req ): array|\WP_Error {
		$record = Installer::get_record_by_id( (int) $req->get_param( 'id' ) );

		if ( ! $record ) {
			return new \WP_Error( 'gitwire_not_found', __( 'Repository is not installed.', 'gitwire' ), [ 'status' => 404 ] );
		}

		$owner  = (string) $record['owner'];
		$repo   = (string) $record['repo'];
		$branch = sanitize_text_field( $req->get_param( 'branch' ) ?? '' );

		if ( ! $branch ) {
			return new \WP_Error( 'missing_branch', __( 'Branch is required.', 'gitwire' ), [ 'status' => 400 ] );
		}

		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$provider    = (string) $record['provider'];
		$full_name   = (string) $record['full_name'];
		$override_id = sanitize_text_field( $req->get_param( 'connection_id' ) ?? '' );
		$override_id = '' !== $override_id ? $override_id : null;

		if ( null !== $override_id ) {
			$scope_error = REST::assert_connection_scope( $override_id );
			if ( null !== $scope_error ) {
				return $scope_error;
			}
		}

		$existing_record = $record;
		$is_pull         = ( $record['branch'] ?? '' ) === $branch;

		$result = Installer::switch_branch( $provider, $full_name, $branch, $override_id );

		if ( is_wp_error( $result ) ) {
			$action = $is_pull ? 'Pull' : 'Switch branch';
			Logger::log( sprintf( '[%s] %s failed for %s/%s: %s', $provider, $action, $owner, $repo, $result->get_error_message() ), 'error' );
			return $result;
		}

		$stored_conn_id = $override_id ?? ( $existing_record['connection_id'] ?? null );
		self::store_head( $owner, $repo, $branch, $provider, $stored_conn_id );

		if ( ! $is_pull ) {
			// Clear the previous branch's remote head so sync_installed() resolves it again.
			Installer::set_remote_head( $provider, $full_name, '' );
		}

		$type = $existing_record['type'] ?? 'plugin';
		if ( $is_pull ) {
			Logger::log( sprintf( '[%s] Pulled %s/%s (%s) on branch %s', $provider, $owner, $repo, $type, $branch ) );
		} else {
			Logger::log( sprintf( '[%s] Switched %s/%s (%s) to branch %s', $provider, $owner, $repo, $type, $branch ) );
		}

		return $result;
	}

	/**
	 * Removes an installed repository.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, bool>|\WP_Error Success data or WP_Error on failure.
	 */
	public static function remove_installed( \WP_REST_Request $req ): array|\WP_Error {
		$record = Installer::get_record_by_id( (int) $req->get_param( 'id' ) );

		if ( ! $record ) {
			return new \WP_Error( 'gitwire_not_found', __( 'Repository is not installed.', 'gitwire' ), [ 'status' => 404 ] );
		}

		$provider  = (string) $record['provider'];
		$full_name = (string) $record['full_name'];
		$owner     = (string) $record['owner'];
		$repo      = (string) $record['repo'];

		if ( 'plugin' === ( $record['type'] ?? '' ) ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_file = $record['basename'] ?? '';
			if ( $plugin_file && is_plugin_active( $plugin_file ) ) {
				return new \WP_Error(
					'gitwire_active',
					__( 'Deactivate the plugin before removing it.', 'gitwire' ),
					[ 'status' => 409 ]
				);
			}
		}

		if ( Repository_Detector::is_theme( $record['type'] ?? '' ) ) {
			$slug           = $record['name'] ?? '';
			$active_theme   = get_stylesheet();
			$template_theme = get_template();
			if ( $slug && ( $slug === $active_theme || $slug === $template_theme ) ) {
				return new \WP_Error(
					'gitwire_active',
					__( 'Switch to a different theme before removing it.', 'gitwire' ),
					[ 'status' => 409 ]
				);
			}
		}

		$result = Installer::remove( $provider, $full_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$pending = self::get_running_task();
		if ( is_array( $pending ) && ( $pending['full_name'] ?? '' ) === $full_name ) {
			Error_Handler::abort_pending_guard();
		}

		Logger::log( sprintf( '[%s] Uninstalled %s/%s (%s)', $provider, $owner, $repo, $record['type'] ?? 'plugin' ) );

		return [ 'removed' => true ];
	}

	/**
	 * Removes the Gitwire tracking record without deleting the files from disk.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function untrack_installed( \WP_REST_Request $req ): array|\WP_Error {
		$record = Installer::get_record_by_id( (int) $req->get_param( 'id' ) );

		if ( ! $record ) {
			return new \WP_Error( 'gitwire_not_found', __( 'Repository is not installed.', 'gitwire' ), [ 'status' => 404 ] );
		}

		$result = Installer::untrack( (string) $record['provider'], (string) $record['full_name'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [ 'untracked' => true ];
	}

	/**
	 * Saves auto-update settings for an installed repository.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error Updated values or WP_Error on failure.
	 */
	public static function save_auto_update( \WP_REST_Request $req ): array|\WP_Error {
		$record = Installer::get_record_by_id( (int) $req->get_param( 'id' ) );

		if ( ! $record ) {
			return new \WP_Error( 'gitwire_not_found', __( 'Repository is not installed.', 'gitwire' ), [ 'status' => 404 ] );
		}

		$provider    = (string) $record['provider'];
		$full_name   = (string) $record['full_name'];
		$auto_update = (string) $req->get_param( 'auto_update' );

		Installation::instance()->update_auto_update( $provider, $full_name, $auto_update );
		Installer::invalidate_installed_cache();

		return [
			'provider'    => $provider,
			'full_name'   => $full_name,
			'auto_update' => $auto_update,
		];
	}

	/**
	 * Returns the last 10 commits for an installed repository's current branch.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<int, array<string, string>>|\WP_Error Commit list or WP_Error on failure.
	 */
	public static function get_commits( \WP_REST_Request $req ): array|\WP_Error {
		$record = Installer::get_record_by_id( (int) $req->get_param( 'id' ) );

		if ( ! $record ) {
			return new \WP_Error( 'gitwire_not_found', __( 'Repository is not installed.', 'gitwire' ), [ 'status' => 404 ] );
		}

		$provider  = (string) $record['provider'];
		$full_name = (string) $record['full_name'];
		$owner     = (string) $record['owner'];
		$repo      = (string) $record['repo'];

		$installation_id = $record['id'];
		$cached          = self::get_cached_commits( $installation_id, $record['branch'] );
		if ( null !== $cached ) {
			return self::annotate_commits_with_fatal( $cached, $provider, $full_name, $record['branch'] );
		}

		$api     = self::make_api( $provider, $record['connection_id'] ?? null );
		$commits = $api->get_commits( $owner, $repo, $record['branch'] );

		if ( is_wp_error( $commits ) ) {
			return $commits;
		}

		self::save_cached_commits( $installation_id, $record['branch'], $commits );

		return self::annotate_commits_with_fatal( $commits, $provider, $full_name, $record['branch'] );
	}
}
