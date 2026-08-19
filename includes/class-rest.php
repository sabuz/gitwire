<?php
/**
 * REST API router — registers all routes and shared utilities.
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
	 * Permission callback. On multisite, plugins/themes are shared across the
	 * whole network, so only a Super Admin may install or update them,
	 * matching the Network Admin-only menu registered in Admin::init(). REST
	 * requests don't pass through wp-admin/network.php's own Super Admin gate,
	 * so is_super_admin() has to be checked explicitly here.
	 *
	 * @since 1.0.0
	 * @return bool True if the current user can manage Gitwire.
	 */
	public static function can_manage(): bool {
		if ( is_multisite() ) {
			return is_super_admin();
		}
		return current_user_can( 'manage_options' );
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
