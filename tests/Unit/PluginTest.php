<?php
/**
 * Tests for the Plugin class.
 *
 * @package AI_Importer\Tests\Unit
 */

namespace AI_Importer\Tests\Unit;

use AI_Importer\Plugin;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

/**
 * Plugin class tests.
 */
class PluginTest extends TestCase {

	/**
	 * Reset the singleton instance before each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		// Reset the singleton instance using reflection.
		$reflection = new \ReflectionClass( Plugin::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * Test that get_instance returns a Plugin instance.
	 *
	 * @return void
	 */
	public function test_get_instance_returns_plugin(): void {
		$plugin = Plugin::get_instance();

		$this->assertInstanceOf( Plugin::class, $plugin );
	}

	/**
	 * Test that get_instance returns the same instance.
	 *
	 * @return void
	 */
	public function test_get_instance_returns_singleton(): void {
		$plugin1 = Plugin::get_instance();
		$plugin2 = Plugin::get_instance();

		$this->assertSame( $plugin1, $plugin2 );
	}

	/**
	 * Test that init registers rest_api_init action.
	 *
	 * @return void
	 */
	public function test_init_registers_rest_api_action(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		Actions\expectAdded( 'rest_api_init' );

		$plugin = Plugin::get_instance();
		$plugin->init();

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test that init creates Admin instance when in admin.
	 *
	 * @return void
	 */
	public function test_init_creates_admin_when_is_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		// Mock the action hooks that Admin::init() registers.
		Actions\expectAdded( 'admin_menu' );
		Actions\expectAdded( 'admin_enqueue_scripts' );
		Actions\expectAdded( 'rest_api_init' );

		$plugin = Plugin::get_instance();
		$plugin->init();

		$this->assertNotNull( $plugin->get_admin() );
	}

	/**
	 * Test that get_admin returns null before init.
	 *
	 * @return void
	 */
	public function test_get_admin_returns_null_before_init(): void {
		$plugin = Plugin::get_instance();

		$this->assertNull( $plugin->get_admin() );
	}
}
