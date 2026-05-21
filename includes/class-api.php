<?php
/**
 * GitHub API client — wraps the GitHub REST API v3.
 *
 * @package Git_WP
 * @since 1.0.0
 */

namespace Git_WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the GitHub REST API v3.
 * Handles authentication, rate limiting, and response normalisation.
 */
class API {

	/**
	 * Personal access token for authenticated requests.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * GitHub API base URL.
	 *
	 * @var string
	 */
	private const BASE = 'https://api.github.com';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $token Optional personal access token.
	 */
	public function __construct( string $token = '' ) {
		$this->token = $token;
	}

	/**
	 * Tests the API connection and returns profile and rate-limit data.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>|WP_Error Connection data on success, WP_Error on failure.
	 */
	public function test_connection(): array|\WP_Error {
		$result = [];

		// Profile info (authenticated only).
		if ( $this->token ) {
			$user = $this->get( '/user' );
			if ( is_wp_error( $user ) ) {
				return $user;
			}
			$result['login']      = $user['login'] ?? '';
			$result['name']       = $user['name'] ?? '';
			$result['avatar_url'] = $user['avatar_url'] ?? '';
		}

		// Rate limit — always fetch so we always have the numbers.
		$rate = $this->get( '/rate_limit' );
		if ( ! is_wp_error( $rate ) ) {
			$result['rate_limit']     = $rate['rate']['limit'] ?? 60;
			$result['rate_remaining'] = $rate['rate']['remaining'] ?? 0;
			$result['rate_reset']     = $rate['rate']['reset'] ?? 0;
		}

		return $result;
	}

	/**
	 * Returns repositories accessible to the authenticated user or a public user.
	 *
	 * @since 1.0.0
	 * @param string $username GitHub username.
	 * @param int    $page     Page number for paginated results.
	 * @return array<int, mixed>|WP_Error Repository list on success, WP_Error on failure.
	 */
	public function get_repos( string $username, int $page = 1 ): array|\WP_Error {
		if ( $this->token ) {
			$endpoint = '/user/repos?per_page=100&page=' . $page
				. '&sort=updated&affiliation=owner,collaborator,organization_member';
		} else {
			$endpoint = '/users/' . rawurlencode( $username )
				. '/repos?per_page=100&page=' . $page . '&sort=updated';
		}

		return $this->get( $endpoint );
	}

