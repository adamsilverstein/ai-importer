<?php
/**
 * ImportBatch class tests.
 *
 * @package AI_Importer\Tests\Unit\Processor
 */

namespace AI_Importer\Tests\Unit\Processor;

use AI_Importer\Processor\ImportBatch;
use AI_Importer\Processor\ImportState;
use AI_Importer\Tests\Unit\TestCase;
use DateTimeImmutable;
use LogicException;
use Brain\Monkey\Functions;

/**
 * Tests for the ImportBatch class.
 */
class ImportBatchTest extends TestCase {

	/**
	 * Test constructor sets properties.
	 *
	 * @return void
	 */
	public function test_constructor_sets_properties(): void {
		$created_at = new DateTimeImmutable( '2024-06-01 12:00:00' );
		$item_ids   = array( 'item-1', 'item-2', 'item-3' );

		$batch = new ImportBatch(
			id: 'batch-uuid-123',
			source_adapter: 'twitter',
			item_ids: $item_ids,
			created_at: $created_at,
		);

		$this->assertSame( 'batch-uuid-123', $batch->id );
		$this->assertSame( 'twitter', $batch->source_adapter );
		$this->assertSame( $item_ids, $batch->item_ids );
		$this->assertSame( $created_at, $batch->created_at );
		$this->assertSame( ImportState::PENDING, $batch->state );
		$this->assertSame( 0, $batch->processed_items );
		$this->assertSame( 0, $batch->failed_items );
		$this->assertSame( 0, $batch->skipped_items );
		$this->assertSame( 0, $batch->current_offset );
		$this->assertEmpty( $batch->errors );
		$this->assertEmpty( $batch->created_post_ids );
		$this->assertNull( $batch->started_at );
		$this->assertNull( $batch->completed_at );
	}

	/**
	 * Test get_total_items returns count of item IDs.
	 *
	 * @return void
	 */
	public function test_get_total_items(): void {
		$batch = $this->create_batch( item_ids: array( 'a', 'b', 'c' ) );

		$this->assertSame( 3, $batch->get_total_items() );
	}

	/**
	 * Test get_progress_percentage with no items processed.
	 *
	 * @return void
	 */
	public function test_get_progress_percentage_zero(): void {
		$batch = $this->create_batch( item_ids: array( 'a', 'b' ) );

		$this->assertSame( 0.0, $batch->get_progress_percentage() );
	}

	/**
	 * Test get_progress_percentage with all items processed.
	 *
	 * @return void
	 */
	public function test_get_progress_percentage_complete(): void {
		$batch = $this->create_batch( item_ids: array( 'a', 'b' ) );
		$batch->mark_item_processed( 1 );
		$batch->mark_item_processed( 2 );

		$this->assertSame( 100.0, $batch->get_progress_percentage() );
	}

	/**
	 * Test get_progress_percentage includes failed and skipped.
	 *
	 * @return void
	 */
	public function test_get_progress_percentage_includes_failed_and_skipped(): void {
		$batch = $this->create_batch( item_ids: array( 'a', 'b', 'c', 'd' ) );
		$batch->mark_item_processed( 1 );
		$batch->mark_item_failed( 'b', 'Error' );
		$batch->mark_item_skipped();

		$this->assertSame( 75.0, $batch->get_progress_percentage() );
	}

	/**
	 * Test get_progress_percentage with empty batch.
	 *
	 * @return void
	 */
	public function test_get_progress_percentage_empty_batch(): void {
		$batch = $this->create_batch( item_ids: array() );

		$this->assertSame( 100.0, $batch->get_progress_percentage() );
	}

	/**
	 * Test mark_item_processed increments counter and stores post ID.
	 *
	 * @return void
	 */
	public function test_mark_item_processed(): void {
		$batch = $this->create_batch();
		$batch->mark_item_processed( 42 );
		$batch->mark_item_processed( 43 );

		$this->assertSame( 2, $batch->processed_items );
		$this->assertSame( array( 42, 43 ), $batch->created_post_ids );
	}

	/**
	 * Test mark_item_failed increments counter and logs error.
	 *
	 * @return void
	 */
	public function test_mark_item_failed(): void {
		$batch = $this->create_batch();
		$batch->mark_item_failed( 'item-1', 'Something went wrong' );

		$this->assertSame( 1, $batch->failed_items );
		$this->assertCount( 1, $batch->errors );
		$this->assertSame( 'item-1', $batch->errors[0]['item_id'] );
		$this->assertSame( 'Something went wrong', $batch->errors[0]['message'] );
	}

