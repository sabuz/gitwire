<?php
/**
 * Git provider contract shared by GitHub and GitLab clients.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Common methods for Git hosting API clients.
 */
interface Git_Provider_Interface {

	/**
	 * Tests API connectivity.
	 *
	 * @since 1.0.0
	 * @param string $owner Optional owner context for the provider.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function test_connection( string $owner = '' ): array|\WP_Error;

	/**
	 * Returns a paginated repository list.
	 *
	 * @since 1.0.0
	 * @param string $username GitHub username or GitLab namespace context.
	 * @param int    $page     Page number.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_repositories( string $username, int $page = 1 ): array|\WP_Error;

	/**
	 * Returns branch names for a repository.
	 *
	 * @since 1.0.0
	 * @param string $owner      Repository owner.
	 * @param string $repository Repository name.
	 * @return array<int, mixed>|\WP_Error
	 */
	public function get_branches( string $owner, string $repository ): array|\WP_Error;

	/**
	 * Detects whether a repository is a plugin or theme.
	 *
	 * @since 1.0.0
	 * @param string            $owner         Repository owner.
	 * @param string            $repository    Repository name.
	 * @param string            $branch        Branch ref.
	 * @param array<mixed>|null $cached_result Pre-fetched file listing to skip the API call.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function detect_type( string $owner, string $repository, string $branch = 'HEAD', ?array $cached_result = null ): array|\WP_Error;

	/**
	 * Returns recent commits for a branch.
	 *
	 * @since 1.0.0
	 * @param string $owner      Repository owner.
	 * @param string $repository Repository name.
	 * @param string $branch     Branch ref.
	 * @param int    $per_page   Commit count.
	 * @return array<int, array<string, string>>|\WP_Error
	 */
	public function get_commits( string $owner, string $repository, string $branch, int $per_page = 10 ): array|\WP_Error;

	/**
	 * Downloads a repository ZIP archive to a local temp file.
	 *
	 * @since 1.0.0
	 * @param string $owner      Repository owner.
	 * @param string $repository Repository name.
	 * @param string $branch     Branch ref.
	 * @return string|\WP_Error Absolute path to the temp ZIP file, or WP_Error on failure.
	 */
	public function download_zip( string $owner, string $repository, string $branch ): string|\WP_Error;
}
