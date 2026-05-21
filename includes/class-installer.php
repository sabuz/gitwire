<?php
/**
 * Installer — downloads and extracts GitHub repositories as plugins or themes.
 *
 * @package GitHub_WP
 * @since 1.0.0
 */

namespace GitHub_WP;

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
		$installed   = (array) get_option( 'ghwp_installed', [] );
		$dirty       = false;

		foreach ( $installed as $full_name => $rec ) {
			if ( untrailingslashit( $rec['install_path'] ?? '' ) === $deleted_dir ) {
				unset( $installed[ $full_name ] );
				$dirty = true;
				break;
			}
		}

		if ( $dirty ) {
			update_option( 'ghwp_installed', $installed );
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
		$installed   = (array) get_option( 'ghwp_installed', [] );
		$dirty       = false;

		foreach ( $installed as $full_name => $rec ) {
			if ( untrailingslashit( $rec['install_path'] ?? '' ) === $deleted_dir ) {
				unset( $installed[ $full_name ] );
				$dirty = true;
				break;
			}
		}

		if ( $dirty ) {
			update_option( 'ghwp_installed', $installed );
		}
	}

	/**
	 * Installs or updates a repository as a WordPress plugin.
	 *
	 * @since 1.0.0
	 * @param string $owner  GitHub owner or organisation.
	 * @param string $repo   Repository name.
	 * @param string $branch Branch, tag, or SHA.
	 * @param string $slug   Desired directory slug (defaults to sanitised repo name).
	 * @return array<string, mixed>|WP_Error Installed record on success, WP_Error on failure.
	 */
	public static function install_plugin(
		string $owner,
		string $repo,
		string $branch,
		string $slug = ''
	): array|\WP_Error {
		if ( ! $slug ) {
			$slug = sanitize_title( $repo );
		}

		$destination = WP_PLUGIN_DIR . '/' . $slug;

		return self::run( $owner, $repo, $branch, $slug, $destination, 'plugin' );
	}

	/**
	 * Installs or updates a repository as a WordPress theme.
	 *
	 * @since 1.0.0
	 * @param string $owner  GitHub owner or organisation.
	 * @param string $repo   Repository name.
	 * @param string $branch Branch, tag, or SHA.
	 * @param string $slug   Desired directory slug (defaults to sanitised repo name).
	 * @return array<string, mixed>|WP_Error Installed record on success, WP_Error on failure.
	 */
	public static function install_theme(
		string $owner,
		string $repo,
		string $branch,
		string $slug = ''
	): array|\WP_Error {
		if ( ! $slug ) {
			$slug = sanitize_title( $repo );
		}

		$destination = get_theme_root() . '/' . $slug;

		return self::run( $owner, $repo, $branch, $slug, $destination, 'theme' );
	}

	/**
	 * Switches the active branch for an already-installed repository.
	 *
	 * @since 1.0.0
	 * @param string $full_name Repository full name (owner/repo).
	 * @param string $new_branch Branch to switch to.
	 * @return array<string, mixed>|WP_Error Updated record on success, WP_Error on failure.
	 */
	public static function switch_branch( string $full_name, string $new_branch ): array|\WP_Error {
		$installed = self::get_installed();

		if ( ! isset( $installed[ $full_name ] ) ) {
			return new \WP_Error( 'ghwp_not_found', 'Repository is not installed.' );
		}

		$rec    = $installed[ $full_name ];
		$parts  = explode( '/', $full_name );
		$owner  = $parts[0];
		$repo   = $parts[1];
		$method = 'theme' === $rec['type'] ? 'install_theme' : 'install_plugin';

		return self::$method( $owner, $repo, $new_branch, $rec['slug'] );
	}

	/**
	 * Removes an installed repository from the filesystem and the installation record.
	 * Does NOT deactivate the plugin or theme first.
	 *
	 * @since 1.0.0
	 * @param string $full_name Repository full name (owner/repo).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function remove( string $full_name ): bool|\WP_Error {
		$installed = self::get_installed();

		if ( ! isset( $installed[ $full_name ] ) ) {
			return new \WP_Error( 'ghwp_not_found', 'Repository is not installed.' );
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

	/**
	 * Returns all currently installed repository records.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Map of full_name => record.
	 */
	public static function get_installed(): array {
		return (array) get_option( 'ghwp_installed', [] );
	}

	/**
	 * Returns a single installation record by full name.
	 *
	 * @since 1.0.0
	 * @param string $full_name Repository full name (owner/repo).
	 * @return array<string, mixed>|null Record array, or null if not found.
	 */
	public static function get_record( string $full_name ): ?array {
		$installed = self::get_installed();
		return $installed[ $full_name ] ?? null;
	}

	/**
	 * Core install routine: downloads, backs up, extracts, and records a repository.
	 *
	 * @since 1.0.0
	 * @param string $owner        GitHub owner or organisation.
	 * @param string $repo         Repository name.
	 * @param string $branch       Branch, tag, or SHA.
	 * @param string $slug         Directory slug for the installation.
	 * @param string $install_path Absolute filesystem path for the installation.
	 * @param string $type         Installation type: "plugin" or "theme".
	 * @return array<string, mixed>|WP_Error Installed record on success, WP_Error on failure.
	 */
	private static function run(
		string $owner,
		string $repo,
		string $branch,
		string $slug,
		string $install_path,
		string $type
	): array|\WP_Error {
		self::init_fs();

		$settings  = (array) get_option( 'ghwp_settings', [] );
		$api       = new API( $settings['token'] ?? '' );
		$full_name = $owner . '/' . $repo;

		// Download.
		$zip_file = $api->download_zip( $owner, $repo, $branch );
		if ( is_wp_error( $zip_file ) ) {
			return $zip_file;
		}

		// Backup existing installation (for fatal-error rollback).
		$backup_path = null;
		if ( is_dir( $install_path ) ) {
			$backup_path = $install_path . '--ghwp-bak-' . time();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! rename( $install_path, $backup_path ) ) {
				wp_delete_file( $zip_file );
				return new \WP_Error( 'ghwp_backup_failed', 'Could not create backup of existing installation.' );
			}
		}

		// Register pending-update so the error handler can roll back.
		$pending = [
			'full_name'    => $full_name,
			'type'         => $type,
			'slug'         => $slug,
			'install_path' => $install_path,
			'backup_path'  => $backup_path,
			'plugin_file'  => null,
		];

		// Record the currently-active plugin file (if this is an update).
		if ( 'plugin' === $type ) {
			$installed = self::get_installed();
			if ( isset( $installed[ $full_name ]['plugin_file'] ) ) {
				$pending['plugin_file'] = $installed[ $full_name ]['plugin_file'];
			}
		}

		update_option( 'ghwp_pending_update', $pending, false );

		// Extract.
		$extracted = self::extract_zip( $zip_file, $install_path );
		wp_delete_file( $zip_file );

		if ( is_wp_error( $extracted ) ) {
			// Restore backup immediately (no fatal error needed).
			self::restore_backup( $install_path, $backup_path );
			delete_option( 'ghwp_pending_update' );
			return $extracted;
		}

		// Detect main plugin file.
		if ( 'plugin' === $type ) {
			$plugin_file            = self::find_plugin_file( $install_path, $slug );
			$pending['plugin_file'] = $plugin_file;
			update_option( 'ghwp_pending_update', $pending, false );
		}

		// Save record.
		$record = [
			'slug'         => $slug,
			'repo'         => $repo,
			'owner'        => $owner,
			'full_name'    => $full_name,
			'branch'       => $branch,
			'type'         => $type,
			'install_path' => $install_path,
			'plugin_file'  => 'plugin' === $type ? ( $pending['plugin_file'] ?? null ) : null,
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
		$tmp_dir = get_temp_dir() . 'ghwp-extract-' . uniqid( '', true );

		$result = unzip_file( $zip_path, $tmp_dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// GitHub ZIPs contain exactly one top-level folder.
		$subdirs = glob( trailingslashit( $tmp_dir ) . '*', GLOB_ONLYDIR );
		if ( empty( $subdirs ) ) {
			$wp_filesystem->delete( $tmp_dir, true );
			return new \WP_Error( 'ghwp_empty_zip', 'The downloaded ZIP contained no directory.' );
		}

		$extracted_folder = $subdirs[0];

		// Move to final destination.
		if ( ! $wp_filesystem->move( $extracted_folder, $destination, true ) ) {
			$wp_filesystem->delete( $tmp_dir, true );
			return new \WP_Error( 'ghwp_move_failed', 'Could not move extracted files to destination.' );
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
	private static function find_plugin_file( string $plugin_dir, string $slug ): ?string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Refresh the plugin cache for our folder.
		$plugins = get_plugins( '/' . $slug );

		if ( ! empty( $plugins ) ) {
			$first_key = array_key_first( $plugins );
			return $first_key;
		}

		return null;
	}

	/**
	 * Restores a backup directory to the original install path.
	 *
	 * @since 1.0.0
	 * @param string      $install_path Absolute install path.
	 * @param string|null $backup_path  Absolute backup path, or null if no backup exists.
	 * @return void
	 */
	public static function restore_backup( string $install_path, ?string $backup_path ): void {
		if ( ! $backup_path || ! is_dir( $backup_path ) ) {
			return;
		}

		if ( is_dir( $install_path ) ) {
			self::rmdir_recursive( $install_path );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		@rename( $backup_path, $install_path );
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
