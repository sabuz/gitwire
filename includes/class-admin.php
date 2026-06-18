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
		add_filter( 'all_plugins', [ self::class, 'label_managed_plugins' ] );
		add_filter( 'wp_prepare_themes_for_js', [ self::class, 'label_managed_themes' ] );
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
		$installed_result = REST::get_installed();
		$installed        = $installed_result['installed'];
		$orphaned         = $installed_result['orphaned'];
		$fatal_notice     = get_option( 'gitwire_fatal_notice' );
		if ( $fatal_notice ) {
			delete_option( 'gitwire_fatal_notice' );
		}
		$update_success = get_option( 'gitwire_update_success' );
		if ( $update_success ) {
			delete_option( 'gitwire_update_success' );
		}

		$activation_success = get_option( 'gitwire_activation_success' );
		if ( $activation_success ) {
			delete_option( 'gitwire_activation_success' );
		}

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
					'nonce'              => wp_create_nonce( 'wp_rest' ),
					'public_connections' => Public_Connections::all(),
					'connections_metadata' => REST::get_connection_cache(),
					'icon_url'           => GITWIRE_URL . 'assets/images/icon.svg',
					'disconnected_url'   => GITWIRE_URL . 'assets/images/cloud-alert.svg',
					'not_found_url'      => GITWIRE_URL . 'assets/images/folder-x.svg',
					'themes_url'         => admin_url( 'themes.php' ),
					'initial_tab'        => $initial_tab,
					'settings'           => $settings,
					'installed'          => $installed ? $installed : (object) [],
					'orphaned'           => $orphaned,
					'fatal_notice'       => $fatal_notice ? $fatal_notice : null,
					'update_success'     => $update_success ? $update_success : null,
					'activation_success' => $activation_success ? $activation_success : null,
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
	 * Appends a [Gitwire] label to managed plugin names in the plugins list table.
	 *
	 * @since 1.0.0
	 * @param array<string, array<string, string>> $all_plugins All installed plugins keyed by plugin file.
	 * @return array<string, array<string, string>>
	 */
	public static function label_managed_plugins( array $all_plugins ): array {
		if ( ! ( Settings::get_raw()['show_repo_label'] ?? true ) ) {
			return $all_plugins;
		}

		foreach ( Installer::get_installed() as $rec ) {
			$file = $rec['plugin_file'] ?? '';
			if ( '' === $file || ! isset( $all_plugins[ $file ] ) ) {
				continue;
			}
			$all_plugins[ $file ]['Name'] .= ' [Gitwire]';
		}

		return $all_plugins;
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
