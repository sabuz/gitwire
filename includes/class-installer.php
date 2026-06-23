<?php
/**
 * Installer — downloads and extracts GitHub repositories as plugins or themes.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles downloading, extracting, backing up, and removing Git repositories
 * installed as WordPress plugins or themes.
 */
class Installer {

	/**
	 * Request-scoped cache for the gitwire_installations table rows.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $installed_cache = null;

	/**
	 * Clears the request-scope installed cache after a write.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function invalidate_installed_cache(): void {
		self::$installed_cache = null;
	}

	/**
	 * Returns the gitwire_installations table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function installed_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'gitwire_installations';
	}

	/**
	 * Returns the gitwire_commits table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function commits_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'gitwire_commits';
	}

	/**
	 * Converts a raw DB row into the PHP record shape used throughout the plugin.
	 *
	 * Adds owner and repo (derived from full_name) so callers never need to split.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $row Raw row from gitwire_installations.
	 * @return array<string, mixed>
	 */
	private static function hydrate_record( array $row ): array {
		$parts = explode( '/', (string) ( $row['full_name'] ?? '' ), 2 );
		return array_merge(
			$row,
			[
				'id'           => (int) ( $row['id'] ?? 0 ),
				'repo'         => $parts[1] ?? '',
				'installed_at' => $row['installed_at'] ?? '',
				'updated_at'   => $row['updated_at'] ?? '',
				'auto_update'  => $row['auto_update'] ?? 'disabled',
			]
		);
	}

	/**
	 * Deletes the commit cache for an installed repository.
	 *
	 * @since 1.0.0
	 * @param int $installation_id Primary key of the gitwire_installations row.
	 * @return void
	 */
	private static function delete_commits( int $installation_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete(
			self::commits_table(),
			[ 'installation_id' => $installation_id ],
			[ '%d' ]
		);
	}

