<?php
/**
 * ImportState enum tests.
 *
 * @package AI_Importer\Tests\Unit\Processor
 */

namespace AI_Importer\Tests\Unit\Processor;

use AI_Importer\Processor\ImportState;
use AI_Importer\Tests\Unit\TestCase;

/**
 * Tests for the ImportState enum.
 */
class ImportStateTest extends TestCase {

	/**
	 * Test pending can transition to processing.
	 *
	 * @return void
	 */
	public function test_pending_can_transition_to_processing(): void {
		$this->assertTrue( ImportState::PENDING->can_transition_to( ImportState::PROCESSING ) );
	}

	/**
	 * Test pending can transition to failed.
	 *
	 * @return void
	 */
	public function test_pending_can_transition_to_failed(): void {
		$this->assertTrue( ImportState::PENDING->can_transition_to( ImportState::FAILED ) );
	}

	/**
	 * Test pending cannot transition to completed.
	 *
	 * @return void
	 */
	public function test_pending_cannot_transition_to_completed(): void {
		$this->assertFalse( ImportState::PENDING->can_transition_to( ImportState::COMPLETED ) );
	}

	/**
	 * Test processing can transition to paused.
	 *
	 * @return void
	 */
	public function test_processing_can_transition_to_paused(): void {
		$this->assertTrue( ImportState::PROCESSING->can_transition_to( ImportState::PAUSED ) );
	}

	/**
	 * Test processing can transition to completed.
	 *
	 * @return void
	 */
	public function test_processing_can_transition_to_completed(): void {
		$this->assertTrue( ImportState::PROCESSING->can_transition_to( ImportState::COMPLETED ) );
	}

	/**
	 * Test processing can transition to failed.
	 *
	 * @return void
	 */
	public function test_processing_can_transition_to_failed(): void {
		$this->assertTrue( ImportState::PROCESSING->can_transition_to( ImportState::FAILED ) );
	}

	/**
	 * Test paused can transition to processing.
	 *
	 * @return void
	 */
	public function test_paused_can_transition_to_processing(): void {
		$this->assertTrue( ImportState::PAUSED->can_transition_to( ImportState::PROCESSING ) );
	}

	/**
	 * Test completed can transition to rolled back.
	 *
	 * @return void
	 */
	public function test_completed_can_transition_to_rolled_back(): void {
		$this->assertTrue( ImportState::COMPLETED->can_transition_to( ImportState::ROLLED_BACK ) );
	}

	/**
	 * Test completed cannot transition to processing.
	 *
	 * @return void
	 */
	public function test_completed_cannot_transition_to_processing(): void {
		$this->assertFalse( ImportState::COMPLETED->can_transition_to( ImportState::PROCESSING ) );
	}

	/**
	 * Test failed can transition to processing (retry).
	 *
	 * @return void
	 */
	public function test_failed_can_transition_to_processing(): void {
		$this->assertTrue( ImportState::FAILED->can_transition_to( ImportState::PROCESSING ) );
	}

	/**
	 * Test failed can transition to rolled back.
	 *
	 * @return void
	 */
	public function test_failed_can_transition_to_rolled_back(): void {
		$this->assertTrue( ImportState::FAILED->can_transition_to( ImportState::ROLLED_BACK ) );
	}

	/**
	 * Test rolled back is terminal.
	 *
	 * @return void
	 */
	public function test_rolled_back_cannot_transition(): void {
		$this->assertFalse( ImportState::ROLLED_BACK->can_transition_to( ImportState::PENDING ) );
		$this->assertFalse( ImportState::ROLLED_BACK->can_transition_to( ImportState::PROCESSING ) );
	}

	/**
	 * Test get_label returns translated strings.
	 *
	 * @return void
	 */
	public function test_get_label_returns_strings(): void {
		$this->assertSame( 'Pending', ImportState::PENDING->get_label() );
		$this->assertSame( 'Processing', ImportState::PROCESSING->get_label() );
		$this->assertSame( 'Paused', ImportState::PAUSED->get_label() );
		$this->assertSame( 'Completed', ImportState::COMPLETED->get_label() );
		$this->assertSame( 'Failed', ImportState::FAILED->get_label() );
		$this->assertSame( 'Rolled Back', ImportState::ROLLED_BACK->get_label() );
	}

	/**
	 * Test is_terminal returns true for terminal states.
	 *
	 * @return void
	 */
	public function test_is_terminal(): void {
		$this->assertFalse( ImportState::PENDING->is_terminal() );
		$this->assertFalse( ImportState::PROCESSING->is_terminal() );
		$this->assertFalse( ImportState::PAUSED->is_terminal() );
		$this->assertTrue( ImportState::COMPLETED->is_terminal() );
		$this->assertTrue( ImportState::FAILED->is_terminal() );
		$this->assertTrue( ImportState::ROLLED_BACK->is_terminal() );
	}
}
