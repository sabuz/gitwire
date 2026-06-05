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
 * Handles downloading, extracting, backing up, and removing GitHub repositories
 * installed as WordPress plugins or themes.
 */
class Installer {

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

		// Build the absolute path of the deleted plugin's directory.
		$deleted_dir = untrailingslashit( WP_PLUGIN_DIR ) . '/' . dirname( $plugin_file );
		$installed   = (array) get_option( 'gitwire_installed', [] );
		$dirty       = false;

		foreach ( $installed as $key => $rec ) {
			if ( untrailingslashit( $rec['install_path'] ?? '' ) === $deleted_dir ) {
				unset( $installed[ $key ] );
				$dirty = true;
				break;
			}
		}

		if ( $dirty ) {
			update_option( 'gitwire_installed', $installed );
		}
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

		// Build the absolute path of the deleted theme's directory.
		$deleted_dir = untrailingslashit( get_theme_root() ) . '/' . $stylesheet;
		$installed   = (array) get_option( 'gitwire_installed', [] );
		$dirty       = false;

		foreach ( $installed as $key => $rec ) {
			if ( untrailingslashit( $rec['install_path'] ?? '' ) === $deleted_dir ) {
				unset( $installed[ $key ] );
				$dirty = true;
				break;
			}
		}

		if ( $dirty ) {
			update_option( 'gitwire_installed', $installed );
		}
	}

	/**
	 * Installs or updates a repository as a WordPress plugin.
	 *
	 * @since 1.0.0
	 * @param string $owner    Git owner or organisation.
	 * @param string $repo     Repository name.
	 * @param string $branch   Branch, tag, or SHA.
	 * @param string $slug     Desired directory slug (defaults to sanitised repo name).
	 * @param string $provider Git provider: 'github' or 'gitlab'.
	 * @param bool   $replace  Whether to overwrite an existing directory instead of auto-renaming.
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
	 * @param string $owner    Git owner or organisation.
	 * @param string $repo     Repository name.
	 * @param string $branch   Branch, tag, or SHA.
	 * @param string $slug     Desired directory slug (defaults to sanitised repo name).
	 * @param string $provider Git provider: 'github' or 'gitlab'.
	 * @param bool   $replace  Whether to overwrite an existing directory instead of auto-renaming.
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
	 * @param string $provider   Git provider: 'github' or 'gitlab'.
	 * @param string $full_name  Repository full name (owner/repo).
	 * @param string $new_branch Branch to switch to.
	 * @return array<string, mixed>|WP_Error Updated record on success, WP_Error on failure.
	 */
	public static function switch_branch( string $provider, string $full_name, string $new_branch ): array|\WP_Error {
		$installed = self::get_installed();
		$key       = $provider . ':' . $full_name;

		if ( ! isset( $installed[ $key ] ) ) {
			return new \WP_Error( 'gitwire_not_found', 'Repository is not installed.' );
		}

		$rec           = $installed[ $key ];
		$parts         = explode( '/', $full_name );
		$owner         = $parts[0];
		$repo          = $parts[1];
		$method        = 'theme' === $rec['type'] ? 'install_theme' : 'install_plugin';
		$connection_id = $rec['connection_id'] ?? null;

		$result = self::$method( $owner, $repo, $new_branch, $rec['slug'], $provider, false, $connection_id );

		return $result;
	}

	/**
	 * Removes an installed repository from the filesystem and the installation record.
	 * Does NOT deactivate the plugin or theme first.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider: 'github' or 'gitlab'.
	 * @param string $full_name Repository full name (owner/repo).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function remove( string $provider, string $full_name ): bool|\WP_Error {
		$installed = self::get_installed();
		$key       = $provider . ':' . $full_name;

		if ( ! isset( $installed[ $key ] ) ) {
			return new \WP_Error( 'gitwire_not_found', 'Repository is not installed.' );
		}

		$rec  = $installed[ $key ];
		$path = $rec['install_path'];

		if ( is_dir( $path ) ) {
			self::init_fs();
			global $wp_filesystem;
			$wp_filesystem->delete( $path, true );
		}

		unset( $installed[ $key ] );
		update_option( 'gitwire_installed', $installed );

		return true;
	}

	/**
	 * Removes the tracking record for a repository without deleting its files.
	 *
	 * @since 3.0.0
	 * @param string $provider  Git provider key.
	 * @param string $full_name Repository full name (owner/repo).
	 * @return true|\WP_Error True on success, WP_Error when not found.
	 */
	public static function untrack( string $provider, string $full_name ): bool|\WP_Error {
		$installed = self::get_installed();
		$key       = $provider . ':' . $full_name;

		if ( ! isset( $installed[ $key ] ) ) {
			return new \WP_Error( 'gitwire_not_found', 'Repository is not installed.' );
		}

		unset( $installed[ $key ] );
		update_option( 'gitwire_installed', $installed );

		return true;
	}

	/**
	 * Activates an installed plugin or switches to an installed theme.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider: 'github' or 'gitlab'.
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
						$installed[ $key ]['plugin_file'] = $plugin_file;
						update_option( 'gitwire_installed', $installed );
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
		} elseif ( 'theme' === $rec['type'] ) {
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

			delete_option( 'gitwire_pending_update' );

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
	 * @since 1.2.0
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

		if ( 'theme' === $rec['type'] ) {
			$pending['target_stylesheet'] = $rec['slug'];
		}

		self::clear_guard_feedback();
		update_option( 'gitwire_pending_update', $pending, false );

		return $pending;
	}

	/**
	 * Clears a pending activation guard when activation fails before bootstrap.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private static function clear_activation_guard(): void {
		delete_option( 'gitwire_pending_update' );
	}

	/**
	 * Stores the active theme slugs on the pending activation guard record.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private static function sync_theme_activation_target(): void {
		$pending = get_option( 'gitwire_pending_update' );
		if ( ! is_array( $pending ) || 'theme' !== ( $pending['type'] ?? '' ) ) {
			return;
		}

		$pending['target_stylesheet'] = get_stylesheet();
		$pending['target_template']   = get_template();
		update_option( 'gitwire_pending_update', $pending, false );
	}

	/**
	 * Clears the activation guard after core has sandboxed a plugin activation.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private static function complete_plugin_activation_guard(): void {
		delete_option( 'gitwire_pending_update' );
	}

	/**
	 * Deactivates an installed plugin. Themes cannot be deactivated this way.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider: 'github' or 'gitlab'.
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
					$installed[ $key ]['plugin_file'] = $plugin_file;
					update_option( 'gitwire_installed', $installed );
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
	 * Migrates legacy keys (full_name only) on first read.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Map of "provider:full_name" => record.
	 */
	public static function get_installed(): array {
		$raw      = (array) get_option( 'gitwire_installed', [] );
		$result   = [];
		$migrated = false;

		foreach ( $raw as $key => $rec ) {
			if ( strpos( $key, ':' ) === false ) {
				$provider = $rec['provider'] ?? 'github';
				$key      = $provider . ':' . $key;
				$migrated = true;
			}
			$result[ $key ] = $rec;
		}

		if ( $migrated ) {
			update_option( 'gitwire_installed', $result );
		}

		return $result;
	}

	/**
	 * Returns a single installation record by provider and full name.
	 *
	 * @since 1.0.0
	 * @param string $provider  Git provider: 'github' or 'gitlab'.
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
	 * @param string $provider  Git provider: 'github' or 'gitlab'.
	 * @param string $full_name Repository full name (owner/repo).
	 * @param string $sha       Short commit SHA (7 characters).
	 * @return void
	 */
	public static function set_head( string $provider, string $full_name, string $sha ): void {
		$installed = (array) get_option( 'gitwire_installed', [] );
		$key       = $provider . ':' . $full_name;
		if ( isset( $installed[ $key ] ) ) {
			$installed[ $key ]['head'] = $sha;
			update_option( 'gitwire_installed', $installed );
		}
	}

	/**
	 * Fetches the latest remote commit SHA for a branch.
	 *
	 * @since 1.2.0
	 * @param Git_Provider_Interface $api    Provider API client.
	 * @param string                 $owner  Repository owner.
	 * @param string                 $repo   Repository name.
	 * @param string                 $branch Branch name.
	 * @return string|null Short SHA or null when unavailable.
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
	 * @param array<string, mixed> $rec       Installed record.
	 * @param string               $provider  Git provider.
	 * @param string               $full_name Repository full name.
	 * @param string               $branch    Branch name.
	 * @return string|null
	 */
	private static function resolve_remote_head_for_record( array $rec, string $provider, string $full_name, string $branch ): ?string {
		$remote_key = 'gitwire_remote_' . md5( $provider . ':' . $full_name . ':' . $branch );
		$cached     = get_transient( $remote_key );
		if ( is_string( $cached ) && $cached ) {
			return $cached;
		}

		$parts = explode( '/', $full_name, 2 );
		if ( count( $parts ) < 2 ) {
			return null;
		}

		$connection_id = $rec['connection_id'] ?? null;
		$api           = Provider_Factory::make( $provider, $connection_id );

		return self::fetch_remote_head_sha( $api, $parts[0], $parts[1], $branch );
	}

	/**
	 * Persists an installed record while preserving metadata such as head and installed_at.
	 *
	 * @since 1.2.0
	 * @param string               $record_key Installed record key.
	 * @param array<string, mixed> $record     New record payload.
	 * @param string|null          $head_sha   Head SHA to store; null keeps the previous value.
	 * @return array<string, mixed> Saved record.
	 */
	private static function save_installed_record( string $record_key, array $record, ?string $head_sha = null ): array {
		$installed = self::get_installed();
		$existing  = $installed[ $record_key ] ?? [];

		if ( ! empty( $existing['installed_at'] ) ) {
			$record['installed_at'] = $existing['installed_at'];
		}

		if ( $head_sha ) {
			$record['head'] = $head_sha;
		} elseif ( ! empty( $existing['head'] ) ) {
			$record['head'] = $existing['head'];
		}

		$installed[ $record_key ] = $record;

		// drop any other record that claimed the same directory (replace-install).
		$new_path = untrailingslashit( $record['install_path'] ?? '' );
		$evicted  = [];
		if ( $new_path ) {
			foreach ( array_keys( $installed ) as $key ) {
				if ( $key === $record_key ) {
					continue;
				}
				$other_path = untrailingslashit( $installed[ $key ]['install_path'] ?? '' );
				if ( $other_path && $other_path === $new_path ) {
					$evicted[] = $installed[ $key ];
					unset( $installed[ $key ] );
				}
			}
		}

		update_option( 'gitwire_installed', $installed );

		if ( ! empty( $evicted ) ) {
			$record['_evicted'] = $evicted;
		}

		return $record;
	}

	/**
	 * Core install routine: downloads, backs up, extracts, and records a repository.
	 *
	 * @since 1.0.0
	 * @param string $owner        Git owner or organisation.
	 * @param string $repo         Repository name.
	 * @param string $branch       Branch, tag, or SHA.
	 * @param string $slug         Directory slug for the installation.
	 * @param string $install_path Absolute filesystem path for the installation.
	 * @param string $type         Installation type: "plugin" or "theme".
	 * @param string $provider     Git provider: 'github' or 'gitlab'.
	 * @param bool   $replace      Whether to overwrite an existing directory instead of auto-renaming.
	 * @return array<string, mixed>|WP_Error Installed record on success, WP_Error on failure.
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
				delete_option( 'gitwire_pending_update' );
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
			update_option( 'gitwire_pending_update', $pending, false );
		}

		// Extract.
		$extracted = self::extract_zip( $zip_file, $install_path );
		wp_delete_file( $zip_file );

		if ( is_wp_error( $extracted ) ) {
			// Restore backup immediately (no fatal error needed).
			self::restore_backup( $install_path, $backup_path );
			if ( ! $sync_theme_guard ) {
				delete_option( 'gitwire_pending_update' );
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
				update_option( 'gitwire_pending_update', $pending, false );
			}
		}

		// Save record.
		$record = [
			'slug'          => $slug,
			'repo'          => $repo,
			'owner'         => $owner,
			'full_name'     => $full_name,
			'branch'        => $branch,
			'type'          => $type,
			'provider'      => $provider,
			'connection_id' => $connection_id,
			'install_path'  => $install_path,
			'plugin_file'   => 'plugin' === $type ? ( $pending['plugin_file'] ?? null ) : null,
			'installed_at'  => time(),
			'updated_at'    => time(),
			'slug_renamed'  => $slug_renamed,
		];

		$record_key             = $provider . ':' . $full_name;
		$installed              = self::get_installed();
		$pending['prev_record'] = $installed[ $record_key ] ?? null;
		$pending['provider']    = $provider;
		if ( ! $sync_theme_guard ) {
			update_option( 'gitwire_pending_update', $pending, false );
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
			update_option( 'gitwire_pending_update', $pending, false );

			self::refresh_plugin_runtime( $install_path, $slug );

			$activated = self::reactivate_plugin_after_update( $plugin_file );
			if ( is_wp_error( $activated ) ) {
				self::restore_backup( $install_path, $backup_path );
				delete_option( 'gitwire_pending_update' );
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
				delete_option( 'gitwire_pending_update' );

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
	 * @since 1.2.0
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
	 * @since 1.2.0
	 * @param string|null $backup_path Absolute backup path.
	 * @return void
	 */
	private static function finalize_successful_update( ?string $backup_path ): void {
		self::delete_backup_path( $backup_path );
		delete_option( 'gitwire_pending_update' );
	}

	/**
	 * Clears stale guard feedback before arming a new verify cycle.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private static function clear_guard_feedback(): void {
		delete_option( 'gitwire_fatal_notice' );
		Error_Handler::clear_bootstrap_verified();
	}

	/**
	 * Returns whether the installed plugin or theme is currently active.
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
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
