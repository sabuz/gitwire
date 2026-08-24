<?php
/**
 * Shutdown-based fatal-error handler for safe plugin installation.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rolls back a broken install when the request it ran in dies.
 *
 * Sticks to plain PHP and raw SQL in the shutdown path, which may run before
 * WordPress has finished bootstrapping.
 */
class Error_Handler {

	/**
	 * Option holding the in-flight install/activation guard.
	 *
	 * @var string
	 */
	const GUARD_OPTION = 'gitwire_running_task';

	/**
	 * How long a guard stays actionable, in seconds.
	 *
	 * A guard outlives its request when an install crashes hard enough to skip the
	 * cleanup. Past this window an unrelated fatal would otherwise trigger a
	 * rollback for an install that finished, or never really started.
	 *
	 * @var int
	 */
	const GUARD_MAX_AGE = 900;

	/**
	 * Whether the shutdown function has already been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Whether our exception handler is currently installed.
	 *
	 * @var bool
	 */
	private static bool $handler_armed = false;

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
		add_action( 'admin_init', [ self::class, 'clear_stale_activation_guard' ], 5 );
		add_action( 'admin_init', [ self::class, 'clear_stale_update_guard' ], 6 );
		add_action( 'admin_init', [ self::class, 'process_pending_deactivation' ], 7 );

		/*
		 * Arm the moment a guard is written. The install runs inside a REST request
		 * that is already past plugins_loaded, so hooking the option write is the
		 * only way to get on top of the chain before the new code is executed.
		 */
		add_action( 'add_option_' . self::GUARD_OPTION, [ self::class, 'arm_exception_handler' ] );
		add_action( 'update_option_' . self::GUARD_OPTION, [ self::class, 'arm_exception_handler' ] );
		add_action( 'delete_option_' . self::GUARD_OPTION, [ self::class, 'disarm_exception_handler' ] );

