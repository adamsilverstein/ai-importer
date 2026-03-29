<?php
/**
 * BatchRepository class tests.
 *
 * @package AI_Importer\Tests\Unit\Processor
 */

namespace AI_Importer\Tests\Unit\Processor;

use AI_Importer\Processor\BatchRepository;
use AI_Importer\Processor\ImportBatch;
use AI_Importer\Processor\ImportState;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use DateTimeImmutable;

/**
 * Tests for the BatchRepository class.
 */
class BatchRepositoryTest extends TestCase {

	/**
	 * Repository instance.
	 *
	 * @var BatchRepository
	 */
	private BatchRepository $repository;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->repository = new BatchRepository();
	}

	/**
	 * Test save stores batch in wp_options and updates index.
	 *
	 * @return void
	 */
	public function test_save_stores_batch(): void {
		$batch = $this->create_batch( 'save-test' );

		Functions\expect( 'update_option' )
			->once()
			->with( 'ai_importer_batch_save-test', $batch->to_array(), false )
			->andReturn( true );

		Functions\expect( 'get_option' )
			->once()
			->with( 'ai_importer_batch_index', array() )
			->andReturn( array() );

		Functions\expect( 'update_option' )
			->once()
			->with(
				'ai_importer_batch_index',
				\Mockery::on(
					function ( $index ) {
						return is_array( $index )
							&& 1 === count( $index )
							&& 'save-test' === $index[0]['id'];
					}
				),
				false
			)
			->andReturn( true );

		$result = $this->repository->save( $batch );

		$this->assertTrue( $result );
	}

	/**
	 * Test find returns batch when it exists.
	 *
	 * @return void
	 */
	public function test_find_returns_batch(): void {
		$batch = $this->create_batch( 'find-test' );

		Functions\expect( 'get_option' )
			->once()
			->with( 'ai_importer_batch_find-test', null )
			->andReturn( $batch->to_array() );

		$found = $this->repository->find( 'find-test' );

		$this->assertNotNull( $found );
		$this->assertSame( 'find-test', $found->id );
		$this->assertSame( 'twitter', $found->source_adapter );
	}

	/**
	 * Test find returns null when batch does not exist.
	 *
	 * @return void
	 */
	public function test_find_returns_null_when_not_found(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'ai_importer_batch_nonexistent', null )
			->andReturn( null );

		$this->assertNull( $this->repository->find( 'nonexistent' ) );
	}

	/**
	 * Test delete removes batch and updates index.
	 *
	 * @return void
	 */
	public function test_delete_removes_batch(): void {
		Functions\expect( 'delete_option' )
			->once()
			->with( 'ai_importer_batch_delete-test' )
			->andReturn( true );

		Functions\expect( 'get_option' )
			->once()
			->with( 'ai_importer_batch_index', array() )
			->andReturn(
				array(
					array(
						'id'         => 'delete-test',
						'state'      => 'pending',
						'created_at' => '2024-06-01T12:00:00+00:00',
					),
					array(
						'id'         => 'other-batch',
						'state'      => 'completed',
						'created_at' => '2024-06-02T12:00:00+00:00',
					),
				)
			);

		Functions\expect( 'update_option' )
			->once()
			->with(
				'ai_importer_batch_index',
				\Mockery::on(
					function ( $index ) {
						return is_array( $index )
							&& 1 === count( $index )
							&& 'other-batch' === $index[0]['id'];
					}
				),
				false
			)
			->andReturn( true );

		$result = $this->repository->delete( 'delete-test' );

		$this->assertTrue( $result );
	}

	/**
	 * Test find_active returns only non-terminal batches.
	 *
	 * @return void
	 */
	public function test_find_active_returns_non_terminal(): void {
		$processing_batch = $this->create_batch( 'processing-batch' );
		$processing_batch->transition_to( ImportState::PROCESSING );

		Functions\expect( 'get_option' )
			->once()
			->with( 'ai_importer_batch_index', array() )
			->andReturn(
				array(
					array(
						'id'         => 'processing-batch',
						'state'      => 'processing',
						'created_at' => '2024-06-01T12:00:00+00:00',
					),
					array(
						'id'         => 'completed-batch',
						'state'      => 'completed',
						'created_at' => '2024-06-02T12:00:00+00:00',
					),
				)
			);

		Functions\expect( 'get_option' )
			->once()
			->with( 'ai_importer_batch_processing-batch', null )
			->andReturn( $processing_batch->to_array() );

		$active = $this->repository->find_active();

		$this->assertCount( 1, $active );
		$this->assertSame( 'processing-batch', $active[0]->id );
	}

	/**
	 * Helper to create a batch with defaults.
	 *
	 * @param string $id Batch ID.
	 * @return ImportBatch
	 */
	private function create_batch( string $id = 'test-batch' ): ImportBatch {
		return new ImportBatch(
			id: $id,
			source_adapter: 'twitter',
			item_ids: array( 'item-1', 'item-2' ),
			created_at: new DateTimeImmutable( '2024-06-01 12:00:00' ),
		);
	}
}
