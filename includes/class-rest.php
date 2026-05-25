<?php
/**
 * REST API endpoints for the Git for WordPress plugin.
 *
 * @package Git_WP
 * @since 1.0.0
 */

namespace Git_WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles all REST API routes under the gwp/v1 namespace.
 */
class REST {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	private const NS = 'gwp/v1';

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
			'/connection',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'test_connection' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'provider'     => [
						'type'    => 'string',
						'default' => '',
					],
					'username'     => [
						'type'    => 'string',
						'default' => '',
					],
					'token'        => [
						'type'    => 'string',
						'default' => '',
					],
					'gitlab_token' => [
						'type'    => 'string',
						'default' => '',
					],
					'gitlab_url'   => [
						'type'    => 'string',
						'default' => '',
					],
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
						'enum'    => [ 'github', 'gitlab' ],
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
					'slug'     => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_file_name',
					],
					'type'     => [
						'type'    => 'string',
						'default' => 'plugin',
						'enum'    => [ 'plugin', 'theme' ],
					],
					'owner'    => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'repo'     => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'provider' => [
						'type'    => 'string',
						'default' => 'github',
						'enum'    => [ 'github', 'gitlab' ],
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
				return in_array( $val, [ 'github', 'gitlab' ], true ) ? $val : 'github';
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
		$fatal = get_option( 'gwp_fatal_notice' );
		if ( $fatal ) {
			delete_option( 'gwp_fatal_notice' );
			delete_option( 'gwp_pending_update' );
			return rest_ensure_response(
				[
					'status' => 'fatal',
					'notice' => $fatal,
				]
			);
		}

		$pending = get_option( 'gwp_pending_update' );
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
		$fatal = get_option( 'gwp_fatal_notice' );
		if ( $fatal ) {
			delete_option( 'gwp_fatal_notice' );
			delete_option( 'gwp_pending_update' );
			return rest_ensure_response(
				[
					'status' => 'fatal',
					'notice' => $fatal,
				]
			);
		}

		$pending = get_option( 'gwp_pending_update' );
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

		Error_Handler::arm_rest_bootstrap_verify();

		return rest_ensure_response( [ 'status' => 'pending' ] );
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
		foreach ( [ 'token', 'username', 'smart_install', 'gitlab_token', 'gitlab_url' ] as $key ) {
			if ( null !== $req->get_param( $key ) ) {
				$incoming[ $key ] = $req->get_param( $key );
			}
		}

		$merged = Settings::merge_save( $incoming );

		if ( ! empty( $merged['gitlab_url'] ) && ! Settings::is_allowed_gitlab_url( $merged['gitlab_url'] ) ) {
			return new \WP_Error(
				'invalid_gitlab_url',
				__( 'GitLab URL must use HTTPS and cannot point to a private network address.', 'git' ),
				[ 'status' => 400 ]
			);
		}

		update_option( 'gwp_settings', $merged );

		return [
			'saved'         => true,
			'smart_install' => $merged['smart_install'],
			'settings'      => Settings::get_public(),
		];
	}

	/**
	 * Tests the configured API connection and caches the result.
	 * When called without a request (e.g. from cron) tests all configured providers.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request|null $req REST request object, or null for cron.
	 * @return array<string, mixed>|\WP_Error Connection data on success, WP_Error on failure.
	 */
	public static function test_connection( ?\WP_REST_Request $req = null ): array|\WP_Error {
		$settings = (array) get_option( 'gwp_settings', [] );

		if ( null === $req ) {
			// Cron path — test every provider that has saved credentials.
			if ( ! empty( $settings['token'] ) || ! empty( $settings['username'] ) ) {
				self::run_provider_test( 'github', $settings );
			}
			if ( ! empty( $settings['gitlab_token'] ) ) {
				self::run_provider_test( 'gitlab', $settings );
			}
			return [];
		}

		$provider = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		if ( ! in_array( $provider, [ 'github', 'gitlab' ], true ) ) {
			$provider = 'github';
		}

		$overrides = 'gitlab' === $provider
			? [
				'gitlab_token' => $req->get_param( 'gitlab_token' ),
				'gitlab_url'   => $req->get_param( 'gitlab_url' ),
			]
			: [
				'token'    => $req->get_param( 'token' ),
				'username' => $req->get_param( 'username' ),
			];

		return self::run_provider_test( $provider, $settings, $overrides );
	}

	/**
	 * Runs a connection test for a single provider and updates the cache slot.
	 *
	 * @since 1.0.0
	 * @param string $provider  Provider key: 'github' or 'gitlab'.
	 * @param array  $settings  Saved plugin settings.
	 * @param array  $overrides Optional credential overrides from the request.
	 * @return array<string, mixed>|\WP_Error Connection data, or WP_Error on failure.
	 */
	private static function run_provider_test( string $provider, array $settings, array $overrides = [] ): array|\WP_Error {
		if ( 'gitlab' === $provider ) {
			$saved_token = $settings['gitlab_token'] ?? '';
			$saved_url   = $settings['gitlab_url'] ?? '';
			$token       = sanitize_text_field( $overrides['gitlab_token'] ?? $saved_token );
			$gitlab_url  = esc_url_raw( $overrides['gitlab_url'] ?? $saved_url );
			$cache_this  = ( $saved_token === $token && $saved_url === $gitlab_url );

			$api    = new GitLab_API( $token, $gitlab_url );
			$result = $api->test_connection();

			if ( is_wp_error( $result ) ) {
				if ( $cache_this ) {
					self::set_connection_cache(
						'gitlab',
						[
							'provider' => 'gitlab',
							'error'    => $result->get_error_message(),
						]
					);
				}
				return $result;
			}

			$data = [
				'provider'       => 'gitlab',
				'authenticated'  => true,
				'login'          => $result['login'] ?? '',
				'name'           => $result['name'] ?? '',
				'avatar_url'     => $result['avatar_url'] ?? '',
				'rate_limit'     => $result['rate_limit'] ?? 0,
				'rate_remaining' => $result['rate_remaining'] ?? 0,
				'rate_reset'     => $result['rate_reset'] ?? 0,
				'checked_at'     => time(),
			];

			self::set_connection_cache( 'gitlab', $data );

			return $data;
		}

		$saved_token    = $settings['token'] ?? '';
		$saved_username = $settings['username'] ?? '';
		$token          = sanitize_text_field( $overrides['token'] ?? $saved_token );
		$username       = sanitize_text_field( $overrides['username'] ?? $saved_username );
		$cache_this     = ( $saved_token === $token && $saved_username === $username );

		$api    = new API( $token );
		$result = $api->test_connection( $username );

		if ( is_wp_error( $result ) ) {
			if ( $cache_this ) {
				self::set_connection_cache(
					'github',
					[
						'provider' => 'github',
						'error'    => $result->get_error_message(),
					]
				);
			}
			return $result;
		}

		$data = [
			'provider'       => 'github',
			'authenticated'  => ! empty( $token ),
			'login'          => $result['login'] ?? '',
			'name'           => $result['name'] ?? '',
			'avatar_url'     => $result['avatar_url'] ?? '',
			'rate_limit'     => $result['rate_limit'] ?? 60,
			'rate_remaining' => $result['rate_remaining'] ?? 0,
			'rate_reset'     => $result['rate_reset'] ?? 0,
			'checked_at'     => time(),
		];

		self::set_connection_cache( 'github', $data );

		return $data;
	}

	/**
	 * Updates a single provider slot in the connection cache.
	 *
	 * @since 1.0.0
	 * @param string     $provider Provider key.
	 * @param array|null $data     Connection data, or null to clear.
	 * @return void
	 */
	private static function set_connection_cache( string $provider, ?array $data ): void {
		$cache              = (array) get_option( 'gwp_connection_cache', [] );
		$cache[ $provider ] = $data;
		update_option( 'gwp_connection_cache', $cache, false );
	}

	/**
	 * Returns a paginated list of repositories for the configured user.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error Repository payload on success, WP_Error on failure.
	 */
	public static function get_repos( \WP_REST_Request $req ): array|\WP_Error {
		$settings = (array) get_option( 'gwp_settings', [] );
		$provider = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		if ( ! in_array( $provider, [ 'github', 'gitlab' ], true ) ) {
			$provider = 'github';
		}
		$page = max( 1, (int) ( $req->get_param( 'page' ) ?? 1 ) );

		if ( 'gitlab' === $provider ) {
			if ( ! ( $settings['gitlab_token'] ?? '' ) ) {
				return new \WP_Error( 'missing_config', 'Configure a GitLab token first.', [ 'status' => 400 ] );
			}

			$cache_key = 'gwp_repos_' . md5( 'gitlab' . ( $settings['gitlab_token'] ?? '' ) . ( $settings['gitlab_url'] ?? '' ) . $page );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				return self::enrich_repos_payload( $cached, 'gitlab' );
			}

			$api       = new GitLab_API( $settings['gitlab_token'] ?? '', $settings['gitlab_url'] ?? '' );
			$result    = $api->get_repos( '', $page );
			$installed = Installer::get_installed();

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$repos = array_map(
				static function ( $r ) use ( $installed ) {
					$full_name  = $r['path_with_namespace'] ?? '';
					$parts      = explode( '/', $full_name );
					$is_private = ( $r['visibility'] ?? 'private' ) !== 'public';
					return [
						'id'               => $r['id'],
						'name'             => $r['path'] ?? '',
						'full_name'        => $full_name,
						'owner'            => $parts[0] ?? '',
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

			$payload = [
				'repos'    => $repos,
				'has_more' => count( $result ) === 100,
				'page'     => $page,
			];

			set_transient( $cache_key, $payload, 30 * MINUTE_IN_SECONDS );

			return self::enrich_repos_payload( $payload, 'gitlab' );
		}

		$username = sanitize_text_field( $req->get_param( 'username' ) ?? $settings['username'] ?? '' );

		if ( ! $username && ! ( $settings['token'] ?? '' ) ) {
			return new \WP_Error( 'missing_config', 'Configure a GitHub username or token first.', [ 'status' => 400 ] );
		}

		$cache_key = 'gwp_repos_' . md5( ( $settings['token'] ?? '' ) . $username . $page );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return self::enrich_repos_payload( $cached, 'github' );
		}

		$api       = new API( $settings['token'] ?? '' );
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

		$payload = [
			'repos'    => $repos,
			'has_more' => count( $result ) === 100,
			'page'     => $page,
		];

		set_transient( $cache_key, $payload, 30 * MINUTE_IN_SECONDS );

		return self::enrich_repos_payload( $payload, 'github' );
	}

	/**
	 * Returns a list of branch names for a repository.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<int, string>|\WP_Error Branch name list on success, WP_Error on failure.
	 */
	public static function get_branches( \WP_REST_Request $req ): array|\WP_Error {
		$owner    = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo     = sanitize_text_field( $req->get_param( 'repo' ) );
		$provider = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		$settings = (array) get_option( 'gwp_settings', [] );
		$api      = self::make_api( $settings, $provider );
		$result   = $api->get_branches( $owner, $repo );

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
		$owner  = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo   = sanitize_text_field( $req->get_param( 'repo' ) );
		$branch = sanitize_text_field( $req->get_param( 'branch' ) ?? 'HEAD' );

		$provider  = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		$cache_key = 'gwp_detect_' . md5( $provider . $owner . $repo . $branch );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$settings = (array) get_option( 'gwp_settings', [] );
		$api      = self::make_api( $settings, $provider );
		$result   = $api->detect_type( $owner, $repo, $branch );

		// Absorb GitHub errors (private repo, rate-limit, network) so the
		// frontend always gets a valid response and can render the card.
		if ( is_wp_error( $result ) ) {
			$result = [
				'type'       => 'unknown',
				'subtype'    => null,
				'confidence' => 'none',
				'name'       => '',
			];
		}

		set_transient( $cache_key, $result, HOUR_IN_SECONDS );

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

		if ( $smart_install && ! $force_type ) {
			$provider = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
			if ( ! in_array( $provider, [ 'github', 'gitlab' ], true ) ) {
				$provider = 'github';
			}

			$api      = self::make_api( $settings, $provider );
			$detected = $api->detect_type( $owner, $repo, $branch );

			if ( is_wp_error( $detected ) ) {
				return new \WP_Error(
					'detect_failed',
					__( 'Could not verify repository type. Disable Smart Install or retry.', 'git' ),
					[ 'status' => 400 ]
				);
			}

			$detected_type = $detected['type'] ?? 'unknown';
			if ( 'unknown' === $detected_type ) {
				return new \WP_Error(
					'unknown_type',
					__( 'This repository is not detected as a WordPress plugin or theme.', 'git' ),
					[ 'status' => 400 ]
				);
			}

			if ( $detected_type !== $type ) {
				return new \WP_Error(
					'type_mismatch',
					sprintf(
						/* translators: 1: detected type, 2: requested type */
						__( 'Repository detected as %1$s, not %2$s.', 'git' ),
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
		if ( ! in_array( $provider, [ 'github', 'gitlab' ], true ) ) {
			$provider = 'github';
		}

		$method = 'theme' === $type ? 'install_theme' : 'install_plugin';
		$result = Installer::$method( $owner, $repo, $branch, $slug, $provider, $replace );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::bust_repos_cache();
		self::store_head( $owner, $repo, $branch, $provider );

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
		$slug     = sanitize_file_name( $req->get_param( 'slug' ) );
		$type     = $req->get_param( 'type' ) ?? 'plugin';
		$owner    = sanitize_text_field( $req->get_param( 'owner' ) ?? '' );
		$repo_arg = sanitize_text_field( $req->get_param( 'repo' ) ?? '' );
		$provider = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );

		$path = 'theme' === $type
			? get_theme_root() . '/' . $slug
			: WP_PLUGIN_DIR . '/' . $slug;

		if ( ! is_dir( $path ) ) {
			return [ 'conflict' => false ];
		}

		if ( $owner && $repo_arg ) {
			$full_name = $owner . '/' . $repo_arg;
			$key       = $provider . ':' . $full_name;
			$installed = Installer::get_installed();
			$is_own    = isset( $installed[ $key ] ) &&
				untrailingslashit( $installed[ $key ]['install_path'] ?? '' ) === untrailingslashit( $path );
			if ( $is_own ) {
				return [ 'conflict' => false ];
			}
		}

		return [ 'conflict' => true ];
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
		$settings = Settings::get_raw();

		foreach ( $records as $key => &$rec ) {
			if ( empty( $rec['provider'] ) || ! in_array( $rec['provider'], [ 'github', 'gitlab' ], true ) ) {
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

			if ( empty( $rec['head'] ) && ! empty( $rec['owner'] ) && ! empty( $rec['repo'] ) && ! empty( $rec['branch'] ) ) {
				$provider  = $rec['provider'] ?? 'github';
				$api       = self::make_api( $settings, $provider );
				$commits   = $api->get_commits( $rec['owner'], $rec['repo'], $rec['branch'], 1 );
				$full_name = $rec['full_name'] ?? ( $rec['owner'] . '/' . $rec['repo'] );
				if ( ! is_wp_error( $commits ) && ! empty( $commits[0]['sha'] ) ) {
					$rec['head'] = $commits[0]['sha'];
					Installer::set_head( $provider, $full_name, $rec['head'] );
					$pruned = true;
				}
			}

			$remote_head = self::fetch_remote_head( $rec, $settings );
			if ( $remote_head ) {
				set_transient(
					'gwp_remote_' . md5( ( $rec['provider'] ?? 'github' ) . ':' . ( $rec['full_name'] ?? '' ) . ':' . ( $rec['branch'] ?? '' ) ),
					$remote_head,
					HOUR_IN_SECONDS
				);
			}
		}
		unset( $rec );

		if ( $pruned ) {
			update_option( 'gwp_installed', $records );
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
		$pending       = get_option( 'gwp_pending_update' );
		$pending_guard = is_array( $pending )
			&& in_array( $pending['context'] ?? '', [ 'activation', 'update' ], true );

		foreach ( $records as $key => &$rec ) {
			if ( empty( $rec['provider'] ) || ! in_array( $rec['provider'], [ 'github', 'gitlab' ], true ) ) {
				$rec['provider'] = 'github';
			}

			if ( 'plugin' === ( $rec['type'] ?? '' ) ) {
				$rec['active']  = ! empty( $rec['plugin_file'] ) && is_plugin_active( $rec['plugin_file'] );
				$rec['subtype'] = 'plugin';
			} else {
				$rec['active']  = ( $rec['slug'] ?? '' ) === $active_theme;
				$rec['subtype'] = ! empty( $rec['install_path'] ) && file_exists( $rec['install_path'] . '/theme.json' ) ? 'block' : 'classic';
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
			}

			$remote_key  = 'gwp_remote_' . md5( ( $rec['provider'] ?? 'github' ) . ':' . ( $rec['full_name'] ?? '' ) . ':' . ( $rec['branch'] ?? '' ) );
			$remote_head = get_transient( $remote_key );
			if ( false !== $remote_head ) {
				$rec['remote_head']      = $remote_head;
				$rec['update_available'] = ! empty( $rec['head'] ) && $remote_head !== $rec['head'];
			}
		}
		unset( $rec );

		return $records;
	}

	/**
	 * Fetches the latest remote commit SHA for an installed record.
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $rec      Installed record.
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return string|null Remote HEAD SHA or null on failure.
	 */
	private static function fetch_remote_head( array $rec, array $settings ): ?string {
		if ( empty( $rec['owner'] ) || empty( $rec['repo'] ) || empty( $rec['branch'] ) ) {
			return null;
		}

		$provider = $rec['provider'] ?? 'github';
		$api      = self::make_api( $settings, $provider );
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
		$repos    = $req->get_param( 'repos' );
		$settings = Settings::get_raw();
		$results  = [];

		if ( ! is_array( $repos ) ) {
			return [ 'detections' => $results ];
		}

		$repos = array_slice( $repos, 0, 50 );

		foreach ( $repos as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$owner    = sanitize_text_field( $entry['owner'] ?? '' );
			$repo     = sanitize_text_field( $entry['repo'] ?? '' );
			$branch   = sanitize_text_field( $entry['branch'] ?? 'HEAD' );
			$provider = sanitize_key( $entry['provider'] ?? 'github' );

			if ( ! $owner || ! $repo ) {
				continue;
			}

			if ( ! in_array( $provider, [ 'github', 'gitlab' ], true ) ) {
				$provider = 'github';
			}

			$key    = $provider . ':' . $owner . '/' . $repo;
			$cached = get_transient( 'gwp_detect_' . md5( $provider . $owner . $repo . $branch ) );
			if ( false !== $cached ) {
				$results[ $key ] = $cached;
				continue;
			}

			$api    = self::make_api( $settings, $provider );
			$result = $api->detect_type( $owner, $repo, $branch );

			if ( is_wp_error( $result ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'GWP detect failed for ' . $key . ': ' . $result->get_error_message() );
				}
				$result = [
					'type'       => 'unknown',
					'subtype'    => null,
					'confidence' => 'none',
					'name'       => '',
					'error_code' => $result->get_error_code(),
				];
			}

			set_transient( 'gwp_detect_' . md5( $provider . $owner . $repo . $branch ), $result, HOUR_IN_SECONDS );
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
		$result    = Installer::activate( $provider, $full_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

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
		$result    = Installer::deactivate( $provider, $full_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

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

		$provider  = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		$full_name = $owner . '/' . $repo;
		$result    = Installer::switch_branch( $provider, $full_name, $branch );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		delete_transient( 'gwp_commits_' . md5( $provider . ':' . $full_name . ':' . $branch ) );
		self::bust_repos_cache();
		self::store_head( $owner, $repo, $branch, $provider );

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
			return new \WP_Error( 'gwp_not_found', 'Repository is not installed.', [ 'status' => 404 ] );
		}

		if ( 'plugin' === ( $record['type'] ?? '' ) ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_file = $record['plugin_file'] ?? '';
			if ( $plugin_file && is_plugin_active( $plugin_file ) ) {
				return new \WP_Error(
					'gwp_active',
					__( 'Deactivate the plugin before removing it.', 'git' ),
					[ 'status' => 409 ]
				);
			}
		}

		$result = Installer::remove( $provider, $full_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$pending = get_option( 'gwp_pending_update' );
		if ( is_array( $pending ) && ( $pending['full_name'] ?? '' ) === $full_name ) {
			Error_Handler::abort_pending_guard();
		}

		self::bust_repos_cache();

		return [ 'removed' => true ];
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
			return new \WP_Error( 'gwp_not_found', 'Repository is not installed.', [ 'status' => 404 ] );
		}

		$cache_key = 'gwp_commits_' . md5( $provider . ':' . $full_name . ':' . $record['branch'] );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$settings = (array) get_option( 'gwp_settings', [] );
		$api      = self::make_api( $settings, $provider );
		$commits  = $api->get_commits( $owner, $repo, $record['branch'] );

		if ( ! is_wp_error( $commits ) ) {
			set_transient( $cache_key, $commits, HOUR_IN_SECONDS );
		}

		return $commits;
	}

	/**
	 * Returns an API client instance for the given provider.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $settings Plugin settings array.
	 * @param string               $provider Provider key: 'github' or 'gitlab'.
	 * @return API|GitLab_API Appropriate API client.
	 */
	private static function make_api( array $settings, string $provider = 'github' ): Git_Provider_Interface {
		return Provider_Factory::make( $settings, $provider );
	}

	/**
	 * Enriches a repos payload with cached detections and live installed state.
	 *
	 * @since 1.2.0
	 * @param array  $payload  Repos payload with a 'repos' key.
	 * @param string $provider Provider key: 'github' or 'gitlab'.
	 * @return array Enriched repos payload.
	 */
	private static function enrich_repos_payload( array $payload, string $provider ): array {
		return self::enrich_with_detections( $payload, $provider );
	}

	/**
	 * Enriches a repos payload with any already-cached detection results.
	 *
	 * Checks each repo's detection transient and, when found, embeds the result
	 * directly so the frontend can skip redundant detect API calls.
	 *
	 * @since 1.0.0
	 * @param array  $payload  Repos payload with a 'repos' key.
	 * @param string $provider Provider key: 'github' or 'gitlab'.
	 * @return array The same payload with 'detection' added to each cached repo.
	 */
	private static function enrich_with_detections( array $payload, string $provider ): array {
		$payload['repos'] = array_map(
			static function ( $repo ) use ( $provider ) {
				$cache_key = 'gwp_detect_' . md5( $provider . $repo['owner'] . $repo['name'] . $repo['default_branch'] );
				$detection = get_transient( $cache_key );
				if ( false !== $detection ) {
					$repo['detection'] = $detection;
				}
				return $repo;
			},
			$payload['repos']
		);
		return $payload;
	}

	/**
	 * Clears the repos and detection transient caches.
	 *
	 * @since 1.0.0
	 * @return array<string, bool> Confirmation payload.
	 */
	public static function clear_cache(): array {
		self::bust_repos_cache( true );
		return [ 'cleared' => true ];
	}

	/**
	 * Fetches the latest commit SHA for a branch and stores it on the installed record.
	 * Runs fire-and-forget after install/switch — failures are silently ignored.
	 *
	 * @since 1.0.0
	 * @param string $owner    Repository owner.
	 * @param string $repo     Repository name.
	 * @param string $branch   Branch name.
	 * @param string $provider Git provider: 'github' or 'gitlab'.
	 * @return void
	 */
	private static function store_head( string $owner, string $repo, string $branch, string $provider ): void {
		$settings  = (array) get_option( 'gwp_settings', [] );
		$api       = self::make_api( $settings, $provider );
		$commits   = $api->get_commits( $owner, $repo, $branch, 1 );
		$full_name = $owner . '/' . $repo;
		if ( ! is_wp_error( $commits ) && ! empty( $commits ) ) {
			Installer::set_head( $provider, $full_name, $commits[0]['sha'] );
		}
	}

	/**
	 * Deletes cached repository list transients.
	 * When $include_detections is true (manual refresh), detection transients are also cleared.
	 *
	 * @since 1.0.0
	 * @param bool $include_detections Whether to also clear detection transients.
	 * @return void
	 */
	private static function bust_repos_cache( bool $include_detections = false ): void {
		global $wpdb;
		$where = "option_name LIKE '_transient_gwp_repos_%'";
		if ( $include_detections ) {
			$where .= " OR option_name LIKE '_transient_gwp_detect_%'";
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE {$where}" );
	}
}
