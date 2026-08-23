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
		add_filter(
			'auto_plugin_update_send_email',
			[ self::class, 'filter_update_email' ],
			10,
			2
		);
		add_filter(
			'auto_theme_update_send_email',
			[ self::class, 'filter_update_email' ],
			10,
			2
		);
	}

	/**
	 * Drops the background update email when it would report nothing but our own refusals.
	 *
	 * A blocked update is a failed update as far as the automatic updater is
	 * concerned, so it lands in update_results and core mails the site owner about
	 * it on every run. Nothing went wrong and there is nothing to act on.
	 *
	 * Core's filter is one boolean for every result of that type, so this only
	 * declines when every entry is a refusal of ours. A batch holding a real
	 * success or a real failure still sends, carrying our entry with it, because
	 * silencing that mail would hide someone else's broken update.
	 *
	 * @since 1.0.0
	 * @param mixed $enabled Whether core intends to send the email.
	 * @param mixed $results Update results for one type.
	 * @return mixed
	 */
	public static function filter_update_email( $enabled, $results ) {
		if ( true !== $enabled || ! is_array( $results ) || ! $results ) {
			return $enabled;
		}

		foreach ( $results as $result ) {
			if ( ! self::is_blocked_result( $result ) ) {
				return $enabled;
			}
		}

		return false;
	}

	/**
	 * Returns whether one update result is an update this guard refused.
	 *
	 * @since 1.0.0
	 * @param mixed $result Entry from the automatic updater's results.
	 * @return bool
	 */
	private static function is_blocked_result( $result ): bool {
		$outcome = is_object( $result ) && isset( $result->result ) ? $result->result : null;

		return is_wp_error( $outcome ) && self::ERROR_CODE === $outcome->get_error_code();
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

		$type = self::resolve_target_type( $hook_extra );
		if ( null === $type ) {
			return $reply;
		}

		$identity = self::resolve_target_identity( $type, $hook_extra );
		if ( '' === $identity || ! self::is_managed( $type, $identity ) ) {
			return $reply;
		}

		return self::build_blocked_error( $type );
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

		$type = self::resolve_target_type( $hook_extra );
		if ( null === $type ) {
			return $response;
		}

		$identity = self::resolve_target_identity( $type, $hook_extra );
		if ( '' === $identity || ! self::is_managed( $type, $identity ) ) {
			return $response;
		}

		return self::build_blocked_error( $type );
	}

	/**
	 * Returns the target type when the upgrader identifies a plugin or theme.
	 *
	 * @since 1.0.0
	 * @param mixed $hook_extra Arguments identifying the upgrade target.
	 * @return string|null
	 */
	private static function resolve_target_type( $hook_extra ): ?string {
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
	private static function resolve_target_identity( string $type, array $hook_extra ): string {
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
	private static function build_blocked_error( string $type ): \WP_Error {
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
