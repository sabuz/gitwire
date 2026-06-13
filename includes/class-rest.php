<?php
/**
 * REST API endpoints for the Gitwire plugin.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles all REST API routes under the gitwire/v1 namespace.
 */
class REST {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	private const NS        = 'gitwire/v1';
	private const PAGE_SIZE = 100;

	/**
	 * Request-scoped cache for the gitwire_pending_update option.
	 *
	 * @var array<string, mixed>|false|null null = not yet loaded, false = loaded + absent.
	 */
	private static mixed $pending_cache = null;

	/**
	 * Returns the pending update option, reading the DB at most once per request.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed>|false
	 */
	private static function get_pending_update(): mixed {
		if ( null === self::$pending_cache ) {
			self::$pending_cache = get_option( 'gitwire_pending_update' );
		}
		return self::$pending_cache;
	}

	/**
	 * Clears the pending update cache and persists the new value.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $pending New pending update value.
	 * @return void
	 */
	private static function set_pending_update( array $pending ): void {
		self::$pending_cache = $pending;
		update_option( 'gitwire_pending_update', $pending, false );
	}

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
	 * Registers all plugin REST routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		$ns = self::NS;

		register_rest_route(
			$ns,
			'/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get_settings' ],
					'permission_callback' => [ self::class, 'can_manage' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'save_settings' ],
					'permission_callback' => [ self::class, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			$ns,
			'/repos',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_repos' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'connection_id' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/repos/cache',
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'clear_cache' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$ns,
			'/repos/(?P<owner>[^/]+)/(?P<repo>[^/]+)/branches',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_branches' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$ns,
			'/repos/(?P<owner>[^/]+)/(?P<repo>[^/]+)/detect',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'detect_repo' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$ns,
			'/repos/detect-batch',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'detect_batch' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'repos' => [
						'required' => true,
						'type'     => 'array',
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/install',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'install' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'owner'    => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'repo'     => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'type'     => [
						'type'    => 'string',
						'default' => 'plugin',
						'enum'    => [ 'plugin', 'theme' ],
					],
					'provider' => [
						'type'    => 'string',
						'default' => 'github',
						'enum'    => [ 'github', 'gitlab', 'bitbucket' ],
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/check-slug',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'check_slug' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'slug' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_file_name',
					],
					'type' => [
						'type'    => 'string',
						'default' => 'plugin',
						'enum'    => [ 'plugin', 'theme' ],
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/installed/sync',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'sync_installed' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$ns,
			'/installed',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_installed' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		$provider_arg = [
			'type'              => 'string',
			'default'           => 'github',
			'sanitize_callback' => static function ( $val ) {
				$val = sanitize_key( $val );
				return in_array( $val, [ 'github', 'gitlab', 'bitbucket' ], true ) ? $val : 'github';
			},
		];

		register_rest_route(
			$ns,
			'/installed/(?P<owner>[^/]+)/(?P<repo>[^/]+)/branch',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'switch_branch' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [ 'provider' => $provider_arg ],
			]
		);

