/**
 * External dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

/**
 * WordPress dependencies
 */
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

/**
 * Custom webpack configuration for AI Importer.
 *
 * Extends the default @wordpress/scripts configuration with:
 * - Custom entry points for different admin screens
 * - Production optimizations
 * - Source map configuration
 */
module.exports = {
	...defaultConfig,
	entry: {
		// Main admin interface
		index: path.resolve( __dirname, 'src/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
		filename: '[name].js',
		clean: true,
	},
	optimization: {
		...defaultConfig.optimization,
		// Split vendor chunks for better caching
		splitChunks: {
			cacheGroups: {
				vendor: {
					test: /[\\/]node_modules[\\/]/,
					name: 'vendors',
					chunks: ( chunk ) => chunk.name === 'index',
				},
			},
		},
	},
	// Enable source maps in development
	devtool: process.env.NODE_ENV === 'production' ? false : 'source-map',
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new DependencyExtractionWebpackPlugin( {
			injectPolyfill: false,
			combineAssets: false,
		} ),
	],
};
