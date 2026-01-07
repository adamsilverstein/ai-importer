<?php
/**
 * Base test case class for unit tests.
 *
 * @package AI_Importer\Tests\Unit
 */

namespace AI_Importer\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Base test case with Brain Monkey integration.
 */
abstract class TestCase extends PolyfillTestCase {

	/**
	 * Set up Brain Monkey before each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();

		// Mock common WordPress functions.
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	/**
	 * Tear down Brain Monkey after each test.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}

	/**
	 * Assert that all Brain Monkey expectations were met.
	 *
	 * Use this in tests that only verify Brain Monkey expectations.
	 *
	 * @return void
	 */
	protected function assertBrainMonkeyExpectations(): void {
		// This is a workaround for PHPUnit's "risky" test detection.
		// Brain Monkey verifies its expectations in tear_down, but PHPUnit
		// wants to see at least one assertion per test.
		$this->assertTrue( true );
	}
}
