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
		add_action( 'after_setup_theme', [ self::class, 'release_activation_guard_if_stable' ], 99999 );
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
	 * Clears a pending activation guard after the new theme or plugin loads cleanly.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function release_activation_guard_if_stable(): void {
		$pending = get_option( 'gwp_pending_update' );
		if ( ! is_array( $pending ) || 'activation' !== ( $pending['context'] ?? '' ) ) {
			return;
		}

		$type = $pending['type'] ?? '';

		if ( 'theme' === $type ) {
			$target = $pending['target_stylesheet'] ?? '';
			if ( $target && get_stylesheet() === $target ) {
				self::mark_activation_success( $pending );
				delete_option( 'gwp_pending_update' );
			}
			return;
		}

		if ( 'plugin' === $type ) {
			$plugin_file = $pending['plugin_file'] ?? '';
			if ( $plugin_file && function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) ) {
				self::mark_activation_success( $pending );
				delete_option( 'gwp_pending_update' );
			}
		}
	}

	/**
	 * Records a verified activation for the Git admin UI toast.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending Pending activation record.
	 * @return void
	 */
	private static function mark_activation_success( array $pending ): void {
		set_transient(
			'gwp_activation_success',
			[
				'full_name' => $pending['full_name'] ?? '',
				'type'      => $pending['type'] ?? '',
			],
			MINUTE_IN_SECONDS
		);
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
