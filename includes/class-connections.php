<?php
/**
 * Connection store — CRUD, encryption, and resolution for provider credentials.
 *
 * @package Gitwire
 * @since 2.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the gitwire_connections option: a list of encrypted provider credential sets.
 */
class Connections {

	/**
	 * WordPress option key.
	 *
	 * @var string
	 */
	const OPTION = 'gitwire_connections';

	/**
	 * Returns all stored connection records (credentials still encrypted).
	 *
	 * @since 2.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		return array_values( (array) get_option( self::OPTION, [] ) );
	}

	/**
	 * Returns a public-safe list — no raw credentials, masked previews only.
	 *
	 * @since 2.0.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_public_list(): array {
		return array_values( array_map( [ self::class, 'to_public' ], self::all() ) );
	}

	/**
	 * Finds a single connection by ID.
	 *
	 * @since 2.0.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $id ): ?array {
		foreach ( self::all() as $conn ) {
			if ( ( $conn['id'] ?? '' ) === $id ) {
				return $conn;
			}
		}
		return null;
	}

	/**
	 * Returns the default connection for a provider, or the first one if none is marked default.
	 *
	 * @since 2.0.0
	 * @param string $provider Provider key: 'github', 'gitlab', or 'bitbucket'.
	 * @return array<string, mixed>|null
	 */
	public static function get_default( string $provider ): ?array {
		$for_provider = array_values(
			array_filter( self::all(), static fn( $c ) => ( $c['provider'] ?? '' ) === $provider )
		);
		foreach ( $for_provider as $conn ) {
			if ( $conn['is_default'] ?? false ) {
				return $conn;
			}
		}
		return $for_provider[0] ?? null;
	}

	/**
	 * Returns decrypted credentials for a connection.
	 *
	 * @since 2.0.0
	 * @param string $id Connection ID.
	 * @return array<string, mixed>|null Null when the connection does not exist.
	 */
	public static function get_credentials( string $id ): ?array {
		$conn = self::find( $id );
		if ( null === $conn ) {
			return null;
		}
		$raw = $conn['credentials'] ?? '';
		if ( '' === $raw ) {
			return [];
		}
		$json = self::decrypt( $raw );
		return ( '' !== $json ) ? (array) json_decode( $json, true ) : [];
	}

	/**
	 * Returns decrypted credentials for the default connection of a provider.
	 *
	 * @since 2.0.0
	 * @param string $provider Provider key.
	 * @return array<string, mixed>|null Null when no connection exists for the provider.
	 */
	public static function get_default_credentials( string $provider ): ?array {
		$conn = self::get_default( $provider );
		if ( null === $conn ) {
			return null;
		}
		return self::get_credentials( $conn['id'] );
	}

	/**
	 * Creates or updates a connection. Pass 'id' to update; omit to create.
	 * The 'credentials' key must be a plain array — it will be encrypted here.
	 *
	 * @since 2.0.0
	 * @param array<string, mixed> $data Connection data.
	 * @return array<string, mixed> Saved record (credentials encrypted).
	 */
	public static function upsert( array $data ): array {
		$all      = self::all();
		$id       = $data['id'] ?? self::make_id();
		$provider = $data['provider'] ?? '';

		$data['id'] = $id;

		if ( isset( $data['credentials'] ) && is_array( $data['credentials'] ) ) {
			$data['credentials'] = self::encrypt( (string) wp_json_encode( $data['credentials'] ) );
		}

		// Clear other defaults for this provider when this one is being set as default.
		if ( $data['is_default'] ?? false ) {
			foreach ( $all as &$conn ) {
				if ( ( $conn['provider'] ?? '' ) === $provider && ( $conn['id'] ?? '' ) !== $id ) {
					$conn['is_default'] = false;
				}
			}
			unset( $conn );
		}

		$found = false;
		foreach ( $all as &$conn ) {
			if ( ( $conn['id'] ?? '' ) === $id ) {
				// Preserve existing encrypted credentials when the caller hasn't sent new ones.
				if ( ! isset( $data['credentials'] ) ) {
					$data['credentials'] = $conn['credentials'] ?? '';
				}
				$conn  = $data;
				$found = true;
				break;
			}
		}
		unset( $conn );

		if ( ! $found ) {
			$all[] = $data;
		}

		update_option( self::OPTION, array_values( $all ) );
		return $data;
	}

