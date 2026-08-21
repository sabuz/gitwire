<?php
/**
 * Plugin orchestrator.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires plugin services and hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Main plugin file path.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Returns the singleton instance.
	 *
	 * @param string|null $file Main plugin file path.
	 * @return self
	 */
	public static function instance( ?string $file = null ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $file ?? ( defined( 'GITWIRE_FILE' ) ? GITWIRE_FILE : '' ) );
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @param string $file Main plugin file path.
	 */
	private function __construct( string $file ) {
		$this->file = $file;

		Error_Handler::register();

		// must be constructed here so register_activation_hook() fires before the file finishes loading.
		Database_Manager::instance();

		add_filter( 'cron_schedules', [ $this, 'register_cron_schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( 'gitwire_maintenance', [ $this, 'run_maintenance' ] );
		add_action( 'gitwire_trim_logs', [ $this, 'trim_logs' ] );
		add_action( 'gitwire_refresh_repositories', [ Repositories::class, 'scheduled_refresh' ] );
		add_action( 'gitwire_refresh_repository_types', [ Repositories::class, 'scheduled_type_refresh' ] );
		add_action( 'gitwire_background_type_detection', [ Repositories::class, 'scheduled_background_detection' ] );
		add_action( 'gitwire_refresh_connections', [ Connection_Meta::class, 'refresh_public_connections' ] );
		add_action( 'gitwire_update_check', [ Installer::class, 'run_auto_updates' ] );
		add_action( 'plugins_loaded', [ $this, 'boot' ] );

		if ( $this->file ) {
			register_activation_hook( $this->file, [ $this, 'activate' ] );
			register_deactivation_hook( $this->file, [ $this, 'deactivate' ] );
		}
	}

	/**
	 * Registers custom cron intervals.
	 *
	 * @param array<string, array<string, mixed>> $schedules Cron schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_cron_schedules( array $schedules ): array {
		$schedules['everyfiveminutes'] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'gitwire' ),
		];
		$schedules['halfhourly']       = [
			'interval' => 1800,
			'display'  => __( 'Every 30 minutes', 'gitwire' ),
		];
		return $schedules;
	}

	/**
	 * Cron handler that syncs installed records.
	 *
	 * @return void
	 */
	public function run_maintenance(): void {
		REST_Installer::sync_installed();
		Installer::purge_orphaned_backups();
		Logger::purge_log_dir();
	}

	/**
	 * Cron handler that trims old log entries.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function trim_logs(): void {
		Logger::get_instance()->trim_old_entries();
	}

	/**
	 * Boots plugin services on plugins_loaded.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( is_multisite() && ! is_main_site() ) {
			return;
		}

		Installer::init();
		REST::init();

		if ( is_admin() ) {
			Admin::init();
		}

		/*
		 * Everything below reads non-autoloaded options. Gitwire has no front-end
		 * surface, so a page view should not pay for schema and cron bookkeeping.
		 */
		if ( self::is_management_request() ) {
			if ( Database_Manager::instance()->needs_migrate() ) {
				Database_Manager::instance()->migrate();
			}

			$this->ensure_cron_events();
		}

		/**
		 * Fires after the free plugin finishes bootstrapping.
		 *
		 * Gitwire Pro registers its connection filters here, guaranteed
		 * before any apply_filters call in the free plugin runs.
		 *
		 * @since 1.0.0
		 */
		do_action( 'gitwire_loaded' );
	}

	/**
	 * Returns whether this request is one that manages Gitwire state.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_management_request(): bool {
		return is_admin()
			|| wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Registers every recurring event, rescheduling the two that follow a setting.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function ensure_cron_events(): void {
		if ( ! wp_next_scheduled( 'gitwire_maintenance' ) ) {
			wp_schedule_event( time(), 'hourly', 'gitwire_maintenance' );
		}

		if ( ! wp_next_scheduled( 'gitwire_refresh_connections' ) ) {
			wp_schedule_event( time(), 'halfhourly', 'gitwire_refresh_connections' );
		}

		/*
		 * Fixed cadence, not a setting: this is opportunistic work bounded by its own
		 * rate-limit guard, not a freshness sweep, so it doesn't belong on either
		 * refresh frequency. The Background Type Pre-Detection toggle gates whether it
		 * does anything on a given tick.
		 */
		if ( ! wp_next_scheduled( 'gitwire_background_type_detection' ) ) {
			wp_schedule_event( time(), 'halfhourly', 'gitwire_background_type_detection' );
		}

		if ( ! wp_next_scheduled( 'gitwire_trim_logs' ) ) {
			wp_schedule_event( time(), 'hourly', 'gitwire_trim_logs' );
		}

		$this->schedule_repos_cron();
		$this->schedule_repository_types_cron();
		$this->schedule_update_check_cron();
	}

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public function activate(): void {

		if ( ! get_option( 'gitwire_settings' ) ) {
			add_option( 'gitwire_settings', Settings::defaults(), '', false );
		}
		$this->ensure_cron_events();
	}

	/**
	 * Schedules or reschedules the repository list cache cron to match the current frequency setting.
	 *
	 * Safe to call on every boot, since it only reschedules when the stored interval differs.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function schedule_repos_cron(): void {
		$freq    = Settings::get_repository_refresh_frequency();
		$current = wp_get_schedule( 'gitwire_refresh_repositories' );
		if ( $current === $freq ) {
			return;
		}
		wp_clear_scheduled_hook( 'gitwire_refresh_repositories' );
		wp_schedule_event( time(), $freq, 'gitwire_refresh_repositories' );
	}

	/**
	 * Schedules or reschedules the repository type re-detection cron to match its frequency setting.
	 *
	 * When the frequency is 'never', the event is removed and types are only re-detected
	 * by a manual "Refresh Repositories & Types" or "Refresh Types Only" from the Browse
	 * tab. Turning type detection off entirely removes it too, without touching the
	 * stored frequency, so the old cadence comes back when detection is re-enabled.
	 * Safe to call on every boot, since it only reschedules when the stored interval
	 * differs.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function schedule_repository_types_cron(): void {
		$freq = Settings::get_repository_type_refresh_frequency();

		if ( 'never' === $freq || ! Settings::is_type_detection_enabled() ) {
			wp_clear_scheduled_hook( 'gitwire_refresh_repository_types' );
			return;
		}

		if ( wp_get_schedule( 'gitwire_refresh_repository_types' ) === $freq ) {
			return;
		}
		wp_clear_scheduled_hook( 'gitwire_refresh_repository_types' );
		wp_schedule_event( time(), $freq, 'gitwire_refresh_repository_types' );
	}

	/**
	 * Schedules or reschedules the auto-update cron to match the update_check_interval setting.
	 *
	 * When update_check_interval is 'never', the event is removed entirely.
	 * Safe to call on every boot, since it only reschedules when the stored interval differs.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function schedule_update_check_cron(): void {
		$interval = Settings::get_public()['update_check_interval'] ?? 'halfhourly';

		if ( 'never' === $interval ) {
			wp_clear_scheduled_hook( 'gitwire_update_check' );
			return;
		}

		$allowed    = [ 'everyfiveminutes', 'halfhourly', 'hourly', 'twicedaily', 'daily', 'weekly' ];
		$recurrence = in_array( $interval, $allowed, true ) ? $interval : 'halfhourly';
		$current    = wp_get_schedule( 'gitwire_update_check' );

		if ( $current === $recurrence ) {
			return;
		}
		wp_clear_scheduled_hook( 'gitwire_update_check' );
		wp_schedule_event( time(), $recurrence, 'gitwire_update_check' );
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		/*
		 * The repository and detection caches deliberately survive. Deactivating to
		 * troubleshoot should not cost a full re-fetch of every repo on every
		 * connection when the plugin comes back.
		 */
		wp_clear_scheduled_hook( 'gitwire_maintenance' );
		wp_clear_scheduled_hook( 'gitwire_trim_logs' );
		wp_clear_scheduled_hook( 'gitwire_refresh_repositories' );
		wp_clear_scheduled_hook( 'gitwire_refresh_repository_types' );
		wp_clear_scheduled_hook( 'gitwire_background_type_detection' );
		wp_clear_scheduled_hook( 'gitwire_refresh_connections' );
		wp_clear_scheduled_hook( 'gitwire_update_check' );
	}
}
