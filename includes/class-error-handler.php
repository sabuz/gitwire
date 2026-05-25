<?php
/**
 * Shutdown-based fatal-error handler for safe plugin installation.
 *
 * Registers a PHP shutdown function that detects fatal errors introduced
 * by a plugin/theme we just installed or updated. On fatal: restores the
 * backup directory, deactivates the plugin (if it was active), and stores
 * a fatal notice for the Git admin UI.
 *
 * Uses only plain PHP and raw MySQL so it works even when WordPress has
 * not finished bootstrapping.
 *
 * @package Git_WP
 * @since 1.0.0
 */

namespace Git_WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a shutdown handler that rolls back broken plugin/theme installs.
 */
class Error_Handler {

	/**
	 * Whether the shutdown function has already been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Whether the current request should mark bootstrap verification on shutdown.
	 *
	 * @var bool
	 */
	private static bool $rest_bootstrap_verify = false;

	/**
	 * Registers the PHP shutdown function (idempotent).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		register_shutdown_function( [ self::class, 'handle_shutdown' ] );
		register_shutdown_function( [ self::class, 'release_verified_guard_on_git_page' ] );
	}

	/**
	 * Shutdown callback — checks for a fatal error and rolls back if needed.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function handle_shutdown(): void {
		$error = error_get_last();
		if ( ! $error ) {
			return;
		}

		$fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
		if ( ! in_array( $error['type'], $fatal_types, true ) ) {
			return;
		}

		// Read the pending-update record directly from the DB.
		$pending = self::db_get_option( 'gwp_pending_update' );
		if ( ! $pending ) {
			return;
		}

		// Immediately clear the flag so we don't loop.
		self::clear_pending_update();

		$install_path = $pending['install_path'] ?? null;
		$backup_path  = $pending['backup_path'] ?? null;
		$plugin_file  = $pending['plugin_file'] ?? null;
		$full_name    = $pending['full_name'] ?? 'unknown';
		$type         = $pending['type'] ?? 'plugin';
		$context      = $pending['context'] ?? 'install';

		if ( 'activation' === $context ) {
			if ( 'plugin' === $type && $plugin_file ) {
				self::deactivate_plugin( $plugin_file );
			} elseif ( 'theme' === $type ) {
				self::restore_theme(
					$pending['previous_stylesheet'] ?? null,
					$pending['previous_template'] ?? null
				);
			}
		} else {
			// Restore backup.
			if ( $backup_path && is_dir( $backup_path ) ) {
				if ( $install_path && is_dir( $install_path ) ) {
					Installer::rmdir_recursive( $install_path );
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				@rename( $backup_path, $install_path );
			} elseif ( $install_path && is_dir( $install_path ) ) {
				// No backup means it was a fresh install — remove the broken copy.
				Installer::rmdir_recursive( $install_path );
			}

			// Deactivate plugin.
			if ( 'plugin' === $type && $plugin_file ) {
				self::deactivate_plugin( $plugin_file );
			}
		}

		// Store fatal notice for the Git admin UI.
		$notice = [
			'full_name' => $full_name,
			'type'      => $type,
			'context'   => $context,
			'error'     => sprintf( '%s in %s on line %d', $error['message'], $error['file'], $error['line'] ),
			'time'      => time(),
			'restored'  => 'activation' === $context ? true : (bool) $backup_path,
		];

		self::db_update_option( 'gwp_fatal_notice', $notice );
		self::clear_pending_update();
		self::clear_bootstrap_verified();

		if ( 'activation' === $context && 'theme' !== $type ) {
			self::redirect_to_git_admin();
		}
	}

	/**
	 * Sends the admin back to Git after an activation fatal was recovered.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private static function redirect_to_git_admin(): void {
		if ( ! function_exists( 'admin_url' ) ) {
			return;
		}

		$url = admin_url( 'admin.php?page=git' );

		if ( ! headers_sent() ) {
			wp_safe_redirect( $url );
			exit;
		}

		echo '<meta http-equiv="refresh" content="0;url=' . esc_attr( $url ) . '">';
		if ( function_exists( 'esc_js' ) ) {
			echo '<script>window.location.replace("' . esc_js( $url ) . '");</script>';
		}
		exit;
	}

	/**
	 * Defers bootstrap verification for a REST request until shutdown.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function arm_rest_bootstrap_verify(): void {
		if ( self::$rest_bootstrap_verify ) {
			return;
		}

		self::$rest_bootstrap_verify = true;
		register_shutdown_function( [ self::class, 'mark_rest_bootstrap_verified_on_shutdown' ] );
	}

	/**
	 * Marks bootstrap verification after a REST bootstrap request finishes cleanly.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function mark_rest_bootstrap_verified_on_shutdown(): void {
		if ( ! self::$rest_bootstrap_verify ) {
			return;
		}

		self::$rest_bootstrap_verify = false;

		if ( self::has_fatal_shutdown_error() ) {
			return;
		}

		self::try_mark_bootstrap_verified();
	}

	/**
	 * Finalizes a verified guard after the Git admin page reloads cleanly.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function release_verified_guard_on_git_page(): void {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( ! self::is_git_admin_page() ) {
			return;
		}

		if ( self::has_fatal_shutdown_error() ) {
			return;
		}

		self::finalize_verified_guard_if_ready();
	}

	/**
	 * Finalizes a verified guard when bootstrap checks have already passed.
	 *
	 * @since 1.2.0
	 * @return array<string, string>|null Finalized record metadata, or null when not ready.
	 */
	public static function finalize_verified_guard_if_ready(): ?array {
		$pending = get_option( 'gwp_pending_update' );
		if ( ! is_array( $pending ) ) {
			return null;
		}

		$context = $pending['context'] ?? '';
		if ( ! in_array( $context, [ 'activation', 'update' ], true ) ) {
			return null;
		}

		if ( ! self::is_bootstrap_verified( $pending ) || ! self::is_pending_target_active( $pending ) ) {
			return null;
		}

		$result = [
			'full_name' => $pending['full_name'] ?? '',
			'type'      => $pending['type'] ?? '',
			'context'   => $context,
		];

		self::finalize_guard_success( $pending );

		return $result;
	}

