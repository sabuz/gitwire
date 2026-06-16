<?php
/**
 * GitLab API client — wraps the GitLab REST API v4.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the GitLab REST API v4.
 * Handles authentication, response normalisation, and ZIP streaming.
 */
class GitLab_API implements Git_Provider_Interface {

	/**
	 * Personal access token for authenticated requests.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * GitLab API base URL (supports self-hosted instances).
	 *
	 * @var string
	 */
	private string $base;

	/**
	 * Rate limit data captured from the last API response headers.
	 *
	 * @var array<string, int>
	 */
	private array $last_rate = [
		'limit'     => 0,
		'remaining' => 0,
		'reset'     => 0,
	];

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $token    Personal access token.
	 * @param string $base_url GitLab instance base URL (defaults to gitlab.com).
	 */
	public function __construct( string $token = '', string $base_url = '' ) {
		$this->token = $token;
		$this->base  = rtrim( $base_url ? $base_url : 'https://gitlab.com', '/' ) . '/api/v4';
	}

	/**
	 * Tests the API connection and returns profile data.
	 *
	 * Attempts /user first for full profile info. If the token lacks read_user
	 * scope (common with read_api-only tokens), falls back to /projects to
	 * confirm the token is valid, returning authenticated state without profile.
	 *
	 * @since 1.0.0
	 * @param string $owner Unused; kept for interface compatibility.
	 * @return array<string, mixed>|\WP_Error Connection data on success, WP_Error on failure.
	 */
	public function test_connection( string $owner = '' ): array|\WP_Error {
		if ( ! $this->token ) {
			return new \WP_Error( 'gitwire_no_token', 'GitLab requires a Personal Access Token.' );
		}

		$user = $this->get( '/user' );

		if ( ! is_wp_error( $user ) ) {
			return [
				'login'          => $user['username'] ?? '',
				'name'           => $user['name'] ?? '',
				'avatar_url'     => $user['avatar_url'] ?? '',
				'rate_limit'     => $this->last_rate['limit'],
				'rate_remaining' => $this->last_rate['remaining'],
				'rate_reset'     => $this->last_rate['reset'],
			];
		}

		// /user requires read_user or api scope. If missing, try /projects
		// to verify the token is at least valid for listing repositories.
		$projects = $this->get( '/projects?per_page=1&membership=true' );
		if ( is_wp_error( $projects ) ) {
			return $user;
		}

		return [
			'login'          => '',
			'name'           => '',
			'avatar_url'     => '',
			'rate_limit'     => $this->last_rate['limit'],
			'rate_remaining' => $this->last_rate['remaining'],
			'rate_reset'     => $this->last_rate['reset'],
		];
	}

	/**
	 * Returns projects accessible to the authenticated user, or a user's public
	 * projects when a username is given and no token is set.
	 *
	 * @since 1.0.0
	 * @param string $username GitLab username for public-mode listing.
	 * @param int    $page     Page number for paginated results.
	 * @return array<int, mixed>|\WP_Error Project list on success, WP_Error on failure.
	 */
	public function get_repos( string $username, int $page = 1 ): array|\WP_Error {
		if ( '' === $this->token && '' !== $username ) {
			return $this->get(
				'/users/' . rawurlencode( $username ) . '/projects?per_page=100&page=' . $page
				. '&order_by=last_activity_at&sort=desc'
			);
		}

		return $this->get(
			'/projects?membership=true&per_page=100&page=' . $page
			. '&order_by=last_activity_at&sort=desc'
		);
	}

	/**
	 * Detects whether a project is a WordPress plugin or theme.
	 *
	 * Uses the same detection heuristics as the GitHub API class.
	 *
	 * @since 1.0.0
	 * @param string $owner  GitLab namespace (group or username).
	 * @param string $repo   Project path.
	 * @param string $branch Branch, tag, or SHA to inspect.
	 * @return array<string, mixed>|\WP_Error Detection result on success, WP_Error on failure.
	 */
	public function detect_type( string $owner, string $repo, string $branch = 'HEAD' ): array|\WP_Error {
		$project_id = rawurlencode( $owner . '/' . $repo );

		return Repo_Detector::detect(
			$repo,
			$branch,
			function ( $ref ) use ( $project_id ) {
				$contents = $this->get(
					'/projects/' . $project_id . '/repository/tree?per_page=100&ref=' . rawurlencode( $ref )
				);
				if ( is_wp_error( $contents ) ) {
					return $contents;
				}
				return array_map(
					static function ( $item ) {
						$type = $item['type'] ?? '';
						return [
							'name' => $item['name'] ?? '',
							'type' => 'blob' === $type ? 'file' : ( 'tree' === $type ? 'dir' : $type ),
						];
					},
					$contents
				);
			},
			fn( $path, $ref ) => $this->get_raw_content( $owner, $repo, $path, $ref )
		);
	}

