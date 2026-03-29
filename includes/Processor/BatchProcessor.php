<?php
/**
 * Batch processor for orchestrating imports via Action Scheduler.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Processor;

use AI_Importer\Adapters\AdapterRegistry;
use AI_Importer\Normalizer\ContentNormalizer;
use DateTimeImmutable;
use RuntimeException;

/**
 * Orchestrates batch import processing via Action Scheduler.
 */
class BatchProcessor {

	/**
	 * Action Scheduler hook name.
	 *
	 * @var string
	 */
	public const ACTION_HOOK = 'ai_importer_process_batch';

	/**
	 * Number of items to process per scheduled action.
	 *
	 * @var int
	 */
	private const CHUNK_SIZE = 5;

	/**
	 * Batch repository.
	 *
	 * @var BatchRepository
	 */
	private BatchRepository $repository;

	/**
	 * Item processor.
	 *
	 * @var ItemProcessor
	 */
	private ItemProcessor $item_processor;

	/**
	 * Adapter registry.
	 *
	 * @var AdapterRegistry
	 */
	private AdapterRegistry $adapter_registry;

	/**
	 * Constructor.
	 *
	 * @param BatchRepository $repository       Batch repository.
	 * @param ItemProcessor   $item_processor   Item processor.
	 * @param AdapterRegistry $adapter_registry Adapter registry.
	 */
	public function __construct(
		BatchRepository $repository,
		ItemProcessor $item_processor,
		AdapterRegistry $adapter_registry,
	) {
		$this->repository       = $repository;
		$this->item_processor   = $item_processor;
		$this->adapter_registry = $adapter_registry;
	}

	/**
	 * Initialize the processor by registering the Action Scheduler hook.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::ACTION_HOOK, array( $this, 'process_chunk' ) );
	}

	/**
	 * Create a new import batch and schedule processing.
	 *
	 * @param string             $source_adapter Source adapter ID.
	 * @param array<int, string> $item_ids       Manifest item IDs to import.
	 * @return ImportBatch The created batch.
	 */
	public function create_batch(
		string $source_adapter,
		array $item_ids,
	): ImportBatch {
		$batch = new ImportBatch(
			id: ImportBatch::generate_id(),
			source_adapter: $source_adapter,
			item_ids: $item_ids,
			created_at: new DateTimeImmutable(),
		);

		$batch->transition_to( ImportState::PROCESSING );
		$this->repository->save( $batch );
		$this->schedule_next_chunk( $batch->id );

		return $batch;
	}

	/**
	 * Process the next chunk of items in a batch.
	 *
	 * This is the Action Scheduler callback.
	 *
	 * @param string $batch_id The batch UUID.
	 * @return void
	 */
	public function process_chunk( string $batch_id ): void {
		$batch = $this->repository->find( $batch_id );

		if ( null === $batch ) {
			return;
		}

		// Do not process terminal or paused batches.
		if ( $batch->state->is_terminal() || ImportState::PAUSED === $batch->state ) {
			return;
		}

		$adapter = $this->adapter_registry->get( $batch->source_adapter );

		if ( null === $adapter ) {
			$batch->transition_to( ImportState::FAILED );
			$batch->add_error( '', 'Source adapter not found: ' . $batch->source_adapter );
			$this->repository->save( $batch );
			return;
		}

		$chunk = $this->get_next_chunk( $batch );

		foreach ( $chunk as $item_id ) {
			try {
				$raw_item        = $adapter->fetch_item( $item_id );
				$normalizer      = $this->get_normalizer( $batch->source_adapter );
				$normalized_item = $normalizer->normalize( $raw_item );
				$post_id         = $this->item_processor->process( $normalized_item, $batch->id );
				$batch->mark_item_processed( $post_id );
			} catch ( \Throwable $e ) {
				$batch->mark_item_failed( $item_id, $e->getMessage() );
			}
		}

		$batch->current_offset += count( $chunk );
		$this->repository->save( $batch );

		// Schedule next chunk or complete.
		if ( $batch->current_offset < $batch->get_total_items() ) {
			$this->schedule_next_chunk( $batch->id );
		} else {
			$batch->transition_to( ImportState::COMPLETED );
			$this->repository->save( $batch );

			/**
			 * Fires when an import batch completes.
			 *
			 * @param ImportBatch $batch The completed batch.
			 */
			do_action( 'ai_importer_batch_completed', $batch );
		}
	}

