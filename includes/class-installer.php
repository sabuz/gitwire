<?php
/**
 * Installer: downloads and extracts GitHub repositories as plugins or themes.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

use Gitwire\Models\Installation;
use Gitwire\Models\Commit;
use Gitwire\Models\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles downloading, extracting, backing up, and removing Git repositories
 * installed as WordPress plugins or themes.
 */
class Installer {

	/**
	 * Request-scoped keyed cache built from Installations_Model::all().
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
		Installation::instance()->invalidate_cache();
	}

	/**
	 * Returns whether this site permits writing to the plugins and themes directories.
	 *
	 * Wraps the same switch core consults before offering its own install and update
	 * screens, so a site that sets DISALLOW_FILE_MODS gets the same answer from
	 * Gitwire that it gets from WordPress.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function file_mods_allowed(): bool {
		return wp_is_file_mod_allowed( 'gitwire_modify_installs' );
	}

	/**
	 * The refusal returned by every path that would write to disk when file
	 * modifications are switched off.
	 *
	 * @since 1.0.0
	 * @return \WP_Error
	 */
	private static function file_mods_error(): \WP_Error {
		return new \WP_Error(
			'gitwire_file_mods_disabled',
			__( 'This site does not allow plugin and theme files to be changed, so Gitwire cannot install, update, or remove anything. Remove the DISALLOW_FILE_MODS setting to re-enable it.', 'gitwire' ),
			[ 'status' => 403 ]
		);
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
		/*
		 * Split on the last slash, not the first. A GitLab subgroup makes full_name
		 * three or more segments, and splitting at the front gave an owner and a repo
		 * that overlapped: acme/team/widget came back as owner acme/team and repo
		 * team/widget, so owner . '/' . repo addressed a project that does not exist.
		 * Every caller that rebuilds the pair, get_record() included, missed.
		 */
		$full  = (string) ( $row['full_name'] ?? '' );
		$slash = strrpos( $full, '/' );

		return array_merge(
			$row,
			[
				'id'          => (int) ( $row['id'] ?? 0 ),
				'owner'       => false !== $slash ? substr( $full, 0, $slash ) : $full,
				'repo'        => false !== $slash ? substr( $full, $slash + 1 ) : '',
				'updated_at'  => $row['updated_at'] ?? '',
				'auto_update' => $row['auto_update'] ?? 'disabled',
			]
		);
	}

	/**
	 * Returns a single installation record by its primary key.
	 *
	 * @since 1.0.0
	 * @param int $id Row ID from gitwire_installations.
	 * @return array<string, mixed>|null
	 */
	public static function get_record_by_id( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		foreach ( self::get_installed() as $rec ) {
			if ( (int) ( $rec['id'] ?? 0 ) === $id ) {
				return $rec;
			}
		}

		return null;
	}

