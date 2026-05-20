<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers a PHP shutdown function that detects fatal errors introduced
 * by a plugin/theme we just installed or updated.
 *
 * On fatal: restores the backup directory, deactivates the plugin (if it
 * was active), and stores an admin notice for the next page load.
 *
 * Uses only plain PHP and raw MySQL so it works even when WordPress has
 * not finished bootstrapping.
 */
class GHWP_Error_Handler {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		register_shutdown_function( [ self::class, 'handle_shutdown' ] );
	}

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
		$pending = self::db_get_option( 'ghwp_pending_update' );
		if ( ! $pending ) {
			return;
		}

		// Immediately clear the flag so we don't loop.
		self::db_delete_option( 'ghwp_pending_update' );

		$install_path = $pending['install_path'] ?? null;
		$backup_path  = $pending['backup_path'] ?? null;
		$plugin_file  = $pending['plugin_file'] ?? null;
		$full_name    = $pending['full_name'] ?? 'unknown';
		$type         = $pending['type'] ?? 'plugin';

		// ----- Restore backup -------------------------------------------
		if ( $backup_path && is_dir( $backup_path ) ) {
			if ( $install_path && is_dir( $install_path ) ) {
				GHWP_Installer::rmdir_recursive( $install_path );
			}
			@rename( $backup_path, $install_path );
		} elseif ( $install_path && is_dir( $install_path ) ) {
			// No backup means it was a fresh install – remove the broken copy.
			GHWP_Installer::rmdir_recursive( $install_path );
		}

		// ----- Deactivate plugin ----------------------------------------
		if ( $type === 'plugin' && $plugin_file ) {
			self::deactivate_plugin( $plugin_file );
		}

		// ----- Store admin notice ----------------------------------------
		$notice = [
			'full_name' => $full_name,
			'type'      => $type,
			'error'     => sprintf( '%s in %s on line %d', $error['message'], $error['file'], $error['line'] ),
			'time'      => time(),
			'restored'  => (bool) $backup_path,
		];

		self::db_update_option( 'ghwp_fatal_notice', $notice );
	}

	// -----------------------------------------------------------------------
	// Database helpers (bypass WP functions in case WP is partially loaded)
	// -----------------------------------------------------------------------

	private static function db_get_option( string $name ): mixed {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
		);
		return $row ? maybe_unserialize( $row ) : null;
	}

	private static function db_delete_option( string $name ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->options, [ 'option_name' => $name ], [ '%s' ] );
	}

	private static function db_update_option( string $name, mixed $value ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$serialized = maybe_serialize( $value );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $name )
		);
		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$wpdb->options,
				[ 'option_value' => $serialized ],
				[ 'option_name' => $name ],
				[ '%s' ],
				[ '%s' ]
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

	private static function deactivate_plugin( string $plugin_file ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->options,
			[ 'option_value' => serialize( $active ) ],
			[ 'option_name' => 'active_plugins' ],
			[ '%s' ],
			[ '%s' ]
		);
	}
}
