<?php
/**
 * Factory for Git provider API clients.
 *
 * @package Git_WP
 * @since 1.2.0
 */

namespace Git_WP;

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

		return new API( $settings['token'] ?? '' );
	}
}
