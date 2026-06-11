<?php
/**
 * Import processor for background batch processing.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Processor;

use AI_Importer\Adapters\AdapterRegistry;
use AI_Importer\Normalizer\ContentNormalizer;
use AI_Importer\Normalizer\BloggerNormalizer;
use AI_Importer\Normalizer\InstagramNormalizer;
use AI_Importer\Normalizer\MediumNormalizer;
use AI_Importer\Normalizer\SubstackNormalizer;
use AI_Importer\Normalizer\TumblrNormalizer;
use AI_Importer\Normalizer\TwitterNormalizer;

/**
 * Orchestrates the import pipeline using Action Scheduler for
 * background processing. Listens to batch lifecycle hooks from
 * ImportsController and processes items in chunks.
 */
class ImportProcessor {

	/**
	 * Number of items to process per Action Scheduler invocation.
	 */
	private const CHUNK_SIZE = 10;

	/**
	 * Maximum seconds per invocation before re-enqueueing.
	 */
	private const TIME_LIMIT = 25;

	/**
	 * Action Scheduler hook name.
	 */
	public const HOOK_NAME = 'ai_importer_process_batch';

	/**
	 * Action Scheduler group name.
	 */
	private const AS_GROUP = 'ai-importer';

	/**
	 * Maximum number of errors to store per batch.
	 */
	private const MAX_ERRORS = 100;

	/**
	 * Content creator instance.
	 *
	 * @var ContentCreator
	 */
	private ContentCreator $creator;

	/**
	 * Media handler instance.
	 *
	 * @var MediaHandler
	 */
	private MediaHandler $media_handler;

	/**
	 * Optional AI enhancer.
	 *
	 * @var ItemEnhancer|null
	 */
	private ?ItemEnhancer $enhancer;

	/**
	 * Registered normalizers keyed by adapter ID.
	 *
	 * @var array<string, ContentNormalizer>|null
	 */
	private ?array $normalizers = null;

