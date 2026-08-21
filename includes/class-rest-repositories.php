<?php
/**
 * REST endpoints for repository browsing, detection, and resolution.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the /repos REST routes.
 */
class REST_Repositories {

	/**
	 * Page size for provider API calls.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Requests held back from detection for user-initiated work.
	 *
	 * Detection is speculative: nobody asked for the badge on a repo they are only
	 * scrolling past. Installing, listing branches, and resolving a pasted URL are not,
	 * and on an unauthenticated GitHub connection the whole hourly allowance is 60, so
	 * a single browse page can spend it before any of those get a turn. The background
	 * job yields at a higher floor still, since it is not even on screen.
	 *
	 * @var int
	 */
	private const DETECTION_RESERVE = 15;

	/**
	 * Shared provider argument definition.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	private static function provider_arg(): array {
		return [
			'type'    => 'string',
			'default' => 'github',
			'enum'    => [ 'github', 'gitlab', 'bitbucket' ],
		];
	}

	/**
	 * Shared connection ID argument definition.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>
	 */
	private static function connection_arg(): array {
		return [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		];
	}

	/**
	 * Registers /repos routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		$namespace = REST::NAMESPACE;

		register_rest_route(
			$namespace,
			'/repos',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_repos' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
				'args'                => [
					'offset'         => [
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					],
					'search'         => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'connection_ids' => [
						'type'    => 'array',
						'items'   => [ 'type' => 'string' ],
						'default' => [],
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/repos/cache',
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'clear_cache' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
				'args'                => [
					'mode' => [
						'type'    => 'string',
						'default' => 'repos',
						'enum'    => [ 'repos', 'repos_and_types', 'types' ],
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/repos/(?P<owner>[^/]+)/(?P<repo>[^/]+)/branches',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_branches' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
				'args'                => [
					'provider'      => self::provider_arg(),
					'connection_id' => self::connection_arg(),
				],
			]
		);

		register_rest_route(
			$namespace,
			'/repos/(?P<owner>[^/]+)/(?P<repo>[^/]+)/detect',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'detect_repo' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
				'args'                => [
					'provider'      => self::provider_arg(),
					'connection_id' => self::connection_arg(),
					'branch'        => [
						'type'              => 'string',
						'default'           => 'HEAD',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/repos/detect-batch',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'detect_batch' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
				'args'                => [
					'repositories' => [
						'required' => true,
						'type'     => 'array',
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/repos/resolve',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'resolve_repo' ],
				'permission_callback' => [ REST::class, 'can_manage' ],
				'args'                => [
					'url' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);
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
	 * Returns a paginated list of repositories for the configured user.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error Repository payload on success, WP_Error on failure.
	 */
	public static function get_repos( \WP_REST_Request $req ): array|\WP_Error {
		$offset     = max( 0, (int) ( $req->get_param( 'offset' ) ?? 0 ) );
		$search     = (string) $req->get_param( 'search' );
		$filter_ids = array_values( array_filter( (array) ( $req->get_param( 'connection_ids' ) ?? [] ) ) );

		$connections = array_values(
			array_filter(
				Connection_Resolver::all(),
				static fn( $c ) => isset( $c['id'] ) && '' !== $c['id']
					&& in_array( $c['provider'] ?? '', [ 'github', 'gitlab', 'bitbucket' ], true )
					&& null !== Connection_Resolver::get_credentials( $c['id'] )
			)
		);

		if ( empty( $connections ) ) {
			return [
				'repositories' => [],
				'has_more'     => false,
				'offset'       => 0,
			];
		}

		$connection_ids      = array_column( $connections, 'id' );
		$cached_ids          = Repositories::get_cached_connection_ids( $connection_ids );
		$uncached            = array_values(
			array_filter( $connections, static fn( $c ) => ! in_array( $c['id'], $cached_ids, true ) )
		);
		$connection_errors   = [];
		$connection_warnings = [];

		// Fetch any connections not yet represented in the cache table.
		foreach ( $uncached as $conn ) {
			$provider = $conn['provider'];
			$result   = Repositories::fetch_repositories( $provider, 1, $conn['id'] );
			if ( is_wp_error( $result ) ) {
				$connection_errors[] = [
					'connection_id' => $conn['id'],
					'provider'      => $provider,
					'message'       => $result->get_error_message(),
				];
			} elseif ( 'gitlab' === $provider && empty( $result['repositories'] ) ) {
				$creds = Connection_Resolver::get_credentials( $conn['id'] );
				// public (no-token) connection returned 0 repos; private/group repos need a PAT.
				if ( empty( $creds['token'] ) ) {
					$connection_warnings[] = [
						'connection_id' => $conn['id'],
						'provider'      => $provider,
						'message'       => 'No public GitLab repositories found. Public connections only show your own public projects. Add a Personal Access Token to browse private and group repositories.',
					];
				}
			}
		}

		// Narrow the DB query to the requested connections; uncached fetch above always runs for all.
		$query_ids = ! empty( $filter_ids )
			? array_values( array_intersect( $connection_ids, $filter_ids ) )
			: $connection_ids;

		if ( empty( $query_ids ) ) {
			return [
				'repositories'        => [],
				'has_more'            => false,
				'offset'              => 0,
				'connection_errors'   => $connection_errors,
				'connection_warnings' => $connection_warnings,
			];
		}

		$cached = Repositories::get_repositories( $query_ids, $offset, $search );

		if ( null === $cached ) {
			return [
				'repositories'        => [],
				'has_more'            => false,
				'offset'              => 0,
				'connection_errors'   => $connection_errors,
				'connection_warnings' => $connection_warnings,
			];
		}

		$payload = self::enrich_with_detections( self::merge_installed( $cached ) );
		if ( ! empty( $connection_errors ) ) {
			$payload['connection_errors'] = $connection_errors;
		}
		if ( ! empty( $connection_warnings ) ) {
			$payload['connection_warnings'] = $connection_warnings;
		}
		return $payload;
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
	 * Does not embed installed status; callers merge that at response time via merge_installed().
	 *
	 * @since 1.0.0
	 * @param string $provider      Provider key: github, gitlab, or bitbucket.
	 * @param int    $page          Page number.
	 * @param string $connection_id Connection ID to use for credentials.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function build_repositories( string $provider, int $page, string $connection_id = '' ): array|\WP_Error {
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
				? Provider_Factory::make( 'bitbucket', $connection_id )
				: new Bitbucket_API( '', '' );
			// Authenticated: empty string, since get_repos auto-discovers workspaces via /user/workspaces.
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
						'owner'            => $parts[0],
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

			/*
			 * Through the factory when authenticated; either way the client carries the
			 * connection ID its rate-limit transient is keyed by. Built without it, a
			 * public connection's listings counted against the 'anon' bucket instead.
			 */
			$api    = $has_auth
				? Provider_Factory::make( 'gitlab', $connection_id )
				: new GitLab_API( '', $gitlab_url, $connection_id );
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

		/*
		 * Through the factory so the gitwire_provider_factory_auth filter applies here
		 * too, and so the client carries the connection ID its rate-limit transient is
		 * keyed by. Built directly, every listing counted against the 'anon' bucket.
		 */
		$api    = Provider_Factory::make( 'github', $connection_id );
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

		if ( '' !== $connection_id ) {
			$scope_error = REST::assert_connection_scope( $connection_id );
			if ( null !== $scope_error ) {
				return $scope_error;
			}
		}

		$result = self::make_api( $provider, '' !== $connection_id ? $connection_id : null )->get_branches( $owner, $repo );

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

		$cached = Repositories::get_repository_type( $provider, $owner, $repo );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api    = self::make_api( $provider, '' !== $connection_id ? $connection_id : null );
		$result = $api->detect_type( $owner, $repo, $branch );

		if ( is_wp_error( $result ) ) {
			if ( '' !== $connection_id ) {
				$status = (int) ( $result->get_error_data()['status'] ?? 400 );
				return new \WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => $status ] );
			}

			/*
			 * Answered as unknown but deliberately not cached, same as detect_batch():
			 * a rate-limit 403 or a network blip would otherwise pin the repo as
			 * unknown until someone clears the cache.
			 */
			return [
				'type'       => 'unknown',
				'confidence' => 'none',
				'name'       => '',
			];
		}

