<?php
/**
 * Factory for Git provider API clients.
 *
 * @package Gitwire
 * @since 1.2.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates GitHub or GitLab API client instances from settings.
 */
class Provider_Factory {

	/**
	 * Builds a provider client for the given settings.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @param string               $provider Provider key.
	 * @return Git_Provider_Interface
	 */
	public static function make( array $settings, string $provider = 'github' ): Git_Provider_Interface {
		if ( 'gitlab' === $provider ) {
			return new GitLab_API(
				$settings['gitlab_token'] ?? '',
				$settings['gitlab_url'] ?? ''
			);
		}

		if ( 'bitbucket' === $provider ) {
			return new Bitbucket_API(
				$settings['bitbucket_email'] ?? '',
				$settings['bitbucket_api_token'] ?? ''
			);
		}

		return new API( $settings['token'] ?? '' );
	}
}
