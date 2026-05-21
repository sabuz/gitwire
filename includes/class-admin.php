<?php
/**
 * Admin class — registers menus, enqueues assets, and renders the admin page.
 *
 * @package GitHub_WP
 * @since 1.0.0
 */

namespace GitHub_WP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all WordPress admin integration: menu pages, asset enqueueing,
 * admin notices, and page rendering.
 */
class Admin {

	/**
	 * WordPress admin page slug.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'ghwp';

	/**
	 * Registers all admin hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'admin_notices', [ self::class, 'show_fatal_notice' ] );
		add_filter( 'admin_body_class', [ self::class, 'body_class' ] );
		add_action( 'admin_head', [ self::class, 'hide_admin_notices' ], 999 );
	}

	/**
	 * Removes all admin notices on GHWP pages to keep the UI clean.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function hide_admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'ghwp' ) ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'admin_footer_text' );
	}

	/**
	 * Appends a CSS class to the body element on GHWP admin pages.
	 *
	 * @since 1.0.0
	 * @param string $classes Space-separated list of body classes.
	 * @return string Modified body class string.
	 */
	public static function body_class( string $classes ): string {
		$screen = get_current_screen();
		if ( $screen && false !== strpos( $screen->id, 'ghwp' ) ) {
			$classes .= ' ghwp-admin-page';
		}
		return $classes;
	}

	/**
	 * Registers the top-level menu page and all subpages.
	 *
	 * @since 1.0.0
	 * @return void
	 */
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

	/**
	 * Enqueues admin scripts and styles for GHWP pages.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		// Match toplevel_page_ghwp and github_page_ghwp-{browse,settings}.
		if ( false === strpos( $hook, '_page_ghwp' ) ) {
			return;
		}

		$asset_file = GHWP_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : [
			'dependencies' => [],
			'version'      => GHWP_VERSION,
		];

		// wp-scripts outputs styles imported in JS to style-index.css.
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
		$installed        = REST::get_installed();
		$first_activation = (bool) get_transient( 'ghwp_first_activation' );

		if ( $first_activation ) {
			delete_transient( 'ghwp_first_activation' );
		}

		// Derive initial tab from path param, activation state, or setup status.
		$path = sanitize_key( $_GET['path'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $first_activation || ! ( $settings['username'] ?? '' ) ) {
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
					'connection'  => $connection ? $connection : null,
					'installed'   => $installed ? $installed : (object) [],
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Renders the admin page view.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ghwp' ) );
		}
		require_once GHWP_DIR . 'views/admin-page.php';
	}

	/**
	 * Displays a persistent admin notice when a fatal PHP error was caught
	 * during plugin installation or update.
	 *
	 * @since 1.0.0
	 * @return void
	 */
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

		if ( $restored ) {
			/* translators: %s: Plugin or theme full name. */
			$msg = sprintf( __( '<strong>GitHub for WordPress:</strong> A fatal PHP error was detected after updating <em>%s</em>. The previous version has been automatically restored and the plugin deactivated.', 'ghwp' ), $name );
		} else {
			/* translators: %s: Plugin or theme full name. */
			$msg = sprintf( __( '<strong>GitHub for WordPress:</strong> A fatal PHP error was detected after installing <em>%s</em>. The broken files have been removed.', 'ghwp' ), $name );
		}

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p><details><summary>%s</summary><pre>%s</pre></details></div>',
			wp_kses_post( $msg ),
			esc_html__( 'Error details', 'ghwp' ),
			esc_html( $notice['error'] ?? '' )
		);
	}
}