	/**
	 * Pause a running batch.
	 *
	 * @param string $batch_id The batch UUID.
	 * @return void
	 * @throws RuntimeException If the batch cannot be paused.
	 */
	public function pause_batch( string $batch_id ): void {
		$batch = $this->repository->find( $batch_id );

		if ( null === $batch ) {
			throw new RuntimeException( 'Batch not found: ' . esc_html( $batch_id ) );
		}

		$batch->transition_to( ImportState::PAUSED );
		$this->repository->save( $batch );

		as_unschedule_all_actions( self::ACTION_HOOK, array( $batch_id ), 'ai-importer' );
	}

	/**
	 * Resume a paused batch.
	 *
	 * @param string $batch_id The batch UUID.
	 * @return void
	 * @throws RuntimeException If the batch cannot be resumed.
	 */
	public function resume_batch( string $batch_id ): void {
		$batch = $this->repository->find( $batch_id );

		if ( null === $batch ) {
			throw new RuntimeException( 'Batch not found: ' . esc_html( $batch_id ) );
		}

		$batch->transition_to( ImportState::PROCESSING );
		$this->repository->save( $batch );
		$this->schedule_next_chunk( $batch_id );
	}

	/**
	 * Rollback a batch by deleting all created posts and attachments.
	 *
	 * @param string $batch_id The batch UUID.
	 * @return void
	 * @throws RuntimeException If the batch cannot be rolled back.
	 */
	public function rollback_batch( string $batch_id ): void {
		$batch = $this->repository->find( $batch_id );

		if ( null === $batch ) {
			throw new RuntimeException( 'Batch not found: ' . esc_html( $batch_id ) );
		}

		// Cancel any pending scheduled actions.
		as_unschedule_all_actions( self::ACTION_HOOK, array( $batch_id ), 'ai-importer' );

		// Delete attachments associated with this batch.
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for batch rollback.
		$attachments = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'meta_key'    => '_ai_importer_batch_id',
				'meta_value'  => $batch_id,
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		foreach ( $attachments as $attachment_id ) {
			wp_delete_attachment( (int) $attachment_id, true );
		}

		// Delete created posts.
		foreach ( $batch->created_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$batch->transition_to( ImportState::ROLLED_BACK );
		$this->repository->save( $batch );

		/**
		 * Fires when an import batch is rolled back.
		 *
		 * @param ImportBatch $batch The rolled-back batch.
		 */
		do_action( 'ai_importer_batch_rolled_back', $batch );
	}

	/**
	 * Get progress information for a batch.
	 *
	 * @param string $batch_id The batch UUID.
	 * @return array<string, mixed>|null Progress data, or null if batch not found.
	 */
	public function get_progress( string $batch_id ): ?array {
		$batch = $this->repository->find( $batch_id );

		if ( null === $batch ) {
			return null;
		}

		return array(
			'batch_id'     => $batch->id,
			'state'        => $batch->state->value,
			'state_label'  => $batch->state->get_label(),
			'total'        => $batch->get_total_items(),
			'processed'    => $batch->processed_items,
			'failed'       => $batch->failed_items,
			'skipped'      => $batch->skipped_items,
			'percentage'   => $batch->get_progress_percentage(),
			'errors'       => $batch->errors,
			'created_at'   => $batch->created_at->format( 'c' ),
			'started_at'   => $batch->started_at?->format( 'c' ),
			'completed_at' => $batch->completed_at?->format( 'c' ),
		);
	}

	/**
	 * Schedule the next chunk for processing.
	 *
	 * @param string $batch_id The batch UUID.
	 * @return void
	 */
	private function schedule_next_chunk( string $batch_id ): void {
		as_schedule_single_action( time(), self::ACTION_HOOK, array( $batch_id ), 'ai-importer' );
	}

	/**
	 * Get the next chunk of item IDs to process.
	 *
	 * @param ImportBatch $batch The batch.
	 * @return array<int, string> Item IDs for this chunk.
	 */
	private function get_next_chunk( ImportBatch $batch ): array {
		return array_slice( $batch->item_ids, $batch->current_offset, self::CHUNK_SIZE );
	}

	/**
	 * Get the content normalizer for an adapter.
	 *
	 * @param string $adapter_id The adapter ID.
	 * @return ContentNormalizer The normalizer.
	 * @throws RuntimeException If no normalizer is available.
	 */
	private function get_normalizer( string $adapter_id ): ContentNormalizer {
		/**
		 * Filters the content normalizer for an adapter.
		 *
		 * @param ContentNormalizer|null $normalizer  The normalizer instance.
		 * @param string                $adapter_id  The adapter ID.
		 */
		$normalizer = apply_filters( 'ai_importer_content_normalizer', null, $adapter_id );

		if ( null === $normalizer ) {
			throw new RuntimeException( 'No content normalizer registered for adapter: ' . esc_html( $adapter_id ) );
		}

		return $normalizer;
	}
}
