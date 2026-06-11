<?php
/**
 * Factory for Git provider API clients.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates GitHub, GitLab, or Bitbucket API client instances from stored connections.
 */
class Provider_Factory {

	/**
	 * Builds a provider client for the given provider and optional connection ID.
	 *
	 * When $connection_id is null the default connection for the provider is used.
	 * When no connection exists at all, an unauthenticated client is returned.
	 *
	 * @since 1.0.0
	 * @param string      $provider      Provider key: 'github', 'gitlab', or 'bitbucket'.
	 * @param string|null $connection_id Specific connection ID, or null for the default.
	 * @return Git_Provider_Interface
	 */
	public static function make( string $provider = 'github', ?string $connection_id = null ): Git_Provider_Interface {
		$creds = null === $connection_id || '' === $connection_id
			? Connection_Resolver::get_credentials_for_provider( $provider )
			: Connection_Resolver::get_credentials( $connection_id );

		$creds = $creds ?? [];

		/**
		 * Filters the resolved credentials before a provider client is built.
		 *
		 * @since 1.0.0
		 * @param array       $creds         Decrypted credential array.
		 * @param string      $provider      Provider key.
		 * @param string|null $connection_id Connection ID, or null for the default.
		 */
		$creds = (array) apply_filters( 'gitwire_provider_factory_auth', $creds, $provider, $connection_id );

		// No token connection — public mode using the saved browse account.
		if ( empty( $creds ) ) {
			$creds = Settings::public_credentials( $provider );
		}

		if ( 'gitlab' === $provider ) {
			return new GitLab_API(
				$creds['token'] ?? '',
				$creds['gitlab_url'] ?? ''
			);
		}

		if ( 'bitbucket' === $provider ) {
			return new Bitbucket_API(
				$creds['email'] ?? '',
				$creds['api_token'] ?? ''
			);
		}

		return new API( $creds['token'] ?? '' );
	}
}
