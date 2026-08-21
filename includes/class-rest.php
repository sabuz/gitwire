<?php
/**
 * REST API router: registers all routes and shared utilities.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all plugin REST routes and provides shared helpers used by sub-classes.
 */
class REST {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'gitwire/v1';

	/**
	 * Registers the rest_api_init hook.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	/**
	 * Delegates route registration to each focused handler class.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		REST_Settings::register_routes();
		REST_Repositories::register_routes();
		REST_Installer::register_routes();
		REST_Connections::register_routes();
		REST_Logs::register_routes();

		/**
		 * Fires after the core REST routes are registered.
		 *
		 * Gitwire Pro registers its connection routes here.
		 *
		 * @since 1.0.0
		 */
		do_action( 'gitwire_rest_init' );
	}

	/**
	 * Network precondition shared by every permission callback.
	 *
	 * On multisite, plugins/themes are shared across the whole network, so only a
	 * Super Admin may reach Gitwire at all, matching the Network Admin-only menu
	 * registered in Admin::init(). REST requests don't pass through
	 * wp-admin/network.php's own Super Admin gate, so it has to be checked here.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private static function network_gate(): bool {
		return ! is_multisite() || is_super_admin();
	}

	/**
	 * Permission callback for reads and for settings that touch no files.
	 *
	 * @since 1.0.0
	 * @return bool True if the current user can manage Gitwire.
	 */
	public static function can_manage(): bool {
		if ( ! self::network_gate() ) {
			return false;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns whether the current user may write plugin files.
	 *
	 * Both capabilities are required because one endpoint serves first installs
	 * and updates alike. Core's map_meta_cap() folds DISALLOW_FILE_MODS into
	 * these, so a site that forbids file modifications fails here without
	 * Gitwire needing its own constant check.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function can_write_plugins(): bool {
		return self::network_gate()
			&& current_user_can( 'install_plugins' )
			&& current_user_can( 'update_plugins' );
	}

	/**
	 * Returns whether the current user may write theme files.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function can_write_themes(): bool {
		return self::network_gate()
			&& current_user_can( 'install_themes' )
			&& current_user_can( 'update_themes' );
	}

	/**
	 * Permission callback for /install, where the target type is a request param.
	 *
	 * Defaults are applied before permission callbacks run, so 'type' is readable
	 * here. It is not enum-validated yet, and anything unrecognised falls to the
	 * plugin branch, then validation rejects it with a 400 before the handler runs.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	public static function can_install( \WP_REST_Request $request ): bool {
		return Repository_Detector::is_theme( (string) $request->get_param( 'type' ) )
			? self::can_write_themes()
			: self::can_write_plugins();
	}

	/**
	 * Permission callback for routes that rewrite an existing installation's files.
	 *
	 * The type comes from the stored record rather than the request, so a caller
	 * cannot pick the weaker of the two capabilities by lying about it.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	public static function can_write_installed( \WP_REST_Request $request ): bool {
		$record = self::record_for( $request );

		// Nothing to write to; let the handler answer 404 rather than 403.
		if ( ! $record ) {
			return self::can_write_plugins() || self::can_write_themes();
		}

		return Repository_Detector::is_theme( (string) ( $record['type'] ?? '' ) )
			? self::can_write_themes()
			: self::can_write_plugins();
	}

	/**
	 * Permission callback for the auto-update toggle.
	 *
	 * Arming it needs the write capability even though the request itself only
	 * sets a column: the install it schedules runs from cron, where there is no
	 * current user left to check. Switching it off schedules nothing, so it stays
	 * reachable, or a site that later forbids file changes could never clear a
	 * flag it can no longer act on.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	public static function can_toggle_auto_update( \WP_REST_Request $request ): bool {
		if ( 'disabled' === $request->get_param( 'auto_update' ) ) {
			return self::can_manage();
		}

		return self::can_write_installed( $request );
	}

	/**
	 * Permission callback for deleting an installation's files from disk.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	public static function can_delete_installed( \WP_REST_Request $request ): bool {
		if ( ! self::network_gate() ) {
			return false;
		}

		$record = self::record_for( $request );

		if ( ! $record ) {
			return current_user_can( 'delete_plugins' ) || current_user_can( 'delete_themes' );
		}

		return Repository_Detector::is_theme( (string) ( $record['type'] ?? '' ) )
			? current_user_can( 'delete_themes' )
			: current_user_can( 'delete_plugins' );
	}

	/**
	 * Permission callback for activating and deactivating an installation.
	 *
	 * Deliberately not gated on the file-modification capabilities: activating
	 * writes no files, and core keeps activate_plugins and switch_themes out of
	 * the DISALLOW_FILE_MODS group for the same reason. A locked-down site can
	 * still toggle what is already on disk, exactly as the Plugins screen allows.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	public static function can_activate( \WP_REST_Request $request ): bool {
		if ( ! self::network_gate() ) {
			return false;
		}

		$record = self::record_for( $request );

		if ( ! $record ) {
			return current_user_can( 'activate_plugins' ) || current_user_can( 'switch_themes' );
		}

		return Repository_Detector::is_theme( (string) ( $record['type'] ?? '' ) )
			? current_user_can( 'switch_themes' )
			: current_user_can( 'activate_plugins' );
	}

	/**
	 * Resolves the installation a route's owner/repo/provider params point at.
	 *
	 * Permission callbacks run before the route's own sanitize_callbacks, so the
	 * provider is normalised the same way here. Reading it raw would let
	 * "provider=GitHub" miss the record and fall through to the looser branch.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming request.
	 * @return array<string, mixed>|null
	 */
	private static function record_for( \WP_REST_Request $request ): ?array {
		$owner = (string) $request->get_param( 'owner' );
		$repo  = (string) $request->get_param( 'repo' );

		if ( '' === $owner || '' === $repo ) {
			return null;
		}

		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		if ( ! in_array( $provider, [ 'github', 'gitlab', 'bitbucket' ], true ) ) {
			$provider = 'github';
		}

		return Installer::get_record( $provider, $owner . '/' . $repo );
	}

	/**
	 * Returns a WP_Error if the current user cannot use the given connection, null otherwise.
	 *
	 * @since 1.0.0
	 * @param string $connection_id Connection ID to check.
	 * @return \WP_Error|null
	 */
	public static function assert_connection_scope( string $connection_id ): ?\WP_Error {
		$conn     = Connection_Resolver::find( $connection_id );
		$db_scope = $conn['scope'] ?? 'all';
		if ( $conn && 'all' !== $db_scope && (string) get_current_user_id() !== $db_scope ) {
			return new \WP_Error( 'forbidden', 'You do not have permission to use this connection.', [ 'status' => 403 ] );
		}
		return null;
	}
}
