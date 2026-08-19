const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

/*
 * @wordpress/theme only became a core script handle in WordPress 7.0, but
 * @wordpress/dataviews reaches it through @wordpress/ui. Left externalized, the
 * built asset.php declares a wp-theme dependency that core cannot resolve below
 * 7.0, and WP_Dependencies::all_deps() then silently refuses to print the whole
 * admin bundle. Bundling it keeps the real floor at what the rest of the bundle
 * needs. @wordpress/ui itself is already bundled for the same reason.
 */
module.exports = {
	...defaultConfig,
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !==
				'DependencyExtractionWebpackPlugin'
		),
		new DependencyExtractionWebpackPlugin( {
			requestToExternal( request ) {
				if ( '@wordpress/theme' === request ) {
					return false;
				}
			},
		} ),
	],
};
