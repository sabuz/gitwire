<?php
/**
 * Bitbucket API client — wraps the Bitbucket Cloud REST API v2.
 *
 * @package Gitwire
 * @since 1.3.0
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
	 * @since 1.3.0
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
	 * @since 1.3.0
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
		// Fall back to listing repos to confirm the token is at least valid for repo access.
		$repos = $this->get( '/repositories?role=member&pagelen=1' );
		if ( is_wp_error( $repos ) ) {
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
	 * @since 1.3.0
	 * @param string $username Bitbucket workspace slug (falls back to configured username).
	 * @param int    $page     Page number for paginated results.
	 * @return array<int, mixed>|\WP_Error Repository list on success, WP_Error on failure.
	 */
	public function get_repos( string $username, int $page = 1 ): array|\WP_Error {
		// /user/repositories lists every repo accessible to the authenticated user.
		$result = $this->get(
			'/repositories?role=member&pagelen=100&page=' . $page
			. '&sort=-updated_on'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result['values'] ?? [];
	}

	/**
	 * Returns all branches for a repository.
	 *
	 * @since 1.3.0
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
	 * @since 1.3.0
	 * @param string $owner  Repository workspace slug.
	 * @param string $repo   Repository slug.
	 * @param string $branch Branch ref.
	 * @return array<string, mixed>|\WP_Error Detection result on success, WP_Error on failure.
	 */
	public function detect_type( string $owner, string $repo, string $branch = 'HEAD' ): array|\WP_Error {
		return Repo_Detector::detect(
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
			fn( $path, $ref ) => $this->get_raw_content( $owner, $repo, $path, $ref )
		);
	}

	/**
	 * Returns the last N commits for a branch, normalised to a flat array.
	 *
	 * @since 1.3.0
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
				$hash = $c['hash'] ?? '';
				return [
					'sha'     => substr( $hash, 0, 7 ),
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
	 * Uses the Bitbucket web archive URL with Basic auth for private repos.
	 *
	 * @since 1.3.0
	 * @param string $owner  Repository workspace slug.
	 * @param string $repo   Repository slug.
	 * @param string $branch Branch ref.
	 * @return string|\WP_Error Absolute path to the temp ZIP file, or WP_Error on failure.
	 */
	public function download_zip( string $owner, string $repo, string $branch ): string|\WP_Error {
		$url      = 'https://bitbucket.org/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo )
			. '/get/' . rawurlencode( $branch ) . '.zip';
		$tmp_file = wp_tempnam( 'gitwire-bitbucket-' );

		$response = wp_remote_get(
			$url,
			[
				'headers'  => $this->headers(),
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $tmp_file,
			]
		);

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

		return $tmp_file;
	}

	/**
	 * Fetches the raw content of a single file from a repository.
	 *
	 * @since 1.3.0
	 * @param string $owner  Repository workspace slug.
	 * @param string $repo   Repository slug.
	 * @param string $path   File path within the repository.
	 * @param string $branch Branch, tag, or SHA reference.
	 * @return string|\WP_Error File content on success, WP_Error on failure.
	 */
	private function get_raw_content( string $owner, string $repo, string $path, string $branch ): string|\WP_Error {
		$response = wp_remote_get(
			$this->base . '/repositories/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo )
			. '/src/' . rawurlencode( $branch ) . '/' . ltrim( $path, '/' ),
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
	 * @since 1.3.0
	 * @return array<string, string> HTTP headers.
	 */
	private function headers(): array {
		$h = [
			'User-Agent' => 'GitHub-for-WordPress/' . GITWIRE_VERSION,
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
	 * @since 1.3.0
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
			return new \WP_Error(
				'gitwire_api_error',
				is_string( $message ) ? $message : sprintf( 'Bitbucket API error (HTTP %d)', $code ),
				[ 'status' => $code ]
			);
		}

		return is_array( $body ) ? $body : [];
	}
}
