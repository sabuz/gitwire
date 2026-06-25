<?php
/**
 * REST endpoints for public (no-token) browse connections.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the /public-connections REST routes.
 */
class REST_Connections {

	/**
	 * Registers /public-connections routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		$ns = REST::NS;

		register_rest_route(
			$ns,
			'/public-connections',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'list_public_connections' ],
					'permission_callback' => [ REST::class, 'can_manage' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'add_public_connection' ],
					'permission_callback' => [ REST::class, 'can_manage' ],
					'args'                => [
						'provider'  => [
							'required' => true,
							'type'     => 'string',
							'enum'     => [ 'github', 'gitlab', 'bitbucket' ],
						],
						'username'  => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'host_url'  => [
							'type'    => 'string',
							'default' => '',
						],
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/public-connections/(?P<id>[^/]+)/rate-limit',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_public_connection_rate_limit' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$ns,
			'/public-connections/(?P<id>[^/]+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'delete_public_connection' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
			]
		);
	}

	/**
	 * Returns all public (no-token) browse connections.
	 *
	 * @since 1.0.0
	 * @return array<int, array<string, string>>
	 */
	public static function list_public_connections(): array {
		return Public_Connections::all();
	}

	/**
	 * Adds a new public browse connection.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function add_public_connection( \WP_REST_Request $req ): array|\WP_Error {
		$provider = (string) $req->get_param( 'provider' );
		$valid    = Public_Connections::validate(
			$provider,
			(string) $req->get_param( 'username' ),
			(string) $req->get_param( 'host_url' )
		);
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$conn = Public_Connections::add( $provider, $valid['identifier'], $valid['gitlab_url'] );
		$id   = $conn['id'] ?? '';

		if ( '' !== $id ) {
			Connection_Meta::write_public_metadata( $id, $provider, $valid['identifier'], $valid['gitlab_url'] );
		}

		return [
			'connection' => $conn,
			'metadata'   => Connection_Meta::get_public_connections_metadata( $id ) ?: null,
		];
	}

	/**
	 * Removes a public browse connection by ID.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function delete_public_connection( \WP_REST_Request $req ): array|\WP_Error {
		$id = sanitize_text_field( (string) $req->get_param( 'id' ) );

		if ( ! Public_Connections::delete( $id ) ) {
			return new \WP_Error( 'not_found', __( 'Connection not found.', 'gitwire' ), [ 'status' => 404 ] );
		}

		return [ 'deleted' => true ];
	}

	/**
	 * Returns the current API rate limit for a public connection.
	 *
	 * Only GitHub supports an unauthenticated rate-limit endpoint. Other
	 * providers either require auth or expose no dedicated endpoint.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_public_connection_rate_limit( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$id   = sanitize_text_field( (string) $req->get_param( 'id' ) );
		$conn = Public_Connections::find( $id );

		if ( ! $conn ) {
			return new \WP_Error( 'not_found', __( 'Connection not found.', 'gitwire' ), [ 'status' => 404 ] );
		}

		if ( 'github' !== ( $conn['provider'] ?? '' ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		$payload = Connection_Meta::get_public_github_rate( $id, $conn['identifier'] ?? '', $conn['avatar_url'] ?? '' );

		if ( null === $payload ) {
			return new \WP_REST_Response( null, 204 );
		}

		return new \WP_REST_Response( $payload );
	}
}
