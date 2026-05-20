<?php
defined( 'ABSPATH' ) || exit;

class GHWP_Installer {

	// -----------------------------------------------------------------------
	// Boot
	// -----------------------------------------------------------------------

	public static function init(): void {
		add_action( 'deleted_plugin', [ self::class, 'on_plugin_deleted' ] );
		add_action( 'deleted_theme', [ self::class, 'on_theme_deleted' ] );
	}

	public static function on_plugin_deleted( string $plugin_file ): void {
		$installed = (array) get_option( 'ghwp_installed', [] );
		$dirty     = false;

		foreach ( $installed as $full_name => $rec ) {
			if ( ( $rec['plugin_file'] ?? '' ) === $plugin_file ) {
				unset( $installed[ $full_name ] );
				$dirty = true;
			}
		}

		if ( $dirty ) {
			update_option( 'ghwp_installed', $installed );
		}
	}

	public static function on_theme_deleted( string $stylesheet ): void {
		$installed = (array) get_option( 'ghwp_installed', [] );
		$dirty     = false;

		foreach ( $installed as $full_name => $rec ) {
			if ( ( $rec['type'] ?? '' ) === 'theme' && ( $rec['slug'] ?? '' ) === $stylesheet ) {
				unset( $installed[ $full_name ] );
				$dirty = true;
			}
		}

		if ( $dirty ) {
			update_option( 'ghwp_installed', $installed );
		}
	}

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Install or update a repository as a plugin.
	 *
	 * @param string $owner    GitHub owner/org
	 * @param string $repo     Repository name
	 * @param string $branch   Branch / tag / SHA
	 * @param string $slug     Desired directory slug (defaults to repo name)
	 * @return array|WP_Error  On success: installed record array.
	 */
	public static function install_plugin(
		string $owner,
		string $repo,
		string $branch,
		string $slug = ''
	): array|WP_Error {
		if ( ! $slug ) {
			$slug = sanitize_title( $repo );
		}

		$destination = WP_PLUGIN_DIR . '/' . $slug;

		return self::run( $owner, $repo, $branch, $slug, $destination, 'plugin' );
	}

	/**
	 * Install or update a repository as a theme.
	 */
	public static function install_theme(
		string $owner,
		string $repo,
		string $branch,
		string $slug = ''
	): array|WP_Error {
		if ( ! $slug ) {
			$slug = sanitize_title( $repo );
		}

		$destination = get_theme_root() . '/' . $slug;

		return self::run( $owner, $repo, $branch, $slug, $destination, 'theme' );
	}

	/**
	 * Switch branch for an already-installed repo.
	 */
	public static function switch_branch( string $full_name, string $new_branch ): array|WP_Error {
		$installed = self::get_installed();

		if ( ! isset( $installed[ $full_name ] ) ) {
			return new WP_Error( 'ghwp_not_found', 'Repository is not installed.' );
		}

		$rec    = $installed[ $full_name ];
		$parts  = explode( '/', $full_name );
		$owner  = $parts[0];
		$repo   = $parts[1];
		$method = $rec['type'] === 'theme' ? 'install_theme' : 'install_plugin';

		return self::$method( $owner, $repo, $new_branch, $rec['slug'] );
	}

	/**
	 * Remove an installed repository from the filesystem and from the record.
	 * Does NOT deactivate the plugin/theme first.
	 */
	public static function remove( string $full_name ): true|WP_Error {
		$installed = self::get_installed();

		if ( ! isset( $installed[ $full_name ] ) ) {
			return new WP_Error( 'ghwp_not_found', 'Repository is not installed.' );
		}

		$rec  = $installed[ $full_name ];
		$path = $rec['install_path'];

		if ( is_dir( $path ) ) {
			self::init_fs();
			global $wp_filesystem;
			$wp_filesystem->delete( $path, true );
		}

		unset( $installed[ $full_name ] );
		update_option( 'ghwp_installed', $installed );

		return true;
	}

	// -----------------------------------------------------------------------
	// Installed-record helpers (static storage in wp_options)
	// -----------------------------------------------------------------------

	public static function get_installed(): array {
		return (array) get_option( 'ghwp_installed', [] );
	}

	public static function get_record( string $full_name ): ?array {
		$installed = self::get_installed();
		return $installed[ $full_name ] ?? null;
	}

	// -----------------------------------------------------------------------
	// Core install logic
	// -----------------------------------------------------------------------

