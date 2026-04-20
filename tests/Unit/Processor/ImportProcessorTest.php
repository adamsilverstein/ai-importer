<?php
/**
 * ImportProcessor tests.
 *
 * @package AI_Importer\Tests\Unit\Processor
 */

namespace AI_Importer\Tests\Unit\Processor;

use AI_Importer\Adapters\AdapterInterface;
use AI_Importer\Adapters\AdapterRegistry;
use AI_Importer\Adapters\Manifest\ContentManifest;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\ContentNormalizer;
use AI_Importer\Normalizer\NormalizedItem;
use AI_Importer\Processor\ContentCreator;
use AI_Importer\Processor\ImportProcessor;
use AI_Importer\Processor\ItemEnhancer;
use AI_Importer\Processor\MediaHandler;
use AI_Importer\Schema\SettingsSchema;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use Mockery;

/**
 * Tests for the ImportProcessor class.
 */
class ImportProcessorTest extends TestCase {

	/**
	 * In-memory options store.
	 *
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * Whether AS action was scheduled.
	 *
	 * @var bool
	 */
	private bool $action_scheduled;

	/**
	 * Whether AS actions were unscheduled.
	 *
	 * @var bool
	 */
	private bool $actions_unscheduled;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		AdapterRegistry::get_instance()->reset();
		$this->options              = array();
		$this->action_scheduled     = false;
		$this->actions_unscheduled  = false;

