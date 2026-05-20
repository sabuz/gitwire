<?php
defined( 'ABSPATH' ) || exit;

class GHWP_REST {

	private const NS = 'ghwp/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		$ns = self::NS;

		register_rest_route( $ns, '/settings', [
			[ 'methods' => 'GET',  'callback' => [ self::class, 'get_settings'  ], 'permission_callback' => [ self::class, 'can_manage' ] ],
			[ 'methods' => 'POST', 'callback' => [ self::class, 'save_settings' ], 'permission_callback' => [ self::class, 'can_manage' ] ],
		] );

		register_rest_route( $ns, '/connection', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'test_connection' ],
			'permission_callback' => [ self::class, 'can_manage' ],
		] );

		register_rest_route( $ns, '/repos', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'get_repos' ],
			'permission_callback' => [ self::class, 'can_manage' ],
		] );

		register_rest_route( $ns, '/repos/(?P<owner>[^/]+)/(?P<repo>[^/]+)/branches', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'get_branches' ],
			'permission_callback' => [ self::class, 'can_manage' ],
		] );

		register_rest_route( $ns, '/repos/(?P<owner>[^/]+)/(?P<repo>[^/]+)/detect', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'detect_repo' ],
			'permission_callback' => [ self::class, 'can_manage' ],
		] );

		register_rest_route( $ns, '/install', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'install' ],
			'permission_callback' => [ self::class, 'can_manage' ],
		] );

		register_rest_route( $ns, '/installed', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'get_installed' ],
			'permission_callback' => [ self::class, 'can_manage' ],
		] );

		register_rest_route( $ns, '/installed/(?P<owner>[^/]+)/(?P<repo>[^/]+)/branch', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'switch_branch' ],
			'permission_callback' => [ self::class, 'can_manage' ],
		] );

		register_rest_route( $ns, '/installed/(?P<owner>[^/]+)/(?P<repo>[^/]+)', [
			'methods'             => 'DELETE',
			'callback'            => [ self::class, 'remove_installed' ],
			'permission_callback' => [ self::class, 'can_manage' ],
		] );
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	// -----------------------------------------------------------------------
	// Settings
	// -----------------------------------------------------------------------

	public static function get_settings(): array {
		$s = (array) get_option( 'ghwp_settings', [] );
		return [
			'username'      => $s['username']      ?? '',
			'token'         => $s['token']         ?? '',
			'smart_install' => $s['smart_install'] ?? true,
		];
	}

	public static function save_settings( WP_REST_Request $req ): array|WP_Error {
		$token         = sanitize_text_field( $req->get_param( 'token' )         ?? '' );
		$username      = sanitize_text_field( $req->get_param( 'username' )      ?? '' );
		$smart_install = (bool) $req->get_param( 'smart_install' );

		if ( ! $username ) {
			return new WP_Error( 'missing_username', 'GitHub Username is required.', [ 'status' => 400 ] );
		}

		update_option( 'ghwp_settings', compact( 'token', 'username', 'smart_install' ) );
		delete_option( 'ghwp_connection_cache' );

		return [ 'saved' => true, 'smart_install' => $smart_install ];
	}

	// -----------------------------------------------------------------------
	// Connection
	// -----------------------------------------------------------------------

	public static function test_connection(): array|WP_Error {
		$settings = (array) get_option( 'ghwp_settings', [] );
		$api      = new GHWP_API( $settings['token'] ?? '' );
		$result   = $api->test_connection();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = [
			'authenticated'  => ! empty( $result['login'] ),
			'login'          => $result['login']          ?? '',
			'name'           => $result['name']           ?? '',
			'avatar_url'     => $result['avatar_url']     ?? '',
			'rate_limit'     => $result['rate_limit']     ?? 60,
			'rate_remaining' => $result['rate_remaining'] ?? 0,
			'rate_reset'     => $result['rate_reset']     ?? 0,
			'checked_at'     => time(),
		];

		update_option( 'ghwp_connection_cache', $data, false );

		return $data;
	}

	// -----------------------------------------------------------------------
	// Repos
	// -----------------------------------------------------------------------

	public static function get_repos( WP_REST_Request $req ): array|WP_Error {
		$settings = (array) get_option( 'ghwp_settings', [] );
		$username = sanitize_text_field( $req->get_param( 'username' ) ?? $settings['username'] ?? '' );
		$page     = max( 1, (int) ( $req->get_param( 'page' ) ?? 1 ) );

		if ( ! $username && ! ( $settings['token'] ?? '' ) ) {
			return new WP_Error( 'missing_config', 'Configure a GitHub username or token first.', [ 'status' => 400 ] );
		}

		$cache_key = 'ghwp_repos_' . md5( ( $settings['token'] ?? '' ) . $username . $page );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}

		$api       = new GHWP_API( $settings['token'] ?? '' );
		$result    = $api->get_repos( $username, $page );
		$installed = GHWP_Installer::get_installed();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$repos = array_map( static function ( $r ) use ( $installed ) {
			$full_name = $r['full_name'] ?? '';
			return [
				'id'              => $r['id'],
				'name'            => $r['name'],
				'full_name'       => $full_name,
				'owner'           => $r['owner']['login'] ?? explode( '/', $full_name )[0],
				'description'     => $r['description']  ?? '',
				'private'         => (bool) ( $r['private'] ?? false ),
				'html_url'        => $r['html_url']      ?? '',
				'default_branch'  => $r['default_branch'] ?? 'main',
				'updated_at'      => $r['updated_at']    ?? '',
				'stargazers_count'=> (int) ( $r['stargazers_count'] ?? 0 ),
				'installed'       => $installed[ $full_name ] ?? null,
			];
		}, $result );

		$payload = [
			'repos'    => $repos,
			'has_more' => count( $result ) === 100,
			'page'     => $page,
		];

		set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );

		return $payload;
	}

	public static function get_branches( WP_REST_Request $req ): array|WP_Error {
		$owner    = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo     = sanitize_text_field( $req->get_param( 'repo' )  );
		$settings = (array) get_option( 'ghwp_settings', [] );
		$api      = new GHWP_API( $settings['token'] ?? '' );
		$result   = $api->get_branches( $owner, $repo );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_column( $result, 'name' );
	}

	public static function detect_repo( WP_REST_Request $req ): array|WP_Error {
		$owner  = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo   = sanitize_text_field( $req->get_param( 'repo' )  );
		$branch = sanitize_text_field( $req->get_param( 'branch' ) ?? 'HEAD' );

		$cache_key = 'ghwp_detect_' . md5( $owner . $repo . $branch );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}

		$settings = (array) get_option( 'ghwp_settings', [] );
		$api      = new GHWP_API( $settings['token'] ?? '' );
		$result   = $api->detect_type( $owner, $repo, $branch );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		set_transient( $cache_key, $result, HOUR_IN_SECONDS );

		return $result;
	}

	// -----------------------------------------------------------------------
	// Install / manage
	// -----------------------------------------------------------------------

	public static function install( WP_REST_Request $req ): array|WP_Error {
		$owner  = sanitize_text_field( $req->get_param( 'owner' )  ?? '' );
		$repo   = sanitize_text_field( $req->get_param( 'repo' )   ?? '' );
		$branch = sanitize_text_field( $req->get_param( 'branch' ) ?? 'main' );
		$type   = sanitize_key( $req->get_param( 'type' ) ?? 'plugin' );

		if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
			return new WP_Error( 'invalid_type', 'Type must be plugin or theme.', [ 'status' => 400 ] );
		}
		if ( ! $owner || ! $repo ) {
			return new WP_Error( 'missing_params', 'Missing owner or repo.', [ 'status' => 400 ] );
		}

		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$method = $type === 'theme' ? 'install_theme' : 'install_plugin';
		$result = GHWP_Installer::$method( $owner, $repo, $branch );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::bust_repos_cache();

		return $result;
	}

	public static function get_installed(): array {
		return GHWP_Installer::get_installed();
	}

	public static function switch_branch( WP_REST_Request $req ): array|WP_Error {
		$owner  = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo   = sanitize_text_field( $req->get_param( 'repo' )  );
		$branch = sanitize_text_field( $req->get_param( 'branch' ) ?? '' );

		if ( ! $branch ) {
			return new WP_Error( 'missing_branch', 'Branch is required.', [ 'status' => 400 ] );
		}

		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$full_name = $owner . '/' . $repo;
		$result    = GHWP_Installer::switch_branch( $full_name, $branch );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::bust_repos_cache();

		return $result;
	}

	public static function remove_installed( WP_REST_Request $req ): array|WP_Error {
		$owner     = sanitize_text_field( $req->get_param( 'owner' ) );
		$repo      = sanitize_text_field( $req->get_param( 'repo' )  );
		$full_name = $owner . '/' . $repo;
		$result    = GHWP_Installer::remove( $full_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::bust_repos_cache();

		return [ 'removed' => true ];
	}

	// -----------------------------------------------------------------------

	private static function bust_repos_cache(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ghwp_repos_%'"
		);
	}
}
