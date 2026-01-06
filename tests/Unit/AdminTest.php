<?php
/**
 * Tests for the Admin class.
 *
 * @package AI_Importer\Tests\Unit
 */

namespace AI_Importer\Tests\Unit;

use AI_Importer\Admin;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

/**
 * Admin class tests.
 */
class AdminTest extends TestCase {

	/**
	 * Test that init registers admin_menu action.
	 *
	 * @return void
	 */
	public function test_init_registers_admin_menu_action(): void {
		Actions\expectAdded( 'admin_menu' );
		Actions\expectAdded( 'admin_enqueue_scripts' );

		$admin = new Admin();
		$admin->init();

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test that register_menu adds menu page.
	 *
	 * @return void
	 */
	public function test_register_menu_adds_menu_page(): void {
		Functions\expect( 'add_menu_page' )
			->once()
			->with(
				'AI Importer',
				'AI Importer',
				'manage_options',
				'ai-importer',
				\Mockery::type( 'array' ),
				'dashicons-download',
				30
			)
			->andReturn( 'toplevel_page_ai-importer' );

		Functions\expect( 'add_submenu_page' )->times( 5 );

		$admin = new Admin();
		$admin->register_menu();

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test that enqueue_scripts does not enqueue on non-plugin pages.
	 *
	 * @return void
	 */
	public function test_enqueue_scripts_skips_non_plugin_pages(): void {
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();

		$admin = new Admin();
		$admin->enqueue_scripts( 'edit.php' );

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test that enqueue_scripts loads assets on plugin pages.
	 *
	 * @return void
	 */
	public function test_enqueue_scripts_loads_on_plugin_pages(): void {
		// Mock file_exists to return true for the asset file.
		Functions\when( 'file_exists' )->justReturn( true );

		// Mock WordPress functions for asset loading.
		Functions\expect( 'wp_enqueue_script' )->once();
		Functions\expect( 'wp_enqueue_style' )->once();
		Functions\expect( 'wp_localize_script' )->once();
		Functions\when( 'rest_url' )->justReturn( 'https://example.com/wp-json/ai-importer/v1/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );

		$admin = new Admin();
		$admin->enqueue_scripts( 'toplevel_page_ai-importer' );

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test that render_page outputs the root div.
	 *
	 * @return void
	 */
	public function test_render_page_outputs_root_div(): void {
		$admin = new Admin();

		ob_start();
		$admin->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="ai-importer-root"', $output );
		$this->assertStringContainsString( 'class="wrap"', $output );
	}
}
