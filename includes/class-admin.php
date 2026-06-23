<?php
/**
 * Admin class — registers menus, enqueues assets, and renders the admin page.
 *
 * @package Gitwire
 * @since 1.0.0
 */

namespace Gitwire;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all WordPress admin integration: menu pages, asset enqueueing,
 * and page rendering.
 */
class Admin {

	/**
	 * WordPress admin page slug.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'gitwire';

	/**
	 * Registers all admin hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_filter( 'admin_body_class', [ self::class, 'body_class' ] );
		add_action( 'admin_head', [ self::class, 'hide_admin_notices' ], 999 );

		// Native list repo labels.
		add_filter( 'plugin_action_links', [ self::class, 'label_managed_plugins' ], 10, 2 );
		add_filter( 'wp_prepare_themes_for_js', [ self::class, 'label_managed_themes' ] );

		// Settings link in the plugins list table.
		add_filter( 'plugin_action_links_' . GITWIRE_BASENAME, [ self::class, 'add_settings_link' ] );
	}

	/**
	 * Removes admin header notices on Gitwire pages to keep the UI clean.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function hide_admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! is_string( $screen->id ) ) {
			return;
		}

		if ( false === strpos( $screen->id, '_page_gitwire' ) ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'admin_footer_text' );
	}

	/**
	 * Appends a CSS class to the body element on Gitwire admin pages.
	 *
	 * @since 1.0.0
	 * @param string $classes Space-separated list of body classes.
	 * @return string Modified body class string.
	 */
	public static function body_class( string $classes ): string {
		$screen = get_current_screen();
		if ( $screen && false !== strpos( $screen->id, '_page_gitwire' ) ) {
			$classes .= ' gitwire-admin-page';
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
		$icon_path = GITWIRE_DIR . 'assets/images/icon.svg';
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
			__( 'Gitwire', 'gitwire' ),
			__( 'Gitwire', 'gitwire' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ],
			$menu_icon,
			65
		);

		// First submenu replaces the auto-generated duplicate of the parent.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Repositories', 'gitwire' ),
			__( 'Repositories', 'gitwire' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ],
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Add Repository', 'gitwire' ),
			__( 'Add Repository', 'gitwire' ),
			'manage_options',
			self::PAGE_SLUG . '&path=add-repository',
			[ self::class, 'render_page' ],
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Settings', 'gitwire' ),
			__( 'Settings', 'gitwire' ),
			'manage_options',
			self::PAGE_SLUG . '&path=settings',
			[ self::class, 'render_page' ],
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Tools', 'gitwire' ),
			__( 'Tools', 'gitwire' ),
			'manage_options',
			self::PAGE_SLUG . '&path=tools',
			[ self::class, 'render_page' ],
		);

