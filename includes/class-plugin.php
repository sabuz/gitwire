<?php
/**
 * Plugin orchestrator.
 *
 * @package Git_WP
 * @since 1.2.0
 */

namespace Git_WP;

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
			self::$instance = new self( $file ?? ( defined( 'GWP_FILE' ) ? GWP_FILE : '' ) );
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
		add_action( 'init', [ $this, 'maybe_migrate_options' ], 1 );
		add_filter( 'cron_schedules', [ $this, 'register_cron_schedules' ] );
		add_action( 'gwp_auto_check_connection', [ $this, 'run_connection_check' ] );
		add_action( 'gwp_maintenance', [ $this, 'run_maintenance' ] );
		add_action( 'plugins_loaded', [ $this, 'boot' ] );

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
			'git',
			false,
			dirname( plugin_basename( $this->file ) ) . '/languages'
		);
	}

	/**
	 * Migrates legacy ghwp_* options to gwp_* keys.
	 *
	 * @return void
	 */
	public function maybe_migrate_options(): void {
		if ( get_option( 'gwp_migrated_from_ghwp' ) ) {
			return;
		}
		$map = [
			'ghwp_settings'         => 'gwp_settings',
			'ghwp_connection_cache' => 'gwp_connection_cache',
			'ghwp_installed'        => 'gwp_installed',
			'ghwp_pending_update'   => 'gwp_pending_update',
			'ghwp_fatal_notice'     => 'gwp_fatal_notice',
		];
		foreach ( $map as $old => $new ) {
			$value = get_option( $old );
			if ( false !== $value && false === get_option( $new ) ) {
				add_option( $new, $value );
				delete_option( $old );
			}
		}
		add_option( 'gwp_migrated_from_ghwp', true );
	}

	/**
	 * Registers custom cron intervals.
	 *
	 * @param array<string, array<string, mixed>> $schedules Cron schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_cron_schedules( array $schedules ): array {
		$schedules['gwp_half_hourly'] = [
			'interval' => 1800,
			'display'  => __( 'Every 30 minutes', 'git' ),
		];
		return $schedules;
	}

	/**
	 * Cron handler that refreshes connection cache.
	 *
	 * @return void
	 */
	public function run_connection_check(): void {
		$settings = Settings::get_raw();
		if ( $settings ) {
			REST::test_connection();
		}
	}

	/**
	 * Cron handler that syncs installed records.
	 *
	 * @return void
	 */
	public function run_maintenance(): void {
		REST::sync_installed();
	}

	/**
	 * Boots plugin services on plugins_loaded.
	 *
	 * @return void
	 */
	public function boot(): void {
		Installer::init();
		REST::init();

		if ( is_admin() ) {
			Admin::init();
		}

		if ( ! wp_next_scheduled( 'gwp_auto_check_connection' ) ) {
			wp_schedule_event( time(), 'gwp_half_hourly', 'gwp_auto_check_connection' );
		}

		if ( ! wp_next_scheduled( 'gwp_maintenance' ) ) {
			wp_schedule_event( time(), 'gwp_half_hourly', 'gwp_maintenance' );
		}
	}

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public function activate(): void {
		if ( ! get_option( 'gwp_settings' ) ) {
			add_option(
				'gwp_settings',
				[
					'token'         => '',
					'username'      => '',
					'smart_install' => true,
				]
			);
		}
		set_transient( 'gwp_first_activation', true, 60 );
		wp_schedule_event( time(), 'gwp_half_hourly', 'gwp_auto_check_connection' );
		wp_schedule_event( time(), 'gwp_half_hourly', 'gwp_maintenance' );
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		delete_transient( 'gwp_repos_cache' );
		wp_clear_scheduled_hook( 'gwp_auto_check_connection' );
		wp_clear_scheduled_hook( 'gwp_maintenance' );
	}
}