	/**
	 * Returns all branches for a project.
	 *
	 * @since 1.0.0
	 * @param string $owner GitLab namespace.
	 * @param string $repo  Project path.
	 * @return array<int, mixed>|\WP_Error Branch list on success, WP_Error on failure.
	 */
	public function get_branches( string $owner, string $repo ): array|\WP_Error {
		$project_id = rawurlencode( $owner . '/' . $repo );
		return $this->get( '/projects/' . $project_id . '/repository/branches?per_page=100' );
	}

	/**
	 * Returns the last N commits for a branch, normalised to a flat array.
	 *
	 * @since 1.0.0
	 * @param string $owner    GitLab namespace.
	 * @param string $repo     Project path.
	 * @param string $branch   Branch, tag, or SHA.
	 * @param int    $per_page Number of commits to return (max 100).
	 * @return array<int, array<string, string>>|\WP_Error Commit list or WP_Error on failure.
	 */
	public function get_commits( string $owner, string $repo, string $branch, int $per_page = 10 ): array|\WP_Error {
		$project_id = rawurlencode( $owner . '/' . $repo );
		$data       = $this->get(
			'/projects/' . $project_id
			. '/repository/commits?ref_name=' . rawurlencode( $branch ) . '&per_page=' . $per_page
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array_map(
			static function ( $c ) {
				return [
					'sha'     => $c['short_id'] ?? substr( $c['id'], 0, 7 ),
					'message' => $c['title'] ?? '',
					'author'  => $c['author_name'] ?? '',
					'date'    => $c['created_at'] ?? '',
				];
			},
			$data
		);
	}

	/**
	 * Downloads a project archive ZIP and returns the local temp-file path.
	 *
	 * Unlike GitHub's zipball (which redirects), GitLab streams the archive
	 * directly. We use wp_remote_get() with stream=true to avoid buffering
	 * large repos in memory, and pass the auth header manually.
	 *
	 * @since 1.0.0
	 * @param string $owner  GitLab namespace.
	 * @param string $repo   Project path.
	 * @param string $branch Branch, tag, or SHA to download.
	 * @return string|\WP_Error Local temp file path on success, WP_Error on failure.
	 */
	public function download_zip( string $owner, string $repo, string $branch ): string|\WP_Error {
		$project_id = rawurlencode( $owner . '/' . $repo );
		$url        = $this->base . '/projects/' . $project_id
			. '/repository/archive.zip?sha=' . rawurlencode( $branch );

		$tmp_file = wp_tempnam( 'gitwire-gitlab-' );

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
				sprintf( 'GitLab archive download failed (HTTP %d).', $code ),
				[ 'status' => $code ]
			);
		}

		return $tmp_file;
	}

	/**
	 * Fetches the raw content of a single file from a project.
	 *
	 * @since 1.0.0
	 * @param string $owner  Project namespace.
	 * @param string $repo   Project path.
	 * @param string $path   File path within the project.
	 * @param string $branch Branch, tag, or SHA reference.
	 * @return string|\WP_Error File content on success, WP_Error on failure.
	 */
	private function get_raw_content( string $owner, string $repo, string $path, string $branch ): string|\WP_Error {
		$project_id = rawurlencode( $owner . '/' . $repo );
		$file_path  = rawurlencode( $path );

		$response = wp_remote_get(
			$this->base . '/projects/' . $project_id . '/repository/files/' . $file_path . '/raw?ref=' . rawurlencode( $branch ),
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
	 * Builds the HTTP headers array for GitLab API requests.
	 *
	 * @since 1.0.0
	 * @return array<string, string> HTTP headers.
	 */
	private function headers(): array {
		$h = [
			'User-Agent' => 'Gitwire/' . GITWIRE_VERSION,
		];
		if ( $this->token ) {
			$h['Authorization'] = 'Bearer ' . $this->token;
		}
		return $h;
	}

	/**
	 * Makes a GET request to the GitLab API and returns the decoded response body.
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

		$limit     = (int) wp_remote_retrieve_header( $response, 'ratelimit-limit' );
		$remaining = (int) wp_remote_retrieve_header( $response, 'ratelimit-remaining' );
		$reset     = (int) wp_remote_retrieve_header( $response, 'ratelimit-reset' );
		if ( $limit > 0 ) {
			$this->last_rate = [
				'limit'     => $limit,
				'remaining' => $remaining,
				'reset'     => $reset,
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$message = $body['message'] ?? ( $body['error'] ?? sprintf( 'GitLab API error (HTTP %d)', $code ) );
			return new \WP_Error(
				'gitwire_api_error',
				is_string( $message ) ? $message : sprintf( 'GitLab API error (HTTP %d)', $code ),
				[ 'status' => $code ]
			);
		}

		return is_array( $body ) ? $body : [];
	}
}
