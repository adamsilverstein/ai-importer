/**
 * E2E tests for the AI Importer admin page.
 *
 * These tests require a running WordPress installation with the plugin activated.
 *
 * @see https://playwright.dev/docs/writing-tests
 */

const { test, expect } = require( '@playwright/test' );

/**
 * WordPress admin credentials.
 * Set via environment variables for security.
 */
const WP_ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const WP_ADMIN_PASS = process.env.WP_ADMIN_PASS || 'password';

test.describe( 'AI Importer Admin Page', () => {
	test.beforeEach( async ( { page } ) => {
		// Log in to WordPress admin.
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', WP_ADMIN_USER );
		await page.fill( '#user_pass', WP_ADMIN_PASS );
		await page.click( '#wp-submit' );

		// Wait for login to complete.
		await page.waitForURL( /\/wp-admin\// );
	} );

	test( 'should display the AI Importer menu item', async ( { page } ) => {
		// Navigate to the admin dashboard.
		await page.goto( '/wp-admin/' );

		// Look for the AI Importer menu item.
		const menuItem = page
			.locator( '#adminmenu a[href*="ai-importer"]' )
			.first();
		await expect( menuItem ).toBeVisible();
		await expect( menuItem ).toHaveText( /AI Importer/i );
	} );

	test( 'should load the AI Importer dashboard page', async ( { page } ) => {
		// Navigate to the AI Importer page.
		await page.goto( '/wp-admin/admin.php?page=ai-importer' );

		// Check that the React root element is present.
		const rootElement = page.locator( '#ai-importer-root' );
		await expect( rootElement ).toBeVisible();
	} );

	test( 'should navigate to submenu pages', async ( { page } ) => {
		// Test Import submenu page.
		await page.goto( '/wp-admin/admin.php?page=ai-importer-import' );
		const rootElement = page.locator( '#ai-importer-root' );
		await expect( rootElement ).toBeVisible();
	} );
} );
