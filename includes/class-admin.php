<?php
/**
 * Admin class — registers menus, enqueues assets, and renders the admin page.
 *
 * @package Git_WP
 * @since 1.0.0
 */

namespace Git_WP;

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
	private const PAGE_SLUG = 'git';

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
	 * Removes all admin notices on GWP pages to keep the UI clean.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function hide_admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, '_page_git' ) ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'admin_footer_text' );
	}

	/**
	 * Appends a CSS class to the body element on GWP admin pages.
	 *
	 * @since 1.0.0
	 * @param string $classes Space-separated list of body classes.
	 * @return string Modified body class string.
	 */
	public static function body_class( string $classes ): string {
		$screen = get_current_screen();
		if ( $screen && false !== strpos( $screen->id, '_page_git' ) ) {
			$classes .= ' gwp-admin-page';
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
		$menu_icon = 'none';
		$icon_path = GWP_DIR . 'assets/images/icon.svg';
		if ( file_exists( $icon_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$svg_raw = (string) file_get_contents( $icon_path );
			// Replace all hex fill/stroke colours with white so WordPress
			// colour-scheme CSS can tint the icon via opacity correctly.
			$svg_white = (string) preg_replace( '/(fill|stroke)="#[0-9a-fA-F]{3,6}"/', '$1="#ffffff"', $svg_raw );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$menu_icon = 'data:image/svg+xml;base64,' . base64_encode( $svg_white );
		}

		add_menu_page(
			__( 'Git', 'git' ),
			__( 'Git', 'git' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ],
			$menu_icon,
			65
		);

		// First submenu replaces the auto-generated duplicate of the parent.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Installed', 'git' ),
			__( 'Installed', 'git' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ],
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Browse', 'git' ),
			__( 'Browse', 'git' ),
			'manage_options',
			self::PAGE_SLUG . '&path=browse',
			[ self::class, 'render_page' ],
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Settings', 'git' ),
			__( 'Settings', 'git' ),
			'manage_options',
			self::PAGE_SLUG . '&path=settings',
			[ self::class, 'render_page' ],
		);
	}

	/**
	 * Enqueues admin scripts and styles for GWP pages.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		// Match toplevel_page_git and git_page_git-{browse,settings}.
		if ( false === strpos( $hook, '_page_git' ) ) {
			return;
		}

		$asset_file = GWP_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : [
			'dependencies' => [],
			'version'      => GWP_VERSION,
		];

		// wp-scripts outputs styles imported in JS to style-index.css.
		$css_file = file_exists( GWP_DIR . 'build/index.css' )
			? GWP_URL . 'build/index.css'
			: GWP_URL . 'build/style-index.css';

		wp_enqueue_style(
			'gwp-app',
			$css_file,
			[ 'wp-components' ],
			$asset['version']
		);

		wp_enqueue_script(
			'gwp-app',
			GWP_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'gwp-app', 'git', GWP_DIR . 'languages' );

		$settings         = (array) get_option( 'gwp_settings', [] );
		$has_github       = ! empty( $settings['username'] ) || ! empty( $settings['token'] );
		$has_gitlab       = ! empty( $settings['gitlab_token'] );
		$has_config       = $has_github || $has_gitlab;
		$raw_cache        = $has_config ? (array) get_option( 'gwp_connection_cache', [] ) : [];
		$connection       = [
			'github' => isset( $raw_cache['github'] ) ? $raw_cache['github'] : null,
			'gitlab' => isset( $raw_cache['gitlab'] ) ? $raw_cache['gitlab'] : null,
		];
		$installed        = REST::get_installed();
		$first_activation = (bool) get_transient( 'gwp_first_activation' );

		if ( $first_activation ) {
			delete_transient( 'gwp_first_activation' );
		}

		// Derive initial tab from path param, activation state, or setup status.
		$path = sanitize_key( $_GET['path'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $first_activation || ! $has_config ) {
			$initial_tab = 'settings';
		} elseif ( in_array( $path, [ 'browse', 'settings' ], true ) ) {
			$initial_tab = $path;
		} else {
			$initial_tab = 'installed';
		}

		wp_add_inline_script(
			'gwp-app',
			'window.GWP = ' . wp_json_encode(
				[
					'nonce'            => wp_create_nonce( 'wp_rest' ),
					'icon_url'         => GWP_URL . 'assets/images/icon.svg',
					'disconnected_url' => GWP_URL . 'assets/images/disconnected.svg',
					'initial_tab'      => $initial_tab,
					'settings'         => [
						'username'      => $settings['username'] ?? '',
						'token'         => $settings['token'] ?? '',
						'smart_install' => $settings['smart_install'] ?? true,
						'gitlab_token'  => $settings['gitlab_token'] ?? '',
						'gitlab_url'    => $settings['gitlab_url'] ?? '',
					],
					'connection'       => $connection,
					'installed'        => $installed ? $installed : (object) [],
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'git' ) );
		}
		require_once GWP_DIR . 'views/admin-page.php';
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
		$notice = get_option( 'gwp_fatal_notice' );
		if ( ! $notice ) {
			return;
		}
		delete_option( 'gwp_fatal_notice' );

		$name     = esc_html( $notice['full_name'] ?? 'Unknown' );
		$restored = ! empty( $notice['restored'] );

		if ( $restored ) {
			/* translators: %s: Plugin or theme full name. */
			$msg = sprintf( __( '<strong>Git:</strong> A fatal PHP error was detected after updating <em>%s</em>. The previous version has been automatically restored and the plugin deactivated.', 'git' ), $name );
		} else {
			/* translators: %s: Plugin or theme full name. */
			$msg = sprintf( __( '<strong>Git:</strong> A fatal PHP error was detected after installing <em>%s</em>. The broken files have been removed.', 'git' ), $name );
		}

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p><details><summary>%s</summary><pre>%s</pre></details></div>',
			wp_kses_post( $msg ),
			esc_html__( 'Error details', 'git' ),
			esc_html( $notice['error'] ?? '' )
		);
	}
}