	/**
	 * Deletes the commit cache for an installed repository.
	 *
	 * @since 1.0.0
	 * @param int $installation_id Primary key of the gitwire_installations row.
	 * @return void
	 */
	private static function delete_commits( int $installation_id ): void {
		Commit::instance()->delete_by_installation( $installation_id );
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
		$row             = Installation::instance()->find_by_repo( $provider, $full_name );
		$installation_id = $row ? (int) ( $row['id'] ?? 0 ) : 0;
		Installation::instance()->delete_by_repo( $provider, $full_name );
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
		Installation::instance()->upsert(
			array_merge(
				$record,
				[
					'basename'   => $record['basename'] ?? $record['plugin_file'] ?? '',
					'updated_at' => current_datetime()->format( 'Y-m-d H:i:s' ),
				]
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
		Installation::instance()->update_remote_head( $provider, $full_name, $sha );
		self::invalidate_installed_cache();
	}

	/**
	 * Updates the basename column for a single installed record.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @param string $basename  plugin_basename() value.
	 * @return void
	 */
	public static function set_plugin_file( string $provider, string $full_name, string $basename ): void {
		Installation::instance()->update_basename( $provider, $full_name, $basename );
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

		/*
		 * A repository installed into a directory whose name matches a plugin or theme
		 * on WordPress.org is offered that project's release by the core update check,
		 * which would overwrite the tracked code with an unrelated project. Gitwire owns
		 * updates for what it installed, so those entries come out of the transients
		 * core reads, and the auto-updater is told no as well in case anything puts
		 * them back.
		 */
		add_filter( 'site_transient_update_plugins', [ self::class, 'suppress_plugin_updates' ] );
		add_filter( 'site_transient_update_themes', [ self::class, 'suppress_theme_updates' ] );
		add_filter( 'auto_update_plugin', [ self::class, 'block_plugin_auto_update' ], 10, 2 );
		add_filter( 'auto_update_theme', [ self::class, 'block_theme_auto_update' ], 10, 2 );
	}

	/**
	 * Returns the plugin directory slugs Gitwire manages, as a lookup set.
	 *
	 * Keyed on the directory rather than the bootstrap file: the directory is what
	 * collides with a WordPress.org slug, and it is still known when the entry file
	 * has not been resolved yet.
	 *
	 * @since 1.0.0
	 * @return array<string, true>
	 */
	private static function managed_plugin_dirs(): array {
		$dirs = [];
		foreach ( self::get_installed() as $rec ) {
			$slug = (string) ( $rec['name'] ?? '' );
			if ( 'plugin' === ( $rec['type'] ?? '' ) && '' !== $slug ) {
				$dirs[ $slug ] = true;
			}
		}
		return $dirs;
	}

	/**
	 * Returns the theme stylesheets Gitwire manages, as a lookup set.
	 *
	 * @since 1.0.0
	 * @return array<string, true>
	 */
	private static function managed_theme_slugs(): array {
		$slugs = [];
		foreach ( self::get_installed() as $rec ) {
			$slug = (string) ( $rec['name'] ?? '' );
			if ( Repository_Detector::is_theme( (string) ( $rec['type'] ?? '' ) ) && '' !== $slug ) {
				$slugs[ $slug ] = true;
			}
		}
		return $slugs;
	}

	/**
	 * Drops Gitwire-managed plugins from the core update transient.
	 *
	 * @since 1.0.0
	 * @param mixed $value Transient value.
	 * @return mixed
	 */
	public static function suppress_plugin_updates( $value ) {
		$managed = self::managed_plugin_dirs();
		if ( ! is_object( $value ) || ! $managed ) {
			return $value;
		}

		// Cloned first: an object cache can hand the same instance to the next reader.
		$value = clone $value;

		foreach ( [ 'response', 'no_update' ] as $key ) {
			if ( ! isset( $value->$key ) || ! is_array( $value->$key ) ) {
				continue;
			}
			foreach ( array_keys( $value->$key ) as $plugin_file ) {
				if ( isset( $managed[ dirname( (string) $plugin_file ) ] ) ) {
					unset( $value->{$key}[ $plugin_file ] );
				}
			}
		}

		return $value;
	}

	/**
	 * Drops Gitwire-managed themes from the core update transient.
	 *
	 * @since 1.0.0
	 * @param mixed $value Transient value.
	 * @return mixed
	 */
	public static function suppress_theme_updates( $value ) {
		$managed = self::managed_theme_slugs();
		if ( ! is_object( $value ) || ! $managed ) {
			return $value;
		}

		$value = clone $value;

		foreach ( [ 'response', 'no_update' ] as $key ) {
			if ( ! isset( $value->$key ) || ! is_array( $value->$key ) ) {
				continue;
			}
			foreach ( array_keys( $value->$key ) as $stylesheet ) {
				if ( isset( $managed[ (string) $stylesheet ] ) ) {
					unset( $value->{$key}[ $stylesheet ] );
				}
			}
		}

		return $value;
	}

	/**
	 * Refuses a core auto-update for a plugin Gitwire tracks.
	 *
	 * @since 1.0.0
	 * @param mixed $update Whether core intends to update.
	 * @param mixed $item   Update offer, carrying the plugin basename.
	 * @return mixed
	 */
	public static function block_plugin_auto_update( $update, $item ) {
		$file = is_object( $item ) ? ( $item->plugin ?? '' ) : ( is_array( $item ) ? ( $item['plugin'] ?? '' ) : '' );
		if ( '' === $file ) {
			return $update;
		}

		return isset( self::managed_plugin_dirs()[ dirname( (string) $file ) ] ) ? false : $update;
	}

	/**
	 * Refuses a core auto-update for a theme Gitwire tracks.
	 *
	 * @since 1.0.0
	 * @param mixed $update Whether core intends to update.
	 * @param mixed $item   Update offer, carrying the stylesheet.
	 * @return mixed
	 */
	public static function block_theme_auto_update( $update, $item ) {
		$slug = is_object( $item ) ? ( $item->theme ?? '' ) : ( is_array( $item ) ? ( $item['theme'] ?? '' ) : '' );
		if ( '' === $slug ) {
			return $update;
		}

		return isset( self::managed_theme_slugs()[ (string) $slug ] ) ? false : $update;
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

		$deleted_dir = untrailingslashit( WP_PLUGIN_DIR ) . '/' . dirname( $plugin_file );
		$row         = Installation::instance()->find_by_path( $deleted_dir );

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

		$deleted_dir = untrailingslashit( get_theme_root() ) . '/' . $stylesheet;
		$row         = Installation::instance()->find_by_path( $deleted_dir );

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

		// Nothing drains this until someone opens a Gitwire screen, so a bulk delete
		// would otherwise grow the option without limit.
		if ( count( $pending ) > 50 ) {
			$pending = array_slice( $pending, -50 );
		}

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
	 * @return array<string, mixed>|\WP_Error Installed record on success, WP_Error on failure.
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
	 * @return array<string, mixed>|\WP_Error Installed record on success, WP_Error on failure.
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
	 * @return array<string, mixed>|\WP_Error Updated record on success, WP_Error on failure.
	 */
	public static function switch_branch( string $provider, string $full_name, string $new_branch, ?string $override_connection_id = null ): array|\WP_Error {
		$installed = self::get_installed();
		$key       = $provider . ':' . $full_name;

		if ( ! isset( $installed[ $key ] ) ) {
			return new \WP_Error( 'gitwire_not_found', __( 'Repository is not installed.', 'gitwire' ) );
		}

		$rec       = $installed[ $key ];
		$owner     = $rec['owner'];
		$repo      = $rec['repo'];
		$method    = Repository_Detector::is_theme( $rec['type'] ) ? 'install_theme' : 'install_plugin';
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
					// No connection system (or none left), so try the public path.
					$connection_id = null;
				} elseif ( 1 !== count( $provider_conns ) ) {
					return new \WP_Error(
						'gitwire_no_connection',
						__( 'The connection used to install this repository no longer exists. Use the Reconnect action to select an account.', 'gitwire' ),
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
				__( 'The connection used to install this repository no longer exists. Use the Reconnect action to select an account.', 'gitwire' ),
				[ 'status' => 400 ]
			);
		}

		$result = self::$method( $owner, $repo, $new_branch, $rec['name'], $provider, false, $connection_id );

		if ( $was_stale && is_wp_error( $result ) ) {
			return new \WP_Error(
				'gitwire_no_connection',
				__( 'The connection used to install this repository no longer exists. Use the Reconnect action to select an account.', 'gitwire' ),
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
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public static function remove( string $provider, string $full_name ): bool|\WP_Error {
		$rec = self::get_record( $provider, $full_name );

		if ( ! $rec ) {
			return new \WP_Error( 'gitwire_not_found', __( 'Repository is not installed.', 'gitwire' ) );
		}

		if ( ! self::file_mods_allowed() ) {
			return self::file_mods_error();
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
			return new \WP_Error( 'gitwire_not_found', __( 'Repository is not installed.', 'gitwire' ) );
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
			return new \WP_Error( 'gitwire_not_found', __( 'Repository is not installed.', 'gitwire' ) );
		}

		$rec = $installed[ $key ];

		if ( 'plugin' === $rec['type'] ) {
			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugin_file = self::heal_plugin_file(
				$provider,
				$full_name,
				$rec['install_path'] ?? '',
				$rec['name'] ?? '',
				$rec['basename'] ?? null
			);

			if ( ! $plugin_file ) {
				return new \WP_Error(
					'gitwire_no_plugin_file',
					__( 'Could not locate the plugin entry file. Try using "Pull latest" to re-sync.', 'gitwire' ),
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
		} elseif ( Repository_Detector::is_theme( $rec['type'] ) ) {
			Error_Handler::clear_stale_activation_guard();
			self::refresh_theme_runtime( $rec['install_path'] ?? '', $rec['name'] ?? '' );

			$ready = self::validate_theme_for_activation( $rec );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			$pending = self::begin_activation_guard( $rec, $full_name );

			$requirements = validate_theme_requirements( $rec['name'] );
			if ( is_wp_error( $requirements ) ) {
				self::clear_activation_guard();
				return new \WP_Error(
					'gitwire_theme_requirements',
					wp_strip_all_tags( $requirements->get_error_message() ),
					[ 'status' => 400 ]
				);
			}

			switch_theme( $rec['name'] );
			self::refresh_theme_runtime( $rec['install_path'] ?? '', $rec['name'] ?? '' );

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
			'started_at'          => time(),
			'full_name'           => $full_name,
			'type'                => $rec['type'],
			'name'                => $rec['name'] ?? '',
			'install_path'        => $rec['install_path'] ?? '',
			'plugin_file'         => $plugin_file,
			'previous_stylesheet' => get_stylesheet(),
			'previous_template'   => get_template(),
		];

		if ( Repository_Detector::is_theme( $rec['type'] ) ) {
			$pending['target_stylesheet'] = $rec['name'];
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
			return new \WP_Error( 'gitwire_not_found', __( 'Repository is not installed.', 'gitwire' ) );
		}

		$rec = $installed[ $key ];

		if ( 'plugin' !== $rec['type'] ) {
			return new \WP_Error( 'gitwire_unsupported', __( 'Only plugins can be deactivated this way.', 'gitwire' ), [ 'status' => 400 ] );
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = self::heal_plugin_file(
			$provider,
			$full_name,
			$rec['install_path'] ?? '',
			$rec['name'] ?? '',
			$rec['basename'] ?? null
		);

		if ( ! $plugin_file ) {
			return new \WP_Error(
				'gitwire_no_plugin_file',
				__( 'Could not locate the plugin entry file. Try using "Pull latest" to re-sync.', 'gitwire' ),
				[ 'status' => 500 ]
			);
		}

		deactivate_plugins( $plugin_file );

		return true;
	}

	/**
	 * Re-scans the install directory for the plugin entry file when the stored path is missing.
	 *
	 * @since 1.0.0
	 * @param string      $provider    Git provider.
	 * @param string      $full_name   Repository full name.
	 * @param string      $install_path Absolute installation path.
	 * @param string      $slug        Plugin slug.
	 * @param string|null $plugin_file Stored plugin file (may be empty or stale).
	 * @return string|null Healed plugin file path, or null when not found.
	 */
	private static function heal_plugin_file( string $provider, string $full_name, string $install_path, string $slug, ?string $plugin_file ): ?string {
		if ( $plugin_file && file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			return $plugin_file;
		}

		if ( empty( $install_path ) || ! is_dir( $install_path ) ) {
			return null;
		}

		$found = self::find_plugin_file( $install_path, $slug );
		if ( ! $found ) {
			return null;
		}

		Installation::instance()->update_basename( $provider, $full_name, $found );
		self::invalidate_installed_cache();

		return $found;
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

		self::$installed_cache = [];
		foreach ( Installation::instance()->all() as $row ) {
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

			if ( 'github' === $provider ) {
				$rl_key   = 'gitwire_gh_rl_' . ( $connection_id ?? 'anon' );
				$rl_value = get_transient( $rl_key );
				if ( false !== $rl_value && (int) $rl_value < 5 ) {
					Logger::log( sprintf( 'Auto-update skipped for %s: GitHub rate limit low (%d remaining)', $rec['full_name'] ?? '', (int) $rl_value ), 'error' );
					continue;
				}
			}

			$api           = Provider_Factory::make( $provider, $connection_id );
			$remote_sha    = self::fetch_remote_head_sha( $api, $owner, $repo, $branch );
			$stored_remote = (string) ( $rec['remote_head'] ?? '' );

			if ( $remote_sha && $remote_sha !== $stored_remote ) {
				$full_name = (string) ( $rec['full_name'] ?? '' );
				self::set_remote_head( $provider, $full_name, $remote_sha );
				$records[ $key ]['remote_head'] = $remote_sha;
			}
		}

		/*
		 * Refreshing remote heads above is still worth doing, so the UI can show what
		 * is behind. Applying is not: bail once here rather than letting every repo
		 * fail its own write and write a log line for it every tick.
		 */
		if ( ! self::file_mods_allowed() ) {
			return;
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
			$slug          = (string) ( $rec['name'] ?? '' );
			$connection_id = $rec['connection_id'] ? $rec['connection_id'] : null;
			$type          = (string) ( $rec['type'] ?? 'plugin' );
			$full_name     = (string) ( $rec['full_name'] ?? '' );

			if ( ! $owner || ! $repo ) {
				continue;
			}

			if ( Repository_Detector::is_theme( $type ) ) {
				$result = self::install_theme( $owner, $repo, $branch, $slug, $provider, true, $connection_id );
			} else {
				$result = self::install_plugin( $owner, $repo, $branch, $slug, $provider, true, $connection_id );
			}

			if ( is_wp_error( $result ) ) {
				Logger::log( 'Auto-update failed: ' . $full_name . ': ' . $result->get_error_message(), 'error' );
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
		Installation::instance()->update_head( $provider, $full_name, $sha );
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

		// Entries written before the location was recorded are still a bare SHA string.
		if ( is_array( $value ) ) {
			$value = $value['sha'] ?? '';
		}

		return is_string( $value ) && $value ? $value : null;
	}

	/**
	 * Returns where the remembered fatal was thrown, as "path:line".
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @param string $branch    Branch name.
	 * @return string Empty when the location was not recorded.
	 */
	public static function get_known_fatal_location( string $provider, string $full_name, string $branch ): string {
		$value = get_transient( self::known_fatal_head_key( $provider, $full_name, $branch ) );
		return is_array( $value ) ? (string) ( $value['location'] ?? '' ) : '';
	}

	/**
	 * Formats the file and line out of a scrape payload for display.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $scrape Scrape failure payload from the sandbox.
	 * @return string Empty when the payload carries no file.
	 */
	private static function fatal_location_from_scrape( array $scrape ): string {
		$file = (string) ( $scrape['file'] ?? '' );
		$line = (int) ( $scrape['line'] ?? 0 );

		if ( '' === $file ) {
			return '';
		}

		// Absolute paths expose the server layout, so report relative to wp-content.
		if ( defined( 'WP_CONTENT_DIR' ) && 0 === strpos( $file, WP_CONTENT_DIR ) ) {
			$file = ltrim( substr( $file, strlen( WP_CONTENT_DIR ) ), '/\\' );
		}

		return $line > 0 ? $file . ':' . $line : $file;
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
	 * @param string $location   Where the error was thrown, as "path:line". Optional.
	 * @return string
	 */
	public static function known_fatal_head_message( string $type, string $remote_sha, string $location = '' ): string {
		$short = substr( $remote_sha, 0, 7 );
		$label = Repository_Detector::is_theme( $type )
			? __( 'theme', 'gitwire' )
			: __( 'plugin', 'gitwire' );

		if ( '' !== $location ) {
			return sprintf(
				/* translators: 1: short commit SHA, 2: plugin or theme, 3: file path and line number */
				__(
					'The latest commit (%1$s) caused a fatal error in %3$s on this active %2$s and was not pulled. Your current version was kept. Push a new commit or wait a few minutes to retry %1$s.',
					'gitwire'
				),
				$short,
				$label,
				$location
			);
		}

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

		$location = self::fatal_location_from_scrape( $data['scrape'] );
		self::mark_known_fatal_remote_head( $provider, $full_name, $branch, $remote_sha, $location );

		return new \WP_Error(
			'gitwire_known_fatal_head',
			self::known_fatal_head_message( $type, $remote_sha, $location ),
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
	 * @param string $location  Where the error was thrown, as "path:line". Optional.
	 * @return void
	 */
	public static function mark_known_fatal_remote_head( string $provider, string $full_name, string $branch, string $sha, string $location = '' ): void {
		set_transient(
			self::known_fatal_head_key( $provider, $full_name, $branch ),
			'' !== $location ? [
				'sha'      => $sha,
				'location' => $location,
			] : $sha,
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
			self::mark_known_fatal_remote_head( $provider, $full_name, $branch, $sha, self::fatal_location_from_scrape( $data['scrape'] ) );
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

		$data = $error->get_error_data();
		$sha  = self::resolve_remote_head_for_record( $rec, $provider, $full_name, $branch );
		if ( $sha ) {
			self::mark_known_fatal_remote_head( $provider, $full_name, $branch, $sha, self::fatal_location_from_scrape( is_array( $data ) ? $data : [] ) );
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
	 * Persists an installed record while preserving metadata such as head.
	 *
	 * @since 1.0.0
	 * @param string               $record_key Installed record key.
	 * @param array<string, mixed> $record     New record payload.
	 * @param string|null          $head_sha   Head SHA to store; null keeps the previous value.
	 * @return array<string, mixed> Saved record.
	 */
	private static function save_installed_record( string $record_key, array $record, ?string $head_sha = null ): array {
		$provider  = $record['provider'] ?? '';
		$full_name = $record['full_name'] ?? '';

		if ( $head_sha ) {
			$record['head'] = $head_sha;
		}

		Installation::instance()->upsert(
			array_merge(
				$record,
				[
					'basename'   => $record['basename'] ?? $record['plugin_file'] ?? '',
					'updated_at' => current_datetime()->format( 'Y-m-d H:i:s' ),
				]
			)
		);

		// Evict any other record that claimed the same directory (replace-install).
		$evicted  = [];
		$new_path = untrailingslashit( $record['install_path'] ?? '' );
		if ( $new_path ) {
			$evicted_rows = Installation::instance()->find_others_by_path( $new_path, $provider, $full_name );
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

		/*
		 * The REST capability gate covers requests, but cron auto-updates run with no
		 * current user, so the site-level switch has to be re-checked at the point of
		 * the write rather than only at the edge.
		 */
		if ( ! self::file_mods_allowed() ) {
			return self::file_mods_error();
		}

		if ( ! self::acquire_install_lock( $provider, $full_name ) ) {
			return new \WP_Error( 'gitwire_locked', __( 'Another install is already in progress for this repository.', 'gitwire' ), [ 'status' => 409 ] );
		}

		try {
			return self::execute_run( $owner, $repo, $branch, $slug, $install_path, $type, $provider, $replace, $connection_id );
		} finally {
			self::release_install_lock( $provider, $full_name );
		}
	}

	/**
	 * Acquires a DB-level install lock for a repository.
	 *
	 * Uses MySQL INSERT IGNORE so the lock is shared across PHP-FPM workers.
	 * wp_cache_add() is per-process on sites without a persistent object cache.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider.
	 * @param string $full_name Repository full name.
	 * @return bool True when the lock was acquired, false when already held.
	 */
	private static function acquire_install_lock( string $provider, string $full_name ): bool {
		global $wpdb;
		$key    = 'gitwire_lock_' . md5( $provider . ':' . $full_name );
		$cutoff = time() - 10 * MINUTE_IN_SECONDS;

		// Remove locks left behind by crashed processes (older than 10 minutes).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				$key,
				$cutoff
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$key,
				(string) time()
			)
		);

		return 1 === (int) $inserted;
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
		delete_option( $key );
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
	 * @return array<string, mixed>|\WP_Error Installed record on success, WP_Error on failure.
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

		/*
		 * Auto-rename if the target directory exists but doesn't belong to this exact record.
		 * Covers both conflicts with other git-managed installs and unmanaged directories
		 * (e.g. a WP.org install with the same slug).
		 */
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

		/*
		 * Short-circuit a retry of a known-fatal commit before paying for the archive.
		 * The guard further down runs after the plugin has been deactivated, which makes
		 * is_active_install() false and leaves it unreachable on the very retry it exists for.
		 */
		if ( is_dir( $install_path )
			&& self::is_active_install( $type, $slug, $all_installed[ $current_key ]['basename'] ?? null )
		) {
			$known = self::get_known_fatal_remote_head( $provider, $full_name, $branch );
			if ( $known && self::fetch_remote_head_sha( $api, $owner, $repo, $branch ) === $known ) {
				return new \WP_Error(
					'gitwire_known_fatal_head',
					self::known_fatal_head_message( $type, $known, self::get_known_fatal_location( $provider, $full_name, $branch ) ),
					[ 'status' => 409 ]
				);
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
			if ( isset( $installed[ $install_key ]['basename'] ) ) {
				$plugin_file = $installed[ $install_key ]['basename'];
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

		$sync_theme_guard = $is_active_update && Repository_Detector::is_theme( $type );

		$remote_sha = null;
		if ( $is_active_update ) {
			if ( Repository_Detector::is_theme( $type ) ) {
				self::clear_guard_feedback();
				delete_option( 'gitwire_running_task' );
			}

			$remote_sha = self::fetch_remote_head_sha( $api, $owner, $repo, $branch );
			if ( $remote_sha && self::matches_known_fatal_remote_head( $provider, $full_name, $branch, $remote_sha ) ) {
				wp_delete_file( $zip_file );
				return new \WP_Error(
					'gitwire_known_fatal_head',
					self::known_fatal_head_message( $type, $remote_sha, self::get_known_fatal_location( $provider, $full_name, $branch ) ),
					[ 'status' => 409 ]
				);
			}
		}

		// Backup existing installation (for fatal-error rollback).
		$backup_path = null;
		if ( is_dir( $install_path ) ) {
			$backup_path = self::get_backup_base_dir() . DIRECTORY_SEPARATOR . basename( $install_path ) . '--gitwire-bak-' . time();
			if ( ! self::move_dir_safe( $install_path, $backup_path ) ) {
				wp_delete_file( $zip_file );
				return new \WP_Error( 'gitwire_backup_failed', __( 'Could not create backup of existing installation.', 'gitwire' ) );
			}
		}

		// Register pending-update so the error handler can roll back.
		$pending = [
			'started_at'        => time(),
			'full_name'         => $full_name,
			'type'              => $type,
			'name'              => $slug,
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

		if ( Repository_Detector::is_theme( $type ) ) {
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
		$html_url = Repository::instance()->get_html_url( $provider, $full_name );

		$record = [
			'name'          => $slug,
			'repo'          => $repo,
			'owner'         => $owner,
			'full_name'     => $full_name,
			'branch'        => $branch,
			'type'          => 'plugin' === $type ? 'plugin' : ( file_exists( $install_path . '/theme.json' ) ? 'block-theme' : 'classic-theme' ),
			'provider'      => $provider,
			'connection_id' => $connection_id,
			'install_path'  => $install_path,
			'html_url'      => $html_url,
			'basename'      => 'plugin' === $type ? ( $pending['plugin_file'] ?? null ) : null,
			'updated_at'    => current_datetime()->format( 'Y-m-d H:i:s' ),
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
				/*
				 * Restoring the backup returns the working version to disk while the
				 * plugin stays active, preserving the user's current install.
				 */
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
					'name'         => $slug,
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
		if ( Repository_Detector::is_theme( $type ) ) {
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
		if ( Repository_Detector::is_theme( $type ) ) {
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
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	private static function extract_zip( string $zip_path, string $destination ): bool|\WP_Error {
		global $wp_filesystem;

		// Unzip to a temp directory first. The name is unguessable rather than uniqid()'s
		// timestamp, so a local user on shared hosting cannot pre-create the path we are
		// about to extract into.
		$tmp_dir = get_temp_dir() . 'gitwire-extract-' . wp_generate_password( 20, false );

		$result = unzip_file( $zip_path, $tmp_dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/*
		 * Every provider's archive endpoint wraps the tree in one prefixed folder. Bail
		 * rather than guess if that ever stops being true.
		 */
		$subdirs = glob( trailingslashit( $tmp_dir ) . '*', GLOB_ONLYDIR );
		if ( empty( $subdirs ) ) {
			$wp_filesystem->delete( $tmp_dir, true );
			return new \WP_Error( 'gitwire_empty_zip', __( 'The downloaded ZIP contained no directory.', 'gitwire' ) );
		}
		if ( count( $subdirs ) > 1 ) {
			$wp_filesystem->delete( $tmp_dir, true );
			return new \WP_Error( 'gitwire_ambiguous_zip', __( 'The downloaded ZIP contained more than one top-level directory.', 'gitwire' ) );
		}

		$extracted_folder = $subdirs[0];

		// Move to final destination.
		if ( ! $wp_filesystem->move( $extracted_folder, $destination, true ) ) {
			$wp_filesystem->delete( $tmp_dir, true );
			return new \WP_Error( 'gitwire_move_failed', __( 'Could not move extracted files to destination.', 'gitwire' ) );
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

		// Move broken install aside on same filesystem as install_path (fast rename).
		$failed_path = null;
		if ( is_dir( $install_path ) ) {
			$failed_path = $install_path . '--gitwire-failed-' . time();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! rename( $install_path, $failed_path ) ) {
				self::rmdir_recursive( $install_path );
				$failed_path = null;
			}
		}

		// Restore backup (backup may be in temp dir, so use move_dir_safe for cross-filesystem support).
		if ( ! self::move_dir_safe( $backup_path, $install_path ) ) {
			if ( $failed_path && is_dir( $failed_path ) && ! is_dir( $install_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				rename( $failed_path, $install_path );
			}
			return false;
		}

		if ( $failed_path && is_dir( $failed_path ) ) {
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
		$slug = basename( $install_path );

		$parents = [
			self::get_backup_base_dir(),
			// Earlier builds stored backups next to the install, then in the system temp dir.
			dirname( $install_path ),
			trailingslashit( sys_get_temp_dir() ) . 'gitwire-backups',
		];

		foreach ( $parents as $parent ) {
			$matches = glob( $parent . DIRECTORY_SEPARATOR . $slug . '--gitwire-bak-*' );
			if ( ! is_array( $matches ) || empty( $matches ) ) {
				continue;
			}
			rsort( $matches );
			return $matches[0];
		}

		return null;
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
		$slug         = $rec['name'] ?? '';
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

		$slug = $rec['name'] ?? '';
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
		$parents = [
			self::get_backup_base_dir(),
			// Earlier builds stored backups next to the install, then in the system temp dir.
			WP_PLUGIN_DIR,
			get_theme_root(),
			trailingslashit( sys_get_temp_dir() ) . 'gitwire-backups',
		];

		foreach ( $parents as $parent ) {
			foreach ( [ '--gitwire-bak-', '--gitwire-failed-' ] as $marker ) {
				$matches = glob( $parent . DIRECTORY_SEPARATOR . '*' . $marker . '*' );
				if ( ! is_array( $matches ) ) {
					continue;
				}
				foreach ( $matches as $dir ) {
					// A symlinked stray in a world-writable temp dir would delete its target.
					if ( is_link( $dir ) ) {
						wp_delete_file( $dir );
						continue;
					}
					self::rmdir_recursive( $dir );
				}
			}
		}
	}

	/**
	 * Removes the entire backup base directory, guard files included.
	 *
	 * The orphaned-backup purge only clears stray backup subdirectories
	 * because it also runs from the maintenance cron on a live site, where
	 * the guarded base directory needs to stay in place for the next
	 * backup. Uninstall has no next backup, so this removes it outright.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function remove_backup_base_dir(): void {
		self::rmdir_recursive( self::get_backup_base_dir() );
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
		// is_dir() follows symlinks, so recursing into one would delete its target.
		if ( is_link( $dir ) ) {
			wp_delete_file( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
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
	 * Returns the base directory for temporary backup storage.
	 *
	 * Same location core uses for its own plugin/theme rollbacks since 6.3. Lives on
	 * the same filesystem as the install path, so move_dir_safe() gets an atomic
	 * rename instead of a full recursive copy, and it is not shared with other
	 * accounts the way sys_get_temp_dir() is.
	 *
	 * @since 1.0.0
	 * @return string Absolute path to the backup directory.
	 */
	private static function get_backup_base_dir(): string {
		$dir = WP_CONTENT_DIR . '/upgrade-temp-backup/gitwire';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		Filesystem_Guard::protect_directory( $dir );
		return $dir;
	}

	/**
	 * Moves a directory, falling back to copy+delete for cross-filesystem moves.
	 *
	 * Safe to call from the shutdown handler (uses only native PHP).
	 *
	 * @since 1.0.0
	 * @param string $src Source path.
	 * @param string $dst Destination path.
	 * @return bool True when dst exists after the move.
	 */
	private static function move_dir_safe( string $src, string $dst ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( rename( $src, $dst ) ) {
			return true;
		}
		// Cross-filesystem fallback: copy every file then remove the source.
		if ( ! self::copy_recursive( $src, $dst ) ) {
			return false;
		}
		self::rmdir_recursive( $src );
		return is_dir( $dst );
	}

	/**
	 * Recursively copies a directory tree using native PHP.
	 *
	 * @since 1.0.0
	 * @param string $src Source directory.
	 * @param string $dst Destination directory.
	 * @return bool False if any step fails.
	 */
	private static function copy_recursive( string $src, string $dst ): bool {
		if ( ! is_dir( $dst ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			if ( ! mkdir( $dst, 0755, true ) ) {
				return false;
			}
		}
		$items = scandir( $src );
		if ( ! $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$s = $src . DIRECTORY_SEPARATOR . $item;
			$d = $dst . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $s ) ) {
				if ( ! self::copy_recursive( $s, $d ) ) {
					return false;
				}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			} elseif ( ! copy( $s, $d ) ) {
				return false;
			}
		}
		return true;
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