		add_action( 'plugins_loaded', [ self::class, 'maybe_arm_exception_handler' ], PHP_INT_MAX );
	}

	/**
	 * Installs the exception handler when a guard is in flight, or during one
	 * of our own scrape requests.
	 *
	 * Runs late on plugins_loaded so we sit above debug plugins that set their own
	 * handler (Query Monitor swallows the exception otherwise, see #6). Skipped on
	 * the front end, which has no install to roll back. A scrape request is the
	 * one exception: it always arms regardless of the guard option, because
	 * Theme_Scraper's activation path deletes the guard before scraping (to keep
	 * the loopback's own shutdown from double-rolling-back), and its update path
	 * never writes one at all. Gating on the option here would leave both scrape
	 * legs unprotected.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_arm_exception_handler(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_REQUEST['wp_scrape_key'] ) ) {
			self::arm_exception_handler();
			return;
		}

		if ( ! Plugin::is_management_request() ) {
			return;
		}

		if ( ! get_option( self::GUARD_OPTION ) ) {
			return;
		}

		self::arm_exception_handler();
	}

	/**
	 * Takes over exception handling for the rest of this request.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function arm_exception_handler(): void {
		if ( self::$handler_armed ) {
			return;
		}

		self::$handler_armed              = true;
		self::$previous_exception_handler = set_exception_handler( [ self::class, 'handle_uncaught_exception' ] );
	}

	/**
	 * Hands exception handling back once the guard clears.
	 *
	 * Reinstates the saved handler rather than calling restore_exception_handler(),
	 * which pops PHP's stack and would hand back the wrong one if anything else
	 * registered after we armed.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function disarm_exception_handler(): void {
		if ( ! self::$handler_armed ) {
			return;
		}

		$previous                         = self::$previous_exception_handler;
		self::$handler_armed              = false;
		self::$previous_exception_handler = null;

		set_exception_handler( $previous );
	}

	/**
	 * Deactivates a plugin flagged by the shutdown handler on the previous request.
	 *
	 * Using deactivate_plugins() from admin_init is safe and honours multisite.
	 * The shutdown handler only stores the plugin file path because calling
	 * deactivate_plugins() from within a shutdown function is unreliable.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function process_pending_deactivation(): void {
		$plugin_file = get_option( 'gitwire_pending_deactivate' );
		if ( ! $plugin_file || ! is_string( $plugin_file ) ) {
			return;
		}
		delete_option( 'gitwire_pending_deactivate' );

		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( $plugin_file );
		}
	}

	/**
	 * Flags an uncaught exception before delegating to the next handler in the chain.
	 *
	 * Only reachable once arm_exception_handler() has run, whether for a guarded
	 * install or one of our own scrape requests. The re-throw below is what lets
	 * PHP terminate the way it normally would; it reports the fatal at this line
	 * rather than the origin, so the notice and the log both use the recorded
	 * location instead of whatever PHP prints.
	 *
	 * @since 1.0.0
	 * @param \Throwable $e The uncaught exception or error.
	 * @throws \Throwable When WordPress core scraping flow expects native fatal markers.
	 * @return void
	 */
	public static function handle_uncaught_exception( \Throwable $e ): void {
		self::$had_uncaught_exception = true;
		self::$exception_message      = get_class( $e ) . ': ' . $e->getMessage();
		self::$exception_file         = $e->getFile();
		self::$exception_line         = $e->getLine();

		// Scrape requests need WordPress error markers, so debug plugins must not intercept them.

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
	 * Shutdown callback: checks for a fatal error and rolls back if needed.
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

		// Debug plugins may call exit() before error_get_last() is populated, so check the flag too.
		if ( ! $is_fatal && ! self::$had_uncaught_exception ) {
			return;
		}

		// Read the pending-update record directly from the DB.
		$pending = self::db_get_option( self::GUARD_OPTION );
		if ( ! $pending || ! self::guard_is_current( $pending ) ) {
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
				self::schedule_plugin_deactivation( $plugin_file );
				$restored = true;
			} elseif ( Repository_Detector::is_theme( $type ) ) {
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
				self::schedule_plugin_deactivation( $plugin_file );
			} elseif ( Repository_Detector::is_theme( $type ) && $restored ) {
				self::ensure_active_theme_after_update_rollback( $pending );
			}
		}

		self::restore_pending_installed_record( $pending );

		if ( $is_fatal ) {
			$error_string = sprintf(
				'%s in %s on line %d',
				$error['message'],
				str_replace( ABSPATH, '', $error['file'] ),
				$error['line']
			);
		} else {
			$error_string = sprintf(
				'%s in %s on line %d',
				self::$exception_message,
				str_replace( ABSPATH, '', self::$exception_file ),
				self::$exception_line
			);
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

		self::db_update_option(
			'gitwire_pending_message',
			[
				'type' => 'fatal',
				'data' => $notice,
			]
		);

		/*
		 * Untranslated, like every other Logger::log() call. The log is a diagnostic
		 * record read alongside PHP's own error log, not UI copy, and translations
		 * may not even be loaded this late in a fatal.
		 */
		Logger::log(
			sprintf(
				'Fatal error during %1$s of "%2$s" (%3$s): %4$s. %5$s',
				$context,
				$full_name,
				$type,
				$error_string,
				$notice['restored'] ? 'Changes were rolled back.' : 'Rollback failed.'
			),
			'error'
		);

		self::clear_running_task();

		if ( 'activation' === $context && ! Repository_Detector::is_theme( $type ) ) {
			self::redirect_to_gitwire_admin();
		}
	}

	/**
	 * Returns whether a guard is recent enough to act on.
	 *
	 * @since 1.0.0
	 * @param mixed $pending Guard record.
	 * @return bool
	 */
	private static function guard_is_current( $pending ): bool {
		if ( ! is_array( $pending ) ) {
			return false;
		}

		$started = (int) ( $pending['started_at'] ?? 0 );

		// Guards written before this field existed get one pass rather than a veto.
		if ( $started <= 0 ) {
			return true;
		}

		return ( time() - $started ) < self::GUARD_MAX_AGE;
	}

	/**
	 * Sends the admin back to Gitwire after an activation fatal was recovered.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function redirect_to_gitwire_admin(): void {
		if ( ! function_exists( 'admin_url' ) ) {
			return;
		}

		$url = is_multisite() ? network_admin_url( 'admin.php?page=gitwire' ) : admin_url( 'admin.php?page=gitwire' );

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
	 * Restores the installed record snapshot stored on the pending guard.
	 *
	 * @since 1.0.0
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

		Installer::upsert_record( $prev_record );
	}

	/**
	 * Drops orphaned activation guards left when a switch was reverted.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function clear_stale_update_guard(): void {
		$pending = get_option( 'gitwire_running_task' );
		if ( ! is_array( $pending ) || 'update' !== ( $pending['context'] ?? '' ) ) {
			return;
		}

		$backup_path = $pending['backup_path'] ?? null;
		if ( $backup_path && is_dir( $backup_path ) ) {
			return;
		}

		delete_option( 'gitwire_running_task' );
	}

	/**
	 * Drops orphaned activation guards left when a switch was reverted.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function clear_stale_activation_guard(): void {
		$pending = get_option( 'gitwire_running_task' );
		if ( ! is_array( $pending ) || 'activation' !== ( $pending['context'] ?? '' ) ) {
			return;
		}

		$slug = $pending['name'] ?? '';
		if ( ! $slug || ! function_exists( 'get_stylesheet' ) ) {
			delete_option( 'gitwire_running_task' );

			return;
		}

		$is_active = get_stylesheet() === $slug || get_template() === $slug;
		if ( ! $is_active ) {
			delete_option( 'gitwire_running_task' );
		}
	}

	/**
	 * Rolls back and clears a pending guard when client verification times out.
	 *
	 * @since 1.0.0
	 * @return bool True when a pending guard was cleared.
	 */
	public static function abort_pending_guard(): bool {
		$pending = get_option( 'gitwire_running_task' );
		if ( ! is_array( $pending ) ) {
			return false;
		}

		$context = $pending['context'] ?? '';
		if ( ! in_array( $context, [ 'activation', 'update' ], true ) ) {
			delete_option( 'gitwire_running_task' );

			return true;
		}

		$type         = $pending['type'] ?? 'plugin';
		$install_path = $pending['install_path'] ?? '';
		$backup_path  = $pending['backup_path'] ?? null;
		$plugin_file  = $pending['plugin_file'] ?? null;

		if ( 'update' === $context && $install_path ) {
			$restored = self::rollback_update_files( $pending );
			if ( Repository_Detector::is_theme( $type ) && $restored ) {
				self::ensure_active_theme_after_update_rollback( $pending );
			}
		} elseif ( 'activation' === $context ) {
			if ( Repository_Detector::is_theme( $type ) ) {
				self::revert_failed_theme_activation( $pending );
			} elseif ( 'plugin' === $type && $plugin_file ) {
				self::schedule_plugin_deactivation( $plugin_file );
			}
		}

		// Restore the installed record that was overwritten before the guard was armed.
		self::restore_pending_installed_record( $pending );

		delete_option( 'gitwire_running_task' );

		return true;
	}

	/**
	 * Restores the previous theme after a guarded activation scrape fails.
	 *
	 * @since 1.0.0
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

		delete_option( 'gitwire_running_task' );
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
	 * @since 1.0.0
	 * @param array<string, mixed> $pending Pending guard record.
	 * @return bool True when install files were restored or already valid.
	 */
	public static function rollback_theme_update( array $pending ): bool {
		$restored = self::rollback_update_files( $pending );
		if ( $restored ) {
			self::ensure_active_theme_after_update_rollback( $pending );
		}

		if ( Repository_Detector::is_theme( $pending['type'] ?? '' ) ) {
			Installer::refresh_theme_runtime(
				$pending['install_path'] ?? '',
				$pending['name'] ?? ''
			);
		}

		return $restored;
	}

	/**
	 * Restores files from a pending update backup, including orphaned backups.
	 *
	 * @since 1.0.0
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

		if ( ( ! $backup_path || ! is_dir( $backup_path ) ) && Repository_Detector::is_theme( $type ) ) {
			$backup_path = Installer::find_orphaned_backup( $install_path );
		}

		if ( $backup_path && is_dir( $backup_path ) ) {
			return Installer::restore_backup( $install_path, $backup_path );
		}

		if ( 'update' === $context && Repository_Detector::is_theme( $type ) ) {
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
	 * @since 1.0.0
	 * @param array<string, mixed> $pending Pending guard record.
	 * @return void
	 */
	private static function ensure_active_theme_after_update_rollback( array $pending ): void {
		if ( 'theme' !== ( $pending['type'] ?? '' ) || empty( $pending['was_active_theme'] ) ) {
			return;
		}

		$stylesheet = $pending['target_stylesheet'] ?? $pending['name'] ?? '';
		$template   = $pending['target_template'] ?? $stylesheet;
		if ( ! $stylesheet ) {
			return;
		}

		self::restore_theme( $stylesheet, $template );
	}

	/**
	 * Clears the pending update flag and any cached copy.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function clear_running_task(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( 'gitwire_running_task' );
			return;
		}

		self::db_delete_option( 'gitwire_running_task' );
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

		self::flush_option_cache( $name );
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
		if ( function_exists( 'update_option' ) ) {
			update_option( $name, $value, false );
			return;
		}

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

		self::flush_option_cache( $name );
	}

	/**
	 * Drops the object-cache entries a raw option write leaves stale.
	 *
	 * Misses are cached in 'notoptions', which survives the request on sites with a
	 * persistent object cache, so without this the value we just wrote is never read back.
	 *
	 * @since 1.0.0
	 * @param string $name Option name.
	 * @return void
	 */
	private static function flush_option_cache( string $name ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Restores the previous active theme directly in the database.
	 *
	 * @since 1.0.0
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

			// Both options are autoloaded, so the write remains hidden until alloptions is refreshed.
			self::flush_option_cache( $option_name );
		}
	}

	/**
	 * Stores a flag so WordPress deactivates the plugin on the next admin_init.
	 *
	 * Calling deactivate_plugins() from a shutdown handler is unreliable and requires
	 * serializing active_plugins by hand. Deferring to admin_init lets WordPress core
	 * handle the option safely.
	 *
	 * @since 1.0.0
	 * @param string $plugin_file Plugin file relative to wp-content/plugins.
	 * @return void
	 */
	private static function schedule_plugin_deactivation( string $plugin_file ): void {
		self::db_update_option( 'gitwire_pending_deactivate', $plugin_file );
	}
}