	/**
	 * Constructor.
	 *
	 * @param ContentCreator|null $creator       Content creator instance.
	 * @param MediaHandler|null   $media_handler Media handler instance.
	 * @param ItemEnhancer|null   $enhancer      Optional AI enhancer.
	 */
	public function __construct(
		?ContentCreator $creator = null,
		?MediaHandler $media_handler = null,
		?ItemEnhancer $enhancer = null
	) {
		$this->creator       = $creator ?? new ContentCreator();
		$this->media_handler = $media_handler ?? new MediaHandler();
		$this->enhancer      = $enhancer;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'ai_importer_batch_created', array( $this, 'on_batch_created' ), 10, 2 );
		add_action( 'ai_importer_batch_resumed', array( $this, 'on_batch_resumed' ), 10, 2 );
		add_action( 'ai_importer_batch_paused', array( $this, 'on_batch_paused' ), 10, 2 );
		add_action( self::HOOK_NAME, array( $this, 'process_batch' ) );
	}

	/**
	 * Schedule processing when a batch is created.
	 *
	 * @param string               $batch_id The batch UUID.
	 * @param array<string, mixed> $batch    The batch data.
	 * @return void
	 */
	public function on_batch_created( string $batch_id, array $batch ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by hook signature.
		$this->schedule_processing( $batch_id );
	}

	/**
	 * Re-schedule processing when a batch is resumed.
	 *
	 * @param string               $batch_id The batch UUID.
	 * @param array<string, mixed> $batch    The batch data.
	 * @return void
	 */
	public function on_batch_resumed( string $batch_id, array $batch ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by hook signature.
		$this->schedule_processing( $batch_id );
	}

	/**
	 * Cancel pending actions when a batch is paused.
	 *
	 * @param string               $batch_id The batch UUID.
	 * @param array<string, mixed> $batch    The batch data.
	 * @return void
	 */
	public function on_batch_paused( string $batch_id, array $batch ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by hook signature.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_NAME, array( $batch_id ), self::AS_GROUP );
		}
	}

	/**
	 * Main processing loop. Called by Action Scheduler.
	 *
	 * Processes up to CHUNK_SIZE items or TIME_LIMIT seconds per invocation,
	 * then re-enqueues itself if items remain.
	 *
	 * @param string $batch_id The batch UUID.
	 * @return void
	 */
	public function process_batch( string $batch_id ): void {
		$batch = get_option( 'ai_importer_batch_' . $batch_id, false );

		if ( ! $batch || 'processing' !== $batch['state'] ) {
			return;
		}

		// Record when processing actually began so progress ETA can be computed.
		if ( empty( $batch['started_at'] ) ) {
			$batch['started_at'] = gmdate( 'c' );
			update_option( 'ai_importer_batch_' . $batch_id, $batch, false );
		}

		$adapter = AdapterRegistry::get_instance()->get( $batch['source_adapter'] );

		if ( ! $adapter ) {
			$batch['state']        = 'failed';
			$batch['errors'][]     = array(
				'item_id' => '',
				'message' => sprintf( 'Source adapter "%s" not found.', $batch['source_adapter'] ),
			);
			$batch['completed_at'] = gmdate( 'c' );
			update_option( 'ai_importer_batch_' . $batch_id, $batch, false );
			return;
		}

		$normalizer = $this->get_normalizer( $batch['source_adapter'] );

		if ( ! $normalizer ) {
			$batch['state']        = 'failed';
			$batch['errors'][]     = array(
				'item_id' => '',
				'message' => sprintf( 'No normalizer found for adapter "%s".', $batch['source_adapter'] ),
			);
			$batch['completed_at'] = gmdate( 'c' );
			update_option( 'ai_importer_batch_' . $batch_id, $batch, false );
			return;
		}

		// Older batches may predate the 'skipped' counter.
		if ( ! isset( $batch['skipped'] ) ) {
			$batch['skipped'] = 0;
		}

		$update_existing = ! empty( $batch['update_existing'] );

		$start_time = time();
		$processed  = 0;
		$offset     = (int) $batch['processed'] + (int) $batch['failed'] + (int) $batch['skipped'];

		while ( $processed < self::CHUNK_SIZE && ( time() - $start_time ) < self::TIME_LIMIT ) {
			$index = $offset + $processed;

			// All items done.
			if ( $index >= count( $batch['item_ids'] ) ) {
				break;
			}

			// Re-check state in case it was paused mid-loop.
			$current_batch = get_option( 'ai_importer_batch_' . $batch_id, false );

			if ( ! $current_batch || 'processing' !== $current_batch['state'] ) {
				return;
			}

			$item_id = $batch['item_ids'][ $index ];

			try {
				$raw_item   = $adapter->fetch_item( $item_id );
				$normalized = $normalizer->normalize( $raw_item );

				// Detect already-imported content before doing any heavy work.
				$existing_id = $this->creator->find_existing(
					$normalized->source_adapter,
					$normalized->source_id
				);

				if ( null !== $existing_id && ! $update_existing ) {
					// Duplicate found and updates not requested — skip.
					++$batch['skipped'];
				} else {
					// Apply AI enhancements (non-fatal failures handled inside).
					if ( null !== $this->enhancer ) {
						$this->enhancer->enhance( $normalized );
					}

					// Sideload media (failures are non-fatal).
					$media_errors = $this->media_handler->process( $normalized );

					foreach ( $media_errors as $media_error ) {
						$batch['errors'][] = array(
							'item_id' => $item_id,
							'message' => $media_error,
						);
					}

					if ( null !== $existing_id ) {
						// Update the existing post in place. The post is not
						// added to imported_ids so a rollback of this batch
						// does not delete content from earlier imports.
						$this->creator->update( $existing_id, $normalized, $batch_id );
					} else {
						// Create the WordPress post.
						$post_id                 = $this->creator->create( $normalized, $batch_id );
						$batch['imported_ids'][] = $post_id;
					}

					++$batch['processed'];
				}
			} catch ( \Throwable $e ) {
				++$batch['failed'];
				$batch['errors'][] = array(
					'item_id' => $item_id,
					'message' => $e->getMessage(),
				);
			}

			// Cap errors.
			if ( count( $batch['errors'] ) > self::MAX_ERRORS ) {
				$batch['errors'] = array_slice( $batch['errors'], -self::MAX_ERRORS );
			}

			// Save progress after each item.
			update_option( 'ai_importer_batch_' . $batch_id, $batch, false );

			++$processed;
		}

		// Check if all items have been processed.
		$total_handled = (int) $batch['processed'] + (int) $batch['failed'] + (int) $batch['skipped'];

		if ( $total_handled >= count( $batch['item_ids'] ) ) {
			$batch['state']        = ( count( $batch['item_ids'] ) === (int) $batch['failed'] ) ? 'failed' : 'completed';
			$batch['completed_at'] = gmdate( 'c' );
			update_option( 'ai_importer_batch_' . $batch_id, $batch, false );

			/**
			 * Fires when an import batch finishes processing.
			 *
			 * @param string               $batch_id The batch UUID.
			 * @param array<string, mixed> $batch    The batch data.
			 */
			do_action( 'ai_importer_batch_completed', $batch_id, $batch );
		} else {
			// More items to process — re-enqueue.
			$this->schedule_processing( $batch_id );
		}
	}

	/**
	 * Schedule an async Action Scheduler job for a batch.
	 *
	 * @param string $batch_id The batch UUID.
	 * @return void
	 */
	private function schedule_processing( string $batch_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_NAME, array( $batch_id ), self::AS_GROUP );
		}
	}

	/**
	 * Get the normalizer for an adapter.
	 *
	 * @param string $adapter_id The adapter ID.
	 * @return ContentNormalizer|null The normalizer or null.
	 */
	private function get_normalizer( string $adapter_id ): ?ContentNormalizer {
		if ( null === $this->normalizers ) {
			$this->normalizers = array(
				'twitter'   => new TwitterNormalizer(),
				'medium'    => new MediumNormalizer(),
				'instagram' => new InstagramNormalizer(),
				'blogger'   => new BloggerNormalizer(),
				'tumblr'    => new TumblrNormalizer(),
				'substack'  => new SubstackNormalizer(),
			);

			/**
			 * Filters the registered normalizers.
			 *
			 * @param array<string, ContentNormalizer> $normalizers Normalizers keyed by adapter ID.
			 */
			$this->normalizers = apply_filters( 'ai_importer_normalizers', $this->normalizers );
		}

		return $this->normalizers[ $adapter_id ] ?? null;
	}
}
