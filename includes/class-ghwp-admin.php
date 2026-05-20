<?php
defined( 'ABSPATH' ) || exit;

class GHWP_Admin {

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'admin_notices', [ self::class, 'show_fatal_notice' ] );
	}

	// -----------------------------------------------------------------------
	// Menu
	// -----------------------------------------------------------------------

	private const PAGE_SLUG = 'ghwp';

	public static function add_menu(): void {
		add_menu_page(
			__( 'GitHub for WordPress', 'ghwp' ),
			__( 'GitHub', 'ghwp' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ],
			'dashicons-randomize',
			65
		);

		// First submenu replaces the auto-generated duplicate of the parent.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Installed', 'ghwp' ),
			__( 'Installed', 'ghwp' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ],
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Browse GitHub', 'ghwp' ),
			__( 'Browse GitHub', 'ghwp' ),
			'manage_options',
			self::PAGE_SLUG . '&path=browse',
			[ self::class, 'render_page' ],
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Settings', 'ghwp' ),
			__( 'Settings', 'ghwp' ),
			'manage_options',
			self::PAGE_SLUG . '&path=settings',
			[ self::class, 'render_page' ],
		);
	}

	// -----------------------------------------------------------------------
	// Assets
	// -----------------------------------------------------------------------

	public static function enqueue( string $hook ): void {
		// Both the top-level page and all submenus share the same render callback.
		$allowed = [ 'toplevel_page_ghwp', 'github_page_ghwp' ];
		if ( ! in_array( $hook, $allowed, true ) ) {
			return;
		}

		$asset_file = GHWP_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : [
			'dependencies' => [],
			'version'      => GHWP_VERSION,
		];

		// wp-scripts outputs styles imported in JS to style-index.css
		$css_file = file_exists( GHWP_DIR . 'build/index.css' )
			? GHWP_URL . 'build/index.css'
			: GHWP_URL . 'build/style-index.css';

		wp_enqueue_style(
			'ghwp-app',
			$css_file,
			[ 'wp-components' ],
			$asset['version']
		);

		wp_enqueue_script(
			'ghwp-app',
			GHWP_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$settings         = (array) get_option( 'ghwp_settings', [] );
		$connection       = get_option( 'ghwp_connection_cache', null );
		$installed        = GHWP_Installer::get_installed();
		$first_activation = (bool) get_transient( 'ghwp_first_activation' );
		$path             = sanitize_key( $_GET['path'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( $first_activation ) {
			delete_transient( 'ghwp_first_activation' );
		}

		// Derive initial tab from URL path, activation state, or setup status.
		if ( $first_activation || ( ! ( $settings['username'] ?? '' ) ) ) {
			$initial_tab = 'settings';
		} elseif ( in_array( $path, [ 'browse', 'settings' ], true ) ) {
			$initial_tab = $path;
		} else {
			$initial_tab = 'installed';
		}

		wp_add_inline_script(
			'ghwp-app',
			'window.GHWP = ' . wp_json_encode(
				[
					'nonce'       => wp_create_nonce( 'wp_rest' ),
					'initial_tab' => $initial_tab,
					'settings'    => [
						'username'      => $settings['username'] ?? '',
						'token'         => $settings['token'] ?? '',
						'smart_install' => $settings['smart_install'] ?? true,
					],
					'connection'  => $connection ?: null,
					'installed'   => $installed ?: (object) [],
				]
			) . ';',
			'before'
		);
	}

	// -----------------------------------------------------------------------
	// Page render
	// -----------------------------------------------------------------------

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ghwp' ) );
		}
		require_once GHWP_DIR . 'views/admin-page.php';
	}

	// -----------------------------------------------------------------------
	// Admin notice for fatal errors
	// -----------------------------------------------------------------------

	public static function show_fatal_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = get_option( 'ghwp_fatal_notice' );
		if ( ! $notice ) {
			return;
		}
		delete_option( 'ghwp_fatal_notice' );

		$name     = esc_html( $notice['full_name'] ?? 'Unknown' );
		$restored = ! empty( $notice['restored'] );
		$msg      = $restored
			? sprintf( __( '<strong>GitHub for WordPress:</strong> A fatal PHP error was detected after updating <em>%s</em>. The previous version has been automatically restored and the plugin deactivated.', 'ghwp' ), $name )
			: sprintf( __( '<strong>GitHub for WordPress:</strong> A fatal PHP error was detected after installing <em>%s</em>. The broken files have been removed.', 'ghwp' ), $name );

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p><details><summary>%s</summary><pre>%s</pre></details></div>',
			wp_kses_post( $msg ),
			esc_html__( 'Error details', 'ghwp' ),
			esc_html( $notice['error'] ?? '' )
		);
	}
}