		Repositories::set_repository_type( $provider, $owner, $repo, $result );

		return $result;
	}

	/**
	 * Detects repository types for a batch of repositories.
	 *
	 * Repositories the request declined to look up, because the time budget ran out or
	 * the provider's remaining quota is down to the reserve, come back under 'paused'
	 * with the reason. They are not failures and carry no type: the caller is expected
	 * to say so rather than leave them looking like a detection still in flight.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed> Detections keyed by provider:owner/repo, plus any
	 *                              paused keys and the reason they were skipped.
	 */
	public static function detect_batch( \WP_REST_Request $req ): array {
		$repositories = $req->get_param( 'repositories' );
		$results      = [];

		if ( ! is_array( $repositories ) ) {
			return [ 'detections' => $results ];
		}

		$repositories = array_slice( $repositories, 0, 10 );
		$started      = time();
		$paused       = [];
		$reason       = '';

		/**
		 * Filters how long one detect-batch request may spend calling providers.
		 *
		 * Each repository costs a tree listing plus up to five file fetches, so ten
		 * of them can be seventy round trips. Returning what is ready beats handing
		 * the client a gateway timeout.
		 *
		 * @since 1.0.0
		 * @param int $budget Seconds per request. Default 15.
		 * @return int
		 */
		$budget = (int) apply_filters( 'gitwire_detect_batch_time_budget', 15 );

		foreach ( $repositories as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			// Cache hits below are free, so only stop once a live lookup is needed.
			$out_of_time = ( time() - $started ) >= $budget;

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
			$cached = Repositories::get_repository_type( $provider, $owner, $repo );
			if ( is_array( $cached ) ) {
				$results[ $key ] = $cached;
				continue;
			}

			/*
			 * Skipped rows are named rather than dropped. Leaving them out of the
			 * response is what left browse cards spinning on a detection that was
			 * never coming.
			 */
			if ( $out_of_time ) {
				$paused[] = $key;
				$reason   = '' !== $reason ? $reason : 'time';
				continue;
			}

			if ( ! self::has_detection_quota( $provider, $connection_id ) ) {
				$paused[] = $key;
				$reason   = 'rate_limit';
				continue;
			}

			if ( null !== $connection_id ) {
				$scope_error = REST::assert_connection_scope( $connection_id );
				if ( null !== $scope_error ) {
					$results[ $key ] = [
						'type'       => 'unknown',
						'confidence' => 'none',
						'name'       => '',
						'error_code' => 'forbidden',
					];
					continue;
				}
			}

			$result = self::detect_type_for_repo( $provider, $owner, $repo, $branch, $connection_id );

			if ( is_wp_error( $result ) ) {
				Logger::log( sprintf( 'Detection failed for %s: %s', $key, $result->get_error_message() ), 'error' );
				$error_code = $result->get_error_code();

				/*
				 * Deliberately not cached. A rate-limit 403 or a network blip would
				 * otherwise pin the repo as 'unknown' until someone clears the cache.
				 */
				$result          = [
					'type'       => 'unknown',
					'confidence' => 'none',
					'name'       => '',
					'error_code' => $error_code,
				];
				$results[ $key ] = $result;
				continue;
			}

			Repositories::set_repository_type( $provider, $owner, $repo, $result );
			$results[ $key ] = $result;
		}

		$payload = [ 'detections' => $results ];

		if ( ! empty( $paused ) ) {
			$payload['paused']        = $paused;
			$payload['paused_reason'] = $reason;
		}

		return $payload;
	}

	/**
	 * Returns whether a provider has enough budget left to spend on a speculative detect.
	 *
	 * Only GitHub and GitLab report a usable remaining count. Bitbucket exposes usage
	 * headers to scaled-tier organisations alone, so there is nothing to check and
	 * nothing to hold back against.
	 *
	 * @since 1.0.0
	 * @param string      $provider      Provider key.
	 * @param string|null $connection_id Connection ID, or null for the anonymous bucket.
	 * @return bool
	 */
	private static function has_detection_quota( string $provider, ?string $connection_id ): bool {
		$conn_key = $connection_id ?? 'anon';

		if ( 'github' === $provider ) {
			$remaining = get_transient( 'gitwire_gh_rl_' . $conn_key );

			// No reading yet is not evidence of a low budget.
			return false === $remaining || (int) $remaining >= self::DETECTION_RESERVE;
		}

		if ( 'gitlab' === $provider ) {
			$cached = get_transient( 'gitwire_gl_rl_' . $conn_key );

			return ! is_array( $cached ) || (int) ( $cached['remaining'] ?? 0 ) >= self::DETECTION_RESERVE;
		}

		return true;
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
				// type_meta is embedded by get_repositories() directly from the cache table row.
				if ( isset( $repo['type_meta'] ) ) {
					$repo['detection'] = array_merge( $repo['type_meta'], [ 'type' => $repo['type'] ?? '' ] );
					unset( $repo['type_meta'] );
					return $repo;
				}
				$detection = Repositories::get_repository_type(
					$repo['provider'] ?? '',
					$repo['owner'] ?? '',
					$repo['name'] ?? ''
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
	 * Refetches connection repository lists and/or clears stored type detections.
	 *
	 * Clearing the list up front and refetching afterwards loses the whole list when
	 * the refetch fails, so a rate-limit blip used to empty the browse tab. Rows are
	 * upserted under a cycle stamp instead, and only the ones the provider no longer
	 * returns are pruned once that connection has answered every page. The page sweep
	 * is the same one cron runs, so a connection larger than one API page is not
	 * truncated to its first page.
	 *
	 * 'repos' only relists. Rows already in the cache keep their stored type, since
	 * upsert_batch() never touches the type columns, so a repo the provider just added
	 * still lands untyped and gets detected without re-detecting everything else.
	 * 'repos_and_types' relists and drops every stored type, so each repo is typed
	 * again on an API round trip. 'types' skips the relist and only drops stored
	 * types, for when the list itself is not in question.
	 *
	 * Both type modes are ignored when detection is off, since nothing would rebuild
	 * what they drop. The Browse tab hides the menu in that state; this is the same
	 * gate on the server, for callers that skip the UI.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed> Refresh outcome, with any per-connection errors.
	 */
	public static function clear_cache( \WP_REST_Request $req ): array {
		$mode          = (string) $req->get_param( 'mode' );
		$refresh_list  = 'types' !== $mode;
		$refresh_types = ( 'types' === $mode || 'repos_and_types' === $mode )
			&& Settings::is_type_detection_enabled();

		$connections = array_values(
			array_filter(
				Connection_Resolver::all(),
				static fn( $c ) => isset( $c['id'] ) && '' !== $c['id']
					&& in_array( $c['provider'] ?? '', [ 'github', 'gitlab', 'bitbucket' ], true )
					&& null !== Connection_Resolver::get_credentials( $c['id'] )
			)
		);

		$errors = $refresh_list ? Repositories::refresh_repositories() : [];
		$failed = array_column( $errors, 'connection_id' );

		/*
		 * Detections are expensive to rebuild, so only the connections that actually
		 * answered lose theirs; one rate-limited connection must not wipe the
		 * detections of every other.
		 */
		$refreshed = 0;
		foreach ( $connections as $conn ) {
			if ( in_array( $conn['id'], $failed, true ) ) {
				continue;
			}

			if ( $refresh_types ) {
				Repositories::clear_repository_types_for_connection( $conn['id'] );
			}
			++$refreshed;
		}

		$payload = [
			'cleared'   => empty( $errors ),
			'refreshed' => $refreshed,
			'mode'      => $mode,
		];

		if ( ! empty( $errors ) ) {
			$payload['connection_errors'] = $errors;
		}

		return $payload;
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
		$error     = is_wp_error( $detected ) ? self::describe_resolve_failure( $detected ) : null;

		return [
			'provider'  => $provider,
			'owner'     => $owner,
			'repo'      => $repo,
			'branch'    => '' !== $branch ? $branch : null,
			'is_public' => null === $error,
			'detection' => null === $error ? $detected : null,
			'error'     => $error,
		];
	}

	/**
	 * Describes why a resolve attempt failed, separating access from everything else.
	 *
	 * Treating every failure as "not found or no access" turned a rate limit into an
	 * accusation that the user cannot see their own public repository. Only 404 and 401
	 * say anything about visibility. GitHub answers 404 for a private repo precisely so
	 * an anonymous caller cannot tell it apart from a missing one. A 403 is the hourly
	 * limit, and a 5xx or a transport failure is the provider's problem.
	 *
	 * @since 1.0.0
	 * @param \WP_Error $err Failure from the detection call.
	 * @return array<string, mixed>
	 */
	private static function describe_resolve_failure( \WP_Error $err ): array {
		$status = (int) ( $err->get_error_data()['status'] ?? 0 );

		if ( in_array( $status, [ 401, 404 ], true ) ) {
			return [
				'status'    => $status,
				'is_access' => true,
				'message'   => $err->get_error_message(),
			];
		}

		$message = in_array( $status, [ 403, 429 ], true )
			? __( 'The provider is rate limiting this site, so the repository could not be checked. Wait for the limit to reset, then try again.', 'gitwire' )
			: $err->get_error_message();

		return [
			'status'    => $status,
			'is_access' => false,
			'message'   => $message,
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

		foreach ( $candidates as $candidate ) {
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
}
