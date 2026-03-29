<?php
/**
 * BatchProcessor class tests.
 *
 * @package AI_Importer\Tests\Unit\Processor
 */

namespace AI_Importer\Tests\Unit\Processor;

use AI_Importer\Adapters\AdapterInterface;
use AI_Importer\Adapters\AdapterRegistry;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\NormalizedItem;
use AI_Importer\Processor\BatchProcessor;
use AI_Importer\Processor\BatchRepository;
use AI_Importer\Processor\ImportBatch;
use AI_Importer\Processor\ImportState;
use AI_Importer\Processor\ItemProcessor;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use DateTimeImmutable;

/**
 * Tests for the BatchProcessor class.
 */
class BatchProcessorTest extends TestCase {

	/**
	 * Mock batch repository.
	 *
	 * @var BatchRepository|\Mockery\MockInterface
	 */
	private $mock_repository;

	/**
	 * Mock item processor.
	 *
	 * @var ItemProcessor|\Mockery\MockInterface
	 */
	private $mock_item_processor;

	/**
	 * Mock adapter registry.
	 *
	 * @var AdapterRegistry|\Mockery\MockInterface
	 */
	private $mock_adapter_registry;

	/**
	 * Processor instance.
	 *
	 * @var BatchProcessor
	 */
	private BatchProcessor $processor;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->mock_repository       = \Mockery::mock( BatchRepository::class );
		$this->mock_item_processor   = \Mockery::mock( ItemProcessor::class );
		$this->mock_adapter_registry = \Mockery::mock( AdapterRegistry::class );

