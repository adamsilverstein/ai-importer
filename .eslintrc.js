/**
 * ESLint configuration for AI Importer.
 *
 * Extends WordPress JavaScript coding standards.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-eslint-plugin/
 */
module.exports = {
	root: true,
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	env: {
		browser: true,
		node: true,
	},
	globals: {
		wp: 'readonly',
		jQuery: 'readonly',
		aiImporter: 'readonly',
	},
	settings: {
		'import/resolver': {
			node: {
				extensions: [ '.js', '.jsx', '.ts', '.tsx' ],
			},
		},
	},
	rules: {
		// Allow console for development debugging
		'no-console': process.env.NODE_ENV === 'production' ? 'error' : 'warn',
		// Enforce JSDoc comments for functions
		'jsdoc/require-jsdoc': [
			'warn',
			{
				require: {
					FunctionDeclaration: true,
					MethodDefinition: true,
					ClassDeclaration: true,
				},
			},
		],
	},
	overrides: [
		{
			// Test files
			files: [ '**/*.test.js', '**/*.spec.js', '**/test/**/*.js' ],
			env: {
				jest: true,
			},
		},
	],
};
