/**
 * Extends the @wordpress/scripts webpack config.
 *
 * wp-scripts sets Terser's extractComments to false, which strips the license
 * banners out of the bundle. One bundled dependency (sonner) is MIT, and MIT
 * requires its notice to travel with the code, so comment extraction is turned
 * back on and the emitted build/index.js.LICENSE.txt ships with the plugin.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

defaultConfig.optimization.minimizer.forEach( ( plugin ) => {
	if ( plugin.options && 'extractComments' in plugin.options ) {
		plugin.options.extractComments = true;
	}
} );

module.exports = defaultConfig;
