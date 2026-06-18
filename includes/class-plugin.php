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

		add_action( 'init', [ $this, 'load_textdomain' ], 0 );
		add_filter( 'cron_schedules', [ $this, 'register_cron_schedules' ] );
		add_action( 'gitwire_maintenance', [ $this, 'run_maintenance' ] );
		add_action( 'gitwire_trim_logs', [ $this, 'trim_logs' ] );
		add_action( 'gitwire_refresh_repos_cache', [ Repo_Cache::class, 'cron_refresh' ] );
		add_action( 'gitwire_refresh_connections', [ REST::class, 'refresh_public_connections' ] );
		add_action( 'plugins_loaded', [ $this, 'boot' ] );
		add_action( 'upgrader_process_complete', [ $this, 'maybe_migrate' ], 10, 2 );

		if ( $this->file ) {
			register_activation_hook( $this->file, [ $this, 'activate' ] );
			register_deactivation_hook( $this->file, [ $this, 'deactivate' ] );
		}
	}

	/**
	 * Loads the plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'gitwire',
			false,
			dirname( plugin_basename( $this->file ) ) . '/languages'
		);
	}

	/**
	 * Registers custom cron intervals.
	 *
	 * @param array<string, array<string, mixed>> $schedules Cron schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_cron_schedules( array $schedules ): array {
		$schedules['gitwire_half_hourly'] = [
			'interval' => 1800,
			'display'  => __( 'Every 30 minutes', 'gitwire' ),
		];
		$schedules['gitwire_daily']       = [
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Once daily', 'gitwire' ),
		];
		return $schedules;
	}

	/**
	 * Cron handler that syncs installed records.
	 *
	 * @return void
	 */
	public function run_maintenance(): void {
		REST::sync_installed();
		Installer::purge_orphaned_backups();
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
	 * Migrates schema immediately after this plugin is updated via the WP upgrader.
	 *
	 * @param \WP_Upgrader                        $upgrader Upgrader instance.
	 * @param array<string, mixed>                $hook_extra Upgrade metadata.
	 * @return void
	 */
	public function maybe_migrate( $upgrader, array $hook_extra ): void {
		if ( ( $hook_extra['action'] ?? '' ) !== 'update' || ( $hook_extra['type'] ?? '' ) !== 'plugin' ) {
			return;
		}
		$plugins = (array) ( $hook_extra['plugins'] ?? [] );
		if ( $this->file && in_array( plugin_basename( $this->file ), $plugins, true ) ) {
			Schema::install();
		}
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

		if ( Schema::needs_install() ) {
			Schema::install();
		}

		Installer::init();
		REST::init();

		if ( is_admin() ) {
			Admin::init();
		}

		if ( ! wp_next_scheduled( 'gitwire_maintenance' ) ) {
			wp_schedule_event( time(), 'hourly', 'gitwire_maintenance' );
		}

		$this->schedule_repos_cron();

		if ( ! wp_next_scheduled( 'gitwire_refresh_connections' ) ) {
			wp_schedule_event( time(), 'gitwire_half_hourly', 'gitwire_refresh_connections' );
		}

		if ( ! wp_next_scheduled( 'gitwire_trim_logs' ) ) {
			wp_schedule_event( time(), 'hourly', 'gitwire_trim_logs' );
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
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public function activate(): void {
		Schema::install();

		if ( ! get_option( 'gitwire_settings' ) ) {
			add_option(
				'gitwire_settings',
				[
					'smart_install'           => true,
					'show_repo_label'         => true,
					'enable_logging'          => true,
					'log_retention_days'      => 7,
					'log_level'               => 'activity',
					'remove_data_on_uninstall' => false,
					'repos_refresh_frequency' => 'daily',
				],
				'',
				false
			);
		}
		if ( ! wp_next_scheduled( 'gitwire_maintenance' ) ) {
			wp_schedule_event( time(), 'hourly', 'gitwire_maintenance' );
		}
		$this->schedule_repos_cron();
		if ( ! wp_next_scheduled( 'gitwire_refresh_connections' ) ) {
			wp_schedule_event( time(), 'gitwire_half_hourly', 'gitwire_refresh_connections' );
		}
		if ( ! wp_next_scheduled( 'gitwire_trim_logs' ) ) {
			wp_schedule_event( time(), 'hourly', 'gitwire_trim_logs' );
		}
	}

	/**
	 * Schedules or reschedules the repos cache cron to match the current frequency setting.
	 *
	 * Safe to call on every boot — only reschedules when the stored interval differs.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function schedule_repos_cron(): void {
		$freq    = Settings::get_repos_refresh_frequency();
		$current = wp_get_schedule( 'gitwire_refresh_repos_cache' );
		if ( $current === $freq ) {
			return;
		}
		wp_clear_scheduled_hook( 'gitwire_refresh_repos_cache' );
		wp_schedule_event( time(), $freq, 'gitwire_refresh_repos_cache' );
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		Repo_Cache::clear_all();
		wp_clear_scheduled_hook( 'gitwire_maintenance' );
		wp_clear_scheduled_hook( 'gitwire_trim_logs' );
		wp_clear_scheduled_hook( 'gitwire_refresh_repos_cache' );
		wp_clear_scheduled_hook( 'gitwire_refresh_connections' );
	}
}
