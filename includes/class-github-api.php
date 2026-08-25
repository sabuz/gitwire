<?php
/**
 * GitHub API client: wraps the GitHub REST API v3.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the GitHub REST API v3.
 * Handles authentication, rate limiting, and response normalisation.
 */
class GitHub_API implements Git_Provider_Interface {

	/**
	 * Personal access token for authenticated requests.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Connection ID used as the rate-limit cache key.
	 *
	 * @var string
	 */
	private string $connection_id;

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
	 * @param string $token         Optional personal access token.
	 * @param string $connection_id Connection ID used for the rate-limit cache key.
	 */
	public function __construct( string $token = '', string $connection_id = '' ) {
		$this->token         = $token;
		$this->connection_id = $connection_id;
	}

	/**
	 * Tests the API connection and returns profile and rate-limit data.
	 *
	 * @since 1.0.0
	 * @param string $username Optional GitHub username for public profile lookup when no token is set.
	 * @return array<string, mixed>|\WP_Error Connection data on success, WP_Error on failure.
	 */
	public function test_connection( string $username = '' ): array|\WP_Error {
		$result = [];

		if ( $this->token ) {
			// Fetch the authenticated user's profile.
			$user = $this->get( '/user' );
			if ( is_wp_error( $user ) ) {
				return $user;
			}
			$result['login']      = $user['login'] ?? '';
			$result['name']       = $user['name'] ?? '';
			$result['avatar_url'] = $user['avatar_url'] ?? '';
		} elseif ( $username ) {
			// Fetch the public profile for the avatar and display name.
			$user = $this->get( '/users/' . rawurlencode( $username ) );
			if ( is_wp_error( $user ) ) {
				$status = (int) ( $user->get_error_data()['status'] ?? 0 );
				if ( 404 === $status ) {
					return new \WP_Error( 'gitwire_not_found', __( 'GitHub user not found.', 'gitwire' ), [ 'status' => 404 ] );
				}
				return $user;
			}
			$result['login']      = $user['login'] ?? '';
			$result['name']       = $user['name'] ?? '';
			$result['avatar_url'] = $user['avatar_url'] ?? '';
		}

		// Always fetch the rate limit so the connection has current values.
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
	 * @return array<int, mixed>|\WP_Error Repository list on success, WP_Error on failure.
	 */
	public function get_repositories( string $username, int $page = 1 ): array|\WP_Error {
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
	 * Detects whether a repository is a WordPress plugin, classic theme, or block theme.
	 *
	 * Detection rules live in Repository_Detector::detect().
	 *
	 * @since 1.0.0
	 * @param string            $owner         GitHub repository owner.
	 * @param string            $repository    Repository name.
	 * @param string            $branch        Branch, tag, or SHA to inspect.
	 * @param array<mixed>|null $cached_result Pre-fetched file listing to skip the API call.
	 * @return array<string, mixed>|\WP_Error Detection result on success, WP_Error on failure.
	 */
	public function detect_type( string $owner, string $repository, string $branch = 'HEAD', ?array $cached_result = null ): array|\WP_Error {
		return Repository_Detector::detect(
			$repository,
			$branch,
			fn( $ref ) => $this->get(
				'/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repository )
				. '/contents?ref=' . rawurlencode( $ref )
			),
			fn( $path, $ref ) => $this->get_raw_content( $owner, $repository, $path, $ref ),
			$cached_result
		);
	}

	/**
	 * Returns all branches for a repository.
	 *
	 * @since 1.0.0
	 * @param string $owner      GitHub repository owner.
	 * @param string $repository Repository name.
	 * @return array<int, mixed>|\WP_Error Branch list on success, WP_Error on failure.
	 */
	public function get_branches( string $owner, string $repository ): array|\WP_Error {
		return $this->get(
			'/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repository ) . '/branches?per_page=100'
		);
	}

	/**
	 * Returns the last N commits for a branch, normalised to a flat array.
	 *
	 * @since 1.0.0
	 * @param string $owner      GitHub repository owner.
	 * @param string $repository Repository name.
	 * @param string $branch     Branch, tag, or SHA.
	 * @param int    $per_page   Number of commits to return (max 100).
	 * @return array<int, array<string, string>>|\WP_Error Commit list or WP_Error on failure.
	 */
	public function get_commits( string $owner, string $repository, string $branch, int $per_page = 10 ): array|\WP_Error {
		$data = $this->get(
			'/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repository )
			. '/commits?sha=' . rawurlencode( $branch ) . '&per_page=' . $per_page
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array_map(
			static function ( $c ) {
				$message = (string) ( $c['commit']['message'] ?? '' );
				return [
					'sha'     => (string) ( $c['sha'] ?? '' ),
					'message' => explode( "\n", trim( $message ) )[0],
					'author'  => $c['commit']['author']['name'] ?? '',
					'date'    => $c['commit']['author']['date'] ?? '',
				];
			},
			is_array( $data ) ? $data : []
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
	 * @param string $owner      GitHub repository owner.
	 * @param string $repository Repository name.
	 * @param string $branch     Branch, tag, or SHA to download.
	 * @return string|\WP_Error Local temp file path on success, WP_Error on failure.
	 */
	public function download_zip( string $owner, string $repository, string $branch ): string|\WP_Error {
		$api_url = self::BASE . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repository )
			. '/zipball/' . rawurlencode( $branch );

		$response = wp_remote_get(
			$api_url,
			[
				'headers'     => $this->headers(),
				'redirection' => 0,   // Do NOT follow, we want the Location header.
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
			// The API served the file directly.
			$download_url = $api_url;
		} else {
			return $this->error_from_response(
				$response,
				$code,
				/* translators: %d: HTTP status code */
				sprintf( __( 'GitHub API returned HTTP %d', 'gitwire' ), $code )
			);
		}

		if ( empty( $download_url ) ) {
			return new \WP_Error( 'gitwire_no_location', __( 'GitHub did not return a download URL.', 'gitwire' ) );
		}

		/*
		 * Check the advertised size first. download_url() has no cap of its own, so
		 * without this a multi-gigabyte archive is fully written to disk before the
		 * check below can reject it.
		 */
		$declared = self::declared_size( $download_url );
		if ( $declared > 256 * MB_IN_BYTES ) {
			return new \WP_Error( 'gitwire_archive_too_large', __( 'Repository ZIP exceeds the 256 MB size limit.', 'gitwire' ) );
		}

		// Stream the archive through WordPress to avoid holding it in memory.
		$tmp = download_url( $download_url, 300 );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		if ( filesize( $tmp ) > 256 * MB_IN_BYTES ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'gitwire_archive_too_large', __( 'Repository ZIP exceeds the 256 MB size limit.', 'gitwire' ) );
		}

		return $tmp;
	}

	/**
	 * Returns the Content-Length a URL advertises, or 0 when it does not say.
	 *
	 * @since 1.0.0
	 * @param string $url Absolute URL.
	 * @return int
	 */
	private static function declared_size( string $url ): int {
		$head = wp_remote_head( $url, [ 'timeout' => 15 ] );

		if ( is_wp_error( $head ) ) {
			return 0;
		}

		return (int) wp_remote_retrieve_header( $head, 'content-length' );
	}

	/**
	 * Fetches the decoded content of a single file from a repository.
	 *
	 * @since 1.0.0
	 * @param string $owner      Repository owner.
	 * @param string $repository Repository name.
	 * @param string $path       File path within the repository.
	 * @param string $branch     Branch, tag, or SHA reference.
	 * @return string|\WP_Error Decoded file content on success, WP_Error on failure.
	 */
	private function get_raw_content( string $owner, string $repository, string $path, string $branch ): string|\WP_Error {
		$result = $this->get(
			'/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repository )
			. '/contents/' . rawurlencode( $path ) . '?ref=' . rawurlencode( $branch )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['content'] ) ) {
			return new \WP_Error( 'gitwire_no_content', __( 'File has no readable content.', 'gitwire' ) );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return base64_decode( str_replace( "\n", '', $result['content'] ) );
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
			'User-Agent' => 'Gitwire/' . GITWIRE_VERSION,
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
	 * @return array<mixed>|\WP_Error Decoded JSON array on success, WP_Error on failure.
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

		$remaining_raw = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		if ( '' !== (string) $remaining_raw ) {
			$reset = (int) wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
			$ttl   = $reset > time() ? min( $reset - time(), HOUR_IN_SECONDS ) : HOUR_IN_SECONDS;
			set_transient( 'gitwire_gh_rl_' . ( $this->connection_id ? $this->connection_id : 'anon' ), (int) $remaining_raw, $ttl );
		}

		if ( $code >= 400 ) {
			return $this->error_from_response(
				$response,
				$code,
				/* translators: %d: HTTP status code */
				sprintf( __( 'GitHub API error (HTTP %d)', 'gitwire' ), $code )
			);
		}

		return is_array( $body ) ? $body : [];
	}

	/**
	 * Builds a WP_Error from a failed response, replacing GitHub's own rate-limit
	 * wording with our own when the quota is what actually failed the request.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $response         Raw response from wp_remote_get()/wp_remote_post().
	 * @param int                  $code              HTTP status code.
	 * @param string               $generic_fallback  Message to use when the body carries none and it is not a rate limit.
	 * @return \WP_Error
	 */
	private function error_from_response( array $response, int $code, string $generic_fallback ): \WP_Error {
		$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );

		if ( '0' === (string) $remaining ) {
			$message = $this->token
				? __( "GitHub's hourly rate limit for this account has been reached. It resets automatically within the hour.", 'gitwire' )
				: __( "GitHub's hourly rate limit for unauthenticated requests has been reached. It resets automatically within the hour. Gitwire Pro adds authenticated connections with a much higher limit.", 'gitwire' );

			/**
			 * Filters the rate-limit message before it reaches the caller.
			 *
			 * The unauthenticated wording pitches Gitwire Pro, which reads oddly when the
			 * request came from a Pro connection that just happens to have no token (a
			 * public username-only connection). Gitwire Pro hooks this to drop that
			 * sentence in that case.
			 *
			 * @since 1.0.0
			 * @param string $message       The rate-limit message.
			 * @param string $connection_id Connection ID used for this request, empty for anonymous.
			 * @param bool   $authenticated Whether the request carried a token.
			 * @return string
			 */
			$message = (string) apply_filters( 'gitwire_rate_limited_message', $message, $this->connection_id, (bool) $this->token );

			return new \WP_Error( 'gitwire_rate_limited', $message, [ 'status' => $code ] );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return new \WP_Error( 'gitwire_api_error', $body['message'] ?? $generic_fallback, [ 'status' => $code ] );
	}
}