		if ( Settings::is_logging_enabled() ) {
			add_submenu_page(
				self::PAGE_SLUG,
				__( 'Logs', 'gitwire' ),
				__( 'Logs', 'gitwire' ),
				'manage_options',
				self::PAGE_SLUG . '&path=logs',
				[ self::class, 'render_page' ],
			);
		}
	}

	/**
	 * Enqueues admin scripts and styles for Gitwire pages.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		// Match toplevel_page_gitwire and gitwire_page_gitwire-{browse,settings}.
		if ( false === strpos( $hook, '_page_gitwire' ) ) {
			return;
		}

		$asset_file = GITWIRE_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : [
			'dependencies' => [],
			'version'      => GITWIRE_VERSION,
		];

		// wp-scripts outputs styles imported in JS to style-index.css.
		$css_file = file_exists( GITWIRE_DIR . 'build/index.css' )
			? GITWIRE_URL . 'build/index.css'
			: GITWIRE_URL . 'build/style-index.css';

		wp_enqueue_style(
			'gitwire-app',
			$css_file,
			[ 'wp-components' ],
			$asset['version']
		);

		wp_enqueue_script(
			'gitwire-app',
			GITWIRE_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'gitwire-app', 'gitwire', GITWIRE_DIR . 'languages' );

		$settings = Settings::get_public();
		Error_Handler::clear_stale_activation_guard();
		$pending_msg = get_option( 'gitwire_pending_message' );
		if ( $pending_msg ) {
			delete_option( 'gitwire_pending_message' );
		}
		// Boot data uses only DB records — orphan detection runs via REST on app init.
		$installed = REST_Installer::annotate_installed( Installer::get_installed() );
		$orphaned  = [];

		// Derive initial tab from path param or setup status.
		$path = sanitize_key( $_GET['path'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'settings' === $path ) {
			$initial_tab = 'settings';
		} elseif ( 'logs' === $path ) {
			$initial_tab = 'logs';
		} elseif ( in_array( $path, [ 'add-repository', 'browse' ], true ) ) {
			$initial_tab = 'add-repository';
		} else {
			$initial_tab = 'repositories';
		}

		wp_add_inline_script(
			'gitwire-app',
			'window.gitwire = ' . wp_json_encode(
				[
					'nonce'                => wp_create_nonce( 'wp_rest' ),
					'public_connections'   => Public_Connections::all(),
					'connections_metadata' => REST_Connection_Cache::get_connection_cache(),
					'icon_url'             => GITWIRE_URL . 'assets/images/icon.svg',
					'disconnected_url'     => GITWIRE_URL . 'assets/images/cloud-alert.svg',
					'not_found_url'        => GITWIRE_URL . 'assets/images/folder-x.svg',
					'themes_url'           => admin_url( 'themes.php' ),
					'initial_tab'          => $initial_tab,
					'settings'             => $settings,
					'installed'            => $installed ? $installed : (object) [],
					'orphaned'             => $orphaned,
					'pending_msg'          => $pending_msg ?: null,
				]
			) . ';',
			'before'
		);

		/**
		 * Fires after the Gitwire admin app assets are enqueued.
		 *
		 * Gitwire Pro enqueues its bundle here with 'gitwire-app' as a dependency.
		 *
		 * @since 1.0.0
		 */
		do_action( 'gitwire_enqueue_assets' );
	}

	/**
	 * Renders the admin page view.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gitwire' ) );
		}
		require_once GITWIRE_DIR . 'views/admin-page.php';
	}

	/**
	 * Adds a Settings link to the Gitwire row in the plugins list table.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $actions Existing action links.
	 * @return array<string, string>
	 */
	public static function add_settings_link( array $actions ): array {
		$url                 = add_query_arg( 'page', 'gitwire', admin_url( 'admin.php' ) );
		$actions['settings'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'gitwire' ) . '</a>';
		return $actions;
	}

	/**
	 * Adds a [Gitwire] badge to managed plugin action links in the plugins list table.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $actions     Existing action links for the plugin.
	 * @param string                $plugin_file Plugin file path relative to wp-content/plugins.
	 * @return array<string, string>
	 */
	public static function label_managed_plugins( array $actions, string $plugin_file ): array {
		if ( ! ( Settings::get_raw()['show_repo_label'] ?? true ) ) {
			return $actions;
		}

		foreach ( Installer::get_installed() as $rec ) {
			if ( ( $rec['plugin_file'] ?? '' ) === $plugin_file ) {
				$actions['gitwire-badge'] = '<span style="color:#666">[Gitwire]</span>';
				break;
			}
		}

		return $actions;
	}

	/**
	 * Appends a [Gitwire] label to managed theme names in the themes browser.
	 *
	 * @since 1.0.0
	 * @param array<string, array<string, mixed>> $prepared Themes data prepared for JS.
	 * @return array<string, array<string, mixed>>
	 */
	public static function label_managed_themes( array $prepared ): array {
		if ( ! ( Settings::get_raw()['show_repo_label'] ?? true ) ) {
			return $prepared;
		}

		$slugs = [];
		foreach ( Installer::get_installed() as $rec ) {
			if ( 'theme' === ( $rec['type'] ?? '' ) && '' !== ( $rec['slug'] ?? '' ) ) {
				$slugs[ $rec['slug'] ] = true;
			}
		}

		foreach ( $prepared as $slug => &$data ) {
			if ( isset( $slugs[ $slug ] ) ) {
				$data['name'] .= ' [Gitwire]';
			}
		}
		unset( $data );

		return $prepared;
	}
}
