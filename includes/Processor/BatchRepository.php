<?php
/**
 * Batch repository for wp_options storage.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Processor;

/**
 * CRUD operations for ImportBatch data in wp_options.
 */
class BatchRepository {

	/**
	 * Option key prefix for individual batches.
	 *
	 * @var string
	 */
	private const OPTION_PREFIX = 'ai_importer_batch_';

	/**
	 * Option key for the batch index.
	 *
	 * @var string
	 */
	private const INDEX_OPTION = 'ai_importer_batch_index';

	/**
	 * Save a batch to the database.
	 *
	 * @param ImportBatch $batch The batch to save.
	 * @return bool True on success.
	 */
	public function save( ImportBatch $batch ): bool {
		$result = update_option(
			$this->get_option_key( $batch->id ),
			$batch->to_array(),
			false
		);

		$this->update_index( $batch->id, $batch->state->value, $batch->created_at->format( 'c' ) );

		return $result;
	}

	/**
	 * Find a batch by ID.
	 *
	 * @param string $batch_id The batch UUID.
	 * @return ImportBatch|null The batch, or null if not found.
	 */
	public function find( string $batch_id ): ?ImportBatch {
		$data = get_option( $this->get_option_key( $batch_id ), null );

		if ( ! is_array( $data ) ) {
			return null;
		}

		return ImportBatch::from_array( $data );
	}

	/**
	 * Delete a batch from the database.
	 *
	 * @param string $batch_id The batch UUID.
	 * @return bool True on success.
	 */
	public function delete( string $batch_id ): bool {
		$result = delete_option( $this->get_option_key( $batch_id ) );
		$this->remove_from_index( $batch_id );
		return $result;
	}

	/**
	 * Find all non-terminal batches.
	 *
	 * @return array<int, ImportBatch>
	 */
	public function find_active(): array {
		$index   = $this->get_index();
		$batches = array();

		foreach ( $index as $entry ) {
			$state = ImportState::tryFrom( $entry['state'] ?? '' );
			if ( null !== $state && ! $state->is_terminal() ) {
				$batch = $this->find( $entry['id'] );
				if ( null !== $batch ) {
					$batches[] = $batch;
				}
			}
		}

		return $batches;
	}

	/**
	 * Find batches by state.
	 *
	 * @param ImportState $state The state to filter by.
	 * @return array<int, ImportBatch>
	 */
	public function find_by_state( ImportState $state ): array {
		$index   = $this->get_index();
		$batches = array();

		foreach ( $index as $entry ) {
			if ( ( $entry['state'] ?? '' ) === $state->value ) {
				$batch = $this->find( $entry['id'] );
				if ( null !== $batch ) {
					$batches[] = $batch;
				}
			}
		}

		return $batches;
	}

	/**
	 * Get recent batches, ordered by creation date descending.
	 *
	 * @param int $limit Maximum number of batches to return.
	 * @return array<int, ImportBatch>
	 */
	public function get_recent( int $limit = 10 ): array {
		$index = $this->get_index();

		// Sort by created_at descending.
		usort(
			$index,
			function ( array $a, array $b ): int {
				return strcmp( $b['created_at'] ?? '', $a['created_at'] ?? '' );
			}
		);

		$index   = array_slice( $index, 0, $limit );
		$batches = array();

		foreach ( $index as $entry ) {
			$batch = $this->find( $entry['id'] );
			if ( null !== $batch ) {
				$batches[] = $batch;
			}
		}

		return $batches;
	}

	/**
	 * Get the option key for a batch.
	 *
	 * @param string $batch_id The batch UUID.
	 * @return string
	 */
	private function get_option_key( string $batch_id ): string {
		return self::OPTION_PREFIX . $batch_id;
	}

	/**
	 * Get the batch index.
	 *
	 * @return array<int, array{id: string, state: string, created_at: string}>
	 */
	private function get_index(): array {
		$index = get_option( self::INDEX_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}

	/**
	 * Update the batch index with current batch info.
	 *
	 * @param string $batch_id   The batch UUID.
	 * @param string $state      The batch state value.
	 * @param string $created_at The creation timestamp.
	 * @return void
	 */
	private function update_index( string $batch_id, string $state, string $created_at ): void {
		$index = $this->get_index();

		// Update existing entry or add new one.
		$found = false;
		foreach ( $index as &$entry ) {
			if ( $entry['id'] === $batch_id ) {
				$entry['state'] = $state;
				$found          = true;
				break;
			}
		}
		unset( $entry );

		if ( ! $found ) {
			$index[] = array(
				'id'         => $batch_id,
				'state'      => $state,
				'created_at' => $created_at,
			);
		}

		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Remove a batch from the index.
	 *
	 * @param string $batch_id The batch UUID.
	 * @return void
	 */
	private function remove_from_index( string $batch_id ): void {
		$index = $this->get_index();
		$index = array_values(
			array_filter(
				$index,
				fn( array $entry ) => $entry['id'] !== $batch_id
			)
		);

		update_option( self::INDEX_OPTION, $index, false );
	}
}