	/**
	 * Detects whether a repo is a WordPress plugin, classic theme, or block theme.
	 *
	 * Detection order (highest confidence first):
	 *  1. theme.json in root                          → block theme  (high)
	 *  2. style.css with "Theme Name:" header         → theme        (high)
	 *  3. PHP file in root with "Plugin Name:" header → plugin       (high)
	 *  4. templates/ directory present                → block theme  (medium)
	 *  5. functions.php present                       → classic theme (medium)
	 *  6. Any PHP files in root                       → plugin       (low)
	 *  7. Otherwise                                   → unknown
	 *
	 * Returns: { type, subtype, confidence, name }
	 *
	 * @since 1.0.0
	 * @param string $owner  GitHub repository owner.
	 * @param string $repo   Repository name.
	 * @param string $branch Branch, tag, or SHA to inspect.
	 * @return array<string, mixed>|WP_Error Detection result on success, WP_Error on failure.
	 */
	public function detect_type( string $owner, string $repo, string $branch = 'HEAD' ): array|\WP_Error {
		$contents = $this->get(
			'/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo )
			. '/contents?ref=' . rawurlencode( $branch )
		);

		if ( is_wp_error( $contents ) ) {
			return $contents;
		}

		// Index root entries by lowercase name for quick lookup.
		$files = [];
		foreach ( $contents as $item ) {
			if ( isset( $item['name'] ) ) {
				$files[ strtolower( $item['name'] ) ] = $item;
			}
		}

		// 1. theme.json → block theme (definitive).
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

		// 2. style.css with "Theme Name:" header → theme.
		if ( isset( $files['style.css'] ) ) {
			$css = $this->get_raw_content( $owner, $repo, 'style.css', $branch );
			if ( ! is_wp_error( $css ) && $this->has_header( $css, 'Theme Name' ) ) {
				// Has templates/ folder? → block theme that declares itself via style.css.
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
		// Check the most likely main-file names first to minimise API calls.
		$priority_names = [ strtolower( $repo ) . '.php', 'plugin.php', 'index.php' ];
		$php_files      = array_filter(
			array_keys( $files ),
			fn( $n ) => str_ends_with( $n, '.php' ) && ( $files[ $n ]['type'] ?? '' ) === 'file'
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

		// 4. templates/ directory → block theme structure without headers.
		if ( isset( $files['templates'] ) && ( $files['templates']['type'] ?? '' ) === 'dir' ) {
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
	 * Returns all branches for a repository.
	 *
	 * @since 1.0.0
	 * @param string $owner GitHub repository owner.
	 * @param string $repo  Repository name.
	 * @return array<int, mixed>|WP_Error Branch list on success, WP_Error on failure.
	 */
	public function get_branches( string $owner, string $repo ): array|\WP_Error {
		return $this->get(
			'/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/branches?per_page=100'
		);
	}

	/**
	 * Downloads a repository ZIP and returns the local temp-file path.
	 *
	 * GitHub's zipball API returns a 302 to a CDN URL that embeds an
	 * auth token in the query string. We grab that Location header
	 * (without following it) and then hand the CDN URL to download_url()
	 * so WordPress can stream it to disk without holding it in memory.
	 *
	 * @since 1.0.0
	 * @param string $owner  GitHub repository owner.
	 * @param string $repo   Repository name.
	 * @param string $branch Branch, tag, or SHA to download.
	 * @return string|WP_Error Local temp file path on success, WP_Error on failure.
	 */
	public function download_zip( string $owner, string $repo, string $branch ): string|\WP_Error {
		$api_url = self::BASE . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo )
			. '/zipball/' . rawurlencode( $branch );

		$response = wp_remote_get(
			$api_url,
			[
				'headers'     => $this->headers(),
				'redirection' => 0,   // Do NOT follow — we want the Location header.
				'timeout'     => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( in_array( $code, [ 301, 302, 307, 308 ], true ) ) {
			$download_url = wp_remote_retrieve_header( $response, 'location' );
		} elseif ( 200 === $code ) {
			// Rare: API served the file directly.
			$download_url = $api_url;
		} else {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return new \WP_Error(
				'gwp_api_error',
				$body['message'] ?? sprintf( 'GitHub API returned HTTP %d', $code ),
				[ 'status' => $code ]
			);
		}

		if ( empty( $download_url ) ) {
			return new \WP_Error( 'gwp_no_location', 'GitHub did not return a download URL.' );
		}

		// Stream to disk via WordPress (handles large repos safely).
		$tmp = download_url( $download_url, 300 );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		return $tmp;
	}

	/**
	 * Fetches the decoded content of a single file from a repository.
	 *
	 * @since 1.0.0
	 * @param string $owner  Repository owner.
	 * @param string $repo   Repository name.
	 * @param string $path   File path within the repository.
	 * @param string $branch Branch, tag, or SHA reference.
	 * @return string|WP_Error Decoded file content on success, WP_Error on failure.
	 */
	private function get_raw_content( string $owner, string $repo, string $path, string $branch ): string|\WP_Error {
		$result = $this->get(
			'/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo )
			. '/contents/' . rawurlencode( $path ) . '?ref=' . rawurlencode( $branch )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['content'] ) ) {
			return new \WP_Error( 'gwp_no_content', 'File has no readable content.' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return base64_decode( str_replace( "\n", '', $result['content'] ) );
	}

	/**
	 * Checks whether a file content string contains a WordPress-style header field.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * Builds the HTTP headers array for GitHub API requests.
	 *
	 * @since 1.0.0
	 * @return array<string, string> HTTP headers.
	 */
	private function headers(): array {
		$h = [
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'GitHub-for-WordPress/' . GWP_VERSION,
		];
		if ( $this->token ) {
			$h['Authorization'] = 'Bearer ' . $this->token;
		}
		return $h;
	}

	/**
	 * Makes a GET request to the GitHub API and returns the decoded response body.
	 *
	 * @since 1.0.0
	 * @param string $endpoint API endpoint path (e.g. "/user/repos").
	 * @return array<mixed>|WP_Error Decoded JSON array on success, WP_Error on failure.
	 */
	private function get( string $endpoint ): array|\WP_Error {
		$response = wp_remote_get(
			self::BASE . $endpoint,
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
			return new \WP_Error(
				'gwp_api_error',
				$body['message'] ?? sprintf( 'GitHub API error (HTTP %d)', $code ),
				[ 'status' => $code ]
			);
		}

		return is_array( $body ) ? $body : [];
	}
}