		$options = &$this->options;
		$scheduled = &$this->action_scheduled;
		$unscheduled = &$this->actions_unscheduled;

		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( &$options ) {
				return $options[ $key ] ?? $default;
			}
		);

		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$options ) {
				$options[ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'as_enqueue_async_action' )->alias(
			function () use ( &$scheduled ) {
				$scheduled = true;
				return 1;
			}
		);

		Functions\when( 'as_unschedule_all_actions' )->alias(
			function () use ( &$unscheduled ) {
				$unscheduled = true;
			}
		);
	}

	/**
	 * Test init registers hooks.
	 *
	 * @return void
	 */
	public function test_init_registers_hooks(): void {
		Actions\expectAdded( 'ai_importer_batch_created' );
		Actions\expectAdded( 'ai_importer_batch_resumed' );
		Actions\expectAdded( 'ai_importer_batch_paused' );
		Actions\expectAdded( 'ai_importer_process_batch' );

		$processor = new ImportProcessor();
		$processor->init();

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test on_batch_created schedules async action.
	 *
	 * @return void
	 */
	public function test_on_batch_created_schedules_action(): void {
		$processor = new ImportProcessor();
		$processor->on_batch_created( 'batch-1', array() );

		$this->assertTrue( $this->action_scheduled );
	}

	/**
	 * Test on_batch_paused unschedules actions.
	 *
	 * @return void
	 */
	public function test_on_batch_paused_unschedules_actions(): void {
		$processor = new ImportProcessor();
		$processor->on_batch_paused( 'batch-1', array() );

		$this->assertTrue( $this->actions_unscheduled );
	}

	/**
	 * Test on_batch_resumed schedules action.
	 *
	 * @return void
	 */
	public function test_on_batch_resumed_schedules_action(): void {
		$processor = new ImportProcessor();
		$processor->on_batch_resumed( 'batch-1', array() );

		$this->assertTrue( $this->action_scheduled );
	}

	/**
	 * Test process_batch skips when batch not found.
	 *
	 * @return void
	 */
	public function test_process_batch_skips_when_not_found(): void {
		$processor = new ImportProcessor();
		$processor->process_batch( 'nonexistent' );

		// No exception = success.
		$this->assertTrue( true );
	}

	/**
	 * Test process_batch skips when batch is paused.
	 *
	 * @return void
	 */
	public function test_process_batch_skips_when_paused(): void {
		$this->store_batch( 'batch-1', 'paused' );

		$processor = new ImportProcessor();
		$processor->process_batch( 'batch-1' );

		$batch = $this->options['ai_importer_batch_batch-1'];
		$this->assertSame( 'paused', $batch['state'] );
	}

	/**
	 * Test process_batch fails when adapter not found.
	 *
	 * @return void
	 */
	public function test_process_batch_fails_when_adapter_missing(): void {
		$this->store_batch( 'batch-1', 'processing' );

		$processor = new ImportProcessor();
		$processor->process_batch( 'batch-1' );

		$batch = $this->options['ai_importer_batch_batch-1'];
		$this->assertSame( 'failed', $batch['state'] );
		$this->assertNotEmpty( $batch['errors'] );
	}

	/**
	 * Test process_batch processes items and updates progress.
	 *
	 * @return void
	 */
	public function test_process_batch_processes_items(): void {
		$this->register_mock_adapter();

		$creator = Mockery::mock( ContentCreator::class );
		$creator->shouldReceive( 'create' )->andReturn( 100, 101 );

		$media_handler = Mockery::mock( MediaHandler::class );
		$media_handler->shouldReceive( 'process' )->andReturn( array() );

		$normalizer = $this->create_mock_normalizer();

		Filters\expectApplied( 'ai_importer_normalizers' )
			->andReturn( array( 'twitter' => $normalizer ) );

		$this->store_batch( 'batch-1', 'processing', array( 'item-1', 'item-2' ) );

		$processor = new ImportProcessor( $creator, $media_handler );
		$processor->process_batch( 'batch-1' );

		$batch = $this->options['ai_importer_batch_batch-1'];
		$this->assertSame( 'completed', $batch['state'] );
		$this->assertSame( 2, $batch['processed'] );
		$this->assertSame( 0, $batch['failed'] );
		$this->assertCount( 2, $batch['imported_ids'] );
		$this->assertContains( 100, $batch['imported_ids'] );
		$this->assertContains( 101, $batch['imported_ids'] );
	}

	/**
	 * Test process_batch handles item failures gracefully.
	 *
	 * @return void
	 */
	public function test_process_batch_handles_failures(): void {
		$this->register_mock_adapter();

		$creator = Mockery::mock( ContentCreator::class );
		$creator->shouldReceive( 'create' )
			->once()
			->andThrow( new \RuntimeException( 'Insert failed' ) );

		$media_handler = Mockery::mock( MediaHandler::class );
		$media_handler->shouldReceive( 'process' )->andReturn( array() );

		$normalizer = $this->create_mock_normalizer();

		Filters\expectApplied( 'ai_importer_normalizers' )
			->andReturn( array( 'twitter' => $normalizer ) );

		$this->store_batch( 'batch-1', 'processing', array( 'item-1' ) );

		$processor = new ImportProcessor( $creator, $media_handler );
		$processor->process_batch( 'batch-1' );

		$batch = $this->options['ai_importer_batch_batch-1'];
		// All items failed = 'failed' state.
		$this->assertSame( 'failed', $batch['state'] );
		$this->assertSame( 0, $batch['processed'] );
		$this->assertSame( 1, $batch['failed'] );
		$this->assertNotEmpty( $batch['errors'] );
	}

	/**
	 * Test process_batch re-enqueues when items remain.
	 *
	 * @return void
	 */
	public function test_process_batch_reenqueues_for_remaining(): void {
		$this->register_mock_adapter();

		$creator = Mockery::mock( ContentCreator::class );
		$creator->shouldReceive( 'create' )->andReturn( 100 );

		$media_handler = Mockery::mock( MediaHandler::class );
		$media_handler->shouldReceive( 'process' )->andReturn( array() );

		$normalizer = $this->create_mock_normalizer();

		Filters\expectApplied( 'ai_importer_normalizers' )
			->andReturn( array( 'twitter' => $normalizer ) );

		// Create batch with 15 items (more than chunk size of 10).
		$item_ids = array();
		for ( $i = 1; $i <= 15; $i++ ) {
			$item_ids[] = "item-{$i}";
		}

		$this->store_batch( 'batch-1', 'processing', $item_ids );
		$this->action_scheduled = false;

		$processor = new ImportProcessor( $creator, $media_handler );
		$processor->process_batch( 'batch-1' );

		$batch = $this->options['ai_importer_batch_batch-1'];
		// Should have processed chunk (10) and re-enqueued.
		$this->assertSame( 'processing', $batch['state'] );
		$this->assertSame( 10, $batch['processed'] );
		$this->assertTrue( $this->action_scheduled );
	}

	/**
	 * Store a test batch in the in-memory options.
	 *
	 * @param string        $id       Batch ID.
	 * @param string        $state    Batch state.
	 * @param array<string> $item_ids Item IDs.
	 * @return void
	 */
	private function store_batch( string $id, string $state, array $item_ids = array( 'item-1' ) ): void {
		$this->options[ 'ai_importer_batch_' . $id ] = array(
			'id'             => $id,
			'source_adapter' => 'twitter',
			'state'          => $state,
			'item_ids'       => $item_ids,
			'total'          => count( $item_ids ),
			'processed'      => 0,
			'failed'         => 0,
			'errors'         => array(),
			'created_at'     => '2024-01-15T10:00:00+00:00',
			'started_at'     => '2024-01-15T10:00:00+00:00',
			'completed_at'   => null,
			'imported_ids'   => array(),
		);
	}

	/**
	 * Register a mock adapter in the registry.
	 *
	 * @return void
	 */
	private function register_mock_adapter(): void {
		$adapter = Mockery::mock( AdapterInterface::class );
		$adapter->shouldReceive( 'get_id' )->andReturn( 'twitter' );
		$adapter->shouldReceive( 'get_name' )->andReturn( 'Twitter' );
		$adapter->shouldReceive( 'get_description' )->andReturn( 'Twitter adapter' );
		$adapter->shouldReceive( 'get_icon' )->andReturn( 'dashicons-twitter' );
		$adapter->shouldReceive( 'get_auth_type' )->andReturn( 'file_upload' );
		$adapter->shouldReceive( 'is_authenticated' )->andReturn( true );
		$adapter->shouldReceive( 'get_supported_content_types' )->andReturn( array( 'post' ) );
		$adapter->shouldReceive( 'fetch_item' )->andReturn(
			array(
				'id'           => 'tweet-1',
				'type'         => 'post',
				'content'      => 'Test tweet',
				'title'        => 'Test',
				'created_at'   => '2024-01-15T10:00:00+00:00',
				'media_urls'   => array(),
				'media_paths'  => array(),
				'metadata'     => array(),
				'parent_id'    => null,
				'original_url' => null,
				'raw'          => array(),
			)
		);

		AdapterRegistry::get_instance()->register( $adapter );
	}

	/**
	 * Test process_batch invokes the enhancer on every normalized item when supplied.
	 *
	 * @return void
	 */
	public function test_process_batch_invokes_enhancer_when_provided(): void {
		$this->register_mock_adapter();

		$creator = Mockery::mock( ContentCreator::class );
		$creator->shouldReceive( 'create' )->andReturn( 200, 201 );

		$media_handler = Mockery::mock( MediaHandler::class );
		$media_handler->shouldReceive( 'process' )->andReturn( array() );

		$normalizer = $this->create_mock_normalizer();

		Filters\expectApplied( 'ai_importer_normalizers' )
			->andReturn( array( 'twitter' => $normalizer ) );

		$enhancer = Mockery::mock( ItemEnhancer::class );
		$enhancer->shouldReceive( 'enhance' )->twice();

		$this->store_batch( 'batch-1', 'processing', array( 'item-1', 'item-2' ) );

		$processor = new ImportProcessor( $creator, $media_handler, $enhancer );
		$processor->process_batch( 'batch-1' );

		$batch = $this->options['ai_importer_batch_batch-1'];
		$this->assertSame( 'completed', $batch['state'] );
		$this->assertSame( 2, $batch['processed'] );
	}

	/**
	 * Create a mock normalizer that returns a NormalizedItem.
	 *
	 * @return ContentNormalizer&\Mockery\MockInterface
	 */
	private function create_mock_normalizer() {
		$normalizer = Mockery::mock( ContentNormalizer::class );
		$normalizer->shouldReceive( 'get_adapter_id' )->andReturn( 'twitter' );
		$normalizer->shouldReceive( 'supports' )->andReturn( true );
		$normalizer->shouldReceive( 'normalize' )->andReturn(
			new NormalizedItem(
				source_id: 'tweet-1',
				source_adapter: 'twitter',
				content_type: ContentType::POST,
				content: '<p>Test tweet</p>',
				publish_date: new DateTimeImmutable( '2024-01-15' ),
				title: 'Test',
			)
		);

		return $normalizer;
	}
}
