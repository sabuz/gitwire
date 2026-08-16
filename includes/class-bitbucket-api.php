<?php
/**
 * Bitbucket API client — wraps the Bitbucket Cloud REST API v2.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the Bitbucket Cloud REST API v2.
 * Authenticates via HTTP Basic using an Atlassian email and API token.
 */
class Bitbucket_API implements Git_Provider_Interface {

	/**
	 * Atlassian account email used for Basic auth.
	 *
	 * @var string
	 */
	private string $email;

	/**
	 * Atlassian API token for authenticated requests.
	 *
	 * @var string
	 */
	private string $api_token;

	/**
	 * Bitbucket API v2 base URL.
	 *
	 * @var string
	 */
	private string $base = 'https://api.bitbucket.org/2.0';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $email     Atlassian account email address.
	 * @param string $api_token Atlassian API token (from id.atlassian.com/manage-profile/security/api-tokens).
	 */
	public function __construct( string $email = '', string $api_token = '' ) {
		$this->email     = $email;
		$this->api_token = $api_token;
	}

	/**
	 * Tests the API connection and returns profile data.
	 *
	 * @since 1.0.0
	 * @param string $owner Unused; kept for interface compatibility.
	 * @return array<string, mixed>|\WP_Error Connection data on success, WP_Error on failure.
	 */
	public function test_connection( string $owner = '' ): array|\WP_Error {
		if ( ! $this->email || ! $this->api_token ) {
			return new \WP_Error( 'gitwire_no_credentials', 'Bitbucket requires an Atlassian email and API token.' );
		}

		$user = $this->get( '/user' );

		if ( ! is_wp_error( $user ) ) {
			return [
				'login'          => $user['nickname'] ?? $user['display_name'] ?? '',
				'name'           => $user['display_name'] ?? '',
				'avatar_url'     => $user['links']['avatar']['href'] ?? '',
				'rate_limit'     => 0,
				'rate_remaining' => 0,
				'rate_reset'     => 0,
			];
		}

		// /user requires the account scope — some API tokens lack it.
		// Prefer a workspace-specific lookup (needs only read:workspace:bitbucket),
		// then fall back to listing all workspaces (needs account scope).
		$ws_endpoint = $owner ? '/workspaces/' . rawurlencode( $owner ) : '/workspaces?pagelen=1';
		$workspace   = $this->get( $ws_endpoint );
		if ( is_wp_error( $workspace ) && $owner ) {
			$workspace = $this->get( '/workspaces?pagelen=1' );
		}
		if ( is_wp_error( $workspace ) ) {
			return $user;
		}

		return [
			'login'          => '',
			'name'           => '',
			'avatar_url'     => '',
			'rate_limit'     => 0,
			'rate_remaining' => 0,
			'rate_reset'     => 0,
		];
	}

	/**
	 * Returns repositories accessible to the authenticated user.
	 *
	 * @since 1.0.0
	 * @param string $username Bitbucket workspace slug (falls back to configured username).
	 * @param int    $page     Page number for paginated results.
	 * @return array{repositories: array<int, mixed>, has_more: bool}|\WP_Error
	 */
	public function get_repos( string $username, int $page = 1 ): array|\WP_Error {
		$slugs = $username ? [ $username ] : $this->get_workspace_slugs();
		if ( is_wp_error( $slugs ) ) {
			return $slugs;
		}

		$all      = [];
		$has_more = false;
		$last_err = null;
		foreach ( $slugs as $slug ) {
			$result = $this->get(
				'/repositories/' . rawurlencode( $slug )
				. '?pagelen=100&page=' . $page . '&sort=-updated_on'
			);
			if ( is_wp_error( $result ) ) {
				$last_err = $result;
				continue;
			}
			$values = $result['values'] ?? [];
			$all    = array_merge( $all, $values );
			if ( ! empty( $result['next'] ) || count( $values ) >= 100 ) {
				$has_more = true;
			}
		}

		if ( empty( $all ) && $last_err ) {
			return $last_err;
		}

		usort(
			$all,
			static function ( $a, $b ) {
				return strcmp( $b['updated_on'] ?? '', $a['updated_on'] ?? '' );
			}
		);

		return [
			'repositories' => $all,
			'has_more'     => $has_more,
		];
	}