		register_rest_route(
			$ns,
			'/installed/(?P<owner>[^/]+)/(?P<repo>[^/]+)/activate',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'activate_installed' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [ 'provider' => $provider_arg ],
			]
		);

		register_rest_route(
			$ns,
			'/installed/(?P<owner>[^/]+)/(?P<repo>[^/]+)/deactivate',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'deactivate_installed' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [ 'provider' => $provider_arg ],
			]
		);

		register_rest_route(
			$ns,
			'/installed/(?P<owner>[^/]+)/(?P<repo>[^/]+)/commits',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_commits' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [ 'provider' => $provider_arg ],
			]
		);

		register_rest_route(
			$ns,
			'/installed/(?P<owner>[^/]+)/(?P<repo>[^/]+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'remove_installed' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [ 'provider' => $provider_arg ],
			]
		);

		register_rest_route(
			$ns,
			'/installed/(?P<owner>[^/]+)/(?P<repo>[^/]+)/untrack',
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'untrack_installed' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [ 'provider' => $provider_arg ],
			]
		);

		register_rest_route(
			$ns,
			'/activation-status',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get_activation_status' ],
					'permission_callback' => [ self::class, 'can_manage' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ self::class, 'abort_activation_guard' ],
					'permission_callback' => [ self::class, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			$ns,
			'/verify-bootstrap',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'verify_bootstrap' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$ns,
			'/repos/resolve',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'resolve_repo' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'url' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/logs',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get_logs' ],
					'permission_callback' => [ self::class, 'can_manage' ],
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
					'permission_callback' => [ self::class, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			$ns,
			'/log-actors',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_log_actors' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'search' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/public-connections',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'list_public_connections' ],
					'permission_callback' => [ self::class, 'can_manage' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'add_public_connection' ],
					'permission_callback' => [ self::class, 'can_manage' ],
					'args'                => [
						'provider'   => [
							'required' => true,
							'type'     => 'string',
							'enum'     => [ 'github', 'gitlab', 'bitbucket' ],
						],
						'username'   => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'gitlab_url' => [
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
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$ns,
			'/public-connections/(?P<id>[^/]+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'delete_public_connection' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		/**
		 * Fires after the core REST routes are registered.
		 *
		 * Gitwire Pro registers its connection routes here.
		 *
		 * @since 1.4.0
		 */
		do_action( 'gitwire_rest_init' );
	}

	/**
	 * Permission callback — requires manage_options capability.
	 *
	 * @since 1.0.0
	 * @return bool True if the current user can manage options.
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns whether a guarded activation finished, failed, or is still pending.
	 *
	 * @since 1.2.0
	 * @return \WP_REST_Response
	 */
	public static function get_activation_status(): \WP_REST_Response {
		$fatal = get_option( 'gitwire_fatal_notice' );
		if ( $fatal ) {
			delete_option( 'gitwire_fatal_notice' );
			return rest_ensure_response(
				[
					'status' => 'fatal',
					'notice' => $fatal,
				]
			);
		}

		$pending = self::get_pending_update();
		if (
			is_array( $pending )
			&& in_array( $pending['context'] ?? '', [ 'activation', 'update' ], true )
		) {
			if ( Error_Handler::is_bootstrap_verified( $pending ) ) {
				return rest_ensure_response(
					[
						'status'    => 'bootstrap_verified',
						'full_name' => $pending['full_name'] ?? '',
						'type'      => $pending['type'] ?? '',
					]
				);
			}

			return rest_ensure_response( [ 'status' => 'pending' ] );
		}

		return rest_ensure_response( [ 'status' => 'idle' ] );
	}

	/**
	 * Clears a stuck pending guard after client verification times out.
	 *
	 * @since 1.2.0
	 * @return \WP_REST_Response
	 */
	public static function abort_activation_guard(): \WP_REST_Response {
		return rest_ensure_response(
			[
				'aborted' => Error_Handler::abort_pending_guard(),
			]
		);
	}

	/**
	 * Bootstraps the active theme and marks the pending guard as verified.
	 *
	 * @since 1.2.0
	 * @return \WP_REST_Response
	 */
	public static function verify_bootstrap(): \WP_REST_Response {
		$fatal = get_option( 'gitwire_fatal_notice' );
		if ( $fatal ) {
			delete_option( 'gitwire_fatal_notice' );
			return rest_ensure_response(
				[
					'status' => 'fatal',
					'notice' => $fatal,
				]
			);
		}

		$pending = self::get_pending_update();
		if (
			! is_array( $pending )
			|| ! in_array( $pending['context'] ?? '', [ 'activation', 'update' ], true )
		) {
			return rest_ensure_response( [ 'status' => 'idle' ] );
		}

		if ( Error_Handler::is_bootstrap_verified( $pending ) ) {
			return rest_ensure_response(
				[
					'status'    => 'bootstrap_verified',
					'full_name' => $pending['full_name'] ?? '',
					'type'      => $pending['type'] ?? '',
				]
			);
		}

		return rest_ensure_response( [ 'status' => 'pending' ] );
	}

	/**
	 * Returns all public (no-token) browse connections.
	 *
	 * @since 1.5.0
	 * @return array<int, array<string, string>>
	 */
	public static function list_public_connections(): array {
		return Public_Connections::all();
	}

	/**
	 * Adds a new public browse connection.
	 *
	 * @since 1.5.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function add_public_connection( \WP_REST_Request $req ): array|\WP_Error {
		$provider   = (string) $req->get_param( 'provider' );
		$username   = sanitize_text_field( (string) $req->get_param( 'username' ) );
		$gitlab_url = '';

		if ( '' === $username ) {
			return new \WP_Error( 'missing_username', __( 'Username is required.', 'gitwire' ), [ 'status' => 400 ] );
		}

		if ( 'gitlab' === $provider ) {
			$raw_url    = (string) $req->get_param( 'gitlab_url' );
			$gitlab_url = '' !== $raw_url ? esc_url_raw( $raw_url ) : '';
			if ( '' !== $gitlab_url && ! Settings::is_allowed_gitlab_url( $gitlab_url ) ) {
				return new \WP_Error(
					'invalid_gitlab_url',
					__( 'GitLab URL must use HTTPS and cannot point to a private network address.', 'gitwire' ),
					[ 'status' => 400 ]
				);
			}
		}

		$conn = Public_Connections::add( $provider, $username, $gitlab_url );

		return [ 'connection' => $conn ];
	}

	/**
	 * Removes a public browse connection by ID.
	 *
	 * @since 1.5.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function delete_public_connection( \WP_REST_Request $req ): array|\WP_Error {
		$id = sanitize_text_field( (string) $req->get_param( 'id' ) );

		if ( ! Public_Connections::delete( $id ) ) {
			return new \WP_Error( 'not_found', __( 'Connection not found.', 'gitwire' ), [ 'status' => 404 ] );
		}

		self::clear_public_rate_cache( $id );

		return [ 'deleted' => true ];
	}

	/**
	 * Returns the current API rate limit for a public connection.
	 *
	 * Only GitHub supports an unauthenticated rate-limit endpoint. Other
	 * providers either require auth or expose no dedicated endpoint.
	 *
	 * @since 1.5.0
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

		$payload = self::fetch_github_profile( $conn['username'] ?? '' );

		if ( null === $payload ) {
			return new \WP_REST_Response( null, 204 );
		}

		self::save_public_rate_cache( $id, $payload );

		return new \WP_REST_Response( $payload );
	}

	/**
	 * Cron handler: refreshes the cached profile for every public connection.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function refresh_public_connections(): void {
		foreach ( Public_Connections::all() as $conn ) {
			$id       = $conn['id'] ?? '';
			$provider = $conn['provider'] ?? '';
			$username = $conn['username'] ?? '';

			if ( '' === $id || 'github' !== $provider ) {
				continue;
			}

			$payload = self::fetch_github_profile( $username );
			if ( null !== $payload ) {
				self::save_public_rate_cache( $id, $payload );
			}
		}
	}

	/**
	 * Fetches rate limit and display name for a GitHub username without auth.
	 *
	 * Returns null when the API call fails so the caller can decide how to handle it.
	 *
	 * @since 1.5.0
	 * @param string $username GitHub username.
	 * @return array<string, mixed>|null
	 */
	private static function fetch_github_profile( string $username ): ?array {
		$headers  = [ 'User-Agent' => 'Gitwire/' . GITWIRE_VERSION ];
		$rate_res = wp_remote_get(
			'https://api.github.com/rate_limit',
			[
				'headers' => $headers,
				'timeout' => 5,
			]
		);

		if ( is_wp_error( $rate_res ) || 200 !== wp_remote_retrieve_response_code( $rate_res ) ) {
			return null;
		}

		$rate_data = json_decode( wp_remote_retrieve_body( $rate_res ), true );
		$core      = $rate_data['resources']['core'] ?? null;

		if ( empty( $core ) ) {
			return null;
		}

		$name     = '';
		$user_res = wp_remote_get(
			'https://api.github.com/users/' . rawurlencode( $username ),
			[
				'headers' => $headers,
				'timeout' => 5,
			]
		);
		if ( ! is_wp_error( $user_res ) && 200 === wp_remote_retrieve_response_code( $user_res ) ) {
			$user_data = json_decode( wp_remote_retrieve_body( $user_res ), true );
			$name      = (string) ( $user_data['name'] ?? '' );
		}

		return [
			'rate_limit'     => (int) $core['limit'],
			'rate_remaining' => (int) $core['remaining'],
			'rate_reset'     => (int) $core['reset'],
			'name'           => $name,
			'checked_at'     => time(),
		];
	}

	/**
	 * Returns the unified connection cache for boot data.
	 *
	 * Starts with the public rate cache; Pro injects authenticated profiles
	 * via the gitwire_connection_cache filter.
	 *
	 * @since 1.5.0
	 * @return array<string, mixed>
	 */
	public static function get_connection_cache(): array {
		return (array) apply_filters( 'gitwire_connection_cache', self::get_public_rate_cache() );
	}

	/**
	 * Returns the full public rate cache for boot data.
	 *
	 * @since 1.5.0
	 * @return array<string, mixed>
	 */
	public static function get_public_rate_cache(): array {
		return (array) get_option( 'gitwire_public_rate_cache', [] );
	}

	/**
	 * Persists a single connection's rate data to the cache.
	 *
	 * @since 1.5.0
	 * @param string               $id   Connection ID.
	 * @param array<string, mixed> $data Rate data to store.
	 * @return void
	 */
	private static function save_public_rate_cache( string $id, array $data ): void {
		$cache       = (array) get_option( 'gitwire_public_rate_cache', [] );
		$cache[ $id ] = $data;
		update_option( 'gitwire_public_rate_cache', $cache, false );
	}

	/**
	 * Removes a connection's rate data from the cache.
	 *
	 * @since 1.5.0
	 * @param string $id Connection ID.
	 * @return void
	 */
	private static function clear_public_rate_cache( string $id ): void {
		$cache = (array) get_option( 'gitwire_public_rate_cache', [] );
		unset( $cache[ $id ] );
		update_option( 'gitwire_public_rate_cache', $cache, false );
	}

	/**
	 * Returns the current plugin settings.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Settings array.
	 */
	public static function get_settings(): array {
		return Settings::get_public();
	}

	/**
	 * Saves plugin settings from the request body.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|WP_Error Success data or WP_Error on validation failure.
	 */
	public static function save_settings( \WP_REST_Request $req ): array|\WP_Error {
		$incoming = [];
		if ( null !== $req->get_param( 'smart_install' ) ) {
			$incoming['smart_install'] = $req->get_param( 'smart_install' );
		}
		if ( null !== $req->get_param( 'show_repo_label' ) ) {
			$incoming['show_repo_label'] = $req->get_param( 'show_repo_label' );
		}
		if ( null !== $req->get_param( 'enable_logging' ) ) {
			$incoming['enable_logging'] = $req->get_param( 'enable_logging' );
		}
		if ( null !== $req->get_param( 'log_retention_days' ) ) {
			$incoming['log_retention_days'] = $req->get_param( 'log_retention_days' );
		}
		if ( null !== $req->get_param( 'log_level' ) ) {
			$incoming['log_level'] = $req->get_param( 'log_level' );
		}
		if ( null !== $req->get_param( 'remove_data_on_uninstall' ) ) {
			$incoming['remove_data_on_uninstall'] = $req->get_param( 'remove_data_on_uninstall' );
		}
		$was_logging = Settings::is_logging_enabled();
		$merged      = Settings::merge_save( $incoming );
		update_option( 'gitwire_settings', $merged );

		$now_logging = (bool) ( $merged['enable_logging'] ?? false );
		if ( ! $was_logging && $now_logging ) {
			Logger::log( 'Logging enabled' );
		}

		return [
			'saved'    => true,
			'settings' => Settings::get_public(),
		];
	}

	/**
	 * Returns a paginated list of repositories for the configured user.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error Repository payload on success, WP_Error on failure.
	 */
	public static function get_repos( \WP_REST_Request $req ): array|\WP_Error {
		$provider = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		if ( ! in_array( $provider, [ 'github', 'gitlab', 'bitbucket' ], true ) ) {
			$provider = 'github';
		}
		$page = max( 1, (int) ( $req->get_param( 'page' ) ?? 1 ) );

		$connection_id = sanitize_text_field( $req->get_param( 'connection_id' ) ?? '' );
		if ( '' === $connection_id ) {
			$first         = Connection_Resolver::get_first_for_provider( $provider );
			$connection_id = $first['id'] ?? '';
		}

		// No token connection — public mode, cached per provider.
		$cache_id = '' !== $connection_id ? $connection_id : 'public:' . $provider;

		$cached = Repo_Cache::get_repos_page( $cache_id, $page );
		if ( is_array( $cached ) ) {
			return self::enrich_with_detections( $cached, $provider );
		}

		$payload = self::build_repos_page( $provider, $page, $connection_id );
		if ( is_wp_error( $payload ) ) {
			Repo_Cache::clear_repos( $cache_id );
			return $payload;
		}

		Repo_Cache::set_repos_page( $cache_id, $page, $payload );

		return self::enrich_with_detections( $payload, $provider );
	}

	/**
	 * Builds a paginated repository list payload from the Git provider API.
	 *
	 * @since 1.2.0
	 * @param string $provider      Provider key: github, gitlab, or bitbucket.
	 * @param int    $page          Page number.
	 * @param string $connection_id Connection ID to use for credentials.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function build_repos_page( string $provider, int $page, string $connection_id = '' ): array|\WP_Error {
		$creds = '' !== $connection_id
			? Connection_Resolver::get_credentials( $connection_id )
			: Connection_Resolver::get_credentials_for_provider( $provider );

		if ( 'bitbucket' === $provider ) {
			$has_auth  = $creds && ! empty( $creds['email'] ) && ! empty( $creds['api_token'] );
			$workspace = '';
			if ( ! $has_auth ) {
				$workspace = $creds['workspace'] ?? '';
				if ( '' === $workspace ) {
					return new \WP_Error( 'missing_config', 'Add a Bitbucket workspace in Settings first.', [ 'status' => 400 ] );
				}
			}

			$api = $has_auth
				? new Bitbucket_API( sanitize_email( $creds['email'] ), $creds['api_token'] )
				: new Bitbucket_API( '', '' );
			// Authenticated: empty string — get_repos auto-discovers workspaces via /user/workspaces.
			$result    = $api->get_repos( $workspace, $page );
			$installed = Installer::get_installed();

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$repos = array_map(
				static function ( $r ) use ( $installed ) {
					$full_name = $r['full_name'] ?? '';
					$parts     = explode( '/', $full_name, 2 );
					return [
						'id'               => $r['uuid'] ?? $full_name,
						'name'             => $r['slug'] ?? '',
						'full_name'        => $full_name,
						'owner'            => $parts[0] ?? '',
						'description'      => $r['description'] ?? '',
						'private'          => (bool) ( $r['is_private'] ?? false ),
						'html_url'         => $r['links']['html']['href'] ?? '',
						'default_branch'   => $r['mainbranch']['name'] ?? 'main',
						'updated_at'       => $r['updated_on'] ?? '',
						'stargazers_count' => 0,
						'installed'        => $installed[ 'bitbucket:' . $full_name ] ?? null,
					];
				},
				$result['repos']
			);

			return [
				'repos'    => $repos,
				'has_more' => $result['has_more'],
				'page'     => $page,
			];
		}

		if ( 'gitlab' === $provider ) {
			$has_auth   = $creds && ! empty( $creds['token'] );
			$username   = '';
			$gitlab_url = '';
			if ( ! $has_auth ) {
				$username   = $creds['username'] ?? '';
				$gitlab_url = $creds['gitlab_url'] ?? '';
				if ( '' === $username ) {
					return new \WP_Error( 'missing_config', 'Add a GitLab account in Settings first.', [ 'status' => 400 ] );
				}
			}

			$api = $has_auth
				? new GitLab_API( $creds['token'], $creds['gitlab_url'] ?? '' )
				: new GitLab_API( '', $gitlab_url );

			$result    = $api->get_repos( $username, $page );
			$installed = Installer::get_installed();

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$repos = array_map(
				static function ( $r ) use ( $installed ) {
					$full_name  = $r['path_with_namespace'] ?? '';
					$slash      = strrpos( $full_name, '/' );
					$is_private = ( $r['visibility'] ?? 'private' ) !== 'public';
					return [
						'id'               => $r['id'],
						'name'             => $r['path'] ?? '',
						'full_name'        => $full_name,
						'owner'            => false !== $slash ? substr( $full_name, 0, $slash ) : $full_name,
						'description'      => $r['description'] ?? '',
						'private'          => $is_private,
						'html_url'         => $r['web_url'] ?? '',
						'default_branch'   => $r['default_branch'] ?? 'main',
						'updated_at'       => $r['last_activity_at'] ?? '',
						'stargazers_count' => (int) ( $r['star_count'] ?? 0 ),
						'installed'        => $installed[ 'gitlab:' . $full_name ] ?? null,
					];
				},
				$result
			);

			return [
				'repos'    => $repos,
				'has_more' => count( $result ) === self::PAGE_SIZE,
				'page'     => $page,
			];
		}

		$creds    = $creds ?? [];
		$username = sanitize_text_field( $creds['username'] ?? '' );

		if ( ! $username && empty( $creds['token'] ) ) {
			return new \WP_Error( 'missing_config', 'Add a GitHub account in Settings first.', [ 'status' => 400 ] );
		}

		$api       = new API( $creds['token'] ?? '' );
		$result    = $api->get_repos( $username, $page );
		$installed = Installer::get_installed();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$repos = array_map(
			static function ( $r ) use ( $installed ) {
				$full_name = $r['full_name'] ?? '';
				return [
					'id'               => $r['id'],
					'name'             => $r['name'],
					'full_name'        => $full_name,
					'owner'            => $r['owner']['login'] ?? explode( '/', $full_name )[0],
					'description'      => $r['description'] ?? '',
					'private'          => (bool) ( $r['private'] ?? false ),
					'html_url'         => $r['html_url'] ?? '',
					'default_branch'   => $r['default_branch'] ?? 'main',
					'updated_at'       => $r['updated_at'] ?? '',
					'stargazers_count' => (int) ( $r['stargazers_count'] ?? 0 ),
					'installed'        => $installed[ 'github:' . $full_name ] ?? null,
				];
			},
			$result
		);

		return [
			'repos'    => $repos,
			'has_more' => count( $result ) === self::PAGE_SIZE,
			'page'     => $page,
		];
	}

	/**
	 * Detects repository type via the provider API.
	 *
	 * @since 1.0.0
	 * @param string      $provider      Provider key.
	 * @param string      $owner         Repository owner.
	 * @param string      $repo          Repository name.
	 * @param string      $branch        Branch name.
	 * @param string|null $connection_id Connection ID for authenticated requests.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function detect_type_for_repo( string $provider, string $owner, string $repo, string $branch, ?string $connection_id = null ): array|\WP_Error {
		return self::make_api( $provider, $connection_id )->detect_type( $owner, $repo, $branch );
	}

	/**
	 * Returns a list of branch names for a repository.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<int, string>|\WP_Error Branch name list on success, WP_Error on failure.
	 */
	public static function get_branches( \WP_REST_Request $req ): array|\WP_Error {
		$owner         = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo          = sanitize_text_field( $req->get_param( 'repo' ) );
		$provider      = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		$connection_id = sanitize_text_field( $req->get_param( 'connection_id' ) ?? '' );
		$result        = self::make_api( $provider, '' !== $connection_id ? $connection_id : null )->get_branches( $owner, $repo );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_column( $result, 'name' );
	}

	/**
	 * Detects the WordPress type (plugin/theme) of a repository, with caching.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error Detection result on success, WP_Error on failure.
	 */
	public static function detect_repo( \WP_REST_Request $req ): array|\WP_Error {
		$owner         = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo          = sanitize_text_field( $req->get_param( 'repo' ) );
		$branch        = sanitize_text_field( $req->get_param( 'branch' ) ?? 'HEAD' );
		$provider      = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		$connection_id = sanitize_text_field( $req->get_param( 'connection_id' ) ?? '' );

		// Only use cache for unauthenticated lookups; a specific connection may access private repos.
		if ( '' === $connection_id ) {
			$cached = Repo_Cache::get_type( $provider, $owner, $repo, $branch );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$api    = self::make_api( $provider, '' !== $connection_id ? $connection_id : null );
		$result = $api->detect_type( $owner, $repo, $branch );

		if ( is_wp_error( $result ) ) {
			if ( '' !== $connection_id ) {
				$status = (int) ( $result->get_error_data()['status'] ?? 400 );
				return new \WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => $status ] );
			}
			$result = [
				'type'       => 'unknown',
				'subtype'    => null,
				'confidence' => 'none',
				'name'       => '',
			];
		}

		if ( '' === $connection_id ) {
			Repo_Cache::set_type( $provider, $owner, $repo, $branch, $result );
		}

		return $result;
	}

	/**
	 * Installs a repository as a plugin or theme.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|WP_Error Installed record on success, WP_Error on failure.
	 */
	public static function install( \WP_REST_Request $req ): array|\WP_Error {
		$owner      = sanitize_text_field( $req->get_param( 'owner' ) ?? '' );
		$repo       = sanitize_text_field( $req->get_param( 'repo' ) ?? '' );
		$branch     = sanitize_text_field( $req->get_param( 'branch' ) ?? 'main' );
		$type       = sanitize_key( $req->get_param( 'type' ) ?? 'plugin' );
		$slug       = sanitize_file_name( $req->get_param( 'slug' ) ?? '' );
		$replace    = (bool) $req->get_param( 'replace' );
		$force_type = (bool) $req->get_param( 'force_type' );

		if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
			return new \WP_Error( 'invalid_type', 'Type must be plugin or theme.', [ 'status' => 400 ] );
		}
		if ( ! $owner || ! $repo ) {
			return new \WP_Error( 'missing_params', 'Missing owner or repo.', [ 'status' => 400 ] );
		}

		$settings      = Settings::get_raw();
		$smart_install = $settings['smart_install'] ?? true;
		$connection_id = sanitize_text_field( $req->get_param( 'connection_id' ) ?? '' );
		$connection_id = '' !== $connection_id ? $connection_id : null;

		if ( null !== $connection_id ) {
			$conn = Connection_Resolver::find( $connection_id );
			if ( $conn && ( $conn['scope'] ?? 'site' ) === 'user' && (int) ( $conn['user_id'] ?? 0 ) !== get_current_user_id() ) {
				return new \WP_Error( 'forbidden', 'You do not have permission to use this connection.', [ 'status' => 403 ] );
			}
			// Unknown ids (public sources, stale connections) install via the public path.
			if ( ! $conn ) {
				$connection_id = null;
			}
		}

		if ( $smart_install && ! $force_type ) {
			$provider = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
			if ( ! in_array( $provider, [ 'github', 'gitlab', 'bitbucket' ], true ) ) {
				$provider = 'github';
			}

			$api      = self::make_api( $provider, $connection_id );
			$detected = $api->detect_type( $owner, $repo, $branch );

			if ( is_wp_error( $detected ) ) {
				return new \WP_Error(
					'detect_failed',
					__( 'Could not verify repository type. Disable Smart Install or retry.', 'gitwire' ),
					[ 'status' => 400 ]
				);
			}

			$detected_type = $detected['type'] ?? 'unknown';
			if ( 'unknown' === $detected_type ) {
				return new \WP_Error(
					'unknown_type',
					__( 'This repository is not detected as a WordPress plugin or theme.', 'gitwire' ),
					[ 'status' => 400 ]
				);
			}

			if ( $detected_type !== $type ) {
				return new \WP_Error(
					'type_mismatch',
					sprintf(
						/* translators: 1: detected type, 2: requested type */
						__( 'Repository detected as %1$s, not %2$s.', 'gitwire' ),
						$detected_type,
						$type
					),
					[ 'status' => 400 ]
				);
			}
		}

		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$provider = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		if ( ! in_array( $provider, [ 'github', 'gitlab', 'bitbucket' ], true ) ) {
			$provider = 'github';
		}

		$method    = 'theme' === $type ? 'install_theme' : 'install_plugin';
		$is_update = null !== Installer::get_record( $provider, $owner . '/' . $repo );
		$result    = Installer::$method( $owner, $repo, $branch, $slug, $provider, $replace, $connection_id );

		if ( is_wp_error( $result ) ) {
			Logger::log( sprintf( '[%s] %s failed — %s/%s: %s', $provider, $is_update ? 'Update' : 'Install', $owner, $repo, $result->get_error_message() ), 'error' );
			return $result;
		}

		unset( $result['_evicted'] );

		Repo_Cache::clear_repos();
		self::store_head( $owner, $repo, $branch, $provider, $connection_id );

		if ( $is_update ) {
			Logger::log( sprintf( '[%s] Updated %s/%s (%s) on branch %s', $provider, $owner, $repo, $type, $branch ) );
		} else {
			Logger::log( sprintf( '[%s] Installed %s/%s as %s on branch %s', $provider, $owner, $repo, $type, $branch ) );
		}

		return $result;
	}

	/**
	 * Checks whether a directory slug is already occupied on the filesystem.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, bool> Whether the slug conflicts with an existing directory.
	 */
	public static function check_slug( \WP_REST_Request $req ): array {
		$slug = sanitize_file_name( $req->get_param( 'slug' ) );
		$type = $req->get_param( 'type' ) ?? 'plugin';

		$path = 'theme' === $type
			? get_theme_root() . '/' . $slug
			: WP_PLUGIN_DIR . '/' . $slug;

		return [ 'conflict' => is_dir( $path ) ];
	}

	/**
	 * Returns all currently installed repository records (read-only).
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Installed records and empty orphaned list.
	 */
	public static function get_installed(): array {
		return [
			'installed' => self::annotate_installed( Installer::get_installed() ),
			'orphaned'  => [],
		];
	}

	/**
	 * Prunes missing directories, heals plugin files, and refreshes remote HEADs.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed> Synced installed records and any orphaned entries.
	 */
	public static function sync_installed(): array {
		$records  = Installer::get_installed();
		$orphaned = [];
		$pruned   = false;

		$pending       = self::get_pending_update();
		$pending_key   = '';
		$pending_guard = is_array( $pending )
			&& in_array( $pending['context'] ?? '', [ 'activation', 'update' ], true );
		if ( $pending_guard ) {
			$pending_key = ( $pending['provider'] ?? 'github' ) . ':' . ( $pending['full_name'] ?? '' );
		}

		foreach ( $records as $key => &$rec ) {
			if ( empty( $rec['provider'] ) || ! in_array( $rec['provider'], [ 'github', 'gitlab', 'bitbucket' ], true ) ) {
				$rec['provider'] = 'github';
			}

			if ( ! empty( $rec['install_path'] ) && ! is_dir( $rec['install_path'] ) ) {
				$orphaned[] = [
					'full_name' => $rec['full_name'],
					'provider'  => $rec['provider'],
				];
				unset( $records[ $key ] );
				$pruned = true;
				continue;
			}

			if ( 'plugin' === ( $rec['type'] ?? '' ) && empty( $rec['plugin_file'] ) && ! empty( $rec['install_path'] ) ) {
				$found = Installer::find_plugin_file( $rec['install_path'], $rec['slug'] ?? '' );
				if ( $found ) {
					$rec['plugin_file'] = $found;
					$pruned             = true;
				}
			}

			if ( 'theme' === ( $rec['type'] ?? '' ) && ! isset( $rec['subtype'] ) && ! empty( $rec['install_path'] ) ) {
				$rec['subtype'] = file_exists( $rec['install_path'] . '/theme.json' ) ? 'block' : 'classic';
				$pruned         = true;
			}

			if ( empty( $rec['head'] ) && ! empty( $rec['owner'] ) && ! empty( $rec['repo'] ) && ! empty( $rec['branch'] ) ) {
				$record_key = ( $rec['provider'] ?? 'github' ) . ':' . ( $rec['full_name'] ?? ( $rec['owner'] . '/' . $rec['repo'] ) );
				if ( $pending_guard && $record_key === $pending_key ) {
					continue;
				}

				$provider  = $rec['provider'] ?? 'github';
				$api       = self::make_api( $provider, $rec['connection_id'] ?? null );
				$commits   = $api->get_commits( $rec['owner'], $rec['repo'], $rec['branch'], 1 );
				$full_name = $rec['full_name'] ?? ( $rec['owner'] . '/' . $rec['repo'] );
				if ( ! is_wp_error( $commits ) && ! empty( $commits[0]['sha'] ) ) {
					$rec['head'] = $commits[0]['sha'];
					Installer::set_head( $provider, $full_name, $rec['head'] );
					$pruned = true;
				}
			}

			$record_key = ( $rec['provider'] ?? 'github' ) . ':' . ( $rec['full_name'] ?? '' );
			if ( ! $pending_guard || $record_key !== $pending_key ) {
				$remote_head = self::fetch_remote_head( $rec );
				if ( $remote_head ) {
					set_transient(
						'gitwire_remote_' . md5( ( $rec['provider'] ?? 'github' ) . ':' . ( $rec['full_name'] ?? '' ) . ':' . ( $rec['branch'] ?? '' ) ),
						$remote_head,
						HOUR_IN_SECONDS
					);
				}
			}
		}
		unset( $rec );

		if ( $pruned ) {
			update_option( 'gitwire_installed', $records, false );
			Installer::invalidate_installed_cache();
		}

		return [
			'installed' => self::annotate_installed( $records ),
			'orphaned'  => $orphaned,
		];
	}

	/**
	 * Annotates installed records with live active state and update availability.
	 *
	 * @since 1.2.0
	 * @param array<string, array<string, mixed>> $records Raw installed records.
	 * @return array<string, array<string, mixed>>
	 */
	private static function annotate_installed( array $records ): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_theme  = get_stylesheet();
		$pending       = self::get_pending_update();
		$pending_guard = is_array( $pending )
			&& in_array( $pending['context'] ?? '', [ 'activation', 'update' ], true );

		$all_connections = [];
		foreach ( Connection_Resolver::all() as $conn ) {
			$all_connections[ $conn['id'] ] = true;
		}

		foreach ( $records as $key => &$rec ) {
			if ( empty( $rec['provider'] ) || ! in_array( $rec['provider'], [ 'github', 'gitlab', 'bitbucket' ], true ) ) {
				$rec['provider'] = 'github';
			}

			// Without Pro there are no connections to reconnect to — updates fall back to public.
			$conn_id = $rec['connection_id'] ?? null;
			if ( $conn_id && ! empty( $all_connections ) && ! isset( $all_connections[ $conn_id ] ) ) {
				$rec['needs_reconnect'] = true;
			}

			if ( 'plugin' === ( $rec['type'] ?? '' ) ) {
				$rec['active']  = ! empty( $rec['plugin_file'] ) && is_plugin_active( $rec['plugin_file'] );
				$rec['subtype'] = 'plugin';
			} else {
				$rec['active']  = ( $rec['slug'] ?? '' ) === $active_theme;
				$rec['subtype'] = $rec['subtype'] ?? ( ! empty( $rec['install_path'] ) && file_exists( $rec['install_path'] . '/theme.json' ) ? 'block' : 'classic' );
			}

			if (
				$pending_guard
				&& ( $pending['full_name'] ?? '' ) === ( $rec['full_name'] ?? '' )
			) {
				$rec['activation_pending'] = true;
				if (
					'theme' === ( $rec['type'] ?? '' )
					&& 'activation' === ( $pending['context'] ?? '' )
				) {
					$rec['active'] = false;
				}

				if (
					'update' === ( $pending['context'] ?? '' )
					&& is_array( $pending['prev_record'] ?? null )
				) {
					if ( ! empty( $pending['prev_record']['head'] ) ) {
						$rec['head'] = $pending['prev_record']['head'];
					}
					if ( ! empty( $pending['was_active_theme'] ) ) {
						$rec['active'] = true;
					}
					$rec['update_available'] = false;
					continue;
				}
			}

			$remote_key  = 'gitwire_remote_' . md5( ( $rec['provider'] ?? 'github' ) . ':' . ( $rec['full_name'] ?? '' ) . ':' . ( $rec['branch'] ?? '' ) );
			$remote_head = get_transient( $remote_key );
			if ( false !== $remote_head ) {
				$rec['remote_head']      = $remote_head;
				$rec['update_available'] = ! empty( $rec['head'] ) && $remote_head !== $rec['head'];
				if (
					! empty( $rec['active'] )
					&& $rec['update_available']
				) {
					$known = Installer::get_known_fatal_remote_head(
						$rec['provider'] ?? 'github',
						$rec['full_name'] ?? '',
						$rec['branch'] ?? ''
					);
					if ( $known && $known === $remote_head ) {
						$rec['known_fatal_head'] = $known;
					}
				}
			}
		}
		unset( $rec );

		return $records;
	}

	/**
	 * Fetches the latest remote commit SHA for an installed record.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $rec Installed record.
	 * @return string|null Remote HEAD SHA or null on failure.
	 */
	private static function fetch_remote_head( array $rec ): ?string {
		if ( empty( $rec['owner'] ) || empty( $rec['repo'] ) || empty( $rec['branch'] ) ) {
			return null;
		}

		$provider = $rec['provider'] ?? 'github';
		$api      = self::make_api( $provider, $rec['connection_id'] ?? null );
		$commits  = $api->get_commits( $rec['owner'], $rec['repo'], $rec['branch'], 1 );

		if ( is_wp_error( $commits ) || empty( $commits[0]['sha'] ) ) {
			return null;
		}

		return $commits[0]['sha'];
	}

	/**
	 * Detects repository types for a batch of repos.
	 *
	 * @since 1.2.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, array<string, mixed>> Map of detection keys to results.
	 */
	public static function detect_batch( \WP_REST_Request $req ): array {
		$repos   = $req->get_param( 'repos' );
		$results = [];

		if ( ! is_array( $repos ) ) {
			return [ 'detections' => $results ];
		}

		$repos = array_slice( $repos, 0, 50 );

		foreach ( $repos as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$owner         = sanitize_text_field( $entry['owner'] ?? '' );
			$repo          = sanitize_text_field( $entry['repo'] ?? '' );
			$branch        = sanitize_text_field( $entry['branch'] ?? 'HEAD' );
			$provider      = sanitize_key( $entry['provider'] ?? 'github' );
			$connection_id = sanitize_text_field( $entry['connection_id'] ?? '' );
			$connection_id = '' !== $connection_id ? $connection_id : null;

			if ( ! $owner || ! $repo ) {
				continue;
			}

			if ( ! in_array( $provider, [ 'github', 'gitlab', 'bitbucket' ], true ) ) {
				$provider = 'github';
			}

			$key    = $provider . ':' . $owner . '/' . $repo;
			$cached = Repo_Cache::get_type( $provider, $owner, $repo, $branch );
			if ( is_array( $cached ) ) {
				$results[ $key ] = $cached;
				continue;
			}

			$result = self::detect_type_for_repo( $provider, $owner, $repo, $branch, $connection_id );

			if ( is_wp_error( $result ) ) {
				Logger::log( sprintf( 'Detection failed — %s: %s', $key, $result->get_error_message() ), 'error' );
				$result          = [
					'type'       => 'unknown',
					'subtype'    => null,
					'confidence' => 'none',
					'name'       => '',
					'error_code' => $result->get_error_code(),
				];
				$results[ $key ] = $result;
				continue;
			}

			Repo_Cache::set_type( $provider, $owner, $repo, $branch, $result );
			$results[ $key ] = $result;
		}

		return [ 'detections' => $results ];
	}

	/**
	 * Activates an installed plugin or switches to an installed theme.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, bool>|\WP_Error Success data or WP_Error on failure.
	 */
	public static function activate_installed( \WP_REST_Request $req ): array|\WP_Error {
		$owner     = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo      = sanitize_text_field( $req->get_param( 'repo' ) );
		$provider  = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		$full_name = $owner . '/' . $repo;
		$record    = Installer::get_record( $provider, $full_name );

		Error_Handler::clear_stale_activation_guard();

		try {
			$result = Installer::activate( $provider, $full_name );
		} catch ( \Throwable $e ) {
			// guard was armed before activation — clean up before returning.
			Error_Handler::abort_pending_guard();
			Logger::log( sprintf( '[%s] Activation failed — %s/%s: fatal error', $provider, $owner, $repo ), 'error' );
			return new \WP_Error(
				'gitwire_activation_fatal',
				__( 'Plugin could not be activated because it triggered a fatal error.', 'gitwire' ),
				[ 'status' => 500 ]
			);
		}

		if ( is_wp_error( $result ) ) {
			Logger::log( sprintf( '[%s] Activation failed — %s/%s: %s', $provider, $owner, $repo, $result->get_error_message() ), 'error' );
			return $result;
		}

		Logger::log( sprintf( '[%s] Activated %s/%s (%s)', $provider, $owner, $repo, $record['type'] ?? 'plugin' ) );

		return [ 'activated' => true ];
	}

	/**
	 * Deactivates an installed plugin.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, bool>|\WP_Error Success data or WP_Error on failure.
	 */
	public static function deactivate_installed( \WP_REST_Request $req ): array|\WP_Error {
		$owner     = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo      = sanitize_text_field( $req->get_param( 'repo' ) );
		$provider  = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		$full_name = $owner . '/' . $repo;
		$record    = Installer::get_record( $provider, $full_name );
		$result    = Installer::deactivate( $provider, $full_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Logger::log( sprintf( '[%s] Deactivated %s/%s (%s)', $provider, $owner, $repo, $record['type'] ?? 'plugin' ) );

		return [ 'deactivated' => true ];
	}

	/**
	 * Switches the active branch for an installed repository.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|WP_Error Updated record on success, WP_Error on failure.
	 */
	public static function switch_branch( \WP_REST_Request $req ): array|\WP_Error {
		$owner  = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo   = sanitize_text_field( $req->get_param( 'repo' ) );
		$branch = sanitize_text_field( $req->get_param( 'branch' ) ?? '' );

		if ( ! $branch ) {
			return new \WP_Error( 'missing_branch', 'Branch is required.', [ 'status' => 400 ] );
		}

		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$provider    = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		$full_name   = $owner . '/' . $repo;
		$override_id = sanitize_text_field( $req->get_param( 'connection_id' ) ?? '' );
		$override_id = '' !== $override_id ? $override_id : null;

		$existing_record = Installer::get_record( $provider, $full_name );
		$is_pull         = $existing_record && ( $existing_record['branch'] ?? '' ) === $branch;

		$result = Installer::switch_branch( $provider, $full_name, $branch, $override_id );

		if ( is_wp_error( $result ) ) {
			$action = $is_pull ? 'Pull' : 'Switch branch';
			Logger::log( sprintf( '[%s] %s failed — %s/%s: %s', $provider, $action, $owner, $repo, $result->get_error_message() ), 'error' );
			return $result;
		}

		delete_transient( 'gitwire_commits_' . md5( $provider . ':' . $full_name . ':' . $branch ) );
		Repo_Cache::clear_repos();
		$stored_conn_id = $override_id ?? ( $existing_record['connection_id'] ?? null );
		self::store_head( $owner, $repo, $branch, $provider, $stored_conn_id );

		$type = $existing_record['type'] ?? 'plugin';
		if ( $is_pull ) {
			Logger::log( sprintf( '[%s] Pulled %s/%s (%s) on branch %s', $provider, $owner, $repo, $type, $branch ) );
		} else {
			Logger::log( sprintf( '[%s] Switched %s/%s (%s) to branch %s', $provider, $owner, $repo, $type, $branch ) );
		}

		return $result;
	}

	/**
	 * Removes an installed repository.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, bool>|WP_Error Success data or WP_Error on failure.
	 */
	public static function remove_installed( \WP_REST_Request $req ): array|\WP_Error {
		$owner     = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo      = sanitize_text_field( $req->get_param( 'repo' ) );
		$provider  = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		$full_name = $owner . '/' . $repo;
		$record    = Installer::get_record( $provider, $full_name );

		if ( ! $record ) {
			return new \WP_Error( 'gitwire_not_found', 'Repository is not installed.', [ 'status' => 404 ] );
		}

		if ( 'plugin' === ( $record['type'] ?? '' ) ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_file = $record['plugin_file'] ?? '';
			if ( $plugin_file && is_plugin_active( $plugin_file ) ) {
				return new \WP_Error(
					'gitwire_active',
					__( 'Deactivate the plugin before removing it.', 'gitwire' ),
					[ 'status' => 409 ]
				);
			}
		}

		if ( 'theme' === ( $record['type'] ?? '' ) ) {
			$slug           = $record['slug'] ?? '';
			$active_theme   = get_stylesheet();
			$template_theme = get_template();
			if ( $slug && ( $slug === $active_theme || $slug === $template_theme ) ) {
				return new \WP_Error(
					'gitwire_active',
					__( 'Switch to a different theme before removing it.', 'gitwire' ),
					[ 'status' => 409 ]
				);
			}
		}

		$result = Installer::remove( $provider, $full_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$pending = self::get_pending_update();
		if ( is_array( $pending ) && ( $pending['full_name'] ?? '' ) === $full_name ) {
			Error_Handler::abort_pending_guard();
		}

		Repo_Cache::clear_repos();

		Logger::log( sprintf( '[%s] Uninstalled %s/%s (%s)', $provider, $owner, $repo, $record['type'] ?? 'plugin' ) );

		return [ 'removed' => true ];
	}

	/**
	 * Removes the Gitwire tracking record without deleting the files from disk.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function untrack_installed( \WP_REST_Request $req ): array|\WP_Error {
		$owner     = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo      = sanitize_text_field( $req->get_param( 'repo' ) );
		$provider  = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		$full_name = $owner . '/' . $repo;

		$result = Installer::untrack( $provider, $full_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Repo_Cache::clear_repos();

		return [ 'untracked' => true ];
	}

	/**
	 * Returns the last 10 commits for an installed repository's current branch.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<int, array<string, string>>|\WP_Error Commit list or WP_Error on failure.
	 */
	public static function get_commits( \WP_REST_Request $req ): array|\WP_Error {
		$owner     = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo      = sanitize_text_field( $req->get_param( 'repo' ) );
		$provider  = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		$full_name = $owner . '/' . $repo;

		$record = Installer::get_record( $provider, $full_name );
		if ( ! $record ) {
			return new \WP_Error( 'gitwire_not_found', 'Repository is not installed.', [ 'status' => 404 ] );
		}

		$cache_key = 'gitwire_commits_' . md5( $provider . ':' . $full_name . ':' . $record['branch'] );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return self::annotate_commits_with_fatal(
				$cached,
				$provider,
				$full_name,
				$record['branch']
			);
		}

		$api     = self::make_api( $provider, $record['connection_id'] ?? null );
		$commits = $api->get_commits( $owner, $repo, $record['branch'] );

		if ( is_wp_error( $commits ) ) {
			return $commits;
		}

		set_transient( $cache_key, $commits, HOUR_IN_SECONDS );

		return self::annotate_commits_with_fatal(
			$commits,
			$provider,
			$full_name,
			$record['branch']
		);
	}

	/**
	 * Flags commits that recently failed active-theme fatal validation.
	 *
	 * @since 1.2.0
	 * @param array<int, array<string, mixed>> $commits   Commit list.
	 * @param string                           $provider  Git provider.
	 * @param string                           $full_name Repository full name.
	 * @param string                           $branch    Branch name.
	 * @return array<int, array<string, mixed>>
	 */
	private static function annotate_commits_with_fatal( array $commits, string $provider, string $full_name, string $branch ): array {
		$known = Installer::get_known_fatal_remote_head( $provider, $full_name, $branch );
		if ( ! $known ) {
			return $commits;
		}

		foreach ( $commits as $i => $commit ) {
			if ( ! is_array( $commit ) ) {
				continue;
			}
			if ( ( $commit['sha'] ?? '' ) === $known ) {
				$commits[ $i ]['has_fatal_error'] = true;
			}
		}

		return $commits;
	}

	/**
	 * Returns an API client instance for the given provider and optional connection ID.
	 *
	 * @since 1.0.0
	 * @param string      $provider      Provider key: 'github', 'gitlab', or 'bitbucket'.
	 * @param string|null $connection_id Specific connection ID, or null for the default.
	 * @return Git_Provider_Interface Appropriate API client.
	 */
	private static function make_api( string $provider = 'github', ?string $connection_id = null ): Git_Provider_Interface {
		return Provider_Factory::make( $provider, $connection_id );
	}

	/**
	 * Enriches a repos payload with any already-cached detection results.
	 *
	 * Checks each repo's detection transient and, when found, embeds the result
	 * directly so the frontend can skip redundant detect API calls.
	 *
	 * @since 1.0.0
	 * @param array  $payload  Repos payload with a 'repos' key.
	 * @param string $provider Provider key: 'github', 'gitlab', or 'bitbucket'.
	 * @return array The same payload with 'detection' added to each cached repo.
	 */
	private static function enrich_with_detections( array $payload, string $provider ): array {
		$payload['repos'] = array_map(
			static function ( $repo ) use ( $provider ) {
				$detection = Repo_Cache::get_type(
					$provider,
					$repo['owner'] ?? '',
					$repo['name'] ?? '',
					$repo['default_branch'] ?? 'main'
				);
				if ( is_array( $detection ) ) {
					$repo['detection'] = $detection;
				}
				return $repo;
			},
			$payload['repos']
		);
		return $payload;
	}

	/**
	 * Clears browse repo and type caches so the next request refetches from the API.
	 *
	 * @since 1.0.0
	 * @return array<string, bool> Confirmation payload.
	 */
	public static function clear_cache(): array {
		Repo_Cache::clear_all();

		return [
			'cleared' => true,
		];
	}

	/**
	 * Parses a repository URL and attempts an anonymous type detection.
	 *
	 * Returns provider/owner/repo/branch, whether the repo is publicly readable,
	 * and the detection result when public.
	 *
	 * @since 1.3.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error Resolve payload or WP_Error on bad URL.
	 */
	public static function resolve_repo( \WP_REST_Request $req ): array|\WP_Error {
		$url    = sanitize_text_field( (string) $req->get_param( 'url' ) );
		$parsed = self::parse_repo_url( $url );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$provider = $parsed['provider'];
		$owner    = $parsed['owner'];
		$repo     = $parsed['repo'];
		$branch   = $parsed['branch'];

		$anon_api  = self::make_anon_api( $parsed );
		$detect_br = '' !== $branch ? $branch : 'HEAD';
		$detected  = $anon_api->detect_type( $owner, $repo, $detect_br );
		$is_public = ! is_wp_error( $detected );

		return [
			'provider'  => $provider,
			'owner'     => $owner,
			'repo'      => $repo,
			'branch'    => '' !== $branch ? $branch : null,
			'is_public' => $is_public,
			'detection' => $is_public ? $detected : null,
		];
	}

	/**
	 * Parses a GitHub, GitLab, or Bitbucket URL into its components.
	 *
	 * Handles .git suffix, /tree/<branch>, trailing slashes, and GitLab
	 * nested namespaces. Self-hosted GitLab is matched against the saved
	 * gitlab_url setting.
	 *
	 * @since 1.3.0
	 * @param string $url Raw URL from the client.
	 * @return array<string, string>|\WP_Error Parsed components or WP_Error.
	 */
	private static function parse_repo_url( string $url ): array|\WP_Error {
		$invalid = new \WP_Error(
			'invalid_url',
			/* translators: shown when the pasted URL is not a GitHub/GitLab/Bitbucket repo link */
			__( "We couldn't recognize this link. Use GitHub, GitLab, or Bitbucket.", 'gitwire' ),
			[ 'status' => 400 ]
		);

		$url   = trim( $url );
		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return $invalid;
		}

		$host = strtolower( $parts['host'] );
		$path = rtrim( $parts['path'], '/' );
		$path = (string) preg_replace( '/\.git$/i', '', $path );

		if ( 'github.com' === $host ) {
			if ( ! preg_match( '#^/([^/]+)/([^/]+)(?:/tree/(.+))?$#', $path, $m ) ) {
				return $invalid;
			}
			return [
				'provider'   => 'github',
				'owner'      => $m[1],
				'repo'       => $m[2],
				'branch'     => isset( $m[3] ) ? trim( $m[3], '/' ) : '',
				'gitlab_url' => '',
			];
		}

		if ( 'bitbucket.org' === $host ) {
			if ( ! preg_match( '#^/([^/]+)/([^/]+)#', $path, $m ) ) {
				return $invalid;
			}
			$branch = '';
			if ( preg_match( '#/src/([^/]+)#', $path, $bm ) ) {
				$branch = $bm[1];
			}
			return [
				'provider'   => 'bitbucket',
				'owner'      => $m[1],
				'repo'       => $m[2],
				'branch'     => $branch,
				'gitlab_url' => '',
			];
		}

		// GitLab.com or self-hosted GitLab.
		$is_gitlab_com = 'gitlab.com' === $host;
		$custom_url    = '';
		$custom_host   = '';

		$candidates = [];
		foreach ( Connection_Resolver::all() as $conn ) {
			if ( 'gitlab' === ( $conn['provider'] ?? '' ) && ! empty( $conn['gitlab_url'] ) ) {
				$candidates[] = $conn['gitlab_url'];
			}
		}

		foreach ( array_filter( $candidates ) as $candidate ) {
			$candidate   = rtrim( $candidate, '/' );
			$parsed_cand = wp_parse_url( $candidate );
			$cand_host   = strtolower( $parsed_cand['host'] ?? '' );
			if ( $cand_host === $host ) {
				$custom_url  = $candidate;
				$custom_host = $cand_host;
				break;
			}
		}
		$is_custom_gitlab = '' !== $custom_host;

		if ( $is_gitlab_com || $is_custom_gitlab ) {
			// Strip /-/tree/branch or /tree/branch.
			$branch = '';
			if ( preg_match( '#^(.+)/-/tree/(.+)$#', $path, $m ) ) {
				$path   = rtrim( $m[1], '/' );
				$branch = trim( $m[2], '/' );
			} elseif ( preg_match( '#^(.+)/tree/([^/].+)$#', $path, $m ) ) {
				$path   = rtrim( $m[1], '/' );
				$branch = trim( $m[2], '/' );
			}

			$segments = array_values( array_filter( explode( '/', ltrim( $path, '/' ) ) ) );
			if ( count( $segments ) < 2 ) {
				return $invalid;
			}

			$repo_name = array_pop( $segments );
			$owner     = implode( '/', $segments );

			return [
				'provider'   => 'gitlab',
				'owner'      => $owner,
				'repo'       => $repo_name,
				'branch'     => $branch,
				'gitlab_url' => $is_custom_gitlab ? $custom_url : '',
			];
		}

		return $invalid;
	}

	/**
	 * Returns an anonymous (no-token) API client for the given parsed URL components.
	 *
	 * @since 1.3.0
	 * @param array<string, string> $parsed Output of parse_repo_url().
	 * @return Git_Provider_Interface
	 */
	private static function make_anon_api( array $parsed ): Git_Provider_Interface {
		$provider = $parsed['provider'] ?? 'github';

		if ( 'gitlab' === $provider ) {
			return new GitLab_API( '', $parsed['gitlab_url'] ?? '' );
		}

		if ( 'bitbucket' === $provider ) {
			return new Bitbucket_API( '', '' );
		}

		return new API( '' );
	}

	/**
	 * Returns the raw log file contents.
	 *
	 * @since 1.3.0
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
	 * @since 1.3.0
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
	 * @since 1.3.0
	 * @return array<string, bool>
	 */
	public static function clear_logs(): array {
		$cleared = Logger::get_instance()->clear();
		if ( $cleared ) {
			Logger::log( 'Log cleared' );
		}
		return [ 'cleared' => $cleared ];
	}

	/**
	 * Fetches the latest commit SHA for a branch and stores it on the installed record.
	 * Runs fire-and-forget after install/switch — failures are silently ignored.
	 *
	 * @since 1.0.0
	 * @param string      $owner         Repository owner.
	 * @param string      $repo          Repository name.
	 * @param string      $branch        Branch name.
	 * @param string      $provider      Git provider: 'github', 'gitlab', or 'bitbucket'.
	 * @param string|null $connection_id Connection ID used for the install.
	 * @return void
	 */
	private static function store_head( string $owner, string $repo, string $branch, string $provider, ?string $connection_id = null ): void {
		$api       = self::make_api( $provider, $connection_id );
		$commits   = $api->get_commits( $owner, $repo, $branch, 1 );
		$full_name = $owner . '/' . $repo;
		if ( ! is_wp_error( $commits ) && ! empty( $commits ) ) {
			Installer::set_head( $provider, $full_name, $commits[0]['sha'] );
		}
	}
}