		$this->processor = new BatchProcessor(
			$this->mock_repository,
			$this->mock_item_processor,
			$this->mock_adapter_registry,
		);
	}

	/**
	 * Test init registers Action Scheduler hook.
	 *
	 * @return void
	 */
	public function test_init_registers_action(): void {
		Actions\expectAdded( 'ai_importer_process_batch' )
			->once();

		$this->processor->init();

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test create_batch creates and schedules batch.
	 *
	 * @return void
	 */
	public function test_create_batch(): void {
		Functions\expect( 'wp_generate_uuid4' )
			->once()
			->andReturn( 'new-uuid' );

		$this->mock_repository
			->shouldReceive( 'save' )
			->once()
			->with(
				\Mockery::on(
					function ( ImportBatch $batch ) {
						return 'new-uuid' === $batch->id
							&& 'twitter' === $batch->source_adapter
							&& ImportState::PROCESSING === $batch->state;
					}
				)
			)
			->andReturn( true );

		Functions\expect( 'as_schedule_single_action' )
			->once()
			->with( \Mockery::type( 'int' ), 'ai_importer_process_batch', array( 'new-uuid' ), 'ai-importer' );

		$batch = $this->processor->create_batch(
			'twitter',
			array( 'item-1', 'item-2' ),
		);

		$this->assertSame( 'new-uuid', $batch->id );
		$this->assertSame( ImportState::PROCESSING, $batch->state );
	}

	/**
	 * Test process_chunk processes items and schedules next chunk.
	 *
	 * @return void
	 */
	public function test_process_chunk_processes_items(): void {
		$batch = $this->create_processing_batch( array( 'item-1', 'item-2' ) );

		$this->mock_repository
			->shouldReceive( 'find' )
			->once()
			->with( 'test-batch' )
			->andReturn( $batch );

		$adapter = \Mockery::mock( AdapterInterface::class );
		$adapter->shouldReceive( 'fetch_item' )
			->twice()
			->andReturn( array( 'content' => 'Hello' ) );

		$this->mock_adapter_registry
			->shouldReceive( 'get' )
			->once()
			->with( 'twitter' )
			->andReturn( $adapter );

		$normalizer      = \Mockery::mock( 'AI_Importer\Normalizer\ContentNormalizer' );
		$normalized_item = new NormalizedItem(
			source_id: 'item-1',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: '<p>Hello</p>',
			publish_date: new DateTimeImmutable(),
		);

		$normalizer->shouldReceive( 'normalize' )
			->twice()
			->andReturn( $normalized_item );

		Functions\expect( 'apply_filters' )
			->with( 'ai_importer_content_normalizer', null, 'twitter' )
			->andReturn( $normalizer );

		$this->mock_item_processor
			->shouldReceive( 'process' )
			->twice()
			->andReturn( 42, 43 );

		$this->mock_repository
			->shouldReceive( 'save' )
			->twice()
			->andReturn( true );

		Actions\expectDone( 'ai_importer_batch_completed' )
			->once();

		$this->processor->process_chunk( 'test-batch' );

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test process_chunk skips terminal batch.
	 *
	 * @return void
	 */
	public function test_process_chunk_skips_terminal_batch(): void {
		$batch = $this->create_processing_batch( array( 'item-1' ) );
		$batch->transition_to( ImportState::COMPLETED );

		$this->mock_repository
			->shouldReceive( 'find' )
			->once()
			->andReturn( $batch );

		// Should not process any items.
		$this->mock_item_processor
			->shouldNotReceive( 'process' );

		$this->processor->process_chunk( 'test-batch' );

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test process_chunk handles missing batch gracefully.
	 *
	 * @return void
	 */
	public function test_process_chunk_handles_missing_batch(): void {
		$this->mock_repository
			->shouldReceive( 'find' )
			->once()
			->andReturn( null );

		// Should not throw.
		$this->processor->process_chunk( 'nonexistent' );

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test process_chunk continues on item failure.
	 *
	 * @return void
	 */
	public function test_process_chunk_continues_on_item_failure(): void {
		$batch = $this->create_processing_batch( array( 'item-1', 'item-2' ) );

		$this->mock_repository
			->shouldReceive( 'find' )
			->once()
			->andReturn( $batch );

		$adapter = \Mockery::mock( AdapterInterface::class );
		$adapter->shouldReceive( 'fetch_item' )
			->with( 'item-1' )
			->andThrow( new \RuntimeException( 'Network error' ) );
		$adapter->shouldReceive( 'fetch_item' )
			->with( 'item-2' )
			->andReturn( array( 'content' => 'Hello' ) );

		$this->mock_adapter_registry
			->shouldReceive( 'get' )
			->once()
			->andReturn( $adapter );

		$normalizer      = \Mockery::mock( 'AI_Importer\Normalizer\ContentNormalizer' );
		$normalized_item = new NormalizedItem(
			source_id: 'item-2',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: '<p>Hello</p>',
			publish_date: new DateTimeImmutable(),
		);

		$normalizer->shouldReceive( 'normalize' )
			->once()
			->andReturn( $normalized_item );

		Functions\expect( 'apply_filters' )
			->with( 'ai_importer_content_normalizer', null, 'twitter' )
			->andReturn( $normalizer );

		$this->mock_item_processor
			->shouldReceive( 'process' )
			->once()
			->andReturn( 42 );

		$this->mock_repository
			->shouldReceive( 'save' )
			->twice()
			->andReturn( true );

		Actions\expectDone( 'ai_importer_batch_completed' )
			->once();

		$this->processor->process_chunk( 'test-batch' );

		$this->assertSame( 1, $batch->failed_items );
		$this->assertSame( 1, $batch->processed_items );
	}

	/**
	 * Test pause_batch pauses and unschedules.
	 *
	 * @return void
	 */
	public function test_pause_batch(): void {
		$batch = $this->create_processing_batch( array( 'item-1' ) );

		$this->mock_repository
			->shouldReceive( 'find' )
			->once()
			->andReturn( $batch );

		$this->mock_repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( true );

		Functions\expect( 'as_unschedule_all_actions' )
			->once()
			->with( 'ai_importer_process_batch', array( 'test-batch' ), 'ai-importer' );

		$this->processor->pause_batch( 'test-batch' );

		$this->assertSame( ImportState::PAUSED, $batch->state );
	}

	/**
	 * Test resume_batch resumes and schedules.
	 *
	 * @return void
	 */
	public function test_resume_batch(): void {
		$batch = $this->create_processing_batch( array( 'item-1' ) );
		$batch->transition_to( ImportState::PAUSED );

		$this->mock_repository
			->shouldReceive( 'find' )
			->once()
			->andReturn( $batch );

		$this->mock_repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( true );

		Functions\expect( 'as_schedule_single_action' )
			->once()
			->with( \Mockery::type( 'int' ), 'ai_importer_process_batch', array( 'test-batch' ), 'ai-importer' );

		$this->processor->resume_batch( 'test-batch' );

		$this->assertSame( ImportState::PROCESSING, $batch->state );
	}

	/**
	 * Test rollback_batch deletes posts and attachments.
	 *
	 * @return void
	 */
	public function test_rollback_batch(): void {
		$batch = $this->create_processing_batch( array( 'item-1' ) );
		$batch->transition_to( ImportState::COMPLETED );
		$batch->created_post_ids = array( 10, 11 );

		$this->mock_repository
			->shouldReceive( 'find' )
			->once()
			->andReturn( $batch );

		Functions\expect( 'as_unschedule_all_actions' )
			->once();

		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array( 50, 51 ) );

		Functions\expect( 'wp_delete_attachment' )
			->twice()
			->andReturn( true );

		Functions\expect( 'wp_delete_post' )
			->twice()
			->andReturn( true );

		$this->mock_repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( true );

		Actions\expectDone( 'ai_importer_batch_rolled_back' )
			->once();

		$this->processor->rollback_batch( 'test-batch' );

		$this->assertSame( ImportState::ROLLED_BACK, $batch->state );
	}

	/**
	 * Test get_progress returns progress data.
	 *
	 * @return void
	 */
	public function test_get_progress(): void {
		$batch = $this->create_processing_batch( array( 'item-1', 'item-2' ) );
		$batch->mark_item_processed( 42 );

		$this->mock_repository
			->shouldReceive( 'find' )
			->once()
			->andReturn( $batch );

		$progress = $this->processor->get_progress( 'test-batch' );

		$this->assertSame( 'test-batch', $progress['batch_id'] );
		$this->assertSame( 'processing', $progress['state'] );
		$this->assertSame( 2, $progress['total'] );
		$this->assertSame( 1, $progress['processed'] );
		$this->assertSame( 50.0, $progress['percentage'] );
	}

	/**
	 * Test get_progress returns null for missing batch.
	 *
	 * @return void
	 */
	public function test_get_progress_returns_null_for_missing_batch(): void {
		$this->mock_repository
			->shouldReceive( 'find' )
			->once()
			->andReturn( null );

		$this->assertNull( $this->processor->get_progress( 'nonexistent' ) );
	}

	/**
	 * Create a processing batch for tests.
	 *
	 * @param array<int, string> $item_ids Item IDs.
	 * @return ImportBatch
	 */
	private function create_processing_batch( array $item_ids ): ImportBatch {
		$batch = new ImportBatch(
			id: 'test-batch',
			source_adapter: 'twitter',
			item_ids: $item_ids,
			created_at: new DateTimeImmutable( '2024-06-01 12:00:00' ),
		);
		$batch->transition_to( ImportState::PROCESSING );
		return $batch;
	}
}
