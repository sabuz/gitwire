<?php
/**
 * GitLab API client — wraps the GitLab REST API v4.
 *
 * @package Git_WP
 * @since 1.1.0
 */

namespace Git_WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the GitLab REST API v4.
 * Handles authentication, response normalisation, and ZIP streaming.
 */
class GitLab_API {

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
	 * Constructor.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
	 * @return array<string, mixed>|\WP_Error Connection data on success, WP_Error on failure.
	 */
	public function test_connection(): array|\WP_Error {
		if ( ! $this->token ) {
			return new \WP_Error( 'gwp_no_token', 'GitLab requires a Personal Access Token.' );
		}

		$user = $this->get( '/user' );

		if ( ! is_wp_error( $user ) ) {
			return [
				'login'      => $user['username'] ?? '',
				'name'       => $user['name'] ?? '',
				'avatar_url' => $user['avatar_url'] ?? '',
			];
		}

		// /user requires read_user or api scope. If missing, try /projects
		// to verify the token is at least valid for listing repositories.
		$projects = $this->get( '/projects?per_page=1&membership=true' );
		if ( is_wp_error( $projects ) ) {
			return $user;
		}

		return [
			'login'      => '',
			'name'       => '',
			'avatar_url' => '',
		];
	}

	/**
	 * Returns projects accessible to the authenticated user.
	 *
	 * @since 1.1.0
	 * @param string $username Unused for GitLab (token always required).
	 * @param int    $page     Page number for paginated results.
	 * @return array<int, mixed>|\WP_Error Project list on success, WP_Error on failure.
	 */
	public function get_repos( string $username, int $page = 1 ): array|\WP_Error {
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
	 * @since 1.1.0
	 * @param string $owner  GitLab namespace (group or username).
	 * @param string $repo   Project path.
	 * @param string $branch Branch, tag, or SHA to inspect.
	 * @return array<string, mixed>|\WP_Error Detection result on success, WP_Error on failure.
	 */
	public function detect_type( string $owner, string $repo, string $branch = 'HEAD' ): array|\WP_Error {
		$project_id = rawurlencode( $owner . '/' . $repo );

		$contents = $this->get(
			'/projects/' . $project_id . '/repository/tree?ref=' . rawurlencode( $branch )
		);

		if ( is_wp_error( $contents ) ) {
			return $contents;
		}

		$files = [];
		foreach ( $contents as $item ) {
			if ( isset( $item['name'] ) ) {
				$files[ strtolower( $item['name'] ) ] = $item;
			}
		}

		// 1. theme.json → block theme.
		if ( isset( $files['theme.json'] ) ) {
			$name = '';
			if ( isset( $files['style.css'] ) ) {
				$css = $this->get_raw_content( $owner, $repo, 'style.css', $branch );
				if ( ! is_wp_error( $css ) ) {
					$name = $this->extract_header( $css, 'Theme Name' );
				}
			}
			return [
				'type'       => 'theme',
				'subtype'    => 'block',
				'confidence' => 'high',
				'name'       => $name,
			];
		}

		// 2. style.css with "Theme Name:" → theme.
		if ( isset( $files['style.css'] ) ) {
			$css = $this->get_raw_content( $owner, $repo, 'style.css', $branch );
			if ( ! is_wp_error( $css ) && $this->has_header( $css, 'Theme Name' ) ) {
				$subtype = isset( $files['templates'] ) ? 'block' : 'classic';
				return [
					'type'       => 'theme',
					'subtype'    => $subtype,
					'confidence' => 'high',
					'name'       => $this->extract_header( $css, 'Theme Name' ),
				];
			}
		}

		// 3. PHP files with "Plugin Name:" header.
		$priority_names = [ strtolower( $repo ) . '.php', 'plugin.php', 'index.php' ];
		$php_files      = array_filter(
			array_keys( $files ),
			fn( $n ) => str_ends_with( $n, '.php' ) && ( $files[ $n ]['type'] ?? '' ) === 'blob'
		);
		usort(
			$php_files,
			static function ( $a, $b ) use ( $priority_names ) {
				$ai = array_search( $a, $priority_names, true );
				$bi = array_search( $b, $priority_names, true );
				if ( false === $ai && false === $bi ) {
					return 0;
				}
				if ( false === $ai ) {
					return 1;
				}
				if ( false === $bi ) {
					return -1;
				}
				return $ai - $bi;
			}
		);

		foreach ( array_slice( $php_files, 0, 5 ) as $lc_name ) {
			$real_name = $files[ $lc_name ]['name'];
			$content   = $this->get_raw_content( $owner, $repo, $real_name, $branch );
			if ( ! is_wp_error( $content ) && $this->has_header( $content, 'Plugin Name' ) ) {
				return [
					'type'       => 'plugin',
					'subtype'    => null,
					'confidence' => 'high',
					'name'       => $this->extract_header( $content, 'Plugin Name' ),
				];
			}
		}

		// 4. templates/ directory → block theme.
		if ( isset( $files['templates'] ) && ( $files['templates']['type'] ?? '' ) === 'tree' ) {
			return [
				'type'       => 'theme',
				'subtype'    => 'block',
				'confidence' => 'medium',
				'name'       => '',
			];
		}

		// 5. functions.php → classic theme.
		if ( isset( $files['functions.php'] ) ) {
			return [
				'type'       => 'theme',
				'subtype'    => 'classic',
				'confidence' => 'medium',
				'name'       => '',
			];
		}

		// 6. Any PHP files → probably a plugin.
		if ( ! empty( $php_files ) ) {
			return [
				'type'       => 'plugin',
				'subtype'    => null,
				'confidence' => 'low',
				'name'       => '',
			];
		}

		return [
			'type'       => 'unknown',
			'subtype'    => null,
			'confidence' => 'none',
			'name'       => '',
		];
	}

	/**
	 * Returns all branches for a project.
	 *
	 * @since 1.1.0
	 * @param string $owner GitLab namespace.
	 * @param string $repo  Project path.
	 * @return array<int, mixed>|\WP_Error Branch list on success, WP_Error on failure.
	 */
	public function get_branches( string $owner, string $repo ): array|\WP_Error {
		$project_id = rawurlencode( $owner . '/' . $repo );
		return $this->get( '/projects/' . $project_id . '/repository/branches?per_page=100' );
	}

	/**
	 * Downloads a project archive ZIP and returns the local temp-file path.
	 *
	 * Unlike GitHub's zipball (which redirects), GitLab streams the archive
	 * directly. We use wp_remote_get() with stream=true to avoid buffering
	 * large repos in memory, and pass the auth header manually.
	 *
	 * @since 1.1.0
	 * @param string $owner  GitLab namespace.
	 * @param string $repo   Project path.
	 * @param string $branch Branch, tag, or SHA to download.
	 * @return string|\WP_Error Local temp file path on success, WP_Error on failure.
	 */
	public function download_zip( string $owner, string $repo, string $branch ): string|\WP_Error {
		$project_id = rawurlencode( $owner . '/' . $repo );
		$url        = $this->base . '/projects/' . $project_id
			. '/repository/archive.zip?sha=' . rawurlencode( $branch );

		$tmp_file = wp_tempnam( 'gwp-gitlab-' );

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
				'gwp_api_error',
				sprintf( 'GitLab archive download failed (HTTP %d).', $code ),
				[ 'status' => $code ]
			);
		}