	/**
	 * Deletes a connection by ID.
	 *
	 * @since 2.0.0
	 * @param string $id Connection ID.
	 * @return bool True when deleted, false when not found.
	 */
	public static function delete( string $id ): bool {
		$all     = self::all();
		$deleted = null;
		foreach ( $all as $conn ) {
			if ( ( $conn['id'] ?? '' ) === $id ) {
				$deleted = $conn;
				break;
			}
		}
		if ( null === $deleted ) {
			return false;
		}

		$filtered = array_values( array_filter( $all, static fn( $c ) => ( $c['id'] ?? '' ) !== $id ) );

		// When the deleted connection was the default, promote the next one for that provider.
		if ( $deleted['is_default'] ?? false ) {
			$provider = $deleted['provider'] ?? '';
			foreach ( $filtered as &$conn ) {
				if ( ( $conn['provider'] ?? '' ) === $provider ) {
					$conn['is_default'] = true;
					break;
				}
			}
			unset( $conn );
		}

		update_option( self::OPTION, $filtered );
		return true;
	}

	/**
	 * Strips raw credentials and adds masked previews for client delivery.
	 *
	 * @since 2.0.0
	 * @param array<string, mixed> $conn Raw connection record.
	 * @return array<string, mixed>
	 */
	private static function to_public( array $conn ): array {
		$creds    = [];
		$raw_json = self::decrypt( $conn['credentials'] ?? '' );
		if ( '' !== $raw_json ) {
			$creds = (array) json_decode( $raw_json, true );
		}

		$provider = $conn['provider'] ?? '';
		$public   = [
			'id'         => $conn['id'] ?? '',
			'provider'   => $provider,
			'label'      => $conn['label'] ?? '',
			'scope'      => $conn['scope'] ?? 'site',
			'is_default' => $conn['is_default'] ?? false,
			'username'   => $conn['username'] ?? '',
			'gitlab_url' => $conn['gitlab_url'] ?? '',
		];

		if ( 'github' === $provider ) {
			$public['token_set']     = ! empty( $creds['token'] );
			$public['token_preview'] = Settings::mask_token( $creds['token'] ?? '' );
		} elseif ( 'gitlab' === $provider ) {
			$public['token_set']     = ! empty( $creds['token'] );
			$public['token_preview'] = Settings::mask_token( $creds['token'] ?? '' );
		} elseif ( 'bitbucket' === $provider ) {
			$public['email']         = $creds['email'] ?? '';
			$public['token_set']     = ! empty( $creds['api_token'] );
			$public['token_preview'] = Settings::mask_token( $creds['api_token'] ?? '' );
		}

		return $public;
	}

	/**
	 * Generates a unique connection ID.
	 *
	 * @since 2.0.0
	 * @return string
	 */
	private static function make_id(): string {
		return 'conn_' . wp_generate_uuid4();
	}

	/**
	 * Encrypts a string using AES-256-CBC with a key derived from wp_salt('auth').
	 *
	 * @since 2.0.0
	 * @param string $plain Plaintext.
	 * @return string Base64-encoded IV + ciphertext, or empty string on failure.
	 */
	private static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$key = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $enc ) {
			return '';
		}
		return base64_encode( $iv . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts a value produced by encrypt().
	 *
	 * @since 2.0.0
	 * @param string $cipher Base64-encoded IV + ciphertext.
	 * @return string Plaintext, or empty string on failure.
	 */
	private static function decrypt( string $cipher ): string {
		if ( '' === $cipher ) {
			return '';
		}
		$key  = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$data = base64_decode( $cipher, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $data || strlen( $data ) <= 16 ) {
			return '';
		}
		$iv  = substr( $data, 0, 16 );
		$enc = substr( $data, 16 );
		$dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return ( false === $dec ) ? '' : $dec;
	}
}
