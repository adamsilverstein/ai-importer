<?php
/**
 * Import state enum.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Processor;

/**
 * Enum representing the states of an import batch.
 */
enum ImportState: string {
	/**
	 * Batch created, not yet started.
	 */
	case PENDING = 'pending';

	/**
	 * Batch is actively processing items.
	 */
	case PROCESSING = 'processing';

	/**
	 * Batch has been paused by the user.
	 */
	case PAUSED = 'paused';

	/**
	 * All items have been processed.
	 */
	case COMPLETED = 'completed';

	/**
	 * Batch encountered an unrecoverable error.
	 */
	case FAILED = 'failed';

	/**
	 * Batch has been rolled back (all created posts deleted).
	 */
	case ROLLED_BACK = 'rolled_back';

	/**
	 * Valid state transitions.
	 */
	private const TRANSITIONS = array(
		'pending'     => array( 'processing', 'failed' ),
		'processing'  => array( 'paused', 'completed', 'failed' ),
		'paused'      => array( 'processing', 'failed' ),
		'completed'   => array( 'rolled_back' ),
		'failed'      => array( 'processing', 'rolled_back' ),
		'rolled_back' => array(),
	);

	/**
	 * Check if a transition to the target state is valid.
	 *
	 * @param ImportState $target The target state.
	 * @return bool True if the transition is valid.
	 */
	public function can_transition_to( ImportState $target ): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid in PHP 8.1+ enums.
		$allowed = self::TRANSITIONS[ $this->value ] ?? array();
		return in_array( $target->value, $allowed, true );
	}

	/**
	 * Get a human-readable label for the state.
	 *
	 * @return string The label.
	 */
	public function get_label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid in PHP 8.1+ enums.
		return match ( $this ) {
			self::PENDING     => __( 'Pending', 'ai-importer' ),
			self::PROCESSING  => __( 'Processing', 'ai-importer' ),
			self::PAUSED      => __( 'Paused', 'ai-importer' ),
			self::COMPLETED   => __( 'Completed', 'ai-importer' ),
			self::FAILED      => __( 'Failed', 'ai-importer' ),
			self::ROLLED_BACK => __( 'Rolled Back', 'ai-importer' ),
		};
	}

	/**
	 * Check if this is a terminal state.
	 *
	 * @return bool True if the state is terminal.
	 */
	public function is_terminal(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid in PHP 8.1+ enums.
		return match ( $this ) {
			self::COMPLETED, self::FAILED, self::ROLLED_BACK => true,
			default => false,
		};
	}
}
