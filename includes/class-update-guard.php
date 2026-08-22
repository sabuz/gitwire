<?php
/**
 * Prevents native WordPress upgrades from replacing Gitwire-managed installs.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards tracked plugins and themes at the native upgrader boundary.
 */
final class Update_Guard {

	/**
	 * Error code returned when a native update targets a Gitwire installation.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'gitwire_managed_update_blocked';

	/**
	 * Registers native upgrader guards.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init(): void {
		add_filter(
			'upgrader_pre_download',
			[ self::class, 'filter_pre_download' ],
			10,
			4
		);
		add_filter(
			'upgrader_pre_install',
			[ self::class, 'filter_pre_install' ],
			10,
			2
		);
	}

	/**
	 * Prevents downloading a native package for a tracked installation.
	 *
	 * @since 1.0.0
	 * @param mixed $reply      Download response.
	 * @param mixed $package    Package URI or local path.
	 * @param mixed $upgrader   Upgrader instance.
	 * @param mixed $hook_extra Arguments identifying the upgrade target.
	 * @return mixed
	 */
	public static function filter_pre_download( $reply, $package, $upgrader, $hook_extra ) {
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$type = self::target_type( $hook_extra );
		if ( null === $type ) {
			return $reply;
		}

		$identity = self::target_identity( $type, $hook_extra );
		if ( '' === $identity || ! self::is_managed( $type, $identity ) ) {
			return $reply;
		}

		return self::blocked_error( $type );
	}

	/**
	 * Prevents installing a native package for a tracked installation.
	 *
	 * @since 1.0.0
	 * @param mixed $response   Installation response.
	 * @param mixed $hook_extra Arguments identifying the installation target.
	 * @return mixed
	 */
	public static function filter_pre_install( $response, $hook_extra ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$type = self::target_type( $hook_extra );
		if ( null === $type ) {
			return $response;
		}

		$identity = self::target_identity( $type, $hook_extra );
		if ( '' === $identity || ! self::is_managed( $type, $identity ) ) {
			return $response;
		}

		return self::blocked_error( $type );
	}

	/**
	 * Returns the target type when the upgrader identifies a plugin or theme.
	 *
	 * @since 1.0.0
	 * @param mixed $hook_extra Arguments identifying the upgrade target.
	 * @return string|null
	 */
	private static function target_type( $hook_extra ): ?string {
		if ( ! is_array( $hook_extra ) ) {
			return null;
		}

		if ( isset( $hook_extra['type'] ) && ! in_array( $hook_extra['type'], [ 'plugin', 'theme' ], true ) ) {
			return null;
		}

		$has_plugin = isset( $hook_extra['plugin'] );
		$has_theme  = isset( $hook_extra['theme'] );
		if ( $has_plugin === $has_theme ) {
			return null;
		}

		return $has_plugin ? 'plugin' : 'theme';
	}

	/**
	 * Returns the exact plugin basename or theme stylesheet supplied by core.
	 *
	 * @since 1.0.0
	 * @param string              $type       Upgrade target type.
	 * @param array<string,mixed> $hook_extra Arguments identifying the target.
	 * @return string
	 */
	private static function target_identity( string $type, array $hook_extra ): string {
		$key = 'plugin' === $type ? 'plugin' : 'theme';
		return trim( (string) ( $hook_extra[ $key ] ?? '' ) );
	}

	/**
	 * Returns whether an exact native target belongs to a tracked installation.
	 *
	 * @since 1.0.0
	 * @param string $type     Upgrade target type.
	 * @param string $identity Plugin basename or theme stylesheet.
	 * @return bool
	 */
	private static function is_managed( string $type, string $identity ): bool {
		foreach ( Installer::get_installed() as $record ) {
			if ( 'plugin' === $type && self::is_managed_plugin( $record, $identity ) ) {
				return true;
			}

			if ( 'theme' === $type && self::is_managed_theme( $record, $identity ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether a plugin target matches a tracked plugin record.
	 *
	 * @since 1.0.0
	 * @param array<string,mixed> $record   Installation record.
	 * @param string              $identity Plugin basename.
	 * @return bool
	 */
	private static function is_managed_plugin( array $record, string $identity ): bool {
		if ( 'plugin' !== ( $record['type'] ?? '' ) ) {
			return false;
		}

		$basename = trim( (string) ( $record['basename'] ?? '' ) );
		if ( '' !== $basename ) {
			return $basename === $identity;
		}

		$directory = basename( untrailingslashit( (string) ( $record['install_path'] ?? '' ) ) );
		return '' !== $directory && dirname( $identity ) === $directory;
	}

	/**
	 * Returns whether a theme target matches a tracked theme record.
	 *
	 * @since 1.0.0
	 * @param array<string,mixed> $record   Installation record.
	 * @param string              $identity Theme stylesheet.
	 * @return bool
	 */
	private static function is_managed_theme( array $record, string $identity ): bool {
		if ( ! Repository_Detector::is_theme( (string) ( $record['type'] ?? '' ) ) ) {
			return false;
		}

		$name = trim( (string) ( $record['name'] ?? '' ) );
		if ( '' !== $name ) {
			return $name === $identity;
		}

		$directory = basename( untrailingslashit( (string) ( $record['install_path'] ?? '' ) ) );
		return '' !== $directory && $directory === $identity;
	}

	/**
	 * Creates the controlled error shown by the native upgrader.
	 *
	 * @since 1.0.0
	 * @param string $type Upgrade target type.
	 * @return \WP_Error
	 */
	private static function blocked_error( string $type ): \WP_Error {
		$label = 'plugin' === $type
			? __( 'plugin', 'gitwire' )
			: __( 'theme', 'gitwire' );

		return new \WP_Error(
			self::ERROR_CODE,
			sprintf(
				/* translators: %s: plugin or theme. */
				__( 'This %s is managed by Gitwire. Update it from Gitwire instead of WordPress.', 'gitwire' ),
				$label
			)
		);
	}
}
