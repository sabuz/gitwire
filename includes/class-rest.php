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
	 * Meta keys that belong to the profile cache.
	 *
	 * Config keys like gitlab_url live in the same table but are excluded from
	 * cache operations so they are never accidentally cleared.
	 *
	 * @var string[]
	 */
	private const PROFILE_KEYS = [ 'provider', 'authenticated', 'username', 'name', 'avatar_url', 'rate_limit', 'rate_remaining', 'rate_reset', 'checked_at', 'error' ];

	/**
	 * Request-scoped cache for the gitwire_running_task option.
	 *
	 * @var array<string, mixed>|false|null null = not yet loaded, false = loaded + absent.
	 */
	private static mixed $pending_cache = null;

	/**
	 * Returns the pending update option, reading the DB at most once per request.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>|false
	 */
	private static function get_running_task(): mixed {
		if ( null === self::$pending_cache ) {
			self::$pending_cache = get_option( 'gitwire_running_task' );
		}
		return self::$pending_cache;
	}

	/**
	 * Clears the pending update cache and persists the new value.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $pending New pending update value.
	 * @return void
	 */
	private static function set_running_task( array $pending ): void {
		self::$pending_cache = $pending;
		update_option( 'gitwire_running_task', $pending, false );
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
					'offset' => [
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					],
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
					'repositories' => [
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
			'/installed/(?P<owner>[^/]+)/(?P<repo>[^/]+)/auto-update',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'save_auto_update' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'provider'    => $provider_arg,
					'auto_update' => [
						'required' => true,
						'type'     => 'string',
						'enum'     => [ 'disabled', 'current', 'any' ],
					],
				],
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
		 * @since 1.0.0
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
			(string) $req->get_param( 'gitlab_url' )
		);
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$conn = Public_Connections::add( $provider, $valid['identifier'], $valid['gitlab_url'] );
		$id   = $conn['id'] ?? '';

		if ( '' !== $id ) {
			self::write_public_metadata( $id, $provider, $valid['identifier'], $valid['gitlab_url'] );
		}

		return [
			'connection' => $conn,
			'metadata'   => self::get_public_connections_metadata( $id ) ?: null,
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

		self::clear_public_connection_metadata( $id );

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

		$payload = self::get_public_github_rate( $id, $conn['identifier'] ?? '', $conn['avatar_url'] ?? '' );

		if ( null === $payload ) {
			return new \WP_REST_Response( null, 204 );
		}

		return new \WP_REST_Response( $payload );
	}

	/**
	 * Cron handler: refreshes the cached profile for every public connection.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function refresh_public_connections(): void {
		foreach ( Public_Connections::all() as $conn ) {
			$id       = $conn['id'] ?? '';
			$provider = $conn['provider'] ?? '';
			$username = $conn['identifier'] ?? '';

			if ( '' === $id ) {
				continue;
			}

			if ( 'github' === $provider ) {
				$payload = self::fetch_github_profile( $username );
				if ( null !== $payload ) {
					self::save_public_connection_metadata( $id, $payload );
				}
			} else {
				self::write_public_metadata( $id, $provider, $username, $conn['gitlab_url'] ?? '' );
			}
		}
	}

	/**
	 * Returns cached-or-fresh GitHub rate data for a public connection.
	 *
	 * Single source of truth for the 15-minute rate transient. Pro delegates here
	 * for its public connections rather than re-fetching from GitHub.
	 *
	 * @since 1.0.0
	 * @param string $id         Public connection ID.
	 * @param string $username   GitHub username.
	 * @param string $avatar_url Stored avatar URL (GitHub avatars are derived, not stored).
	 * @return array<string, mixed>|null Null when the GitHub request fails.
	 */
	public static function get_public_github_rate( string $id, string $username, string $avatar_url = '' ): ?array {
		$cached = self::get_public_connections_metadata( $id );
		if ( null !== $cached ) {
			return $cached;
		}

		$payload = self::fetch_github_profile( $username, $avatar_url );
		if ( null === $payload ) {
			return null;
		}

		self::save_public_connection_metadata( $id, $payload );

		return $payload;
	}

	/**
	 * Fetches rate limit and display name for a GitHub username without auth.
	 *
	 * Returns null when the API call fails so the caller can decide how to handle it.
	 *
	 * @since 1.0.0
	 * @param string $username   GitHub username.
	 * @param string $avatar_url Stored avatar URL; falls back to the deterministic GitHub URL.
	 * @return array<string, mixed>|null
	 */
	private static function fetch_github_profile( string $username, string $avatar_url = '' ): ?array {
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
			if ( '' === $avatar_url ) {
				$avatar_url = (string) ( $user_data['avatar_url'] ?? '' );
			}
		}

		if ( '' === $avatar_url && '' !== $username ) {
			$avatar_url = 'https://avatars.githubusercontent.com/' . rawurlencode( $username );
		}

		return [
			'provider'       => 'github',
			'authenticated'  => false,
			'username'       => $username,
			'name'           => $name,
			'avatar_url'     => $avatar_url,
			'rate_limit'     => (int) $core['limit'],
			'rate_remaining' => (int) $core['remaining'],
			'rate_reset'     => (int) $core['reset'],
			'checked_at'     => time(),
		];
	}

	/**
	 * Returns a Gravatar identicon URL for a given identifier.
	 *
	 * Used as an avatar fallback for providers with no accessible avatar API.
	 *
	 * @since 1.0.0
	 * @param string $identifier Provider handle (username, workspace slug, etc.).
	 * @return string
	 */
	private static function gravatar_url( string $identifier ): string {
		return 'https://www.gravatar.com/avatar/' . md5( strtolower( trim( $identifier ) ) ) . '?s=96&d=identicon';
	}

	/**
	 * Fetches profile fields for a GitLab user via the unauthenticated API.
	 *
	 * Returns null when the request fails or the user is not found.
	 *
	 * @since 1.0.0
	 * @param string $username   GitLab username.
	 * @param string $gitlab_url Self-hosted instance URL, or empty for gitlab.com.
	 * @return array<string, mixed>|null
	 */
	private static function fetch_gitlab_profile( string $username, string $gitlab_url = '' ): ?array {
		$base     = rtrim( $gitlab_url ? $gitlab_url : 'https://gitlab.com', '/' );
		$response = wp_remote_get(
			$base . '/api/v4/users?username=' . rawurlencode( $username ) . '&per_page=1',
			[ 'timeout' => 5 ]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data[0] ) ) {
			return null;
		}

		return [
			'provider'       => 'gitlab',
			'authenticated'  => false,
			'username'       => $username,
			'name'           => (string) ( $data[0]['name'] ?? '' ),
			'avatar_url'     => (string) ( $data[0]['avatar_url'] ?? '' ),
			'rate_limit'     => 0,
			'rate_remaining' => 0,
			'rate_reset'     => 0,
			'checked_at'     => time(),
		];
	}

	/**
	 * Returns the connection meta table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function meta_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'gitwire_connection_meta';
	}

	/**
	 * Returns all profile-cache metadata keyed by connection_id.
	 *
	 * Reads only PROFILE_KEYS rows; config keys like gitlab_url are excluded.
	 * Pro's get_connection_cache() delegates here and filters by authenticated = '1'.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	public static function get_connection_cache(): array {
		global $wpdb;

		$in_sql = implode( ', ', array_fill( 0, count( self::PROFILE_KEYS ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT connection_id, meta_key, meta_value FROM ' . self::meta_table() . ' WHERE meta_key IN (' . $in_sql . ')',
				...self::PROFILE_KEYS
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		$grouped = [];
		foreach ( $rows as $row ) {
			$grouped[ $row['connection_id'] ][ $row['meta_key'] ] = $row['meta_value'];
		}

		$result = [];
		foreach ( $grouped as $id => $meta ) {
			if ( ! isset( $meta['provider'] ) ) {
				continue;
			}
			$result[ $id ] = self::cast_meta( $meta, $id );
		}

		return $result;
	}

	/**
	 * Returns a single connection's profile cache, or null when not cached yet.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_public_connections_metadata( string $id ): ?array {
		global $wpdb;

		$in_sql = implode( ', ', array_fill( 0, count( self::PROFILE_KEYS ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM ' . self::meta_table() . ' WHERE connection_id = %s AND meta_key IN (' . $in_sql . ')',
				$id,
				...self::PROFILE_KEYS
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return null;
		}

		$meta = array_column( $rows, 'meta_value', 'meta_key' );

		if ( ! isset( $meta['provider'] ) ) {
			return null;
		}

		return self::cast_meta( $meta, $id );
	}

	/**
	 * Casts raw EAV string values to the correct PHP types for profile cache fields.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $meta Flat key-value map from the meta table.
	 * @param string                $id   Connection ID.
	 * @return array<string, mixed>
	 */
	private static function cast_meta( array $meta, string $id ): array {
		return [
			'connection_id'  => $id,
			'provider'       => $meta['provider'] ?? '',
			'authenticated'  => (int) ( $meta['authenticated'] ?? 0 ),
			'username'       => $meta['username'] ?? '',
			'name'           => $meta['name'] ?? '',
			'avatar_url'     => $meta['avatar_url'] ?? '',
			'rate_limit'     => (int) ( $meta['rate_limit'] ?? 0 ),
			'rate_remaining' => (int) ( $meta['rate_remaining'] ?? 0 ),
			'rate_reset'     => (int) ( $meta['rate_reset'] ?? 0 ),
			'checked_at'     => (int) ( $meta['checked_at'] ?? 0 ),
			'error'          => ( '' !== ( $meta['error'] ?? '' ) ) ? $meta['error'] : null,
		];
	}

	/**
	 * Writes profile metadata for a public (no-token) connection.
	 *
	 * Fetches real profile data where an API is available (GitLab); falls back
	 * to a Gravatar identicon for providers with no accessible avatar API (Bitbucket).
	 * GitHub is handled separately via get_public_github_rate().
	 *
	 * @since 1.0.0
	 * @param string $id         Connection ID.
	 * @param string $provider   Provider key.
	 * @param string $identifier Username or workspace slug.
	 * @param string $gitlab_url Self-hosted GitLab URL, or empty for gitlab.com.
	 * @return void
	 */
	public static function write_public_metadata( string $id, string $provider, string $identifier, string $gitlab_url = '' ): void {
		if ( 'gitlab' === $provider ) {
			$profile = self::fetch_gitlab_profile( $identifier, $gitlab_url );
			self::save_public_connection_metadata(
				$id,
				$profile ?? [
					'provider'       => 'gitlab',
					'authenticated'  => false,
					'username'       => $identifier,
					'name'           => '',
					'avatar_url'     => self::gravatar_url( $identifier ),
					'rate_limit'     => 0,
					'rate_remaining' => 0,
					'rate_reset'     => 0,
					'checked_at'     => time(),
				]
			);
		} elseif ( 'bitbucket' === $provider ) {
			self::save_public_connection_metadata(
				$id,
				[
					'provider'       => 'bitbucket',
					'authenticated'  => false,
					'username'       => $identifier,
					'name'           => '',
					'avatar_url'     => self::gravatar_url( $identifier ),
					'rate_limit'     => 0,
					'rate_remaining' => 0,
					'rate_reset'     => 0,
					'checked_at'     => time(),
				]
			);
		}
	}

	/**
	 * Upserts all profile cache fields for a connection in a single SQL query.
	 *
	 * @since 1.0.0
	 * @param string               $id   Connection ID.
	 * @param array<string, mixed> $data Metadata fields to store.
	 * @return void
	 */
	public static function save_public_connection_metadata( string $id, array $data ): void {
		global $wpdb;

		$defaults = [
			'provider'       => '',
			'authenticated'  => 0,
			'username'       => '',
			'name'           => '',
			'avatar_url'     => '',
			'rate_limit'     => 0,
			'rate_remaining' => 0,
			'rate_reset'     => 0,
			'checked_at'     => 0,
			'error'          => '',
		];
		$meta     = array_intersect_key( array_merge( $defaults, $data ), $defaults );

		$value_parts = [];
		$params      = [];

		foreach ( $meta as $key => $value ) {
			$value_parts[] = '(%s, %s, %s)';
			$params[]      = $id;
			$params[]      = $key;
			$params[]      = match ( true ) {
				is_bool( $value ) => $value ? '1' : '0',
				null === $value   => '',
				default           => (string) $value,
			};
		}

		$sql = 'INSERT INTO ' . self::meta_table() . ' (connection_id, meta_key, meta_value) VALUES '
			. implode( ', ', $value_parts )
			. ' ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$wpdb->query( $wpdb->prepare( $sql, ...$params ) );
	}

	/**
	 * Deletes all profile cache keys for a connection; leaves config keys intact.
	 *
	 * @since 1.0.0
	 * @param string $id Connection ID.
	 * @return void
	 */
	public static function clear_public_connection_metadata( string $id ): void {
		global $wpdb;

		$in_sql = implode( ', ', array_fill( 0, count( self::PROFILE_KEYS ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::meta_table() . ' WHERE connection_id = %s AND meta_key IN (' . $in_sql . ')',
				$id,
				...self::PROFILE_KEYS
			)
		);
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
		foreach ( [
			'smart_install',
			'show_repo_label',
			'enable_logging',
			'log_retention_days',
			'log_level',
			'remove_data_on_uninstall',
			'repo_list_refresh_frequency',
			'auto_detect_type',
			'repos_per_page',
			'max_repos_per_source',
			'background_type_detection',
			'shallow_detection',
			'block_on_fatal',
			'update_check_interval',
		] as $key ) {
			if ( null !== $req->get_param( $key ) ) {
				$incoming[ $key ] = $req->get_param( $key );
			}
		}
		// excluded_repos is an array — check for it separately.
		if ( null !== $req->get_param( 'excluded_repos' ) ) {
			$incoming['excluded_repos'] = $req->get_param( 'excluded_repos' );
		}

		$was_logging          = Settings::is_logging_enabled();
		$prev_settings        = Settings::get_public();
		$prev_freq            = $prev_settings['repo_list_refresh_frequency'] ?? 'daily';
		$prev_update_interval = $prev_settings['update_check_interval'] ?? 'halfhourly';
		$merged               = Settings::merge_save( $incoming );
		update_option( 'gitwire_settings', $merged );

		$now_logging = (bool) ( $merged['enable_logging'] ?? false );
		if ( ! $was_logging && $now_logging ) {
			Logger::log( 'Logging enabled' );
		}

		if ( ( $merged['repo_list_refresh_frequency'] ?? 'hourly' ) !== $prev_freq ) {
			Repo_Cache::clear_repo_list();
			Plugin::instance()->schedule_repos_cron();
		}

		if ( ( $merged['update_check_interval'] ?? 'halfhourly' ) !== $prev_update_interval ) {
			Plugin::instance()->schedule_update_check_cron();
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
		$offset = max( 0, (int) ( $req->get_param( 'offset' ) ?? 0 ) );
		$search = sanitize_text_field( $req->get_param( 'search' ) ?? '' );

		$connections = array_values(
			array_filter(
				Connection_Resolver::all(),
				static fn( $c ) => isset( $c['id'] ) && '' !== $c['id'] && in_array( $c['provider'] ?? '', [ 'github', 'gitlab', 'bitbucket' ], true )
			)
		);

		if ( empty( $connections ) ) {
			return [
				'repositories' => [],
				'has_more'     => false,
				'offset'       => 0,
			];
		}

		$connection_ids = array_column( $connections, 'id' );
		$cached         = Repo_Cache::get_repo_list( $connection_ids, $offset, $search );

		if ( null === $cached ) {
			// Seed cache with page 1 from each connection on first browse.
			foreach ( $connections as $conn ) {
				Repo_Cache::fetch_repo_list( $conn['provider'], 1, $conn['id'] );
			}
			$cached = Repo_Cache::get_repo_list( $connection_ids, $offset, $search );
		}

		if ( null === $cached ) {
			return [
				'repositories' => [],
				'has_more'     => false,
				'offset'       => 0,
			];
		}

		return self::enrich_with_detections( self::merge_installed( $cached ) );
	}

	/**
	 * Merges current installed status into a cached repo list payload.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $payload Repo list payload from cache.
	 * @return array<string, mixed>
	 */
	private static function merge_installed( array $payload ): array {
		$installed               = Installer::get_installed();
		$payload['repositories'] = array_map(
			static function ( $repo ) use ( $installed ) {
				$repo['installed'] = $installed[ ( $repo['provider'] ?? '' ) . ':' . ( $repo['full_name'] ?? '' ) ] ?? null;
				return $repo;
			},
			$payload['repositories'] ?? []
		);
		return $payload;
	}

	/**
	 * Fetches and normalizes a paginated repository list from the Git provider API.
	 *
	 * Does not embed installed status — callers merge that at response time via merge_installed().
	 *
	 * @since 1.0.0
	 * @param string $provider      Provider key: github, gitlab, or bitbucket.
	 * @param int    $page          Page number.
	 * @param string $connection_id Connection ID to use for credentials.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function build_repo_list( string $provider, int $page, string $connection_id = '' ): array|\WP_Error {
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
			$result = $api->get_repos( $workspace, $page );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$repositories = array_map(
				static function ( $r ) {
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
						'last_activity_at' => $r['updated_on'] ?? '',
						'stargazers_count' => 0,
					];
				},
				$result['repositories']
			);

			return [
				'repositories' => $repositories,
				'has_more'     => $result['has_more'],
				'page'         => $page,
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

			$result = $api->get_repos( $username, $page );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$repositories = array_map(
				static function ( $r ) {
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
						'last_activity_at' => $r['last_activity_at'] ?? '',
						'stargazers_count' => (int) ( $r['star_count'] ?? 0 ),
					];
				},
				$result
			);

			return [
				'repositories' => $repositories,
				'has_more'     => count( $result ) === self::PAGE_SIZE,
				'page'         => $page,
			];
		}

		$creds    = $creds ?? [];
		$username = sanitize_text_field( $creds['username'] ?? '' );

		if ( ! $username && empty( $creds['token'] ) ) {
			return new \WP_Error( 'missing_config', 'Add a GitHub account in Settings first.', [ 'status' => 400 ] );
		}

		$api    = new GitHub_API( $creds['token'] ?? '' );
		$result = $api->get_repos( $username, $page );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$repositories = array_map(
			static function ( $r ) {
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
					'last_activity_at' => $r['updated_at'] ?? '',
					'stargazers_count' => (int) ( $r['stargazers_count'] ?? 0 ),
				];
			},
			$result
		);

		return [
			'repositories' => $repositories,
			'has_more'     => count( $result ) === self::PAGE_SIZE,
			'page'         => $page,
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

		// Only use cache for unauthenticated lookups; a specific connection may access private repositories.
		if ( '' === $connection_id ) {
			$cached = Repo_Cache::get_repo_type( $provider, $owner, $repo, $branch );
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
				'confidence' => 'none',
				'name'       => '',
			];
		}

		if ( '' === $connection_id ) {
			Repo_Cache::set_repo_type( $provider, $owner, $repo, $branch, $result );
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
			$db_scope = $conn['scope'] ?? 'all';
			if ( $conn && 'all' !== $db_scope && $db_scope !== (string) get_current_user_id() ) {
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

		self::store_head( $owner, $repo, $branch, $provider, $connection_id );
		self::update_commit_history_after_pull( $provider, $owner, $repo, $branch, $connection_id );

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
		$records  = Installer::get_installed();
		$orphaned = [];

		foreach ( $records as $key => $rec ) {
			if ( ! empty( $rec['install_path'] ) && ! is_dir( $rec['install_path'] ) ) {
				$orphaned[] = [
					'full_name' => $rec['full_name'] ?? '',
					'provider'  => $rec['provider'] ?? 'github',
				];
				Installer::delete_record( $rec['provider'] ?? 'github', $rec['full_name'] ?? '' );
				unset( $records[ $key ] );
			}
		}

		$pending_orphans = get_option( 'gitwire_orphan_queue' );
		if ( is_array( $pending_orphans ) && $pending_orphans ) {
			delete_option( 'gitwire_orphan_queue' );
			foreach ( $pending_orphans as $item ) {
				$orphaned[] = $item;
			}
		}

		return [
			'installed' => self::annotate_installed( $records ),
			'orphaned'  => $orphaned,
		];
	}

	/**
	 * Prunes missing directories, heals plugin files, and refreshes remote HEADs.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Synced installed records and any orphaned entries.
	 */
	public static function sync_installed(): array {
		$records  = Installer::get_installed();
		$orphaned = [];

		$pending       = self::get_running_task();
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
				Installer::delete_record( $rec['provider'], $rec['full_name'] );
				unset( $records[ $key ] );
				continue;
			}

			if ( 'plugin' === ( $rec['type'] ?? '' ) && empty( $rec['plugin_file'] ) && ! empty( $rec['install_path'] ) ) {
				$found = Installer::find_plugin_file( $rec['install_path'], $rec['slug'] ?? '' );
				if ( $found ) {
					$rec['plugin_file'] = $found;
					Installer::set_plugin_file( $rec['provider'] ?? 'github', $rec['full_name'] ?? '', $found );
				}
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
				}
			}

			$record_key = ( $rec['provider'] ?? 'github' ) . ':' . ( $rec['full_name'] ?? '' );
			if ( ! $pending_guard || $record_key !== $pending_key ) {
				$remote_head = self::fetch_remote_head( $rec );
				if ( $remote_head && $remote_head !== ( $rec['remote_head'] ?? '' ) ) {
					$rec['remote_head'] = $remote_head;
					Installer::set_remote_head( $rec['provider'] ?? 'github', $rec['full_name'] ?? '', $remote_head );
				}
			}
		}
		unset( $rec );

		return [
			'installed' => self::annotate_installed( $records ),
			'orphaned'  => $orphaned,
		];
	}

	/**
	 * Annotates installed records with live active state and update availability.
	 *
	 * @since 1.0.0
	 * @param array<string, array<string, mixed>> $records Raw installed records.
	 * @return array<string, array<string, mixed>>
	 */
	private static function annotate_installed( array $records ): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_theme  = get_stylesheet();
		$pending       = self::get_running_task();
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
				$rec['active'] = ! empty( $rec['plugin_file'] ) && is_plugin_active( $rec['plugin_file'] );
			} else {
				$rec['active'] = ( $rec['slug'] ?? '' ) === $active_theme;
			}

			if (
				$pending_guard
				&& ( $pending['full_name'] ?? '' ) === ( $rec['full_name'] ?? '' )
			) {
				$rec['activation_pending'] = true;
				if (
					Repo_Detector::is_theme( $rec['type'] ?? '' )
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

			$remote_head = $rec['remote_head'] ?? '';
			if ( '' !== $remote_head ) {
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
	 * @since 1.0.0
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
	 * Detects repository types for a batch of repositories.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, array<string, mixed>> Map of detection keys to results.
	 */
	public static function detect_batch( \WP_REST_Request $req ): array {
		$repositories = $req->get_param( 'repositories' );
		$results      = [];

		if ( ! is_array( $repositories ) ) {
			return [ 'detections' => $results ];
		}

		$repositories = array_slice( $repositories, 0, 50 );

		foreach ( $repositories as $entry ) {
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
			$cached = Repo_Cache::get_repo_type( $provider, $owner, $repo, $branch );
			if ( is_array( $cached ) ) {
				$results[ $key ] = $cached;
				continue;
			}

			$result = self::detect_type_for_repo( $provider, $owner, $repo, $branch, $connection_id );

			if ( is_wp_error( $result ) ) {
				Logger::log( sprintf( 'Detection failed — %s: %s', $key, $result->get_error_message() ), 'error' );
				$result          = [
					'type'       => 'unknown',
					'confidence' => 'none',
					'name'       => '',
					'error_code' => $result->get_error_code(),
				];
				$results[ $key ] = $result;
				continue;
			}

			Repo_Cache::set_repo_type( $provider, $owner, $repo, $branch, $result );
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
			Logger::log(
				sprintf(
					'[%s] Activation failed — %s/%s: %s: %s in %s on line %d',
					$provider,
					$owner,
					$repo,
					get_class( $e ),
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				),
				'error'
			);
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

		$stored_conn_id = $override_id ?? ( $existing_record['connection_id'] ?? null );
		self::store_head( $owner, $repo, $branch, $provider, $stored_conn_id );
		self::update_commit_history_after_pull( $provider, $owner, $repo, $branch, $stored_conn_id );

		if ( ! $is_pull ) {
			// remote_head was for the previous branch; wipe it so sync_installed re-resolves.
			Installer::set_remote_head( $provider, $full_name, '' );
		}

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

		$pending = self::get_running_task();
		if ( is_array( $pending ) && ( $pending['full_name'] ?? '' ) === $full_name ) {
			Error_Handler::abort_pending_guard();
		}

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

		return [ 'untracked' => true ];
	}

	/**
	 * Saves auto-update settings for an installed repository.
	 *
	 * @since 2.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error Updated values or WP_Error on failure.
	 */
	public static function save_auto_update( \WP_REST_Request $req ): array|\WP_Error {
		global $wpdb;

		$owner       = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo        = sanitize_text_field( $req->get_param( 'repo' ) );
		$provider    = sanitize_key( $req->get_param( 'provider' ) ?? 'github' );
		$auto_update = (string) $req->get_param( 'auto_update' );
		$full_name   = $owner . '/' . $repo;

		if ( ! Installer::get_record( $provider, $full_name ) ) {
			return new \WP_Error( 'gitwire_not_found', 'Repository is not installed.', [ 'status' => 404 ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->base_prefix . 'gitwire_installations',
			[ 'auto_update' => $auto_update ],
			[
				'provider'  => $provider,
				'full_name' => $full_name,
			],
			[ '%s' ],
			[ '%s', '%s' ]
		);
		Installer::invalidate_installed_cache();

		return [
			'provider'    => $provider,
			'full_name'   => $full_name,
			'auto_update' => $auto_update,
		];
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

		$installation_id = $record['id'];
		$cached       = self::get_cached_commits( $installation_id, $record['branch'] );
		if ( null !== $cached ) {
			return self::annotate_commits_with_fatal( $cached, $provider, $full_name, $record['branch'] );
		}

		$api     = self::make_api( $provider, $record['connection_id'] ?? null );
		$commits = $api->get_commits( $owner, $repo, $record['branch'] );

		if ( is_wp_error( $commits ) ) {
			return $commits;
		}

		self::save_cached_commits( $installation_id, $record['branch'], $commits );

		return self::annotate_commits_with_fatal( $commits, $provider, $full_name, $record['branch'] );
	}

	/**
	 * Returns the gitwire_commits table name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function commits_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'gitwire_commits';
	}

	/**
	 * Returns a cached commit list from the DB, or null when not cached.
	 *
	 * @since 1.0.0
	 * @param int    $installation_id Primary key of the gitwire_installations row.
	 * @param string $branch       Branch name.
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function get_cached_commits( int $installation_id, string $branch ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT data FROM ' . self::commits_table() . ' WHERE installation_id = %d AND branch = %s',
				$installation_id,
				$branch
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$commits = json_decode( $row['data'], true );
		return is_array( $commits ) && $commits ? $commits : null;
	}

	/**
	 * Writes or replaces the commit cache for a repo/branch.
	 *
	 * @since 1.0.0
	 * @param int                              $installation_id Primary key of the gitwire_installations row.
	 * @param string                           $branch       Branch name.
	 * @param array<int, array<string, mixed>> $commits      Commit list.
	 * @return void
	 */
	private static function save_cached_commits( int $installation_id, string $branch, array $commits ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::commits_table() . ' (installation_id, branch, data, updated_at)
				VALUES (%d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)',
				$installation_id,
				$branch,
				wp_json_encode( $commits ),
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Flags commits that recently failed active-theme fatal validation.
	 *
	 * @since 1.0.0
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
	 * Enriches a repository list payload with any already-cached detection results.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $payload Repo list payload with a 'repositories' key.
	 * @return array<string, mixed>
	 */
	private static function enrich_with_detections( array $payload ): array {
		$payload['repositories'] = array_map(
			static function ( $repo ) {
				// type_meta is embedded by get_repo_list() directly from the cache table row.
				if ( isset( $repo['type_meta'] ) ) {
					$repo['detection'] = array_merge( $repo['type_meta'], [ 'type' => $repo['type'] ?? '' ] );
					unset( $repo['type_meta'] );
					return $repo;
				}
				$detection = Repo_Cache::get_repo_type(
					$repo['provider'] ?? '',
					$repo['owner'] ?? '',
					$repo['name'] ?? '',
					$repo['default_branch'] ?? 'main'
				);
				if ( is_array( $detection ) ) {
					$repo['detection'] = $detection;
				}
				return $repo;
			},
			$payload['repositories'] ?? []
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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

		return new GitHub_API( '' );
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

	/**
	 * Fetches commits and writes them to an option after a successful install or pull.
	 *
	 * @since 1.0.0
	 * @param string      $provider      Provider key.
	 * @param string      $owner         Repository owner.
	 * @param string      $repo          Repository name.
	 * @param string      $branch        Branch name.
	 * @param string|null $connection_id Connection ID.
	 * @return void
	 */
	private static function update_commit_history_after_pull( string $provider, string $owner, string $repo, string $branch, ?string $connection_id ): void {
		$record = Installer::get_record( $provider, $owner . '/' . $repo );
		if ( ! $record ) {
			return;
		}
		$api     = self::make_api( $provider, $connection_id );
		$commits = $api->get_commits( $owner, $repo, $branch );
		if ( ! is_wp_error( $commits ) ) {
			self::save_cached_commits( $record['id'], $branch, $commits );
		}
	}
}