	/**
	 * Clears the guard after both iframe and Git admin bootstraps succeed.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending Pending guard record.
	 * @return void
	 */
	private static function finalize_guard_success( array $pending ): void {
		Installer::delete_backup_path( $pending['backup_path'] ?? null );
		self::clear_bootstrap_verified();
		delete_option( 'gwp_pending_update' );
	}

	/**
	 * Marks bootstrap verification when the guarded target is already active.
	 *
	 * @since 1.2.0
	 * @return bool True when the pending guard was marked verified.
	 */
	public static function try_mark_bootstrap_verified(): bool {
		$pending = get_option( 'gwp_pending_update' );
		if ( ! is_array( $pending ) ) {
			return false;
		}

		if ( ! in_array( $pending['context'] ?? '', [ 'activation', 'update' ], true ) ) {
			return false;
		}

		if ( ! self::is_pending_target_active( $pending ) ) {
			return false;
		}

		self::mark_bootstrap_verified( $pending );

		return true;
	}

	/**
	 * Records that the hidden iframe bootstrap completed without error.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending Pending guard record.
	 * @return void
	 */
	private static function mark_bootstrap_verified( array $pending ): void {
		set_transient(
			'gwp_bootstrap_verified',
			self::pending_fingerprint( $pending ),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Returns whether the iframe bootstrap transient matches the pending guard.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending Pending guard record.
	 * @return bool
	 */
	public static function is_bootstrap_verified( array $pending ): bool {
		$stored = get_transient( 'gwp_bootstrap_verified' );
		return is_string( $stored ) && self::pending_fingerprint( $pending ) === $stored;
	}

	/**
	 * Builds a stable fingerprint for a pending guard record.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending Pending guard record.
	 * @return string
	 */
	private static function pending_fingerprint( array $pending ): string {
		return md5(
			wp_json_encode(
				[
					'full_name'    => $pending['full_name'] ?? '',
					'context'      => $pending['context'] ?? '',
					'install_path' => $pending['install_path'] ?? '',
					'slug'         => $pending['slug'] ?? '',
				]
			)
		);
	}

	/**
	 * Clears the iframe bootstrap verified transient.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function clear_bootstrap_verified(): void {
		delete_transient( 'gwp_bootstrap_verified' );
	}

	/**
	 * Rolls back and clears a pending guard when client verification times out.
	 *
	 * @since 1.2.0
	 * @return bool True when a pending guard was cleared.
	 */
	public static function abort_pending_guard(): bool {
		$pending = get_option( 'gwp_pending_update' );
		if ( ! is_array( $pending ) ) {
			self::clear_bootstrap_verified();
			return false;
		}

		$context = $pending['context'] ?? '';
		if ( ! in_array( $context, [ 'activation', 'update' ], true ) ) {
			delete_option( 'gwp_pending_update' );
			self::clear_bootstrap_verified();
			return true;
		}

		$type         = $pending['type'] ?? 'plugin';
		$install_path = $pending['install_path'] ?? '';
		$backup_path  = $pending['backup_path'] ?? null;
		$plugin_file  = $pending['plugin_file'] ?? null;

		if ( 'update' === $context && $backup_path && $install_path ) {
			Installer::restore_backup( $install_path, $backup_path );
			Installer::delete_backup_path( $backup_path );
		} elseif ( 'activation' === $context ) {
			if ( 'theme' === $type ) {
				self::restore_theme(
					$pending['previous_stylesheet'] ?? null,
					$pending['previous_template'] ?? null
				);
			} elseif ( 'plugin' === $type && $plugin_file ) {
				self::deactivate_plugin( $plugin_file );
			}
		}

		// Restore the installed record that was overwritten before the guard was armed.
		$provider    = $pending['provider'] ?? null;
		$prev_record = $pending['prev_record'] ?? null;
		$full_name   = $pending['full_name'] ?? null;

		if ( $provider && $full_name && is_array( $prev_record ) ) {
			$record_key               = $provider . ':' . $full_name;
			$installed                = Installer::get_installed();
			$installed[ $record_key ] = $prev_record;
			update_option( 'gwp_installed', $installed );
		}

		delete_option( 'gwp_pending_update' );
		self::clear_bootstrap_verified();

		return true;
	}

	/**
	 * Returns whether the current request ended with a fatal PHP error.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	private static function has_fatal_shutdown_error(): bool {
		$error = error_get_last();
		if ( ! $error ) {
			return false;
		}

		$fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
		return in_array( $error['type'], $fatal_types, true );
	}

	/**
	 * Returns whether the current request is the Git admin screen.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	private static function is_git_admin_page(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) && 'git' === $_GET['page'];
	}

	/**
	 * Returns whether the guarded plugin or theme is the one currently active.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending Pending guard record.
	 * @return bool
	 */
	private static function is_pending_target_active( array $pending ): bool {
		$type = $pending['type'] ?? '';

		if ( 'theme' === $type ) {
			$target = $pending['target_stylesheet'] ?? $pending['slug'] ?? '';
			if ( ! $target || ! function_exists( 'get_stylesheet' ) ) {
				return false;
			}

			$stylesheet = get_stylesheet();
			$template   = get_template();

			return $target === $stylesheet || $target === $template;
		}

		if ( 'plugin' === $type ) {
			$plugin_file = $pending['plugin_file'] ?? '';
			return $plugin_file
				&& function_exists( 'is_plugin_active' )
				&& is_plugin_active( $plugin_file );
		}

		return false;
	}

	/**
	 * Reads a WordPress option directly from the database.
	 * Safe to call before WordPress has fully loaded.
	 *
	 * @since 1.0.0
	 * @param string $name Option name.
	 * @return mixed|null Unserialized option value, or null if not found.
	 */
	private static function db_get_option( string $name ): mixed {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
		);
		return $row ? maybe_unserialize( $row ) : null;
	}

	/**
	 * Clears the pending update flag and any cached copy.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private static function clear_pending_update(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( 'gwp_pending_update' );
			return;
		}

		self::db_delete_option( 'gwp_pending_update' );
	}

	/**
	 * Deletes a WordPress option directly from the database.
	 * Safe to call before WordPress has fully loaded.
	 *
	 * @since 1.0.0
	 * @param string $name Option name.
	 * @return void
	 */
	private static function db_delete_option( string $name ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->options, [ 'option_name' => $name ], [ '%s' ] );
	}

