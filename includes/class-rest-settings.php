<?php
/**
 * REST endpoints for plugin settings.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the /settings REST routes.
 */
class REST_Settings {

	/**
	 * Registers /settings routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			REST::NS,
			'/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get_settings' ],
					'permission_callback' => [ REST::class, 'can_manage' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'save_settings' ],
					'permission_callback' => [ REST::class, 'can_manage' ],
				],
			]
		);
	}

	/**
	 * Returns the current plugin settings.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Settings array.
	 */
	public static function get_settings(): array {
		return Settings::get_public();
	}

	/**
	 * Saves plugin settings from the request body.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $req REST request object.
	 * @return array<string, mixed>|\WP_Error Success data or WP_Error on validation failure.
	 */
	public static function save_settings( \WP_REST_Request $req ): array|\WP_Error {
		$incoming = [];
		foreach ( [
			'smart_install',
			'show_repo_label',
			'enable_logging',
			'log_retention_days',
			'log_level',
			'remove_data_on_uninstall',
			'repo_list_refresh_frequency',
			'auto_detect_type',
			'repos_per_page',
			'max_repos_per_source',
			'background_type_detection',
			'shallow_detection',
			'block_on_fatal',
			'update_check_interval',
		] as $key ) {
			if ( null !== $req->get_param( $key ) ) {
				$incoming[ $key ] = $req->get_param( $key );
			}
		}
		// excluded_repos is an array — check for it separately.
		if ( null !== $req->get_param( 'excluded_repos' ) ) {
			$incoming['excluded_repos'] = $req->get_param( 'excluded_repos' );
		}

		$was_logging          = Settings::is_logging_enabled();
		$prev_settings        = Settings::get_public();
		$prev_freq            = $prev_settings['repo_list_refresh_frequency'] ?? 'daily';
		$prev_update_interval = $prev_settings['update_check_interval'] ?? 'halfhourly';
		$merged               = Settings::merge_save( $incoming );
		update_option( 'gitwire_settings', $merged );
		Settings::invalidate_cache();

		$now_logging = (bool) ( $merged['enable_logging'] ?? false );
		if ( ! $was_logging && $now_logging ) {
			Logger::log( 'Logging enabled' );
		}

		if ( ( $merged['repo_list_refresh_frequency'] ?? 'hourly' ) !== $prev_freq ) {
			Repo_Cache::clear_repo_list();
			Plugin::instance()->schedule_repos_cron();
		}

		if ( ( $merged['update_check_interval'] ?? 'halfhourly' ) !== $prev_update_interval ) {
			Plugin::instance()->schedule_update_check_cron();
		}

		return [
			'saved'    => true,
			'settings' => Settings::get_public(),
		];
	}
}