	/**
	 * Deletes the installation record from the DB without touching the filesystem.
	 *
	 * Used by REST when pruning orphaned records and by uninstall cleanup.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @return void
	 */
	public static function delete_record( string $provider, string $full_name ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$installation_id = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . self::installed_table() . ' WHERE provider = %s AND full_name = %s', $provider, $full_name )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete(
			self::installed_table(),
			[
				'provider'  => $provider,
				'full_name' => $full_name,
			],
			[ '%s', '%s' ]
		);
		if ( $installation_id ) {
			self::delete_commits( $installation_id );
		}
		self::invalidate_installed_cache();
	}

	/**
	 * Upserts an installation record without eviction or head merging.
	 *
	 * Used by Error_Handler to restore a prev_record after a failed update.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $record Record to restore.
	 * @return void
	 */
	public static function upsert_record( array $record ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::installed_table() . '
					(connection_id, provider, owner, slug, full_name, type, branch, head, remote_head, install_path, plugin_file, installed_at, updated_at)
				VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					connection_id = VALUES(connection_id), slug = VALUES(slug),
					type = VALUES(type), branch = VALUES(branch), head = VALUES(head),
					install_path = VALUES(install_path), plugin_file = VALUES(plugin_file),
					updated_at = VALUES(updated_at)',
				$record['connection_id'] ?? '',
				$record['provider'] ?? '',
				$record['owner'] ?? '',
				$record['slug'] ?? '',
				$record['full_name'] ?? '',
				$record['type'] ?? 'plugin',
				$record['branch'] ?? 'main',
				$record['head'] ?? '',
				$record['remote_head'] ?? '',
				$record['install_path'] ?? '',
				$record['plugin_file'] ?? '',
				$record['installed_at'] ?? current_time( 'mysql' ),
				current_time( 'mysql' )
			)
		);

		self::invalidate_installed_cache();
	}

	/**
	 * Stores the remote HEAD SHA for a repository in the installed table.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @param string $sha       Remote commit SHA.
	 * @return void
	 */
	public static function set_remote_head( string $provider, string $full_name, string $sha ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			self::installed_table(),
			[ 'remote_head' => $sha ],
			[
				'provider'  => $provider,
				'full_name' => $full_name,
			],
			[ '%s' ],
			[ '%s', '%s' ]
		);
		self::invalidate_installed_cache();
	}

	/**
	 * Updates the plugin_file column for a single installed record.
	 *
	 * @since 1.0.0
	 * @param string $provider    Git provider.
	 * @param string $full_name   Repository full name.
	 * @param string $plugin_file Relative plugin bootstrap path.
	 * @return void
	 */
	public static function set_plugin_file( string $provider, string $full_name, string $plugin_file ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			self::installed_table(),
			[ 'plugin_file' => $plugin_file ],
			[
				'provider'  => $provider,
				'full_name' => $full_name,
			],
			[ '%s' ],
			[ '%s', '%s' ]
		);
		self::invalidate_installed_cache();
	}


	/**
	 * Registers hooks that clean up installation records when a plugin or theme
	 * is deleted through the standard WordPress interface.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init(): void {
		add_action( 'deleted_plugin', [ self::class, 'on_plugin_deleted' ], 10, 2 );
		add_action( 'deleted_theme', [ self::class, 'on_theme_deleted' ], 10, 2 );
	}

	/**
	 * Removes the installation record when a plugin is deleted via WordPress.
	 *
	 * @since 1.0.0
	 * @param string $plugin_file Plugin file relative to the plugins directory.
	 * @param bool   $deleted     Whether the plugin was successfully deleted.
	 * @return void
	 */
	public static function on_plugin_deleted( string $plugin_file, bool $deleted ): void {
		if ( ! $deleted ) {
			return;
		}

		global $wpdb;
		$deleted_dir = untrailingslashit( WP_PLUGIN_DIR ) . '/' . dirname( $plugin_file );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::installed_table() . ' WHERE install_path = %s LIMIT 1', $deleted_dir ),
			ARRAY_A
		);

		if ( ! $row ) {
			return;
		}

		self::queue_deleted_notice( self::hydrate_record( $row ) );
		self::delete_record( $row['provider'], $row['full_name'] );
	}

	/**
	 * Removes the installation record when a theme is deleted via WordPress.
	 *
	 * @since 1.0.0
	 * @param string $stylesheet Theme stylesheet (directory name).
	 * @param bool   $deleted    Whether the theme was successfully deleted.
	 * @return void
	 */
	public static function on_theme_deleted( string $stylesheet, bool $deleted ): void {
		if ( ! $deleted ) {
			return;
		}

		global $wpdb;
		$deleted_dir = untrailingslashit( get_theme_root() ) . '/' . $stylesheet;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::installed_table() . ' WHERE install_path = %s LIMIT 1', $deleted_dir ),
			ARRAY_A
		);

		if ( ! $row ) {
			return;
		}

		self::queue_deleted_notice( self::hydrate_record( $row ) );
		self::delete_record( $row['provider'], $row['full_name'] );
	}

	/**
	 * Queues a deleted-record notice to surface on the next Gitwire page load.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $rec Installed record being removed.
	 * @return void
	 */
	private static function queue_deleted_notice( array $rec ): void {
		$existing  = get_option( 'gitwire_orphan_queue' );
		$pending   = is_array( $existing ) ? $existing : [];
		$pending[] = [
			'full_name' => $rec['full_name'] ?? '',
			'provider'  => $rec['provider'] ?? 'github',
		];
		update_option( 'gitwire_orphan_queue', $pending, false );
	}

	/**
	 * Installs or updates a repository as a WordPress plugin.
	 *
	 * @since 1.0.0
	 * @param string      $owner         Git owner or organisation.
	 * @param string      $repo          Repository name.
	 * @param string      $branch        Branch, tag, or SHA.
	 * @param string      $slug          Desired directory slug (defaults to sanitised repo name).
	 * @param string      $provider      Git provider: 'github', 'gitlab', or 'bitbucket'.
	 * @param bool        $replace       Whether to overwrite an existing directory instead of auto-renaming.
	 * @param string|null $connection_id Optional connection ID to use for authenticated requests.
	 * @return array<string, mixed>|WP_Error Installed record on success, WP_Error on failure.
	 */
	public static function install_plugin(
		string $owner,
		string $repo,
		string $branch,
		string $slug = '',
		string $provider = 'github',
		bool $replace = false,
		?string $connection_id = null
	): array|\WP_Error {
		if ( ! $slug ) {
			$slug = sanitize_title( $repo );
		}

		$destination = WP_PLUGIN_DIR . '/' . $slug;

		return self::run( $owner, $repo, $branch, $slug, $destination, 'plugin', $provider, $replace, $connection_id );
	}

	/**
	 * Installs or updates a repository as a WordPress theme.
	 *
	 * @since 1.0.0
	 * @param string      $owner         Git owner or organisation.
	 * @param string      $repo          Repository name.
	 * @param string      $branch        Branch, tag, or SHA.
	 * @param string      $slug          Desired directory slug (defaults to sanitised repo name).
	 * @param string      $provider      Git provider: 'github', 'gitlab', or 'bitbucket'.
	 * @param bool        $replace       Whether to overwrite an existing directory instead of auto-renaming.
	 * @param string|null $connection_id Optional connection ID to use for authenticated requests.
	 * @return array<string, mixed>|WP_Error Installed record on success, WP_Error on failure.
	 */
	public static function install_theme(
		string $owner,
		string $repo,
		string $branch,
		string $slug = '',
		string $provider = 'github',
		bool $replace = false,
		?string $connection_id = null
	): array|\WP_Error {
		if ( ! $slug ) {
			$slug = sanitize_title( $repo );
		}

		$destination = get_theme_root() . '/' . $slug;

		return self::run( $owner, $repo, $branch, $slug, $destination, 'theme', $provider, $replace, $connection_id );
	}

	/**
	 * Switches the active branch for an already-installed repository.
	 *
	 * @since 1.0.0
	 * @param string      $provider              Git provider: 'github', 'gitlab', or 'bitbucket'.
	 * @param string      $full_name             Repository full name (owner/repo).
	 * @param string      $new_branch            Branch to switch to.
	 * @param string|null $override_connection_id Bypass stored connection and use this ID instead.
	 * @return array<string, mixed>|WP_Error Updated record on success, WP_Error on failure.
	 */
	public static function switch_branch( string $provider, string $full_name, string $new_branch, ?string $override_connection_id = null ): array|\WP_Error {
		$installed = self::get_installed();
		$key       = $provider . ':' . $full_name;

		if ( ! isset( $installed[ $key ] ) ) {
			return new \WP_Error( 'gitwire_not_found', 'Repository is not installed.' );
		}

		$rec       = $installed[ $key ];
		$owner     = $rec['owner'];
		$repo      = $rec['repo'];
		$method    = Repo_Detector::is_theme( $rec['type'] ) ? 'install_theme' : 'install_plugin';
		$was_stale = false;
		if ( null !== $override_connection_id ) {
			$connection_id = $override_connection_id;
		} else {
			$connection_id = $rec['connection_id'] ?? null;
			if ( null !== $connection_id && null === Connection_Resolver::get_credentials( $connection_id ) ) {
				$provider_conns = array_values(
					array_filter( Connection_Resolver::all(), static fn( $c ) => ( $c['provider'] ?? '' ) === $provider )
				);
				if ( 0 === count( $provider_conns ) ) {
					// No connection system (or none left) — try the public path.
					$connection_id = null;
				} elseif ( 1 !== count( $provider_conns ) ) {
					return new \WP_Error(
						'gitwire_no_connection',
						'The connection used to install this repository no longer exists. Use the Reconnect action to select an account.',
						[ 'status' => 400 ]
					);
				} else {
					$connection_id = $provider_conns[0]['id'];
					$was_stale     = true;
				}
			}
		}

		if ( null !== $connection_id && null === Connection_Resolver::get_credentials( $connection_id ) ) {
			return new \WP_Error(
				'gitwire_no_connection',
				'The connection used to install this repository no longer exists. Use the Reconnect action to select an account.',
				[ 'status' => 400 ]
			);
		}

		$result = self::$method( $owner, $repo, $new_branch, $rec['slug'], $provider, false, $connection_id );

		if ( $was_stale && is_wp_error( $result ) ) {
			return new \WP_Error(
				'gitwire_no_connection',
				'The connection used to install this repository no longer exists. Use the Reconnect action to select an account.',
				[ 'status' => 400 ]
			);
		}

		return $result;
	}

	/**
	 * Removes an installed repository from the filesystem and the installation record.
	 * Does NOT deactivate the plugin or theme first.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider: 'github', 'gitlab', or 'bitbucket'.
	 * @param string $full_name Repository full name (owner/repo).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function remove( string $provider, string $full_name ): bool|\WP_Error {
		$rec = self::get_record( $provider, $full_name );

		if ( ! $rec ) {
			return new \WP_Error( 'gitwire_not_found', 'Repository is not installed.' );
		}

		$path = $rec['install_path'];
		if ( is_dir( $path ) ) {
			self::init_fs();
			global $wp_filesystem;
			$wp_filesystem->delete( $path, true );
		}

		self::delete_record( $provider, $full_name );

		return true;
	}

	/**
	 * Removes the tracking record for a repository without deleting its files.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider key.
	 * @param string $full_name Repository full name (owner/repo).
	 * @return true|\WP_Error True on success, WP_Error when not found.
	 */
	public static function untrack( string $provider, string $full_name ): bool|\WP_Error {
		if ( ! self::get_record( $provider, $full_name ) ) {
			return new \WP_Error( 'gitwire_not_found', 'Repository is not installed.' );
		}

		self::delete_record( $provider, $full_name );

		return true;
	}

	/**
	 * Activates an installed plugin or switches to an installed theme.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider: 'github', 'gitlab', or 'bitbucket'.
	 * @param string $full_name Repository full name (owner/repo).
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public static function activate( string $provider, string $full_name ): bool|\WP_Error {
		$installed = self::get_installed();
		$key       = $provider . ':' . $full_name;

		if ( ! isset( $installed[ $key ] ) ) {
			return new \WP_Error( 'gitwire_not_found', 'Repository is not installed.' );
		}

		$rec = $installed[ $key ];

		if ( 'plugin' === $rec['type'] ) {
			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugin_file = $rec['plugin_file'] ?? null;

			// Self-heal: re-scan when file is missing or path is stale/wrong.
			if ( ! $plugin_file || ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				if ( ! empty( $rec['install_path'] ) && is_dir( $rec['install_path'] ) ) {
					$plugin_file = self::find_plugin_file( $rec['install_path'], $rec['slug'] );
					if ( $plugin_file ) {
						global $wpdb;
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
						$wpdb->update(
							self::installed_table(),
							[ 'plugin_file' => $plugin_file ],
							[
								'provider'  => $provider,
								'full_name' => $full_name,
							],
							[ '%s' ],
							[ '%s', '%s' ]
						);
						self::invalidate_installed_cache();
					}
				}
			}

			if ( ! $plugin_file ) {
				return new \WP_Error(
					'gitwire_no_plugin_file',
					'Could not locate the plugin entry file. Try using "Pull latest" to re-sync.',
					[ 'status' => 500 ]
				);
			}

			self::begin_activation_guard( $rec, $full_name, $plugin_file );
			$result = activate_plugin( $plugin_file );

			if ( is_wp_error( $result ) ) {
				self::clear_activation_guard();
				self::maybe_mark_known_fatal_from_activation(
					$provider,
					$full_name,
					$rec['branch'] ?? 'main',
					$result,
					$rec
				);
				return $result;
			}

			self::complete_plugin_activation_guard();
		} elseif ( Repo_Detector::is_theme( $rec['type'] ) ) {
			Error_Handler::clear_stale_activation_guard();
			self::refresh_theme_runtime( $rec['install_path'] ?? '', $rec['slug'] ?? '' );

			$ready = self::validate_theme_for_activation( $rec );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			$pending = self::begin_activation_guard( $rec, $full_name );

			$requirements = validate_theme_requirements( $rec['slug'] );
			if ( is_wp_error( $requirements ) ) {
				self::clear_activation_guard();
				return new \WP_Error(
					'gitwire_theme_requirements',
					wp_strip_all_tags( $requirements->get_error_message() ),
					[ 'status' => 400 ]
				);
			}

			switch_theme( $rec['slug'] );
			self::refresh_theme_runtime( $rec['install_path'] ?? '', $rec['slug'] ?? '' );

			delete_option( 'gitwire_running_task' );

			$scrape = Theme_Scraper::scrape_activation();
			if ( is_wp_error( $scrape ) ) {
				Error_Handler::revert_failed_theme_activation( $pending );
				self::maybe_mark_known_fatal_from_scrape(
					$provider,
					$full_name,
					$rec['branch'] ?? 'main',
					$scrape,
					$rec
				);
				return $scrape;
			}

			self::clear_guard_feedback();
		}

		return true;
	}

	/**
	 * Registers a pending activation record for the fatal-error shutdown handler.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $rec         Installed repository record.
	 * @param string               $full_name   Repository full name.
	 * @param string|null          $plugin_file Plugin bootstrap file, if any.
	 * @return array<string, mixed> Pending guard record.
	 */
	private static function begin_activation_guard( array $rec, string $full_name, ?string $plugin_file = null ): array {
		$pending = [
			'context'             => 'activation',
			'full_name'           => $full_name,
			'type'                => $rec['type'],
			'slug'                => $rec['slug'] ?? '',
			'install_path'        => $rec['install_path'] ?? '',
			'plugin_file'         => $plugin_file,
			'previous_stylesheet' => get_stylesheet(),
			'previous_template'   => get_template(),
		];

		if ( Repo_Detector::is_theme( $rec['type'] ) ) {
			$pending['target_stylesheet'] = $rec['slug'];
		}

		self::clear_guard_feedback();
		update_option( 'gitwire_running_task', $pending, false );

		return $pending;
	}

	/**
	 * Clears a pending activation guard when activation fails before bootstrap.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function clear_activation_guard(): void {
		delete_option( 'gitwire_running_task' );
	}

	/**
	 * Clears the activation guard after core has sandboxed a plugin activation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function complete_plugin_activation_guard(): void {
		delete_option( 'gitwire_running_task' );
	}

	/**
	 * Deactivates an installed plugin. Themes cannot be deactivated this way.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider: 'github', 'gitlab', or 'bitbucket'.
	 * @param string $full_name Repository full name (owner/repo).
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public static function deactivate( string $provider, string $full_name ): bool|\WP_Error {
		$installed = self::get_installed();
		$key       = $provider . ':' . $full_name;

		if ( ! isset( $installed[ $key ] ) ) {
			return new \WP_Error( 'gitwire_not_found', 'Repository is not installed.' );
		}

		$rec = $installed[ $key ];

		if ( 'plugin' !== $rec['type'] ) {
			return new \WP_Error( 'gitwire_unsupported', 'Only plugins can be deactivated this way.', [ 'status' => 400 ] );
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = $rec['plugin_file'] ?? null;

		// Self-heal: re-scan when file is missing or path is stale/wrong.
		if ( ! $plugin_file || ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			if ( ! empty( $rec['install_path'] ) && is_dir( $rec['install_path'] ) ) {
				$plugin_file = self::find_plugin_file( $rec['install_path'], $rec['slug'] );
				if ( $plugin_file ) {
					global $wpdb;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->update(
						self::installed_table(),
						[ 'plugin_file' => $plugin_file ],
						[
							'provider'  => $provider,
							'full_name' => $full_name,
						],
						[ '%s' ],
						[ '%s', '%s' ]
					);
					self::invalidate_installed_cache();
				}
			}
		}

		if ( ! $plugin_file ) {
			return new \WP_Error(
				'gitwire_no_plugin_file',
				'Could not locate the plugin entry file. Try using "Pull latest" to re-sync.',
				[ 'status' => 500 ]
			);
		}

		deactivate_plugins( $plugin_file );

		return true;
	}

	/**
	 * Returns all currently installed repository records, keyed by provider:full_name.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Map of "provider:full_name" => record.
	 */
	public static function get_installed(): array {
		if ( null !== self::$installed_cache ) {
			return self::$installed_cache;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::installed_table(), ARRAY_A );

		self::$installed_cache = [];
		foreach ( (array) $rows as $row ) {
			$key                           = $row['provider'] . ':' . $row['full_name'];
			self::$installed_cache[ $key ] = self::hydrate_record( $row );
		}

		return self::$installed_cache;
	}

	/**
	 * Refreshes remote_head for all installed repos, then auto-updates those with auto_update enabled.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function run_auto_updates(): void {
		$records = self::get_installed();

		// First pass: refresh remote_head for every installed repo.
		foreach ( $records as $key => $rec ) {
			$owner    = $rec['owner'] ?? '';
			$repo     = $rec['repo'] ?? '';
			$branch   = (string) ( $rec['branch'] ?? 'main' );
			$provider = (string) ( $rec['provider'] ?? 'github' );

			if ( ! $owner || ! $repo ) {
				continue;
			}

			$connection_id = $rec['connection_id'] ? $rec['connection_id'] : null;
			$api           = Provider_Factory::make( $provider, $connection_id );
			$remote_sha    = self::fetch_remote_head_sha( $api, $owner, $repo, $branch );
			$stored_remote = (string) ( $rec['remote_head'] ?? '' );

			if ( $remote_sha && $remote_sha !== $stored_remote ) {
				$full_name = (string) ( $rec['full_name'] ?? '' );
				self::set_remote_head( $provider, $full_name, $remote_sha );
				$records[ $key ]['remote_head'] = $remote_sha;
			}
		}

		// Second pass: auto-update repos that have it enabled and have a pending commit.
		foreach ( $records as $rec ) {
			if ( 'disabled' === ( $rec['auto_update'] ?? 'disabled' ) ) {
				continue;
			}

			$remote_head = (string) ( $rec['remote_head'] ?? '' );
			$head        = (string) ( $rec['head'] ?? '' );

			if ( ! $remote_head || $remote_head === $head ) {
				continue;
			}

			$owner         = $rec['owner'] ?? '';
			$repo          = $rec['repo'] ?? '';
			$branch        = (string) ( $rec['branch'] ?? 'main' );
			$provider      = (string) ( $rec['provider'] ?? 'github' );
			$slug          = (string) ( $rec['slug'] ?? '' );
			$connection_id = $rec['connection_id'] ? $rec['connection_id'] : null;
			$type          = (string) ( $rec['type'] ?? 'plugin' );
			$full_name     = (string) ( $rec['full_name'] ?? '' );

			if ( ! $owner || ! $repo ) {
				continue;
			}

			if ( Repo_Detector::is_theme( $type ) ) {
				$result = self::install_theme( $owner, $repo, $branch, $slug, $provider, true, $connection_id );
			} else {
				$result = self::install_plugin( $owner, $repo, $branch, $slug, $provider, true, $connection_id );
			}

			if ( is_wp_error( $result ) ) {
				Logger::log( 'Auto-update failed: ' . $full_name . ' — ' . $result->get_error_message(), 'error' );
			} else {
				Logger::log( 'Auto-updated: ' . $full_name . ' to ' . substr( $remote_head, 0, 7 ) );
			}
		}
	}

	/**
	 * Returns a single installation record by provider and full name.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider: 'github', 'gitlab', or 'bitbucket'.
	 * @param string $full_name Repository full name (owner/repo).
	 * @return array<string, mixed>|null Record array, or null if not found.
	 */
	public static function get_record( string $provider, string $full_name ): ?array {
		$installed = self::get_installed();
		return $installed[ $provider . ':' . $full_name ] ?? null;
	}

	/**
	 * Stores the installed HEAD commit SHA for a repository record.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider: 'github', 'gitlab', or 'bitbucket'.
	 * @param string $full_name Repository full name (owner/repo).
	 * @param string $sha       Full commit SHA.
	 * @return void
	 */
	public static function set_head( string $provider, string $full_name, string $sha ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			self::installed_table(),
			[ 'head' => $sha ],
			[
				'provider'  => $provider,
				'full_name' => $full_name,
			],
			[ '%s' ],
			[ '%s', '%s' ]
		);
		self::invalidate_installed_cache();
	}

	/**
	 * Fetches the latest remote commit SHA for a branch.
	 *
	 * @since 1.0.0
	 * @param Git_Provider_Interface $api    Provider API client.
	 * @param string                 $owner  Repository owner.
	 * @param string                 $repo   Repository name.
	 * @param string                 $branch Branch name.
	 * @return string|null Full 40-char SHA or null when unavailable.
	 */
	public static function fetch_remote_head_sha( Git_Provider_Interface $api, string $owner, string $repo, string $branch ): ?string {
		$commits = $api->get_commits( $owner, $repo, $branch, 1 );
		if ( is_wp_error( $commits ) || empty( $commits[0]['sha'] ) ) {
			return null;
		}

		return (string) $commits[0]['sha'];
	}

	/**
	 * Returns a transient key for a known-fatal remote HEAD on an active install.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @param string $branch    Branch name.
	 * @return string
	 */
	private static function known_fatal_head_key( string $provider, string $full_name, string $branch ): string {
		return 'gitwire_fatal_head_' . md5( $provider . ':' . $full_name . ':' . $branch );
	}

	/**
	 * Returns a remote SHA recently rejected by the active fatal guard.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @param string $branch    Branch name.
	 * @return string|null
	 */
	public static function get_known_fatal_remote_head( string $provider, string $full_name, string $branch ): ?string {
		$value = get_transient( self::known_fatal_head_key( $provider, $full_name, $branch ) );
		return is_string( $value ) && $value ? $value : null;
	}

	/**
	 * Returns whether a remote SHA matches the known-fatal cache entry.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @param string $branch    Branch name.
	 * @param string $remote_sha Remote commit SHA.
	 * @return bool
	 */
	public static function matches_known_fatal_remote_head( string $provider, string $full_name, string $branch, string $remote_sha ): bool {
		$known = self::get_known_fatal_remote_head( $provider, $full_name, $branch );
		return $known && $known === $remote_sha;
	}

	/**
	 * User-facing message when the latest remote commit is skipped as known-fatal.
	 *
	 * @since 1.0.0
	 * @param string $type       Installation type: plugin or theme.
	 * @param string $remote_sha Remote commit SHA.
	 * @return string
	 */
	public static function known_fatal_head_message( string $type, string $remote_sha ): string {
		$short = substr( $remote_sha, 0, 7 );
		$label = 'theme' === $type
			? __( 'theme', 'gitwire' )
			: __( 'plugin', 'gitwire' );

		return sprintf(
			/* translators: 1: short commit SHA, 2: plugin or theme */
			__(
				'The latest commit (%1$s) caused a fatal error on this active %2$s and was not pulled. Your current version was kept. Push a new commit or wait a few minutes to retry %1$s.',
				'gitwire'
			),
			$short,
			$label
		);
	}

	/**
	 * Builds the user-facing error for a failed active-install update scrape.
	 *
	 * A genuine fatal is reported with the same friendly "kept your current
	 * version" message used when a known-fatal commit is skipped, so first
	 * attempts and retries read consistently across plugins and themes.
	 * Infrastructure failures keep the scrape message that explains the revert.
	 *
	 * @since 1.0.0
	 * @param \WP_Error   $scrape     Scrape failure error.
	 * @param string      $type       Installation type: plugin or theme.
	 * @param string|null $remote_sha Remote commit SHA, if known.
	 * @param string      $provider   Git provider.
	 * @param string      $full_name  Repository full name.
	 * @param string      $branch     Branch name.
	 * @return \WP_Error
	 */
	private static function update_fatal_error( \WP_Error $scrape, string $type, ?string $remote_sha, string $provider, string $full_name, string $branch ): \WP_Error {
		$data     = $scrape->get_error_data();
		$is_fatal = is_array( $data )
			&& is_array( $data['scrape'] ?? null )
			&& Theme_Scraper::is_php_fatal_result( $data['scrape'] );

		if ( ! $is_fatal || ! $remote_sha ) {
			return $scrape;
		}

		self::mark_known_fatal_remote_head( $provider, $full_name, $branch, $remote_sha );

		return new \WP_Error(
			'gitwire_known_fatal_head',
			self::known_fatal_head_message( $type, $remote_sha ),
			[ 'status' => 409 ]
		);
	}

	/**
	 * Returns whether a plugin activation error came from core's fatal sandbox scrape.
	 *
	 * @since 1.0.0
	 * @param \WP_Error $error Activation error.
	 * @return bool
	 */
	public static function is_activation_fatal_error( \WP_Error $error ): bool {
		if ( 'php_error' !== $error->get_error_code() ) {
			return false;
		}

		$data = $error->get_error_data();
		return is_array( $data ) && Theme_Scraper::is_php_fatal_result( $data );
	}

	/**
	 * Remembers a remote SHA that failed active bootstrap validation.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @param string $branch    Branch name.
	 * @param string $sha       Remote commit SHA.
	 * @return void
	 */
	public static function mark_known_fatal_remote_head( string $provider, string $full_name, string $branch, string $sha ): void {
		set_transient(
			self::known_fatal_head_key( $provider, $full_name, $branch ),
			$sha,
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Stores a known-fatal remote SHA after a failed theme scrape.
	 *
	 * @since 1.0.0
	 * @param string               $provider  Git provider.
	 * @param string               $full_name Repository full name.
	 * @param string               $branch    Branch name.
	 * @param \WP_Error            $error     Scrape failure.
	 * @param array<string, mixed> $rec       Installed record.
	 * @return void
	 */
	private static function maybe_mark_known_fatal_from_scrape( string $provider, string $full_name, string $branch, \WP_Error $error, array $rec ): void {
		$data = $error->get_error_data();
		if (
			! is_array( $data )
			|| ! is_array( $data['scrape'] ?? null )
			|| ! Theme_Scraper::is_php_fatal_result( $data['scrape'] )
		) {
			return;
		}

		$sha = self::resolve_remote_head_for_record( $rec, $provider, $full_name, $branch );
		if ( $sha ) {
			self::mark_known_fatal_remote_head( $provider, $full_name, $branch, $sha );
		}
	}

	/**
	 * Stores a known-fatal remote SHA after a failed plugin activation scrape.
	 *
	 * @since 1.0.0
	 * @param string               $provider  Git provider.
	 * @param string               $full_name Repository full name.
	 * @param string               $branch    Branch name.
	 * @param \WP_Error            $error     Activation failure.
	 * @param array<string, mixed> $rec       Installed record.
	 * @return void
	 */
	private static function maybe_mark_known_fatal_from_activation( string $provider, string $full_name, string $branch, \WP_Error $error, array $rec ): void {
		if ( ! self::is_activation_fatal_error( $error ) ) {
			return;
		}

		$sha = self::resolve_remote_head_for_record( $rec, $provider, $full_name, $branch );
		if ( $sha ) {
			self::mark_known_fatal_remote_head( $provider, $full_name, $branch, $sha );
		}
	}

	/**
	 * Resolves the latest remote SHA for a record, falling back to the API.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $rec       Installed record.
	 * @param string               $provider  Git provider.
	 * @param string               $full_name Repository full name.
	 * @param string               $branch    Branch name.
	 * @return string|null
	 */
	private static function resolve_remote_head_for_record( array $rec, string $provider, string $full_name, string $branch ): ?string {
		$cached = $rec['remote_head'] ?? '';
		if ( '' !== $cached ) {
			return $cached;
		}

		$parts = explode( '/', $full_name, 2 );
		if ( count( $parts ) < 2 ) {
			return null;
		}

		$connection_id = $rec['connection_id'] ?? null;
		$api           = Provider_Factory::make( $provider, $connection_id );
		$sha           = self::fetch_remote_head_sha( $api, $parts[0], $parts[1], $branch );

		if ( $sha ) {
			self::set_remote_head( $provider, $full_name, $sha );
		}

		return $sha;
	}

	/**
	 * Persists an installed record while preserving metadata such as head and installed_at.
	 *
	 * @since 1.0.0
	 * @param string               $record_key Installed record key.
	 * @param array<string, mixed> $record     New record payload.
	 * @param string|null          $head_sha   Head SHA to store; null keeps the previous value.
	 * @return array<string, mixed> Saved record.
	 */
	private static function save_installed_record( string $record_key, array $record, ?string $head_sha = null ): array {
		global $wpdb;

		$provider  = $record['provider'] ?? '';
		$full_name = $record['full_name'] ?? '';

		// Preserve installed_at and head from existing row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT installed_at, head FROM ' . self::installed_table() . ' WHERE provider = %s AND full_name = %s',
				$provider,
				$full_name
			),
			ARRAY_A
		);

		if ( $existing && ! empty( $existing['installed_at'] ) ) {
			$record['installed_at'] = $existing['installed_at'];
		}

		if ( $head_sha ) {
			$record['head'] = $head_sha;
		} elseif ( $existing && ! empty( $existing['head'] ) && empty( $record['head'] ) ) {
			$record['head'] = $existing['head'];
		}

		// Upsert. remote_head is intentionally excluded from the UPDATE clause so
		// a reinstall does not wipe a cached remote SHA written by sync_installed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::installed_table() . '
					(connection_id, provider, owner, slug, full_name, type, branch, head, remote_head, install_path, plugin_file, installed_at, updated_at)
				VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					connection_id = VALUES(connection_id), slug = VALUES(slug),
					type = VALUES(type), branch = VALUES(branch), head = VALUES(head),
					install_path = VALUES(install_path), plugin_file = VALUES(plugin_file),
					updated_at = VALUES(updated_at)',
				$record['connection_id'] ?? '',
				$provider,
				$record['owner'] ?? '',
				$record['slug'] ?? '',
				$full_name,
				$record['type'] ?? 'plugin',
				$record['branch'] ?? 'main',
				$record['head'] ?? '',
				$record['remote_head'] ?? '',
				$record['install_path'] ?? '',
				$record['plugin_file'] ?? '',
				$record['installed_at'] ?? current_time( 'mysql' ),
				current_time( 'mysql' )
			)
		);

		// Evict any other record that claimed the same directory (replace-install).
		$evicted  = [];
		$new_path = untrailingslashit( $record['install_path'] ?? '' );
		if ( $new_path ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$evicted_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::installed_table() . ' WHERE install_path = %s AND NOT (provider = %s AND full_name = %s)',
					$new_path,
					$provider,
					$full_name
				),
				ARRAY_A
			);
			foreach ( $evicted_rows as $evicted_row ) {
				self::delete_record( $evicted_row['provider'], $evicted_row['full_name'] );
				$evicted[] = self::hydrate_record( $evicted_row );
			}
		}

		self::invalidate_installed_cache();

		if ( ! empty( $evicted ) ) {
			$record['_evicted'] = $evicted;
		}

		return $record;
	}

	/**
	 * Acquires a per-repository install lock then delegates to execute_run().
	 *
	 * @since 1.0.0
	 * @param string      $owner         Git owner or organisation.
	 * @param string      $repo          Repository name.
	 * @param string      $branch        Branch, tag, or SHA.
	 * @param string      $slug          Directory slug for the installation.
	 * @param string      $install_path  Absolute filesystem path for the installation.
	 * @param string      $type          Installation type: "plugin" or "theme".
	 * @param string      $provider      Git provider: 'github', 'gitlab', or 'bitbucket'.
	 * @param bool        $replace       Whether to overwrite an existing directory instead of auto-renaming.
	 * @param string|null $connection_id Optional connection ID to use for authenticated requests.
	 * @return array<string, mixed>|\WP_Error Installed record on success, WP_Error on failure.
	 */
	private static function run(
		string $owner,
		string $repo,
		string $branch,
		string $slug,
		string $install_path,
		string $type,
		string $provider = 'github',
		bool $replace = false,
		?string $connection_id = null
	): array|\WP_Error {
		$full_name = $owner . '/' . $repo;

		if ( ! self::acquire_install_lock( $provider, $full_name ) ) {
			return new \WP_Error( 'gitwire_locked', 'Another install is already in progress for this repository.', [ 'status' => 409 ] );
		}

		try {
			return self::execute_run( $owner, $repo, $branch, $slug, $install_path, $type, $provider, $replace, $connection_id );
		} finally {
			self::release_install_lock( $provider, $full_name );
		}
	}

	/**
	 * Acquires a transient-based install lock for a repository.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @return bool True when the lock was acquired, false when already held.
	 */
	private static function acquire_install_lock( string $provider, string $full_name ): bool {
		$key = 'gitwire_lock_' . md5( $provider . ':' . $full_name );
		return wp_cache_add( $key, 1, 'gitwire_locks', 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Releases the install lock for a repository.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @return void
	 */
	private static function release_install_lock( string $provider, string $full_name ): void {
		$key = 'gitwire_lock_' . md5( $provider . ':' . $full_name );
		wp_cache_delete( $key, 'gitwire_locks' );
	}

	/**
	 * Core install routine: downloads, backs up, extracts, and records a repository.
	 *
	 * @since 1.0.0
	 * @param string      $owner         Git owner or organisation.
	 * @param string      $repo          Repository name.
	 * @param string      $branch        Branch, tag, or SHA.
	 * @param string      $slug          Directory slug for the installation.
	 * @param string      $install_path  Absolute filesystem path for the installation.
	 * @param string      $type          Installation type: "plugin" or "theme".
	 * @param string      $provider      Git provider: 'github', 'gitlab', or 'bitbucket'.
	 * @param bool        $replace       Whether to overwrite an existing directory instead of auto-renaming.
	 * @param string|null $connection_id Optional connection ID to use for authenticated requests.
	 * @return array<string, mixed>|WP_Error Installed record on success, WP_Error on failure.
	 */
	private static function execute_run(
		string $owner,
		string $repo,
		string $branch,
		string $slug,
		string $install_path,
		string $type,
		string $provider = 'github',
		bool $replace = false,
		?string $connection_id = null
	): array|\WP_Error {
		self::init_fs();

		$full_name = $owner . '/' . $repo;
		$api       = Provider_Factory::make( $provider, $connection_id );

		// Auto-rename if the target directory exists but doesn't belong to this exact record.
		// Covers both conflicts with other git-managed installs and unmanaged directories
		// (e.g. a WP.org install with the same slug).
		$current_key   = $provider . ':' . $full_name;
		$all_installed = self::get_installed();
		$slug_renamed  = false;

		if ( is_dir( $install_path ) ) {
			$is_own_update = isset( $all_installed[ $current_key ] ) &&
				untrailingslashit( $all_installed[ $current_key ]['install_path'] ?? '' ) === untrailingslashit( $install_path );

			if ( ! $is_own_update && ! $replace ) {
				$dir_base  = trailingslashit( dirname( $install_path ) );
				$base_slug = $slug . '-' . $provider;
				$new_slug  = $base_slug;
				$counter   = 2;
				while ( is_dir( $dir_base . $new_slug ) ) {
					$new_slug = $base_slug . '-' . ( $counter++ );
				}
				$slug         = $new_slug;
				$install_path = $dir_base . $slug;
				$slug_renamed = true;
			}
		}

		// Download.
		$zip_file = $api->download_zip( $owner, $repo, $branch );
		if ( is_wp_error( $zip_file ) ) {
			return $zip_file;
		}

		$plugin_file       = null;
		$was_active_plugin = false;

		if ( 'plugin' === $type ) {
			$installed   = self::get_installed();
			$install_key = $provider . ':' . $full_name;
			if ( isset( $installed[ $install_key ]['plugin_file'] ) ) {
				$plugin_file = $installed[ $install_key ]['plugin_file'];
			}

			if ( $plugin_file && self::is_active_install( $type, $slug, $plugin_file ) ) {
				if ( ! function_exists( 'deactivate_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				deactivate_plugins( $plugin_file, true );
				$was_active_plugin = true;
			}
		}

		$is_active_update = is_dir( $install_path )
			&& self::is_active_install( $type, $slug, $plugin_file );

		$sync_theme_guard = $is_active_update && 'theme' === $type;

		$remote_sha = null;
		if ( $is_active_update ) {
			if ( 'theme' === $type ) {
				self::clear_guard_feedback();
				delete_option( 'gitwire_running_task' );
			}

			$remote_sha = self::fetch_remote_head_sha( $api, $owner, $repo, $branch );
			if ( $remote_sha && self::matches_known_fatal_remote_head( $provider, $full_name, $branch, $remote_sha ) ) {
				wp_delete_file( $zip_file );
				return new \WP_Error(
					'gitwire_known_fatal_head',
					self::known_fatal_head_message( $type, $remote_sha ),
					[ 'status' => 409 ]
				);
			}
		}

		// Backup existing installation (for fatal-error rollback).
		$backup_path = null;
		if ( is_dir( $install_path ) ) {
			$backup_path = $install_path . '--gitwire-bak-' . time();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! rename( $install_path, $backup_path ) ) {
				wp_delete_file( $zip_file );
				return new \WP_Error( 'gitwire_backup_failed', 'Could not create backup of existing installation.' );
			}
		}

		// Register pending-update so the error handler can roll back.
		$pending = [
			'full_name'         => $full_name,
			'type'              => $type,
			'slug'              => $slug,
			'install_path'      => $install_path,
			'backup_path'       => $backup_path,
			'plugin_file'       => $plugin_file,
			'was_active_plugin' => $was_active_plugin,
		];

		if ( ! $sync_theme_guard ) {
			update_option( 'gitwire_running_task', $pending, false );
		}

		// Extract.
		$extracted = self::extract_zip( $zip_file, $install_path );
		wp_delete_file( $zip_file );

		if ( is_wp_error( $extracted ) ) {
			// Restore backup immediately (no fatal error needed).
			self::restore_backup( $install_path, $backup_path );
			if ( ! $sync_theme_guard ) {
				delete_option( 'gitwire_running_task' );
			}
			return $extracted;
		}

		if ( 'theme' === $type ) {
			self::refresh_theme_runtime( $install_path, $slug );
		}

		// Detect main plugin file.
		if ( 'plugin' === $type ) {
			$plugin_file            = self::find_plugin_file( $install_path, $slug );
			$pending['plugin_file'] = $plugin_file;
			if ( ! $sync_theme_guard ) {
				update_option( 'gitwire_running_task', $pending, false );
			}
		}

		// Save record.
		$record = [
			'slug'          => $slug,
			'repo'          => $repo,
			'owner'         => $owner,
			'full_name'     => $full_name,
			'branch'        => $branch,
			'type'          => 'plugin' === $type ? 'plugin' : ( file_exists( $install_path . '/theme.json' ) ? 'block-theme' : 'classic-theme' ),
			'provider'      => $provider,
			'connection_id' => $connection_id,
			'install_path'  => $install_path,
			'plugin_file'   => 'plugin' === $type ? ( $pending['plugin_file'] ?? null ) : null,
			'installed_at'  => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
			'slug_renamed'  => $slug_renamed,
		];

		$record_key             = $provider . ':' . $full_name;
		$installed              = self::get_installed();
		$pending['prev_record'] = $installed[ $record_key ] ?? null;
		$pending['provider']    = $provider;
		if ( ! $sync_theme_guard ) {
			update_option( 'gitwire_running_task', $pending, false );
		}

		$plugin_file  = 'plugin' === $type ? ( $pending['plugin_file'] ?? null ) : null;
		$needs_verify = $sync_theme_guard && $backup_path;

		if ( $was_active_plugin && $plugin_file ) {
			$record = self::save_installed_record(
				$record_key,
				$record,
				$remote_sha ?? self::fetch_remote_head_sha( $api, $owner, $repo, $branch )
			);

			$pending['context'] = 'update';
			self::clear_guard_feedback();
			update_option( 'gitwire_running_task', $pending, false );

			self::refresh_plugin_runtime( $install_path, $slug );

			$activated = self::reactivate_plugin_after_update( $plugin_file );
			if ( is_wp_error( $activated ) ) {
				self::restore_backup( $install_path, $backup_path );
				delete_option( 'gitwire_running_task' );
				if ( ! $remote_sha ) {
					$remote_sha = self::fetch_remote_head_sha( $api, $owner, $repo, $branch );
				}
				if ( $remote_sha && self::is_activation_fatal_error( $activated ) ) {
					self::mark_known_fatal_remote_head( $provider, $full_name, $branch, $remote_sha );
				}
				return $activated;
			}

			$scrape = Theme_Scraper::scrape_plugin_bootstrap();
			if ( is_wp_error( $scrape ) ) {
				// Restoring the backup returns the working version to disk while the
				// plugin stays active, preserving the user's current install.
				self::restore_backup( $install_path, $backup_path );
				self::refresh_plugin_runtime( $install_path, $slug );
				Error_Handler::restore_pending_installed_record( $pending );
				delete_option( 'gitwire_running_task' );

				if ( ! $remote_sha ) {
					$remote_sha = self::fetch_remote_head_sha( $api, $owner, $repo, $branch );
				}

				return self::update_fatal_error( $scrape, $type, $remote_sha, $provider, $full_name, $branch );
			}

			self::finalize_successful_update( $backup_path );
			return $record;
		}

		if ( $needs_verify ) {
			$pending['context']           = 'update';
			$pending['target_stylesheet'] = $slug;
			$pending['target_template']   = function_exists( 'get_template' ) ? get_template() : $slug;
			$pending['was_active_theme']  = true;

			$validated = self::validate_theme_for_active_pull(
				[
					'slug'         => $slug,
					'install_path' => $install_path,
					'full_name'    => $full_name,
				]
			);
			if ( is_wp_error( $validated ) ) {
				Error_Handler::rollback_theme_update( $pending );
				return $validated;
			}

			$scrape = Theme_Scraper::scrape_bootstrap();
			if ( is_wp_error( $scrape ) ) {
				Error_Handler::rollback_theme_update( $pending );
				return self::update_fatal_error( $scrape, $type, $remote_sha, $provider, $full_name, $branch );
			}

			$record = self::save_installed_record( $record_key, $record, $remote_sha );
			self::clear_guard_feedback();
			self::finalize_successful_update( $backup_path );

			return $record;
		}

		$record = self::save_installed_record(
			$record_key,
			$record,
			self::fetch_remote_head_sha( $api, $owner, $repo, $branch )
		);
		if ( 'theme' === $type ) {
			self::clear_guard_feedback();
		}
		self::finalize_successful_update( $backup_path );

		return $record;
	}

	/**
	 * Reactivates a plugin using WordPress core's sandbox scrape.
	 *
	 * @since 1.0.0
	 * @param string $plugin_file Plugin bootstrap file relative to wp-content/plugins.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	private static function reactivate_plugin_after_update( string $plugin_file ): bool|\WP_Error {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$result = activate_plugin( $plugin_file, '', false, false );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Clears a successful update guard and its backup copy.
	 *
	 * @since 1.0.0
	 * @param string|null $backup_path Absolute backup path.
	 * @return void
	 */
	private static function finalize_successful_update( ?string $backup_path ): void {
		self::delete_backup_path( $backup_path );
		delete_option( 'gitwire_running_task' );
	}

	/**
	 * Clears stale guard feedback before arming a new verify cycle.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function clear_guard_feedback(): void {
		delete_option( 'gitwire_pending_message' );
	}

	/**
	 * Returns whether the installed plugin or theme is currently active.
	 *
	 * @since 1.0.0
	 * @param string      $type        Installation type: "plugin" or "theme".
	 * @param string      $slug        Directory slug.
	 * @param string|null $plugin_file Plugin bootstrap file relative to wp-content/plugins.
	 * @return bool
	 */
	private static function is_active_install( string $type, string $slug, ?string $plugin_file ): bool {
		if ( 'theme' === $type ) {
			if ( ! function_exists( 'get_stylesheet' ) ) {
				return false;
			}
			return get_stylesheet() === $slug || get_template() === $slug;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( $plugin_file && is_plugin_active( $plugin_file ) ) {
			return true;
		}

		$active = get_option( 'active_plugins', [] );
		if ( ! is_array( $active ) ) {
			return false;
		}

		foreach ( $active as $file ) {
			if ( is_string( $file ) && 0 === strpos( $file, $slug . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Deletes a temporary backup directory created during install or update.
	 *
	 * @since 1.0.0
	 * @param string|null $backup_path Absolute backup path.
	 * @return void
	 */
	public static function delete_backup_path( ?string $backup_path ): void {
		if ( ! $backup_path || ! is_dir( $backup_path ) ) {
			return;
		}

		self::init_fs();
		global $wp_filesystem;
		$wp_filesystem->delete( $backup_path, true );
	}

	/**
	 * Unzips a downloaded repository archive to the destination path.
	 *
	 * @since 1.0.0
	 * @param string $zip_path    Local path to the ZIP file.
	 * @param string $destination Absolute path for the extracted files.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function extract_zip( string $zip_path, string $destination ): bool|\WP_Error {
		global $wp_filesystem;

		// Unzip to a temp directory first.
		$tmp_dir = get_temp_dir() . 'gitwire-extract-' . uniqid( '', true );

		$result = unzip_file( $zip_path, $tmp_dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// GitHub ZIPs contain exactly one top-level folder.
		$subdirs = glob( trailingslashit( $tmp_dir ) . '*', GLOB_ONLYDIR );
		if ( empty( $subdirs ) ) {
			$wp_filesystem->delete( $tmp_dir, true );
			return new \WP_Error( 'gitwire_empty_zip', 'The downloaded ZIP contained no directory.' );
		}

		$extracted_folder = $subdirs[0];

		// Move to final destination.
		if ( ! $wp_filesystem->move( $extracted_folder, $destination, true ) ) {
			$wp_filesystem->delete( $tmp_dir, true );
			return new \WP_Error( 'gitwire_move_failed', 'Could not move extracted files to destination.' );
		}

		$wp_filesystem->delete( $tmp_dir, true );

		return true;
	}

	/**
	 * Finds the main plugin file by scanning for the "Plugin Name:" header.
	 * Returns path relative to WP_PLUGIN_DIR (e.g. "my-plugin/my-plugin.php").
	 *
	 * @since 1.0.0
	 * @param string $plugin_dir Absolute path to the plugin directory.
	 * @param string $slug       Plugin slug (directory name).
	 * @return string|null Relative plugin file path, or null if not found.
	 */
	public static function find_plugin_file( string $plugin_dir, string $slug ): ?string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_dir( $plugin_dir ) ) {
			return null;
		}

		// Try the most common convention first: slug/slug.php.
		$candidate = $slug . '/' . $slug . '.php';
		if ( file_exists( WP_PLUGIN_DIR . '/' . $candidate ) ) {
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $candidate, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $candidate;
			}
		}

		// Scan all PHP files directly in the plugin directory for a Plugin Name header.
		$files = glob( trailingslashit( $plugin_dir ) . '*.php' );
		if ( ! $files ) {
			return null;
		}
		foreach ( $files as $file ) {
			$data = get_plugin_data( $file, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $slug . '/' . basename( $file );
			}
		}

		return null;
	}

	/**
	 * Restores a backup directory to the original install path.
	 *
	 * @since 1.0.0
	 * @param string      $install_path Absolute install path.
	 * @param string|null $backup_path  Absolute backup path, or null if no backup exists.
	 * @return bool True when the install path was restored.
	 */
	public static function restore_backup( string $install_path, ?string $backup_path ): bool {
		if ( ! $backup_path || ! is_dir( $backup_path ) ) {
			return false;
		}

		$failed_path = $install_path . '--gitwire-failed-' . time();

		if ( is_dir( $install_path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! @rename( $install_path, $failed_path ) ) {
				self::rmdir_recursive( $install_path );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $backup_path, $install_path ) ) {
			if ( is_dir( $failed_path ) && ! is_dir( $install_path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				@rename( $failed_path, $install_path );
			}
			return false;
		}

		if ( is_dir( $failed_path ) ) {
			self::rmdir_recursive( $failed_path );
		}

		if ( is_dir( $install_path ) && self::is_theme_install_path( $install_path ) ) {
			self::refresh_theme_runtime( $install_path, basename( $install_path ) );
		}

		return is_dir( $install_path );
	}

	/**
	 * Finds the newest orphaned backup directory for an install path.
	 *
	 * @since 1.0.0
	 * @param string $install_path Absolute install path.
	 * @return string|null Backup path or null when none exist.
	 */
	public static function find_orphaned_backup( string $install_path ): ?string {
		$parent = dirname( $install_path );
		$slug   = basename( $install_path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$matches = glob( $parent . DIRECTORY_SEPARATOR . $slug . '--gitwire-bak-*' );
		if ( ! is_array( $matches ) || empty( $matches ) ) {
			return null;
		}

		rsort( $matches );

		return $matches[0];
	}

	/**
	 * Returns whether a path is inside the WordPress themes directory.
	 *
	 * @since 1.0.0
	 * @param string $install_path Absolute install path.
	 * @return bool
	 */
	private static function is_theme_install_path( string $install_path ): bool {
		if ( ! function_exists( 'get_theme_root' ) ) {
			return str_contains( wp_normalize_path( $install_path ), '/themes/' );
		}

		$theme_root = wp_normalize_path( get_theme_root() );

		return str_starts_with( wp_normalize_path( $install_path ), trailingslashit( $theme_root ) );
	}

	/**
	 * Clears stale theme runtime state after files on disk change.
	 *
	 * @since 1.0.0
	 * @param string $install_path Theme directory path.
	 * @param string $slug         Theme stylesheet slug.
	 * @return void
	 */
	public static function refresh_theme_runtime( string $install_path, string $slug ): void {
		if ( ! $slug ) {
			return;
		}

		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( false );
		}

		if ( ! function_exists( 'wp_paused_themes' ) ) {
			require_once ABSPATH . 'wp-includes/error-protection.php';
		}

		wp_paused_themes()->delete( $slug );

		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				wp_paused_themes()->delete( $theme->get_template() );
				$theme->cache_delete();
				$theme = wp_get_theme( $slug );
			}
		}

		if ( ! function_exists( 'wp_opcache_invalidate' ) || ! is_dir( $install_path ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$iterator = @new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $install_path, \FilesystemIterator::SKIP_DOTS )
		);

		if ( ! $iterator ) {
			return;
		}

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			wp_opcache_invalidate( $file->getPathname(), true );
		}
	}

	/**
	 * Clears stale plugin runtime state so a fresh loopback compiles new code.
	 *
	 * The current request already loaded the active plugin, so opcache and the
	 * paused-plugins list must be cleared for the loopback scrape to execute the
	 * replaced files instead of the cached, still-working version.
	 *
	 * @since 1.0.0
	 * @param string $install_path Plugin directory path.
	 * @param string $slug         Plugin directory slug.
	 * @return void
	 */
	public static function refresh_plugin_runtime( string $install_path, string $slug ): void {
		if ( $slug ) {
			if ( ! function_exists( 'wp_paused_plugins' ) ) {
				require_once ABSPATH . 'wp-includes/error-protection.php';
			}
			wp_paused_plugins()->delete( $slug );
		}

		if ( ! function_exists( 'wp_opcache_invalidate' ) || ! is_dir( $install_path ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$iterator = @new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $install_path, \FilesystemIterator::SKIP_DOTS )
		);

		if ( ! $iterator ) {
			return;
		}

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			wp_opcache_invalidate( $file->getPathname(), true );
		}
	}

	/**
	 * Checks whether theme files on disk are readable and error-free.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $rec Installed theme record.
	 * @return true|\WP_Error True when the theme can be activated.
	 */
	private static function validate_theme_for_activation( array $rec ): bool|\WP_Error {
		$slug         = $rec['slug'] ?? '';
		$install_path = $rec['install_path'] ?? '';
		$full_name    = $rec['full_name'] ?? $slug;

		if ( ! $slug || ! is_dir( $install_path ) ) {
			return new \WP_Error(
				'gitwire_theme_missing',
				sprintf(
					/* translators: %s: theme full name */
					__( '%s is not installed on disk. Try pulling the latest version first.', 'gitwire' ),
					$full_name
				),
				[ 'status' => 404 ]
			);
		}

		if ( ! is_readable( $install_path . '/style.css' ) ) {
			return new \WP_Error(
				'gitwire_theme_stylesheet_missing',
				sprintf(
					/* translators: %s: theme full name */
					__( '%s is missing a readable style.css file.', 'gitwire' ),
					$full_name
				),
				[ 'status' => 400 ]
			);
		}

		self::refresh_theme_runtime( $install_path, $slug );

		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return new \WP_Error(
				'gitwire_theme_not_found',
				sprintf(
					/* translators: %s: theme full name */
					__( 'WordPress could not find %s in the themes directory.', 'gitwire' ),
					$full_name
				),
				[ 'status' => 404 ]
			);
		}

		if ( $theme->errors() ) {
			return new \WP_Error(
				'gitwire_theme_invalid',
				sprintf(
					/* translators: 1: theme full name, 2: error detail */
					__( '%1$s cannot be activated: %2$s', 'gitwire' ),
					$full_name,
					wp_strip_all_tags( $theme->errors()->get_error_message() )
				),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Validates an active theme pull using the same checks as theme activation.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $rec Installed theme record.
	 * @return true|\WP_Error True when the updated theme can stay active.
	 */
	private static function validate_theme_for_active_pull( array $rec ): bool|\WP_Error {
		$ready = self::validate_theme_for_activation( $rec );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$slug = $rec['slug'] ?? '';
		if ( ! $slug ) {
			return new \WP_Error(
				'gitwire_theme_missing',
				__( 'Theme slug is missing.', 'gitwire' ),
				[ 'status' => 400 ]
			);
		}

		$requirements = validate_theme_requirements( $slug );
		if ( is_wp_error( $requirements ) ) {
			return new \WP_Error(
				'gitwire_theme_requirements',
				wp_strip_all_tags( $requirements->get_error_message() ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Removes any leftover --gitwire-bak-* and --gitwire-failed-* directories
	 * under the plugins and themes roots. Called from the maintenance cron.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function purge_orphaned_backups(): void {
		foreach ( [ WP_PLUGIN_DIR, get_theme_root() ] as $parent ) {
			foreach ( [ '--gitwire-bak-', '--gitwire-failed-' ] as $marker ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$matches = @glob( $parent . DIRECTORY_SEPARATOR . '*' . $marker . '*' );
				if ( is_array( $matches ) ) {
					foreach ( $matches as $dir ) {
						self::rmdir_recursive( $dir );
					}
				}
			}
		}
	}

	/**
	 * Pure-PHP recursive directory delete.
	 * Safe to call from the shutdown handler where WP Filesystem may not be available.
	 *
	 * @since 1.0.0
	 * @param string $dir Absolute path to the directory to remove.
	 * @return void
	 */
	public static function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$items = @scandir( $dir );
		if ( ! $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				self::rmdir_recursive( $path );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $path );
			}
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir );
	}

	/**
	 * Initialises the WP_Filesystem API if it has not already been set up.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function init_fs(): void {
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			return;
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
	}
}