	/**
	 * Inserts or updates a WordPress option directly in the database.
	 * Safe to call before WordPress has fully loaded.
	 *
	 * @since 1.0.0
	 * @param string $name  Option name.
	 * @param mixed  $value Option value (will be serialized).
	 * @return void
	 */
	private static function db_update_option( string $name, mixed $value ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$serialized = maybe_serialize( $value );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $name )
		);
		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->options,
				[ 'option_value' => $serialized ],
				[ 'option_name' => $name ],
				[ '%s' ],
				[ '%s' ]
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->options,
				[
					'option_name'  => $name,
					'option_value' => $serialized,
					'autoload'     => 'no',
				],
				[ '%s', '%s', '%s' ]
			);
		}
	}

	/**
	 * Restores the previous active theme directly in the database.
	 *
	 * @since 1.2.0
	 * @param string|null $stylesheet Previous stylesheet slug.
	 * @param string|null $template   Previous template slug.
	 * @return void
	 */
	private static function restore_theme( ?string $stylesheet, ?string $template ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! $stylesheet ) {
			return;
		}

		if ( ! $template ) {
			$template = $stylesheet;
		}

		foreach (
			[
				'stylesheet' => $stylesheet,
				'template'   => $template,
			] as $option_name => $option_value
		) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->options,
				[ 'option_value' => $option_value ],
				[ 'option_name' => $option_name ],
				[ '%s' ],
				[ '%s' ]
			);
		}
	}

	/**
	 * Removes a plugin from the active_plugins option directly in the database.
	 * Safe to call during a shutdown handler where WordPress may not be loaded.
	 *
	 * @since 1.0.0
	 * @param string $plugin_file Plugin file relative to wp-content/plugins.
	 * @return void
	 */
	private static function deactivate_plugin( string $plugin_file ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$raw = $wpdb->get_var(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'"
		);
		if ( ! $raw ) {
			return;
		}
		$active = maybe_unserialize( $raw );
		if ( ! is_array( $active ) ) {
			return;
		}
		$active = array_values( array_diff( $active, [ $plugin_file ] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->options,
			[ 'option_value' => serialize( $active ) ], // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			[ 'option_name' => 'active_plugins' ],
			[ '%s' ],
			[ '%s' ]
		);
	}
}
