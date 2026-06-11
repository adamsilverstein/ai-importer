<?php
/**
 * ImportScheduler tests.
 *
 * @package AI_Importer\Tests\Unit\Processor
 */

namespace AI_Importer\Tests\Unit\Processor;

use AI_Importer\Adapters\AdapterInterface;
use AI_Importer\Adapters\AdapterRegistry;
use AI_Importer\Adapters\Manifest\ContentManifest;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\Manifest\ManifestItem;
use AI_Importer\Processor\ImportScheduler;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use Mockery;

/**
 * Tests for the ImportScheduler class.
 */
class ImportSchedulerTest extends TestCase {

	/**
	 * In-memory options store.
	 *
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * Whether a recurring action was scheduled.
	 *
	 * @var bool
	 */
	private bool $recurring_scheduled;

	/**
	 * Whether an action was unscheduled.
	 *
	 * @var bool
	 */
	private bool $action_unscheduled;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		AdapterRegistry::get_instance()->reset();
		$this->options             = array();
		$this->recurring_scheduled = false;
		$this->action_unscheduled  = false;

		$options     = &$this->options;
		$scheduled   = &$this->recurring_scheduled;
		$unscheduled = &$this->action_unscheduled;

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

		Functions\when( 'sanitize_text_field' )->alias(
			fn( $value ) => trim( (string) $value )
		);
		Functions\when( 'sanitize_key' )->alias(
			fn( $value ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) )
		);

		$counter = 0;
		Functions\when( 'wp_generate_uuid4' )->alias(
			function () use ( &$counter ) {
				++$counter;
				return "schedule-uuid-{$counter}";
			}
		);

		Functions\when( 'as_schedule_recurring_action' )->alias(
			function () use ( &$scheduled ) {
				$scheduled = true;
				return 1;
			}
		);
		Functions\when( 'as_unschedule_action' )->alias(
			function () use ( &$unscheduled ) {
				$unscheduled = true;
			}
		);
		Functions\when( 'as_next_scheduled_action' )->justReturn( 1893456000 );
	}

	/**
	 * Test init registers the recurring hook handler.
	 *
	 * @return void
	 */
	public function test_init_registers_hook(): void {
		Actions\expectAdded( ImportScheduler::HOOK_NAME );

		$scheduler = new ImportScheduler();
		$scheduler->init();

		$this->assertBrainMonkeyExpectations();
	}

	/**
	 * Test saving a schedule registers a recurring action and stores it.
	 *
	 * @return void
	 */
	public function test_save_schedule_registers_recurring_action(): void {
		$scheduler = new ImportScheduler();

		$schedule = $scheduler->save_schedule(
			array(
				'source_adapter' => 'twitter',
				'interval'       => 'daily',
				'enabled'        => true,
			)
		);

		$this->assertTrue( $this->recurring_scheduled );
		$this->assertSame( 'twitter', $schedule['source_adapter'] );
		$this->assertSame( 'daily', $schedule['interval'] );
		$this->assertTrue( $schedule['enabled'] );
		$this->assertNotEmpty( $schedule['id'] );

		// Stored in the option.
		$this->assertCount( 1, $this->options[ ImportScheduler::OPTION_KEY ] );
	}

	/**
	 * Test a disabled schedule does not register a recurring action.
	 *
	 * @return void
	 */
	public function test_save_disabled_schedule_does_not_schedule(): void {
		$scheduler = new ImportScheduler();

		$scheduler->save_schedule(
			array(
				'source_adapter' => 'twitter',
				'interval'       => 'daily',
				'enabled'        => false,
			)
		);

		$this->assertFalse( $this->recurring_scheduled );
		// The action is still cleared first (to remove any prior schedule).
		$this->assertTrue( $this->action_unscheduled );
	}

	/**
	 * Test updating an existing schedule replaces it in place.
	 *
	 * @return void
	 */
	public function test_save_schedule_updates_existing(): void {
		$scheduler = new ImportScheduler();

		$created = $scheduler->save_schedule(
			array(
				'source_adapter' => 'twitter',
				'interval'       => 'daily',
			)
		);

		$updated = $scheduler->save_schedule(
			array(
				'id'             => $created['id'],
				'source_adapter' => 'twitter',
				'interval'       => 'weekly',
			)
		);

		$this->assertSame( $created['id'], $updated['id'] );
		$this->assertSame( 'weekly', $updated['interval'] );
		$this->assertCount( 1, $this->options[ ImportScheduler::OPTION_KEY ] );
	}

	/**
	 * Test deleting a schedule unschedules its action and removes it.
	 *
	 * @return void
	 */
	public function test_delete_schedule_unschedules_action(): void {
		$scheduler = new ImportScheduler();

		$schedule = $scheduler->save_schedule(
			array(
				'source_adapter' => 'twitter',
				'interval'       => 'daily',
			)
		);

		$this->action_unscheduled = false;

		$result = $scheduler->delete_schedule( $schedule['id'] );

		$this->assertTrue( $result );
		$this->assertTrue( $this->action_unscheduled );
		$this->assertSame( array(), $this->options[ ImportScheduler::OPTION_KEY ] );
	}

	/**
	 * Test deleting an unknown schedule returns false.
	 *
	 * @return void
	 */
	public function test_delete_unknown_schedule_returns_false(): void {
		$scheduler = new ImportScheduler();

		$this->assertFalse( $scheduler->delete_schedule( 'does-not-exist' ) );
	}

	/**
	 * Test running a schedule for a connected source creates a batch.
	 *
	 * @return void
	 */
	public function test_run_schedule_creates_batch_for_connected_source(): void {
		$this->register_mock_adapter( 'twitter', true, array( 'tweet-1', 'tweet-2' ) );

		$created_batch = null;
		Actions\expectDone( 'ai_importer_batch_created' )
			->once()
			->whenHappen(
				function ( $batch_id, $batch ) use ( &$created_batch ) {
					$created_batch = $batch;
				}
			);

		$scheduler = new ImportScheduler();
		$schedule  = $scheduler->save_schedule(
			array(
				'source_adapter' => 'twitter',
				'interval'       => 'daily',
			)
		);

		$scheduler->run_schedule( $schedule['id'] );

		$this->assertNotNull( $created_batch );
		$this->assertSame( 'twitter', $created_batch['source_adapter'] );
		$this->assertTrue( $created_batch['update_existing'] );
		$this->assertSame( 'processing', $created_batch['state'] );
		$this->assertSame( array( 'tweet-1', 'tweet-2' ), $created_batch['item_ids'] );
		$this->assertTrue( $created_batch['scheduled'] );

		// last_run recorded.
		$stored = $this->options[ ImportScheduler::OPTION_KEY ][0];
		$this->assertNotNull( $stored['last_run'] );
	}

	/**
	 * Test running a schedule reuses the source's saved mapping.
	 *
	 * @return void
	 */
	public function test_run_schedule_reuses_saved_mapping(): void {
		$this->register_mock_adapter( 'twitter', true, array( 'tweet-1' ) );

		$this->options['ai_importer_mappings_twitter'] = array(
			'post_type'   => 'post',
			'post_status' => 'publish',
		);

		$created_batch = null;
		Actions\expectDone( 'ai_importer_batch_created' )
			->whenHappen(
				function ( $batch_id, $batch ) use ( &$created_batch ) {
					$created_batch = $batch;
				}
			);

		$scheduler = new ImportScheduler();
		$schedule  = $scheduler->save_schedule(
			array(
				'source_adapter' => 'twitter',
				'interval'       => 'daily',
			)
		);

		$scheduler->run_schedule( $schedule['id'] );

		$this->assertSame(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			),
			$created_batch['mapping']
		);
	}

	/**
	 * Test a disconnected source is skipped without creating a batch.
	 *
	 * @return void
	 */
	public function test_run_schedule_skips_disconnected_source(): void {
		$this->register_mock_adapter( 'twitter', false, array( 'tweet-1' ) );

		Actions\expectDone( 'ai_importer_batch_created' )->never();

		$scheduler = new ImportScheduler();
		$schedule  = $scheduler->save_schedule(
			array(
				'source_adapter' => 'twitter',
				'interval'       => 'daily',
			)
		);

		$scheduler->run_schedule( $schedule['id'] );

		// No batch index created.
		$this->assertArrayNotHasKey( 'ai_importer_batch_index', $this->options );
	}

	/**
	 * Test a missing adapter is skipped gracefully.
	 *
	 * @return void
	 */
	public function test_run_schedule_skips_unknown_adapter(): void {
		Actions\expectDone( 'ai_importer_batch_created' )->never();

		$scheduler = new ImportScheduler();
		$schedule  = $scheduler->save_schedule(
			array(
				'source_adapter' => 'nonexistent',
				'interval'       => 'daily',
			)
		);

		$scheduler->run_schedule( $schedule['id'] );

		$this->assertArrayNotHasKey( 'ai_importer_batch_index', $this->options );
	}

	/**
	 * Test an overlapping run is skipped when a batch is already processing.
	 *
	 * @return void
	 */
	public function test_run_schedule_skips_when_batch_processing(): void {
		$this->register_mock_adapter( 'twitter', true, array( 'tweet-1' ) );

		// Existing processing batch for the same source.
		$this->options['ai_importer_batch_index']             = array( 'existing-batch' );
		$this->options['ai_importer_batch_existing-batch']    = array(
			'id'             => 'existing-batch',
			'source_adapter' => 'twitter',
			'state'          => 'processing',
		);

		Actions\expectDone( 'ai_importer_batch_created' )->never();

		$scheduler = new ImportScheduler();
		$schedule  = $scheduler->save_schedule(
			array(
				'source_adapter' => 'twitter',
				'interval'       => 'daily',
			)
		);

		$scheduler->run_schedule( $schedule['id'] );

		// Index unchanged (no new batch added).
		$this->assertSame( array( 'existing-batch' ), $this->options['ai_importer_batch_index'] );
	}

	/**
	 * Test a disabled schedule does not run even when fired.
	 *
	 * @return void
	 */
	public function test_run_schedule_skips_disabled_schedule(): void {
		$this->register_mock_adapter( 'twitter', true, array( 'tweet-1' ) );

		Actions\expectDone( 'ai_importer_batch_created' )->never();

		$scheduler = new ImportScheduler();
		$schedule  = $scheduler->save_schedule(
			array(
				'source_adapter' => 'twitter',
				'interval'       => 'daily',
				'enabled'        => false,
			)
		);

		$scheduler->run_schedule( $schedule['id'] );

		$this->assertArrayNotHasKey( 'ai_importer_batch_index', $this->options );
	}

	/**
	 * Test running an unknown schedule ID does nothing.
	 *
	 * @return void
	 */
	public function test_run_unknown_schedule_does_nothing(): void {
		Actions\expectDone( 'ai_importer_batch_created' )->never();

		$scheduler = new ImportScheduler();
		$scheduler->run_schedule( 'missing-schedule' );

		$this->assertArrayNotHasKey( 'ai_importer_batch_index', $this->options );
	}

	/**
	 * Register a mock adapter that returns a manifest with the given items.
	 *
	 * @param string             $id              Adapter ID.
	 * @param bool               $is_authenticated Whether authenticated.
	 * @param array<int, string> $item_ids        Manifest item IDs.
	 * @return void
	 */
	private function register_mock_adapter( string $id, bool $is_authenticated, array $item_ids ): void {
		$manifest = new ContentManifest( $id );

		foreach ( $item_ids as $item_id ) {
			$manifest->add_item(
				new ManifestItem(
					id: $item_id,
					type: ContentType::POST,
					title: 'Item ' . $item_id,
					created_at: new DateTimeImmutable( '2024-01-15' ),
				)
			);
		}

		$adapter = Mockery::mock( AdapterInterface::class );
		$adapter->shouldReceive( 'get_id' )->andReturn( $id );
		$adapter->shouldReceive( 'get_name' )->andReturn( ucfirst( $id ) );
		$adapter->shouldReceive( 'get_description' )->andReturn( "The {$id} adapter." );
		$adapter->shouldReceive( 'get_icon' )->andReturn( "dashicons-{$id}" );
		$adapter->shouldReceive( 'get_auth_type' )->andReturn( 'file_upload' );
		$adapter->shouldReceive( 'is_authenticated' )->andReturn( $is_authenticated );
		$adapter->shouldReceive( 'get_supported_content_types' )->andReturn( array( 'post' ) );
		$adapter->shouldReceive( 'fetch_manifest' )->andReturn( $manifest );

		AdapterRegistry::get_instance()->register( $adapter );
	}
}