	/**
	 * Test errors are capped at MAX_ERRORS.
	 *
	 * @return void
	 */
	public function test_errors_capped_at_max(): void {
		$batch = $this->create_batch();

		for ( $i = 0; $i < 110; $i++ ) {
			$batch->add_error( "item-{$i}", "Error {$i}" );
		}

		$this->assertCount( 100, $batch->errors );
		$this->assertSame( 'item-10', $batch->errors[0]['item_id'] );
	}

	/**
	 * Test transition_to valid transition.
	 *
	 * @return void
	 */
	public function test_transition_to_valid(): void {
		$batch = $this->create_batch();
		$batch->transition_to( ImportState::PROCESSING );

		$this->assertSame( ImportState::PROCESSING, $batch->state );
		$this->assertNotNull( $batch->started_at );
	}

	/**
	 * Test transition_to sets completed_at on terminal state.
	 *
	 * @return void
	 */
	public function test_transition_to_terminal_sets_completed_at(): void {
		$batch = $this->create_batch();
		$batch->transition_to( ImportState::PROCESSING );
		$batch->transition_to( ImportState::COMPLETED );

		$this->assertNotNull( $batch->completed_at );
	}

	/**
	 * Test transition_to invalid transition throws exception.
	 *
	 * @return void
	 */
	public function test_transition_to_invalid_throws(): void {
		$batch = $this->create_batch();

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'Cannot transition from pending to completed' );

		$batch->transition_to( ImportState::COMPLETED );
	}

	/**
	 * Test started_at only set on first transition to processing.
	 *
	 * @return void
	 */
	public function test_started_at_only_set_once(): void {
		$batch = $this->create_batch();
		$batch->transition_to( ImportState::PROCESSING );
		$first_started = $batch->started_at;

		$batch->transition_to( ImportState::PAUSED );
		$batch->transition_to( ImportState::PROCESSING );

		$this->assertSame( $first_started, $batch->started_at );
	}

	/**
	 * Test to_array serialization.
	 *
	 * @return void
	 */
	public function test_to_array(): void {
		$batch = $this->create_batch(
			id: 'test-uuid',
			source_adapter: 'medium',
			item_ids: array( 'a', 'b' ),
		);
		$batch->mark_item_processed( 10 );

		$array = $batch->to_array();

		$this->assertSame( 'test-uuid', $array['id'] );
		$this->assertSame( 'pending', $array['state'] );
		$this->assertSame( 'medium', $array['source_adapter'] );
		$this->assertSame( array( 'a', 'b' ), $array['item_ids'] );
		$this->assertSame( 1, $array['processed_items'] );
		$this->assertSame( array( 10 ), $array['created_post_ids'] );
	}

	/**
	 * Test from_array deserialization round-trip.
	 *
	 * @return void
	 */
	public function test_from_array_round_trip(): void {
		$original = $this->create_batch(
			id: 'round-trip-uuid',
			source_adapter: 'twitter',
			item_ids: array( 'x', 'y', 'z' ),
		);
		$original->transition_to( ImportState::PROCESSING );
		$original->mark_item_processed( 100 );
		$original->mark_item_failed( 'y', 'Failed' );
		$original->current_offset = 2;

		$restored = ImportBatch::from_array( $original->to_array() );

		$this->assertSame( $original->id, $restored->id );
		$this->assertSame( $original->state, $restored->state );
		$this->assertSame( $original->source_adapter, $restored->source_adapter );
		$this->assertSame( $original->item_ids, $restored->item_ids );
		$this->assertSame( $original->processed_items, $restored->processed_items );
		$this->assertSame( $original->failed_items, $restored->failed_items );
		$this->assertSame( $original->current_offset, $restored->current_offset );
		$this->assertSame( $original->created_post_ids, $restored->created_post_ids );
		$this->assertCount( 1, $restored->errors );
	}

	/**
	 * Test generate_id calls wp_generate_uuid4.
	 *
	 * @return void
	 */
	public function test_generate_id(): void {
		Functions\expect( 'wp_generate_uuid4' )
			->once()
			->andReturn( 'generated-uuid' );

		$this->assertSame( 'generated-uuid', ImportBatch::generate_id() );
	}

	/**
	 * Helper to create a batch with defaults.
	 *
	 * @param string             $id              Batch ID.
	 * @param string             $source_adapter  Adapter ID.
	 * @param array<int, string> $item_ids        Item IDs.
	 * @return ImportBatch
	 */
	private function create_batch(
		string $id = 'test-batch',
		string $source_adapter = 'twitter',
		array $item_ids = array( 'item-1', 'item-2' ),
	): ImportBatch {
		return new ImportBatch(
			id: $id,
			source_adapter: $source_adapter,
			item_ids: $item_ids,
			created_at: new DateTimeImmutable( '2024-06-01 12:00:00' ),
		);
	}
}