		return $tmp_file;
	}

	/**
	 * Fetches the raw content of a single file from a project.
	 *
	 * @since 1.1.0
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
			return new \WP_Error( 'gwp_api_error', 'Could not fetch file.', [ 'status' => $code ] );
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Checks whether a file content string contains a WordPress-style header field.
	 *
	 * @since 1.1.0
	 * @param string $content File content to search.
	 * @param string $header  Header field name (e.g. "Plugin Name").
	 * @return bool True if the header is present.
	 */
	private function has_header( string $content, string $header ): bool {
		return (bool) preg_match( '/^\s*[\/*#]?\s*' . preg_quote( $header, '/' ) . '\s*:/mi', $content );
	}

	/**
	 * Extracts the value of a WordPress-style header field from file content.
	 *
	 * @since 1.1.0
	 * @param string $content File content to search.
	 * @param string $header  Header field name (e.g. "Theme Name").
	 * @return string Header value, or empty string if not found.
	 */
	private function extract_header( string $content, string $header ): string {
		if ( preg_match( '/^\s*[\/*#]?\s*' . preg_quote( $header, '/' ) . '\s*:\s*(.+)$/mi', $content, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Builds the HTTP headers array for GitLab API requests.
	 *
	 * @since 1.1.0
	 * @return array<string, string> HTTP headers.
	 */
	private function headers(): array {
		$h = [
			'User-Agent' => 'GitHub-for-WordPress/' . GWP_VERSION,
		];
		if ( $this->token ) {
			$h['Authorization'] = 'Bearer ' . $this->token;
		}
		return $h;
	}

	/**
	 * Makes a GET request to the GitLab API and returns the decoded response body.
	 *
	 * @since 1.1.0
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
			$message = $body['message'] ?? ( $body['error'] ?? sprintf( 'GitLab API error (HTTP %d)', $code ) );
			return new \WP_Error(
				'gwp_api_error',
				is_string( $message ) ? $message : sprintf( 'GitLab API error (HTTP %d)', $code ),
				[ 'status' => $code ]
			);
		}

		return is_array( $body ) ? $body : [];
	}
}