	/**
	 * Returns all workspace slugs for the authenticated user via the non-deprecated endpoint.
	 *
	 * @since 1.0.0
	 * @return array<int, string>|\WP_Error Workspace slugs on success, WP_Error on failure.
	 */
	private function get_workspace_slugs(): array|\WP_Error {
		$result = $this->get( '/user/workspaces?pagelen=100' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$slugs = array_filter(
			array_map(
				static function ( $m ) {
					return $m['workspace']['slug'] ?? '';
				},
				$result['values'] ?? []
			)
		);
		if ( empty( $slugs ) ) {
			return new \WP_Error( 'gitwire_api_error', 'No Bitbucket workspaces found for this account.' );
		}
		return array_values( $slugs );
	}

	/**
	 * Returns all branches for a repository.
	 *
	 * @since 1.0.0
	 * @param string $owner Repository workspace slug.
	 * @param string $repo  Repository slug.
	 * @return array<int, mixed>|\WP_Error Branch list on success, WP_Error on failure.
	 */
	public function get_branches( string $owner, string $repo ): array|\WP_Error {
		$result = $this->get(
			'/repositories/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo )
			. '/refs/branches?pagelen=100'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result['values'] ?? [];
	}

	/**
	 * Detects whether a repository is a WordPress plugin or theme.
	 *
	 * @since 1.0.0
	 * @param string            $owner         Repository workspace slug.
	 * @param string            $repo          Repository slug.
	 * @param string            $branch        Branch ref.
	 * @param array<mixed>|null $cached_result Pre-fetched file listing to skip the API call.
	 * @return array<string, mixed>|\WP_Error Detection result on success, WP_Error on failure.
	 */
	public function detect_type( string $owner, string $repo, string $branch = 'HEAD', ?array $cached_result = null ): array|\WP_Error {
		return Repository_Detector::detect(
			$repo,
			$branch,
			function ( $ref ) use ( $owner, $repo ) {
				$result = $this->get(
					'/repositories/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo )
					. '/src/' . rawurlencode( $ref ) . '/?pagelen=100'
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return array_map(
					static function ( $item ) {
						$type = $item['type'] ?? '';
						return [
							'name' => basename( $item['path'] ?? '' ),
							'type' => 'commit_file' === $type ? 'file' : ( 'commit_directory' === $type ? 'dir' : $type ),
						];
					},
					$result['values'] ?? []
				);
			},
			fn( $path, $ref ) => $this->get_raw_content( $owner, $repo, $path, $ref ),
			$cached_result
		);
	}

	/**
	 * Returns the last N commits for a branch, normalised to a flat array.
	 *
	 * @since 1.0.0
	 * @param string $owner    Repository workspace slug.
	 * @param string $repo     Repository slug.
	 * @param string $branch   Branch, tag, or SHA.
	 * @param int    $per_page Number of commits to return.
	 * @return array<int, array<string, string>>|\WP_Error Commit list or WP_Error on failure.
	 */
	public function get_commits( string $owner, string $repo, string $branch, int $per_page = 10 ): array|\WP_Error {
		$result = $this->get(
			'/repositories/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo )
			. '/commits/' . rawurlencode( $branch ) . '?pagelen=' . $per_page
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_map(
			static function ( $c ) {
				return [
					'sha'     => $c['hash'] ?? '',
					'message' => $c['message'] ?? '',
					'author'  => $c['author']['user']['display_name'] ?? $c['author']['raw'] ?? '',
					'date'    => $c['date'] ?? '',
				];
			},
			$result['values'] ?? []
		);
	}

	/**
	 * Downloads a repository ZIP archive to a local temp file.
	 *
	 * The archive URL 302s to an S3-backed CDN on a different host. WP_Http replays
	 * the full header set on a redirect, so following it would hand the Basic auth
	 * credentials to Amazon — resolve the Location ourselves and fetch it unauthenticated.
	 *
	 * @since 1.0.0
	 * @param string $owner  Repository workspace slug.
	 * @param string $repo   Repository slug.
	 * @param string $branch Branch ref.
	 * @return string|\WP_Error Absolute path to the temp ZIP file, or WP_Error on failure.
	 */
	public function download_zip( string $owner, string $repo, string $branch ): string|\WP_Error {
		$url = 'https://bitbucket.org/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo )
			. '/get/' . rawurlencode( $branch ) . '.zip';

		$headers = $this->headers();

		// HEAD so a direct-serve response never costs us the whole archive twice.
		$probe = wp_remote_head(
			$url,
			[
				'headers'     => $headers,
				'redirection' => 0,
				'timeout'     => 15,
			]
		);

		if ( ! is_wp_error( $probe )
			&& in_array( (int) wp_remote_retrieve_response_code( $probe ), [ 301, 302, 307, 308 ], true )
		) {
			$location = (string) wp_remote_retrieve_header( $probe, 'location' );
			if ( '' === $location ) {
				return new \WP_Error( 'gitwire_no_location', 'Bitbucket did not return a download URL.' );
			}
			$url     = $location;
			$headers = [ 'User-Agent' => 'Gitwire/' . GITWIRE_VERSION ];
		}

		$tmp_file = wp_tempnam( 'gitwire-bitbucket-' );

		$response = $this->stream_to( $url, $headers, $tmp_file );

		// HEAD and GET can disagree; catch a redirect the probe did not see.
		if ( ! is_wp_error( $response )
			&& in_array( (int) wp_remote_retrieve_response_code( $response ), [ 301, 302, 307, 308 ], true )
		) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( '' === $location ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $tmp_file );
				return new \WP_Error( 'gitwire_no_location', 'Bitbucket did not return a download URL.' );
			}
			$response = $this->stream_to( $location, [ 'User-Agent' => 'Gitwire/' . GITWIRE_VERSION ], $tmp_file );
		}

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp_file );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp_file );
			return new \WP_Error(
				'gitwire_api_error',
				sprintf( 'Bitbucket archive download failed (HTTP %d).', $code ),
				[ 'status' => $code ]
			);
		}

