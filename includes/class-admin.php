<?php
/**
 * Admin class: registers menus, enqueues assets, and renders the admin page.
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
	 * Screen IDs WordPress generates for this plugin's pages. The '-network'
	 * variants are what WP_Screen produces when the menu is registered via
	 * network_admin_menu (multisite) instead of admin_menu.
	 *
	 * @var string[]
	 */
	private const SCREEN_IDS = [
		'toplevel_page_gitwire',
		'gitwire_page_gitwire',
		'toplevel_page_gitwire-network',
		'gitwire_page_gitwire-network',
	];

	/**
	 * Registers all admin hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init(): void {
		// Register the menu in Network Admin on multisite because plugins and themes are shared.
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_filter( 'admin_body_class', [ self::class, 'body_class' ] );
		add_action( 'admin_head', [ self::class, 'hide_admin_notices' ], 999 );
		add_action( 'admin_head', [ self::class, 'hide_footer_text' ], 999 );
		// The sidebar renders on every screen, not just ours, so this can't be gated to is_gitwire_screen().
		add_action( 'admin_head', [ self::class, 'print_menu_icon_styles' ] );

		// Add labels to native plugin and theme lists.
		add_filter( 'all_plugins', [ self::class, 'label_managed_plugins' ] );
		add_filter( 'wp_prepare_themes_for_js', [ self::class, 'label_managed_themes' ] );

		// Add action links on both the site Plugins screen and Network Admin.
		$link_filter = is_multisite() ? 'network_admin_plugin_action_links_' : 'plugin_action_links_';
		add_filter( $link_filter . GITWIRE_BASENAME, [ self::class, 'add_plugin_action_links' ] );
	}

	/**
	 * Capability required to access Gitwire. Plugins/themes are shared across a
	 * multisite network, so only a Super Admin may install or update them there.
	 * 'manage_network_plugins' is only ever granted to Super Admins (see
	 * WP_User::has_cap()), unlike 'manage_options' which every site Administrator
	 * has, including on subsites that never boot Gitwire at all.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function required_cap(): string {
		return is_multisite() ? 'manage_network_plugins' : 'manage_options';
	}

	/**
	 * Builds an admin URL for the Gitwire page, routed through Network Admin on
	 * multisite since that's the only place the menu is registered.
	 *
	 * @since 1.0.0
	 * @param string $path Admin-relative path, e.g. 'admin.php'.
	 * @return string
	 */
	private static function admin_url_for( string $path ): string {
		return is_multisite() ? network_admin_url( $path ) : admin_url( $path );
	}

	/**
	 * Removes admin header notices on Gitwire pages to keep the UI clean.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function hide_admin_notices(): void {
		if ( ! self::is_gitwire_screen() ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
	}

	/**
	 * Removes the "Thank you for creating with WordPress" text and version number
	 * from the admin footer on Gitwire pages. Both defaults are baked into
	 * admin-footer.php's own apply_filters() call, so they have to be overridden
	 * rather than unhooked.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function hide_footer_text(): void {
		if ( ! self::is_gitwire_screen() ) {
			return;
		}

		// Priority 999 wins without unregistering other plugins' callbacks on this screen.
		add_filter( 'admin_footer_text', '__return_empty_string', 999 );
		add_filter( 'update_footer', '__return_empty_string', 999 );
	}

	/**
	 * Returns whether the current screen is one of ours.
	 *
	 * Exact match rather than a substring: '_page_gitwire' also matches any other
	 * plugin whose page slug happens to start with gitwire.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private static function is_gitwire_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen && in_array( $screen->id, self::SCREEN_IDS, true );
	}

	/**
	 * Appends a CSS class to the body element on Gitwire admin pages.
	 *
	 * @since 1.0.0
	 * @param string $classes Space-separated list of body classes.
	 * @return string Modified body class string.
	 */
	public static function body_class( string $classes ): string {
		if ( self::is_gitwire_screen() ) {
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
		$menu_icon = self::menu_icon();
		$cap       = self::required_cap();

		add_menu_page(
			__( 'Gitwire', 'gitwire' ),
			__( 'Gitwire', 'gitwire' ),
			$cap,
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
			$cap,
			self::PAGE_SLUG,
			[ self::class, 'render_page' ],
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Add Repository', 'gitwire' ),
			__( 'Add Repository', 'gitwire' ),
			$cap,
			self::PAGE_SLUG . '&path=add-repository',
			[ self::class, 'render_page' ],
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Settings', 'gitwire' ),
			__( 'Settings', 'gitwire' ),
			$cap,
			self::PAGE_SLUG . '&path=settings',
			[ self::class, 'render_page' ],
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Tools', 'gitwire' ),
			__( 'Tools', 'gitwire' ),
			$cap,
			self::PAGE_SLUG . '&path=tools',
			[ self::class, 'render_page' ],
		);

		if ( Settings::is_logging_enabled() ) {
			add_submenu_page(
				self::PAGE_SLUG,
				__( 'Logs', 'gitwire' ),
				__( 'Logs', 'gitwire' ),
				$cap,
				self::PAGE_SLUG . '&path=logs',
				[ self::class, 'render_page' ],
			);
		}
	}

	/**
	 * Returns the sidebar icon as a plain file URL.
	 *
	 * A URL rather than a base64 data URI, and that is the whole point.
	 * wp-admin/js/svg-painter.js collects menu icons whose background-image is a
	 * data URI, rewrites every fill in them to one colour scheme value, and
	 * writes the result back. On the plugin's own screens it repaints with the
	 * scheme's `current` colour, which differs from whatever is baked into the
	 * file, so the background is swapped after load and the icon visibly blinks.
	 * Dashicons are font glyphs and never do this, which makes ours the odd one
	 * out on the screen.
	 *
	 * findElements() only looks at background-image, so a URL is skipped
	 * entirely: WordPress emits an <img> and the icon is never touched by that
	 * script. Core's own CSS still applies to it, and it centres and dims a
	 * generic image icon on assumptions this artwork doesn't meet, which
	 * print_menu_icon_styles() corrects.
	 *
	 * Baking a colour-scheme-aware fill in instead is not possible here. It
	 * would need the scheme's icon colours, and register_admin_color_schemes()
	 * runs on admin_init, after admin_menu has already registered this.
	 *
	 * The version query busts the browser cache when the artwork changes. No
	 * transient: there is nothing left to read or encode.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function menu_icon(): string {
		if ( ! file_exists( GITWIRE_DIR . 'assets/images/menu-icon.svg' ) ) {
			return 'none';
		}

		return GITWIRE_URL . 'assets/images/menu-icon.svg?ver=' . GITWIRE_VERSION;
	}

	/**
	 * Corrects core's generic image-icon treatment for our menu item.
	 *
	 * #adminmenu .wp-menu-image img carries `padding: 9px 0 0` with no matching
	 * bottom value, in a 34px-tall box. That only centres a 16px icon; anything
	 * else sits off-centre. It also carries `opacity: .6`, which the background-
	 * image path this replaced never had, since that path painted an already-
	 * dim colour at full strength instead of dimming a bright one. Both need
	 * overriding for a plain 20x20 <img> to look like the rest of the menu.
	 *
	 * Runs on every admin screen, network admin included, because the sidebar
	 * does; it can't be gated to is_gitwire_screen().
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function print_menu_icon_styles(): void {
		?>
		<style>
			#adminmenu #toplevel_page_gitwire .wp-menu-image img,
			#adminmenu #toplevel_page_gitwire-network .wp-menu-image img {
				padding: 7px 0 0;
				opacity: 1;
			}
		</style>
		<?php
	}

	/**
	 * Enqueues admin scripts and styles for Gitwire pages.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( ! in_array( $hook, self::SCREEN_IDS, true ) ) {
			return;
		}

		$asset_file = GITWIRE_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : [
			'dependencies' => [],
			'version'      => GITWIRE_VERSION,
		];

		// wp-scripts writes styles imported by JavaScript to style-index.css.
		$css_file = file_exists( GITWIRE_DIR . 'build/index.css' )
			? GITWIRE_URL . 'build/index.css'
			: GITWIRE_URL . 'build/style-index.css';

		wp_enqueue_style(
			'gitwire-app',
			$css_file,
			[ 'wp-components' ],
			$asset['version']
		);

		// wp-scripts writes style-index-rtl.css beside the left-to-right build.
		wp_style_add_data( 'gitwire-app', 'rtl', 'replace' );

		wp_enqueue_script(
			'gitwire-app',
			GITWIRE_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// No path argument: WordPress.org serves the JSON language packs itself.
		wp_set_script_translations( 'gitwire-app', 'gitwire' );

		$settings = Settings::get_public();
		Error_Handler::clear_stale_activation_guard();
		$pending_msg = get_option( 'gitwire_pending_message' );
		if ( $pending_msg ) {
			delete_option( 'gitwire_pending_message' );
		}
		// Boot data uses database records; REST detects orphans when the app starts.
		$installed = REST_Installer::annotate_installed( Installer::get_installed() );

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
					'file_mods_allowed'    => Installer::file_mods_allowed(),
					'public_connections'   => Public_Connections::all(),
					'connections_metadata' => Connection_Meta::get_connection_cache(),
					'disconnected_url'     => GITWIRE_URL . 'assets/images/disconnected.svg',
					'not_found_url'        => GITWIRE_URL . 'assets/images/not-found.svg',
					'themes_url'           => self::admin_url_for( 'themes.php' ),
					'initial_tab'          => $initial_tab,
					'settings'             => $settings,
					'installed'            => $installed ? $installed : (object) [],
					'pending_msg'          => $pending_msg ? $pending_msg : null,
				],
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
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
		if ( ! current_user_can( self::required_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gitwire' ) );
		}
		require_once GITWIRE_DIR . 'views/admin-page.php';
	}

	/**
	 * Adds Repositories and Settings links to the Gitwire row in the plugins list table.
	 *
	 * @since 1.0.0
	 * @param array<string, string> $actions Existing action links.
	 * @return array<string, string>
	 */
	public static function add_plugin_action_links( array $actions ): array {
		$repositories_url = add_query_arg( 'page', 'gitwire', self::admin_url_for( 'admin.php' ) );
		$settings_url     = add_query_arg(
			[
				'page' => 'gitwire',
				'path' => 'settings',
			],
			self::admin_url_for( 'admin.php' )
		);

		return array_merge(
			[
				'repositories' => '<a href="' . esc_url( $repositories_url ) . '">' . esc_html__( 'Repositories', 'gitwire' ) . '</a>',
				'settings'     => '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'gitwire' ) . '</a>',
			],
			$actions
		);
	}

	/**
	 * Appends a [Gitwire] label to managed plugin names in the plugins list table.
	 *
	 * @since 1.0.0
	 * @param array<string, array<string, string>> $all_plugins All installed plugins keyed by plugin file.
	 * @return array<string, array<string, string>>
	 */
	public static function label_managed_plugins( array $all_plugins ): array {
		global $pagenow;
		if ( 'plugins.php' !== $pagenow ) {
			return $all_plugins;
		}
		if ( ! ( Settings::get_raw()['show_repo_label'] ?? true ) ) {
			return $all_plugins;
		}

		foreach ( Installer::get_installed() as $rec ) {
			$file = $rec['basename'] ?? '';
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
			if ( in_array( $rec['type'] ?? '', [ 'theme', 'block-theme', 'classic-theme' ], true ) && '' !== ( $rec['name'] ?? '' ) ) {
				$slugs[ $rec['name'] ] = true;
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
