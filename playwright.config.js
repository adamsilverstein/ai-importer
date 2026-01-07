/**
 * Playwright configuration for E2E tests.
 *
 * @see https://playwright.dev/docs/test-configuration
 */

const { defineConfig, devices } = require( '@playwright/test' );

/**
 * WordPress test environment configuration.
 * Set WP_BASE_URL environment variable to your WordPress test site URL.
 *
 * @example WP_BASE_URL=http://localhost:8889 npm run test:e2e
 */
const baseURL = process.env.WP_BASE_URL || 'http://localhost:8889';

module.exports = defineConfig( {
	testDir: './tests/e2e',
	outputDir: './tests/e2e/test-results',

	/* Run tests in files in parallel */
	fullyParallel: true,

	/* Fail the build on CI if you accidentally left test.only in the source code */
	forbidOnly: !! process.env.CI,

	/* Retry on CI only */
	retries: process.env.CI ? 2 : 0,

	/* Opt out of parallel tests on CI */
	workers: process.env.CI ? 1 : undefined,

	/* Reporter to use */
	reporter: [
		[ 'list' ],
		[
			'html',
			{ outputFolder: './tests/e2e/playwright-report', open: 'never' },
		],
	],

	/* Shared settings for all the projects below */
	use: {
		baseURL,

		/* Collect trace when retrying the failed test */
		trace: 'on-first-retry',

		/* Take screenshot on failure */
		screenshot: 'only-on-failure',
	},

	/* Configure projects for major browsers */
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
