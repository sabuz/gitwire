/**
 * Extends the @wordpress/scripts webpack config.
 *
 * Enable license-comment extraction for bundled dependencies. The sonner
 * dependency is MIT-licensed and requires its notice to ship with the bundle.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

defaultConfig.optimization.minimizer.forEach( ( plugin ) => {
	if ( plugin.options && 'extractComments' in plugin.options ) {
		plugin.options.extractComments = true;
	}
} );

module.exports = defaultConfig;