		if ( filesize( $tmp_file ) > 256 * MB_IN_BYTES ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp_file );
			return new \WP_Error( 'gitwire_archive_too_large', 'Repository ZIP exceeds the 256 MB size limit.' );
		}

		return $tmp_file;
	}

	/**
	 * Streams one URL to a local file without following redirects.
	 *
	 * @since 1.0.0
	 * @param string                $url      Absolute URL to fetch.
	 * @param array<string, string> $headers  Request headers.
	 * @param string                $tmp_file Destination path.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function stream_to( string $url, array $headers, string $tmp_file ): array|\WP_Error {
		return wp_remote_get(
			$url,
			[
				'headers'     => $headers,
				'timeout'     => 300,
				'stream'      => true,
				'filename'    => $tmp_file,
				'redirection' => 0,
			]
		);
	}

	/**
	 * Fetches the raw content of a single file from a repository.
	 *
	 * @since 1.0.0
	 * @param string $owner  Repository workspace slug.
	 * @param string $repo   Repository slug.
	 * @param string $path   File path within the repository.
	 * @param string $branch Branch, tag, or SHA reference.
	 * @return string|\WP_Error File content on success, WP_Error on failure.
	 */
	private function get_raw_content( string $owner, string $repo, string $path, string $branch ): string|\WP_Error {
		$encoded_path = implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $path, '/' ) ) ) );
		$response     = wp_remote_get(
			$this->base . '/repositories/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo )
			. '/src/' . rawurlencode( $branch ) . '/' . $encoded_path,
			[
				'headers' => $this->headers(),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return new \WP_Error( 'gitwire_api_error', 'Could not fetch file.', [ 'status' => $code ] );
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Builds the HTTP headers for Bitbucket API requests.
	 *
	 * @since 1.0.0
	 * @return array<string, string> HTTP headers.
	 */
	private function headers(): array {
		$h = [
			'User-Agent' => 'Gitwire/' . GITWIRE_VERSION,
		];
		if ( $this->email && $this->api_token ) {
			// Atlassian API tokens use Basic auth with email:token.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$h['Authorization'] = 'Basic ' . base64_encode( $this->email . ':' . $this->api_token );
		}
		return $h;
	}

	/**
	 * Makes a GET request to the Bitbucket API and returns the decoded response body.
	 *
	 * @since 1.0.0
	 * @param string $endpoint API endpoint path (e.g. "/user").
	 * @return array<mixed>|\WP_Error Decoded JSON array on success, WP_Error on failure.
	 */
	private function get( string $endpoint ): array|\WP_Error {
		$response = wp_remote_get(
			$this->base . $endpoint,
			[
				'headers' => $this->headers(),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$message = $body['error']['message'] ?? sprintf( 'Bitbucket API error (HTTP %d)', $code );
			if ( ! is_string( $message ) ) {
				$message = sprintf( 'Bitbucket API error (HTTP %d)', $code );
			}
			// Scope errors mean the API token was created without Bitbucket access.
			if ( 403 === $code && str_contains( $message, 'privilege scopes' ) ) {
				$message = 'Your API token lacks Bitbucket access. When creating the token at id.atlassian.com, choose Scopes → Bitbucket → Read (or use a Classic API token).';
			}
			Logger::log( sprintf( '[bitbucket] HTTP %d on %s%s — %s', $code, $this->base, $endpoint, $message ), 'error' );
			return new \WP_Error( 'gitwire_api_error', $message, [ 'status' => $code ] );
		}

		return is_array( $body ) ? $body : [];
	}
}
