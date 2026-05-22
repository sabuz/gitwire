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
			'/install',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'install' ],
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

		register_rest_route(
			$ns,
			'/installed/(?P<owner>[^/]+)/(?P<repo>[^/]+)/branch',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'switch_branch' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$ns,
			'/installed/(?P<owner>[^/]+)/(?P<repo>[^/]+)/activate',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'activate_installed' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$ns,
			'/installed/(?P<owner>[^/]+)/(?P<repo>[^/]+)/deactivate',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'deactivate_installed' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			$ns,
			'/installed/(?P<owner>[^/]+)/(?P<repo>[^/]+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'remove_installed' ],
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
	 * Returns the current plugin settings.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Settings array.
	 */
	public static function get_settings(): array {
		$s = (array) get_option( 'gwp_settings', [] );
		return [
			'username'      => $s['username'] ?? '',
			'token'         => $s['token'] ?? '',
			'smart_install' => $s['smart_install'] ?? true,
			'provider'      => $s['provider'] ?? 'github',
			'gitlab_token'  => $s['gitlab_token'] ?? '',
			'gitlab_url'    => $s['gitlab_url'] ?? '',
		];
	}

	/**
	 * Saves plugin settings from the request body.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|WP_Error Success data or WP_Error on validation failure.
	 */
	public static function save_settings( \WP_REST_Request $req ): array|\WP_Error {
		$token         = sanitize_text_field( $req->get_param( 'token' ) ?? '' );
		$username      = sanitize_text_field( $req->get_param( 'username' ) ?? '' );
		$smart_install = (bool) $req->get_param( 'smart_install' );
		$provider      = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		$gitlab_token  = sanitize_text_field( $req->get_param( 'gitlab_token' ) ?? '' );
		$gitlab_url    = esc_url_raw( $req->get_param( 'gitlab_url' ) ?? '' );

		if ( ! in_array( $provider, [ 'github', 'gitlab' ], true ) ) {
			$provider = 'github';
		}

		update_option(
			'gwp_settings',
			compact( 'token', 'username', 'smart_install', 'provider', 'gitlab_token', 'gitlab_url' )
		);
		delete_option( 'gwp_connection_cache' );

		return [
			'saved'         => true,
			'smart_install' => $smart_install,
		];
	}

	/**
	 * Tests the configured API connection and caches the result.
	 * Supports both GitHub and GitLab via the provider setting.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error Connection data on success, WP_Error on failure.
	 */
	public static function test_connection( ?\WP_REST_Request $req = null ): array|\WP_Error {
		$settings     = (array) get_option( 'gwp_settings', [] );
		$req_provider = $req ? $req->get_param( 'provider' ) : null;
		$provider     = sanitize_key(
			$req_provider ? $req_provider : ( $settings['provider'] ?? 'github' )
		);

		if ( 'gitlab' === $provider ) {
			$saved_token = $settings['gitlab_token'] ?? '';
			$saved_url   = $settings['gitlab_url'] ?? '';
			$req_token   = $req ? $req->get_param( 'gitlab_token' ) : null;
			$req_url     = $req ? $req->get_param( 'gitlab_url' ) : null;
			$token       = sanitize_text_field( $req_token ? $req_token : $saved_token );
			$gitlab_url  = esc_url_raw( $req_url ? $req_url : $saved_url );
			$cache       = ( $token === $saved_token && $gitlab_url === $saved_url );

			$api    = new GitLab_API( $token, $gitlab_url );
			$result = $api->test_connection();

			if ( is_wp_error( $result ) ) {
				if ( $cache ) {
					update_option(
						'gwp_connection_cache',
						[
							'provider' => 'gitlab',
							'error'    => $result->get_error_message(),
						],
						false
					);
				}
				return $result;
			}

			$data = [
				'provider'      => 'gitlab',
				'authenticated' => true,
				'login'         => $result['login'] ?? '',
				'name'          => $result['name'] ?? '',
				'avatar_url'    => $result['avatar_url'] ?? '',
				'checked_at'    => time(),
			];

			if ( $cache ) {
				update_option( 'gwp_connection_cache', $data, false );
			}

			return $data;
		}

		$saved_token    = $settings['token'] ?? '';
		$saved_username = $settings['username'] ?? '';

		// Prefer params from the request so unsaved form values can be tested.
		$req_token    = $req ? $req->get_param( 'token' ) : null;
		$req_username = $req ? $req->get_param( 'username' ) : null;
		$token        = sanitize_text_field( $req_token ? $req_token : $saved_token );
		$username     = sanitize_text_field( $req_username ? $req_username : $saved_username );

		// Only persist the result when testing with the saved credentials.
		$cache = ( $token === $saved_token && $username === $saved_username );

		$api    = new API( $token );
		$result = $api->test_connection();

		if ( is_wp_error( $result ) ) {
			if ( $cache ) {
				update_option(
					'gwp_connection_cache',
					[
						'provider' => 'github',
						'error'    => $result->get_error_message(),
					],
					false
				);
			}
			return $result;
		}

		$data = [
			'provider'       => 'github',
			'authenticated'  => ! empty( $result['login'] ),
			'login'          => $result['login'] ?? '',
			'name'           => $result['name'] ?? '',
			'avatar_url'     => $result['avatar_url'] ?? '',
			'rate_limit'     => $result['rate_limit'] ?? 60,
			'rate_remaining' => $result['rate_remaining'] ?? 0,
			'rate_reset'     => $result['rate_reset'] ?? 0,
			'checked_at'     => time(),
		];

		if ( $cache ) {
			update_option( 'gwp_connection_cache', $data, false );
		}

		return $data;
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
		$provider = $settings['provider'] ?? 'github';
		$page     = max( 1, (int) ( $req->get_param( 'page' ) ?? 1 ) );

		if ( 'gitlab' === $provider ) {
			if ( ! ( $settings['gitlab_token'] ?? '' ) ) {
				return new \WP_Error( 'missing_config', 'Configure a GitLab token first.', [ 'status' => 400 ] );
			}

			$cache_key = 'gwp_repos_' . md5( 'gitlab' . ( $settings['gitlab_token'] ?? '' ) . ( $settings['gitlab_url'] ?? '' ) . $page );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
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
						'installed'        => $installed[ $full_name ] ?? null,
					];
				},
				$result
			);

			$payload = [
				'repos'    => $repos,
				'has_more' => count( $result ) === 100,
				'page'     => $page,
			];

			set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );

			return $payload;
		}

		$username = sanitize_text_field( $req->get_param( 'username' ) ?? $settings['username'] ?? '' );

		if ( ! $username && ! ( $settings['token'] ?? '' ) ) {
			return new \WP_Error( 'missing_config', 'Configure a GitHub username or token first.', [ 'status' => 400 ] );
		}

		$cache_key = 'gwp_repos_' . md5( ( $settings['token'] ?? '' ) . $username . $page );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
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
					'installed'        => $installed[ $full_name ] ?? null,
				];
			},
			$result
		);

		$payload = [
			'repos'    => $repos,
			'has_more' => count( $result ) === 100,
			'page'     => $page,
		];

		set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );

		return $payload;
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
		$settings = (array) get_option( 'gwp_settings', [] );
		$api      = self::make_api( $settings );
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

		$cache_key = 'gwp_detect_' . md5( $owner . $repo . $branch );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$settings = (array) get_option( 'gwp_settings', [] );
		$api      = self::make_api( $settings );
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
		$owner  = sanitize_text_field( $req->get_param( 'owner' ) ?? '' );
		$repo   = sanitize_text_field( $req->get_param( 'repo' ) ?? '' );
		$branch = sanitize_text_field( $req->get_param( 'branch' ) ?? 'main' );
		$type   = sanitize_key( $req->get_param( 'type' ) ?? 'plugin' );

		if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
			return new \WP_Error( 'invalid_type', 'Type must be plugin or theme.', [ 'status' => 400 ] );
		}
		if ( ! $owner || ! $repo ) {
			return new \WP_Error( 'missing_params', 'Missing owner or repo.', [ 'status' => 400 ] );
		}

		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$method = 'theme' === $type ? 'install_theme' : 'install_plugin';
		$result = Installer::$method( $owner, $repo, $branch );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::bust_repos_cache();

		return $result;
	}

	/**
	 * Returns all currently installed repository records, each annotated with
	 * a live `active` flag reflecting the current plugin/theme state.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Map of full_name => record.
	 */
	public static function get_installed(): array {
		$records = Installer::get_installed();

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_theme = get_stylesheet();

		foreach ( $records as &$rec ) {
			if ( 'plugin' === $rec['type'] ) {
				$rec['active'] = ! empty( $rec['plugin_file'] ) && is_plugin_active( $rec['plugin_file'] );
			} else {
				$rec['active'] = $active_theme === $rec['slug'];
			}
		}
		unset( $rec );

		return $records;
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
		$full_name = $owner . '/' . $repo;
		$result    = Installer::activate( $full_name );

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
		$full_name = $owner . '/' . $repo;
		$result    = Installer::deactivate( $full_name );

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

		$full_name = $owner . '/' . $repo;
		$result    = Installer::switch_branch( $full_name, $branch );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::bust_repos_cache();

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
		$full_name = $owner . '/' . $repo;
		$result    = Installer::remove( $full_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::bust_repos_cache();

		return [ 'removed' => true ];
	}

	/**
	 * Returns an API client instance for the configured provider.
	 *
	 * @since 1.1.0
	 * @param array<string, mixed> $settings Plugin settings array.
	 * @return API|GitLab_API Appropriate API client.
	 */
	private static function make_api( array $settings ): API|GitLab_API {
		if ( 'gitlab' === ( $settings['provider'] ?? 'github' ) ) {
			return new GitLab_API(
				$settings['gitlab_token'] ?? '',
				$settings['gitlab_url'] ?? ''
			);
		}
		return new API( $settings['token'] ?? '' );
	}

	/**
	 * Deletes all cached repository transients from the options table.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function bust_repos_cache(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_gwp_repos_%'"
		);
	}
}