	private static function run(
		string $owner,
		string $repo,
		string $branch,
		string $slug,
		string $install_path,
		string $type
	): array|WP_Error {
		self::init_fs();

		$settings  = (array) get_option( 'ghwp_settings', [] );
		$api       = new GHWP_API( $settings['token'] ?? '' );
		$full_name = $owner . '/' . $repo;

		// ----- Download --------------------------------------------------
		$zip_file = $api->download_zip( $owner, $repo, $branch );
		if ( is_wp_error( $zip_file ) ) {
			return $zip_file;
		}

		// ----- Backup existing installation (for fatal-error rollback) ----
		$backup_path = null;
		if ( is_dir( $install_path ) ) {
			$backup_path = $install_path . '--ghwp-bak-' . time();
			if ( ! rename( $install_path, $backup_path ) ) {
				@unlink( $zip_file );
				return new WP_Error( 'ghwp_backup_failed', 'Could not create backup of existing installation.' );
			}
		}

		// ----- Register pending-update so the error handler can rollback --
		$pending = [
			'full_name'    => $full_name,
			'type'         => $type,
			'slug'         => $slug,
			'install_path' => $install_path,
			'backup_path'  => $backup_path,
			'plugin_file'  => null, // filled below for plugins
		];

		// Record the currently-active plugin file (if this is an update)
		if ( $type === 'plugin' ) {
			$installed = self::get_installed();
			if ( isset( $installed[ $full_name ]['plugin_file'] ) ) {
				$pending['plugin_file'] = $installed[ $full_name ]['plugin_file'];
			}
		}

		update_option( 'ghwp_pending_update', $pending, false );

		// ----- Extract ---------------------------------------------------
		$extracted = self::extract_zip( $zip_file, $install_path );
		@unlink( $zip_file );

		if ( is_wp_error( $extracted ) ) {
			// Restore backup immediately (no fatal error needed)
			self::restore_backup( $install_path, $backup_path );
			delete_option( 'ghwp_pending_update' );
			return $extracted;
		}

		// ----- Detect main plugin file -----------------------------------
		if ( $type === 'plugin' ) {
			$plugin_file            = self::find_plugin_file( $install_path, $slug );
			$pending['plugin_file'] = $plugin_file;
			update_option( 'ghwp_pending_update', $pending, false );
		}

		// ----- Save record -----------------------------------------------
		$record = [
			'slug'         => $slug,
			'repo'         => $repo,
			'owner'        => $owner,
			'full_name'    => $full_name,
			'branch'       => $branch,
			'type'         => $type,
			'install_path' => $install_path,
			'plugin_file'  => $type === 'plugin' ? ( $pending['plugin_file'] ?? null ) : null,
			'installed_at' => time(),
			'updated_at'   => time(),
		];

		$installed               = self::get_installed();
		$installed[ $full_name ] = $record;
		update_option( 'ghwp_installed', $installed );

		// Remove old backup now that everything succeeded.
		if ( $backup_path && is_dir( $backup_path ) ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $backup_path, true );
		}

		delete_option( 'ghwp_pending_update' );

		return $record;
	}

	// -----------------------------------------------------------------------
	// ZIP extraction
	// -----------------------------------------------------------------------

	private static function extract_zip( string $zip_path, string $destination ): true|WP_Error {
		global $wp_filesystem;

		// Unzip to a temp directory first.
		$tmp_dir = get_temp_dir() . 'ghwp-extract-' . uniqid( '', true );

		$result = unzip_file( $zip_path, $tmp_dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// GitHub ZIPs contain exactly one top-level folder.
		$subdirs = glob( trailingslashit( $tmp_dir ) . '*', GLOB_ONLYDIR );
		if ( empty( $subdirs ) ) {
			$wp_filesystem->delete( $tmp_dir, true );
			return new WP_Error( 'ghwp_empty_zip', 'The downloaded ZIP contained no directory.' );
		}

		$extracted_folder = $subdirs[0];

		// Move to final destination.
		if ( ! $wp_filesystem->move( $extracted_folder, $destination, true ) ) {
			$wp_filesystem->delete( $tmp_dir, true );
			return new WP_Error( 'ghwp_move_failed', 'Could not move extracted files to destination.' );
		}

		$wp_filesystem->delete( $tmp_dir, true );

		return true;
	}

	// -----------------------------------------------------------------------
	// Plugin-file detection
	// -----------------------------------------------------------------------

	/**
	 * Finds the main plugin file by scanning for "Plugin Name:" header.
	 * Returns path relative to WP_PLUGIN_DIR (e.g. "my-plugin/my-plugin.php").
	 */
	private static function find_plugin_file( string $plugin_dir, string $slug ): ?string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Refresh the plugin cache for our folder.
		$plugins = get_plugins( '/' . basename( $plugin_dir ) );

		if ( ! empty( $plugins ) ) {
			$first_key = array_key_first( $plugins );
			return $first_key; // e.g. "my-plugin/my-plugin.php"
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Backup restore (used by error handler and inline)
	// -----------------------------------------------------------------------

	public static function restore_backup( string $install_path, ?string $backup_path ): void {
		if ( ! $backup_path || ! is_dir( $backup_path ) ) {
			return;
		}

		if ( is_dir( $install_path ) ) {
			self::rmdir_recursive( $install_path );
		}

		@rename( $backup_path, $install_path );
	}

	/**
	 * Pure-PHP recursive delete (safe to call from shutdown handler where
	 * WP Filesystem may not be available).
	 */
	public static function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = @scandir( $dir );
		if ( ! $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				self::rmdir_recursive( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	// -----------------------------------------------------------------------
	// WP Filesystem bootstrap
	// -----------------------------------------------------------------------

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
