<?php
/**
 * Import batch value object.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Processor;

use DateTimeImmutable;
use LogicException;

/**
 * Represents an import batch with progress tracking.
 */
class ImportBatch {

	/**
	 * Maximum number of errors to store per batch.
	 *
	 * @var int
	 */
	private const MAX_ERRORS = 100;

	/**
	 * Batch state.
	 *
	 * @var ImportState
	 */
	public ImportState $state;

	/**
	 * Number of items processed successfully.
	 *
	 * @var int
	 */
	public int $processed_items = 0;

	/**
	 * Number of items that failed to import.
	 *
	 * @var int
	 */
	public int $failed_items = 0;

	/**
	 * Number of items skipped.
	 *
	 * @var int
	 */
	public int $skipped_items = 0;

	/**
	 * Error log entries.
	 *
	 * @var array<int, array{item_id: string, message: string, timestamp: string}>
	 */
	public array $errors = array();

	/**
	 * WordPress post IDs created by this batch.
	 *
	 * @var array<int, int>
	 */
	public array $created_post_ids = array();

	/**
	 * Current offset into item_ids for chunked processing.
	 *
	 * @var int
	 */
	public int $current_offset = 0;

	/**
	 * Timestamp when processing started.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $started_at = null;

	/**
	 * Timestamp when processing completed.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $completed_at = null;

	/**
	 * Constructor.
	 *
	 * @param string             $id              Batch UUID.
	 * @param string             $source_adapter  Source adapter ID.
	 * @param array<int, string> $item_ids        Manifest item IDs to import.
	 * @param DateTimeImmutable  $created_at      Creation timestamp.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $source_adapter,
		public readonly array $item_ids,
		public readonly DateTimeImmutable $created_at,
	) {
		$this->state = ImportState::PENDING;
	}

	/**
	 * Get total number of items in this batch.
	 *
	 * @return int
	 */
	public function get_total_items(): int {
		return count( $this->item_ids );
	}

	/**
	 * Get progress as a percentage.
	 *
	 * @return float Progress percentage (0-100).
	 */
	public function get_progress_percentage(): float {
		$total = $this->get_total_items();
		if ( 0 === $total ) {
			return 100.0;
		}

		$completed = $this->processed_items + $this->failed_items + $this->skipped_items;
		return round( ( $completed / $total ) * 100, 1 );
	}

	/**
	 * Mark an item as successfully processed.
	 *
	 * @param int $post_id The created WordPress post ID.
	 * @return void
	 */
	public function mark_item_processed( int $post_id ): void {
		++$this->processed_items;
		$this->created_post_ids[] = $post_id;
	}

	/**
	 * Mark an item as failed.
	 *
	 * @param string $item_id The manifest item ID.
	 * @param string $message Error message.
	 * @return void
	 */
	public function mark_item_failed( string $item_id, string $message ): void {
		++$this->failed_items;
		$this->add_error( $item_id, $message );
	}

	/**
	 * Mark an item as skipped.
	 *
	 * @return void
	 */
	public function mark_item_skipped(): void {
		++$this->skipped_items;
	}

	/**
	 * Add an error entry.
	 *
	 * @param string $item_id The manifest item ID.
	 * @param string $message Error message.
	 * @return void
	 */
	public function add_error( string $item_id, string $message ): void {
		if ( count( $this->errors ) >= self::MAX_ERRORS ) {
			array_shift( $this->errors );
		}

		$this->errors[] = array(
			'item_id'   => $item_id,
			'message'   => $message,
			'timestamp' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Transition to a new state.
	 *
	 * @param ImportState $new_state The target state.
	 * @return void
	 * @throws LogicException If the transition is invalid.
	 */
	public function transition_to( ImportState $new_state ): void {
		if ( ! $this->state->can_transition_to( $new_state ) ) {
			throw new LogicException(
				sprintf(
					'Cannot transition from %s to %s.',
					esc_html( $this->state->value ),
					esc_html( $new_state->value )
				)
			);
		}

		$this->state = $new_state;

		if ( ImportState::PROCESSING === $new_state && null === $this->started_at ) {
			$this->started_at = new DateTimeImmutable();
		}

		if ( $new_state->is_terminal() ) {
			$this->completed_at = new DateTimeImmutable();
		}
	}

	/**
	 * Serialize batch to an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'               => $this->id,
			'state'            => $this->state->value,
			'source_adapter'   => $this->source_adapter,
			'item_ids'         => $this->item_ids,
			'processed_items'  => $this->processed_items,
			'failed_items'     => $this->failed_items,
			'skipped_items'    => $this->skipped_items,
			'errors'           => $this->errors,
			'created_post_ids' => $this->created_post_ids,
			'current_offset'   => $this->current_offset,
			'created_at'       => $this->created_at->format( 'c' ),
			'started_at'       => $this->started_at?->format( 'c' ),
			'completed_at'     => $this->completed_at?->format( 'c' ),
		);
	}

	/**
	 * Create an ImportBatch from a serialized array.
	 *
	 * @param array<string, mixed> $data The serialized data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$batch = new self(
			id: $data['id'],
			source_adapter: $data['source_adapter'],
			item_ids: $data['item_ids'],
			created_at: new DateTimeImmutable( $data['created_at'] ),
		);

		$batch->state            = ImportState::from( $data['state'] );
		$batch->processed_items  = $data['processed_items'] ?? 0;
		$batch->failed_items     = $data['failed_items'] ?? 0;
		$batch->skipped_items    = $data['skipped_items'] ?? 0;
		$batch->errors           = $data['errors'] ?? array();
		$batch->created_post_ids = $data['created_post_ids'] ?? array();
		$batch->current_offset   = $data['current_offset'] ?? 0;
		$batch->started_at       = isset( $data['started_at'] ) ? new DateTimeImmutable( $data['started_at'] ) : null;
		$batch->completed_at     = isset( $data['completed_at'] ) ? new DateTimeImmutable( $data['completed_at'] ) : null;

		return $batch;
	}

	/**
	 * Generate a unique batch ID.
	 *
	 * @return string UUID v4.
	 */
	public static function generate_id(): string {
		return wp_generate_uuid4();
	}
}
