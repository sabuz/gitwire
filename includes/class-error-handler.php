<?php
/**
 * Shutdown-based fatal-error handler for safe plugin installation.
 *
 * Registers a PHP shutdown function that detects fatal errors introduced
 * by a plugin/theme we just installed or updated. On fatal: restores the
 * backup directory, deactivates the plugin (if it was active), and stores
 * a fatal notice for the Gitwire admin UI.
 *
 * Uses only plain PHP and raw MySQL so it works even when WordPress has
 * not finished bootstrapping.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

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
	 * Set when our exception handler fires, so handle_shutdown can treat it as fatal.
	 *
	 * @var bool
	 */
	private static bool $had_uncaught_exception = false;

	/**
	 * Message from the uncaught exception, used for the fatal notice.
	 *
	 * @var string
	 */
	private static string $exception_message = '';

	/**
	 * File where the uncaught exception was thrown.
	 *
	 * @var string
	 */
	private static string $exception_file = '';

	/**
	 * Line number where the uncaught exception was thrown.
	 *
	 * @var int
	 */
	private static int $exception_line = 0;

	/**
	 * The exception handler that was active when Gitwire registered its own.
	 *
	 * @var callable|null
	 */
	private static $previous_exception_handler = null;

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
		register_shutdown_function( [ self::class, 'mark_guard_verified_on_verify_request' ] );
		add_action( 'admin_init', [ self::class, 'finish_verify_bootstrap_request' ], 1 );
		add_action( 'admin_init', [ self::class, 'clear_stale_activation_guard' ], 5 );
		add_action( 'admin_init', [ self::class, 'clear_stale_update_guard' ], 6 );
		add_action( 'admin_init', [ self::class, 'finalize_verified_guard_on_git_page' ], 99999 );
		add_action( 'template_redirect', [ self::class, 'finish_verify_bootstrap_request' ], PHP_INT_MAX );

		// late registration puts us above debug plugins (e.g. QM) in the exception-handler chain.
		add_action( 'plugins_loaded', [ self::class, 'register_exception_handler' ], PHP_INT_MAX );
	}

	/**
	 * Registers Gitwire's exception handler after all plugins have set theirs.
	 *
	 * @since 1.2.1
	 * @return void
	 */
	public static function register_exception_handler(): void {
		self::$previous_exception_handler = set_exception_handler( [ self::class, 'handle_uncaught_exception' ] );
	}

	/**
	 * Flags an uncaught exception before delegating to the next handler in the chain.
	 *
	 * @since 1.2.1
	 * @param \Throwable $e The uncaught exception or error.
	 * @throws \Throwable When WordPress core scraping flow expects native fatal markers.
	 * @return void
	 */
	public static function handle_uncaught_exception( \Throwable $e ): void {
		self::$had_uncaught_exception = true;
		self::$exception_message      = get_class( $e ) . ': ' . $e->getMessage();
		self::$exception_file         = $e->getFile();
		self::$exception_line         = $e->getLine();

		// scrape requests need WP's own error markers — don't let debug plugins intercept.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_REQUEST['wp_scrape_key'] ) ) {
			throw $e;
		}

		if ( self::$previous_exception_handler ) {
			call_user_func( self::$previous_exception_handler, $e );
		} else {
			throw $e;
		}

		exit( 1 );
	}

	/**
	 * Shutdown callback — checks for a fatal error and rolls back if needed.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function handle_shutdown(): void {
		if ( defined( 'WP_SANDBOX_SCRAPING' ) && WP_SANDBOX_SCRAPING ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_REQUEST['wp_scrape_key'] ) ) {
			return;
		}

		$error       = error_get_last();
		$fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
		$is_fatal    = $error && in_array( $error['type'], $fatal_types, true );

		// debug plugins (e.g. QM) call exit() before error_get_last() is populated, so check the flag too.
		if ( ! $is_fatal && ! self::$had_uncaught_exception ) {
			return;
		}

		// Read the pending-update record directly from the DB.
		$pending = self::db_get_option( 'gitwire_pending_update' );
		if ( ! $pending ) {
			return;
		}

		$install_path = $pending['install_path'] ?? null;
		$backup_path  = $pending['backup_path'] ?? null;
		$plugin_file  = $pending['plugin_file'] ?? null;
		$full_name    = $pending['full_name'] ?? 'unknown';
		$type         = $pending['type'] ?? 'plugin';
		$context      = $pending['context'] ?? 'install';
		$restored     = false;

		if ( 'activation' === $context ) {
			if ( 'plugin' === $type && $plugin_file ) {
				self::deactivate_plugin( $plugin_file );
				$restored = true;
			} elseif ( 'theme' === $type ) {
				$previous_stylesheet = $pending['previous_stylesheet'] ?? null;
				self::restore_theme(
					$previous_stylesheet,
					$pending['previous_template'] ?? null
				);
				if ( $previous_stylesheet && function_exists( 'get_theme_root' ) ) {
					Installer::refresh_theme_runtime(
						get_theme_root() . '/' . $previous_stylesheet,
						$previous_stylesheet
					);
				}
				$restored = true;
			}
		} else {
			$restored = self::rollback_update_files( $pending );

			if ( 'plugin' === $type && $plugin_file ) {
				self::deactivate_plugin( $plugin_file );
			} elseif ( 'theme' === $type && $restored ) {
				self::ensure_active_theme_after_update_rollback( $pending );
			}
		}

		self::restore_pending_installed_record( $pending );

		if ( $is_fatal ) {
			$error_string = sprintf( '%s in %s on line %d', $error['message'], $error['file'], $error['line'] );
		} else {
			$error_string = sprintf( '%s in %s on line %d', self::$exception_message, self::$exception_file, self::$exception_line );
		}

		// Store fatal notice for the Gitwire admin UI.
		$notice = [
			'full_name' => $full_name,
			'type'      => $type,
			'context'   => $context,
			'error'     => $error_string,
			'time'      => time(),
			'restored'  => 'activation' === $context ? true : $restored,
		];

		self::db_update_option( 'gitwire_fatal_notice', $notice );

		Logger::log(
			sprintf(
				/* translators: 1: install or activation, 2: repository full name, 3: plugin or theme, 4: full error with file and line, 5: rollback outcome */
				__( 'Fatal error during %1$s of "%2$s" (%3$s): %4$s. %5$s', 'gitwire' ),
				$context,
				$full_name,
				$type,
				$error_string,
				$notice['restored'] ? __( 'Changes were rolled back.', 'gitwire' ) : __( 'Rollback failed.', 'gitwire' )
			),
			'error'
		);

		self::clear_pending_update();
		self::clear_bootstrap_verified();

		if ( 'activation' === $context && 'theme' !== $type ) {
			self::redirect_to_gitwire_admin();
		}
	}

	/**
	 * Sends the admin back to Gitwire after an activation fatal was recovered.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private static function redirect_to_gitwire_admin(): void {
		if ( ! function_exists( 'admin_url' ) ) {
			return;
		}

		$url = admin_url( 'admin.php?page=gitwire' );

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
	 * Marks bootstrap verification after a verify bootstrap request finishes cleanly.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function mark_guard_verified_on_verify_request(): void {
		if ( ! self::is_verify_bootstrap_request() ) {
			return;
		}

		if ( self::has_fatal_shutdown_error() ) {
			return;
		}

		$pending = get_option( 'gitwire_pending_update' );
		if ( ! is_array( $pending ) ) {
			return;
		}

		$fingerprint = self::pending_fingerprint( $pending );

		if ( is_admin() ) {
			$frontend_ok = get_transient( 'gitwire_frontend_bootstrap_ok' );
			if ( ! is_string( $frontend_ok ) || $frontend_ok !== $fingerprint ) {
				return;
			}

			delete_transient( 'gitwire_frontend_bootstrap_ok' );
			self::try_mark_bootstrap_verified();
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		set_transient( 'gitwire_frontend_bootstrap_ok', $fingerprint, MINUTE_IN_SECONDS );
	}

	/**
	 * Backward-compatible alias for mark_guard_verified_on_verify_request().
	 *
	 * @deprecated 1.2.0 Use mark_guard_verified_on_verify_request().
	 * @return void
	 */
	public static function mark_guard_verified_on_frontend_verify(): void {
		self::mark_guard_verified_on_verify_request();
	}

	/**
	 * Ends a frontend verify request after WordPress has bootstrapped the theme.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function finish_verify_bootstrap_request(): void {
		if ( ! self::is_verify_bootstrap_request() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			exit;
		}

		status_header( 204 );
		exit;
	}

	/**
	 * Returns whether this request is the frontend theme bootstrap check.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	private static function is_verify_bootstrap_request(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return ! empty( $_GET['gitwire_verify_activation'] );
	}

	/**
	 * Finalizes a verified guard when the Gitwire admin page loads after bootstrap checks.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function finalize_verified_guard_on_git_page(): void {
		if ( ! is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( self::is_verify_bootstrap_request() || ! self::is_gitwire_admin_page() ) {
			return;
		}

		self::finalize_verified_guard_if_ready();
	}

	/**
	 * Backward-compatible alias for finalize_verified_guard_on_git_page().
	 *
	 * @deprecated 1.2.0 Use finalize_verified_guard_on_git_page().
	 * @return void
	 */
	public static function release_verified_guard_on_git_page(): void {
		self::finalize_verified_guard_on_git_page();
	}

	/**
	 * Finalizes a verified guard when bootstrap checks have already passed.
	 *
	 * @since 1.2.0
	 * @return array<string, string>|null Finalized record metadata, or null when not ready.
	 */
	public static function finalize_verified_guard_if_ready(): ?array {
		$pending = get_option( 'gitwire_pending_update' );
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
	 * Clears the guard after both iframe and Gitwire admin bootstraps succeed.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending Pending guard record.
	 * @return void
	 */
	private static function finalize_guard_success( array $pending ): void {
		self::apply_pending_installed_record( $pending );
		Installer::delete_backup_path( $pending['backup_path'] ?? null );
		self::clear_bootstrap_verified();

		if ( 'activation' === ( $pending['context'] ?? '' ) ) {
			set_transient(
				'gitwire_activation_success',
				[
					'full_name' => $pending['full_name'] ?? '',
					'type'      => $pending['type'] ?? '',
				],
				MINUTE_IN_SECONDS
			);
		} else {
			set_transient(
				'gitwire_update_success',
				[
					'full_name' => $pending['full_name'] ?? '',
					'type'      => $pending['type'] ?? '',
				],
				MINUTE_IN_SECONDS
			);
		}

		delete_option( 'gitwire_pending_update' );
	}

	/**
	 * Commits a staged installed record after verification succeeds.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending Pending guard record.
	 * @return void
	 */
	public static function apply_pending_installed_record( array $pending ): void {
		$provider       = $pending['provider'] ?? '';
		$full_name      = $pending['full_name'] ?? '';
		$pending_record = $pending['pending_record'] ?? null;

		if ( ! $provider || ! $full_name || ! is_array( $pending_record ) ) {
			return;
		}

		$record_key               = $provider . ':' . $full_name;
		$installed                = Installer::get_installed();
		$installed[ $record_key ] = $pending_record;
		update_option( 'gitwire_installed', $installed, false );
	}

	/**
	 * Restores the installed record snapshot stored on the pending guard.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending Pending guard record.
	 * @return void
	 */
	public static function restore_pending_installed_record( array $pending ): void {
		$provider    = $pending['provider'] ?? null;
		$full_name   = $pending['full_name'] ?? null;
		$prev_record = $pending['prev_record'] ?? null;

		if ( ! $provider || ! $full_name || ! is_array( $prev_record ) ) {
			return;
		}

		$record_key               = $provider . ':' . $full_name;
		$installed                = Installer::get_installed();
		$installed[ $record_key ] = $prev_record;
		update_option( 'gitwire_installed', $installed, false );
	}

	/**
	 * Marks bootstrap verification when the guarded target is already active.
	 *
	 * @since 1.2.0
	 * @return bool True when the pending guard was marked verified.
	 */
	public static function try_mark_bootstrap_verified(): bool {
		$pending = get_option( 'gitwire_pending_update' );
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
			'gitwire_bootstrap_verified',
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
		$stored = get_transient( 'gitwire_bootstrap_verified' );
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
		delete_transient( 'gitwire_bootstrap_verified' );
		delete_transient( 'gitwire_frontend_bootstrap_ok' );
	}

	/**
	 * Drops orphaned activation guards left when a switch was reverted.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function clear_stale_update_guard(): void {
		$pending = get_option( 'gitwire_pending_update' );
		if ( ! is_array( $pending ) || 'update' !== ( $pending['context'] ?? '' ) ) {
			return;
		}

		$backup_path = $pending['backup_path'] ?? null;
		if ( $backup_path && is_dir( $backup_path ) ) {
			return;
		}

		delete_option( 'gitwire_pending_update' );
		self::clear_bootstrap_verified();
	}

	/**
	 * Drops orphaned activation guards left when a switch was reverted.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function clear_stale_activation_guard(): void {
		$pending = get_option( 'gitwire_pending_update' );
		if ( ! is_array( $pending ) || 'activation' !== ( $pending['context'] ?? '' ) ) {
			return;
		}

		$slug = $pending['slug'] ?? '';
		if ( ! $slug || ! function_exists( 'get_stylesheet' ) ) {
			delete_option( 'gitwire_pending_update' );
			self::clear_bootstrap_verified();
			return;
		}

		$is_active = get_stylesheet() === $slug || get_template() === $slug;
		if ( ! $is_active ) {
			delete_option( 'gitwire_pending_update' );
			self::clear_bootstrap_verified();
		}
	}

	/**
	 * Rolls back and clears a pending guard when client verification times out.
	 *
	 * @since 1.2.0
	 * @return bool True when a pending guard was cleared.
	 */
	public static function abort_pending_guard(): bool {
		$pending = get_option( 'gitwire_pending_update' );
		if ( ! is_array( $pending ) ) {
			self::clear_bootstrap_verified();
			return false;
		}

		$context = $pending['context'] ?? '';
		if ( ! in_array( $context, [ 'activation', 'update' ], true ) ) {
			delete_option( 'gitwire_pending_update' );
			self::clear_bootstrap_verified();
			return true;
		}

		$type         = $pending['type'] ?? 'plugin';
		$install_path = $pending['install_path'] ?? '';
		$backup_path  = $pending['backup_path'] ?? null;
		$plugin_file  = $pending['plugin_file'] ?? null;

		if ( 'update' === $context && $install_path ) {
			$restored = self::rollback_update_files( $pending );
			if ( 'theme' === $type && $restored ) {
				self::ensure_active_theme_after_update_rollback( $pending );
			}
		} elseif ( 'activation' === $context ) {
			if ( 'theme' === $type ) {
				self::revert_failed_theme_activation( $pending );
			} elseif ( 'plugin' === $type && $plugin_file ) {
				self::deactivate_plugin( $plugin_file );
			}
		}

		// Restore the installed record that was overwritten before the guard was armed.
		self::restore_pending_installed_record( $pending );

		delete_option( 'gitwire_pending_update' );
		self::clear_bootstrap_verified();

		return true;
	}

	/**
	 * Restores the previous theme after a guarded activation scrape fails.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending Pending activation guard record.
	 * @return void
	 */
	public static function revert_failed_theme_activation( array $pending ): void {
		$previous_stylesheet = $pending['previous_stylesheet'] ?? null;
		$previous_template   = $pending['previous_template'] ?? null;

		self::restore_theme( $previous_stylesheet, $previous_template );

		if ( $previous_stylesheet && function_exists( 'get_theme_root' ) ) {
			Installer::refresh_theme_runtime(
				get_theme_root() . '/' . $previous_stylesheet,
				$previous_stylesheet
			);
		}

		delete_option( 'gitwire_pending_update' );
		self::clear_bootstrap_verified();
	}

	/**
	 * Returns whether the current request ended with a fatal PHP error or uncaught exception.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	private static function has_fatal_shutdown_error(): bool {
		if ( self::$had_uncaught_exception ) {
			return true;
		}

		$error = error_get_last();
		if ( ! $error ) {
			return false;
		}

		$fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
		return in_array( $error['type'], $fatal_types, true );
	}

	/**
	 * Returns whether the current request is the Gitwire admin screen.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	private static function is_gitwire_admin_page(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) && 'gitwire' === $_GET['page'];
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
	 * Restores a failed theme update and re-applies the active theme when needed.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending Pending guard record.
	 * @return bool True when install files were restored or already valid.
	 */
	public static function rollback_theme_update( array $pending ): bool {
		$restored = self::rollback_update_files( $pending );
		if ( $restored ) {
			self::ensure_active_theme_after_update_rollback( $pending );
		}

		if ( 'theme' === ( $pending['type'] ?? '' ) ) {
			Installer::refresh_theme_runtime(
				$pending['install_path'] ?? '',
				$pending['slug'] ?? ''
			);
		}

		return $restored;
	}

	/**
	 * Restores files from a pending update backup, including orphaned backups.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending Pending guard record.
	 * @return bool True when install files were restored or already valid.
	 */
	private static function rollback_update_files( array $pending ): bool {
		$install_path = $pending['install_path'] ?? '';
		$backup_path  = $pending['backup_path'] ?? null;
		$type         = $pending['type'] ?? '';
		$context      = $pending['context'] ?? 'install';

		if ( ! $install_path ) {
			return false;
		}

		if ( ( ! $backup_path || ! is_dir( $backup_path ) ) && 'theme' === $type ) {
			$backup_path = Installer::find_orphaned_backup( $install_path );
		}

		if ( $backup_path && is_dir( $backup_path ) ) {
			return Installer::restore_backup( $install_path, $backup_path );
		}

		if ( 'update' === $context && 'theme' === $type ) {
			return is_dir( $install_path );
		}

		if ( is_dir( $install_path ) ) {
			Installer::rmdir_recursive( $install_path );
		}

		return false;
	}

	/**
	 * Re-applies the active theme after a guarded update rollback.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending Pending guard record.
	 * @return void
	 */
	private static function ensure_active_theme_after_update_rollback( array $pending ): void {
		if ( 'theme' !== ( $pending['type'] ?? '' ) || empty( $pending['was_active_theme'] ) ) {
			return;
		}

		$stylesheet = $pending['target_stylesheet'] ?? $pending['slug'] ?? '';
		$template   = $pending['target_template'] ?? $stylesheet;
		if ( ! $stylesheet ) {
			return;
		}

		self::restore_theme( $stylesheet, $template );
	}

	/**
	 * Clears the pending update flag and any cached copy.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private static function clear_pending_update(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( 'gitwire_pending_update' );
			return;
		}

		self::db_delete_option( 'gitwire_pending_update' );
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
