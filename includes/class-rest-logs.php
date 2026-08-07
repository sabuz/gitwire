<?php
/**
 * REST endpoints for activity log management.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the /logs and /log-actors REST routes.
 */
class REST_Logs {

	/**
	 * Registers /logs and /log-actors routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		$ns = REST::NS;

		register_rest_route(
			$ns,
			'/logs',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get_logs' ],
					'permission_callback' => [ REST::class, 'can_manage' ],
					'args'                => [
						'from'   => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'to'     => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'level'  => [
							'type'    => 'string',
							'default' => '',
							'enum'    => [ '', 'activity', 'error' ],
						],
						'actors' => [
							'type'    => 'array',
							'default' => [],
							'items'   => [
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							],
						],
					],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ self::class, 'clear_logs' ],
					'permission_callback' => [ REST::class, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			$ns,
			'/log-actors',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_log_actors' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
				'args'                => [
					'search' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Returns the raw log file contents.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>
	 */
	public static function get_logs( \WP_REST_Request $req ): array {
		$from   = sanitize_text_field( $req->get_param( 'from' ) ?? '' );
		$to     = sanitize_text_field( $req->get_param( 'to' ) ?? '' );
		$level  = sanitize_key( $req->get_param( 'level' ) ?? '' );
		$actors = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $req->get_param( 'actors' ) ?? [] ) ) ) );

		return [
			'entries'        => Logger::get_instance()->get_entries( $from, $to, $level, $actors ),
			'enable_logging' => Settings::is_logging_enabled(),
		];
	}

	/**
	 * Returns WP usernames for users with manage_options, optionally filtered by search.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req Request object.
	 * @return string[]
	 */
	public static function get_log_actors( \WP_REST_Request $req ): array {
		$search = sanitize_text_field( $req->get_param( 'search' ) ?? '' );
		$args   = [
			'capability__in' => [ 'manage_options' ],
			'fields'         => [ 'user_login' ],
			'number'         => 20,
			'orderby'        => 'user_login',
		];
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = [ 'user_login', 'display_name' ];
		}
		return array_column( (array) get_users( $args ), 'user_login' );
	}

	/**
	 * Clears the log file.
	 *
	 * @since 1.0.0
	 * @return array<string, bool>
	 */
	public static function clear_logs(): array {
		$cleared = Logger::get_instance()->clear();
		if ( $cleared ) {
			Logger::purge_log_dir();
			Logger::log( 'Log cleared' );
		}
		return [ 'cleared' => $cleared ];
	}
}
