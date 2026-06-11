<?php
/**
 * Scheduled import runner.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Processor;

use AI_Importer\Adapters\AdapterRegistry;
use AI_Importer\Schema\MappingConfig;

/**
 * Manages recurring "scheduled imports" (PRD F10.3).
 *
 * A schedule records that a connected source should be polled automatically
 * on a recurring interval. When the recurring Action Scheduler hook fires for
 * a schedule, the scheduler fetches the source's manifest, builds the list of
 * item IDs, and creates an import batch reusing the standard batch-creation
 * path with update_existing=true. Because update_existing is true, the
 * incremental duplicate detection (F10.2) means a scheduled run only imports
 * NEW content while leaving previously imported posts untouched.
 *
 * Schedules are stored in the ai_importer_schedules option as a list of:
 *   - id              string  Unique schedule UUID.
 *   - source_adapter  string  Adapter ID to import from.
 *   - interval        string  One of hourly|daily|weekly.
 *   - update_existing bool    Whether duplicate posts should be updated.
 *   - enabled         bool    Whether the schedule is active.
 *   - last_run        ?string ISO 8601 timestamp of the last run, or null.
 *   - next_run        ?int    Unix timestamp of the next scheduled run, or null.
 */
class ImportScheduler {

	/**
	 * Option key holding the list of schedules.
	 */
	public const OPTION_KEY = 'ai_importer_schedules';

	/**
	 * Action Scheduler hook fired for each scheduled run.
	 */
	public const HOOK_NAME = 'ai_importer_run_scheduled_import';

	/**
	 * Action Scheduler group name.
	 */
	private const AS_GROUP = 'ai-importer';

	/**
	 * Supported recurrence intervals mapped to seconds.
	 *
	 * @var array<string, int>
	 */
	public const INTERVALS = array(
		'hourly' => HOUR_IN_SECONDS,
		'daily'  => DAY_IN_SECONDS,
		'weekly' => WEEK_IN_SECONDS,
	);

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::HOOK_NAME, array( $this, 'run_schedule' ) );
	}

	/**
	 * Get all stored schedules.
	 *
	 * @return array<int, array<string, mixed>> List of schedule records.
	 */
	public function get_schedules(): array {
		$schedules = get_option( self::OPTION_KEY, array() );

		return is_array( $schedules ) ? array_values( $schedules ) : array();
	}

	/**
	 * Get a single schedule by ID.
	 *
	 * @param string $schedule_id Schedule ID.
	 * @return array<string, mixed>|null The schedule, or null when not found.
	 */
	public function get_schedule( string $schedule_id ): ?array {
		foreach ( $this->get_schedules() as $schedule ) {
			if ( isset( $schedule['id'] ) && $schedule['id'] === $schedule_id ) {
				return $schedule;
			}
		}

		return null;
	}

	/**
	 * Create or update a schedule and (re)register its recurring action.
	 *
	 * When an existing ID is supplied the matching schedule is replaced;
	 * otherwise a new schedule is created with a generated UUID. The recurring
	 * Action Scheduler action is always re-registered to reflect the latest
	 * interval and enabled state.
	 *
	 * @param array<string, mixed> $data {
	 *     Schedule data.
	 *
	 *     @type string $id              Optional. Existing schedule ID to update.
	 *     @type string $source_adapter  Adapter ID.
	 *     @type string $interval        One of hourly|daily|weekly.
	 *     @type bool   $update_existing Whether to update duplicate posts.
	 *     @type bool   $enabled         Whether the schedule is active.
	 * }
	 * @return array<string, mixed> The saved schedule record.
	 */
	public function save_schedule( array $data ): array {
		$schedules = $this->get_schedules();

		$id       = isset( $data['id'] ) && is_string( $data['id'] ) && '' !== $data['id'] ? $data['id'] : '';
		$interval = isset( $data['interval'] ) && isset( self::INTERVALS[ $data['interval'] ] )
			? $data['interval']
			: 'daily';

		$schedule = array(
			'id'              => '' !== $id ? $id : wp_generate_uuid4(),
			'source_adapter'  => isset( $data['source_adapter'] ) ? sanitize_text_field( (string) $data['source_adapter'] ) : '',
			'interval'        => $interval,
			'update_existing' => ! empty( $data['update_existing'] ),
			'enabled'         => ! isset( $data['enabled'] ) || ! empty( $data['enabled'] ),
			'last_run'        => null,
			'next_run'        => null,
		);

		// Preserve last_run from any existing record being updated.
		$found = false;

		foreach ( $schedules as $index => $existing ) {
			if ( isset( $existing['id'] ) && $existing['id'] === $schedule['id'] ) {
				$schedule['last_run'] = $existing['last_run'] ?? null;
				$schedules[ $index ]  = $schedule;
				$found                = true;
				break;
			}
		}

		if ( ! $found ) {
			$schedules[] = $schedule;
		}

		// (Re)register the recurring action and capture the next run time.
		$schedule['next_run'] = $this->register_action( $schedule );

		// Persist the next_run on the stored record too.
		foreach ( $schedules as $index => $existing ) {
			if ( $existing['id'] === $schedule['id'] ) {
				$schedules[ $index ] = $schedule;
				break;
			}
		}

		$this->persist( $schedules );

		return $schedule;
	}

	/**
	 * Delete a schedule and unschedule its recurring action.
	 *
	 * @param string $schedule_id Schedule ID.
	 * @return bool True when a schedule was removed.
	 */
	public function delete_schedule( string $schedule_id ): bool {
		$schedules = $this->get_schedules();
		$remaining = array();
		$removed   = false;

		foreach ( $schedules as $schedule ) {
			if ( isset( $schedule['id'] ) && $schedule['id'] === $schedule_id ) {
				$this->unregister_action( $schedule_id );
				$removed = true;
				continue;
			}

			$remaining[] = $schedule;
		}

		if ( $removed ) {
			$this->persist( $remaining );
		}

		return $removed;
	}

	/**
	 * Action Scheduler callback: run a scheduled import for a source.
	 *
	 * Resolves the schedule, validates the source is connected, fetches the
	 * manifest, and creates an import batch reusing the existing batch-creation
	 * path. Records last_run/next_run. Disconnected or unauthenticated sources
	 * are logged and skipped rather than crashing the scheduler. Overlapping
	 * runs are skipped when a batch for the same source is still processing.
	 *
	 * @param string $schedule_id Schedule ID passed as the action argument.
	 * @return void
	 */
	public function run_schedule( string $schedule_id ): void {
		$schedule = $this->get_schedule( $schedule_id );

		if ( null === $schedule ) {
			$this->log( sprintf( 'Scheduled import skipped: schedule "%s" not found.', $schedule_id ) );
			return;
		}

		// Always record the run timestamp and refresh the next run estimate.
		$this->record_run( $schedule_id );

		if ( empty( $schedule['enabled'] ) ) {
			$this->log( sprintf( 'Scheduled import skipped: schedule "%s" is disabled.', $schedule_id ) );
			return;
		}

		$source_adapter = (string) ( $schedule['source_adapter'] ?? '' );
		$adapter        = AdapterRegistry::get_instance()->get( $source_adapter );

		if ( null === $adapter ) {
			$this->log( sprintf( 'Scheduled import skipped: source adapter "%s" not found.', $source_adapter ) );
			return;
		}

		if ( ! $adapter->is_authenticated() ) {
			$this->log( sprintf( 'Scheduled import skipped: source adapter "%s" is not connected.', $source_adapter ) );
			return;
		}

		// Guard against overlapping runs for the same source.
		if ( $this->has_active_batch( $source_adapter ) ) {
			$this->log( sprintf( 'Scheduled import skipped: an import for "%s" is already processing.', $source_adapter ) );
			return;
		}

		try {
			$manifest = $adapter->fetch_manifest();
		} catch ( \Throwable $e ) {
			$this->log(
				sprintf(
					'Scheduled import for "%s" failed to fetch manifest: %s',
					$source_adapter,
					$e->getMessage()
				)
			);
			return;
		}

		$item_ids = array_keys( $manifest->get_items() );

		if ( empty( $item_ids ) ) {
			$this->log( sprintf( 'Scheduled import for "%s" found no items.', $source_adapter ) );
			return;
		}

		$this->create_batch( $schedule, $item_ids );
	}

	/**
	 * Create an import batch for a scheduled run.
	 *
	 * Mirrors ImportsController::create_item so scheduled and manual imports
	 * share the same batch shape, index, and lifecycle hook. update_existing
	 * is forced on so duplicate detection only imports new content. The
	 * source's saved mapping is reused when present.
	 *
	 * @param array<string, mixed> $schedule Schedule record.
	 * @param array<int, string>   $item_ids Item IDs to import.
	 * @return string The created batch ID.
	 */
	private function create_batch( array $schedule, array $item_ids ): string {
		$source_adapter = (string) $schedule['source_adapter'];
		$batch_id       = wp_generate_uuid4();
		$now            = gmdate( 'c' );

		$saved_mapping = get_option( MappingConfig::get_option_key( $source_adapter ), array() );
		$mapping       = is_array( $saved_mapping ) ? MappingConfig::sanitize( $saved_mapping ) : array();

		$batch = array(
			'id'              => $batch_id,
			'source_adapter'  => $source_adapter,
			'state'           => 'processing',
			'item_ids'        => array_values( $item_ids ),
			'mapping'         => $mapping,
			'total'           => count( $item_ids ),
			'processed'       => 0,
			'failed'          => 0,
			'skipped'         => 0,
			// Scheduled runs always update/skip existing content so only new
			// items are imported via duplicate detection (F10.2).
			'update_existing' => true,
			'errors'          => array(),
			'created_at'      => $now,
			'started_at'      => null,
			'completed_at'    => null,
			'imported_ids'    => array(),
			// Mark provenance so history can distinguish scheduled runs.
			'scheduled'       => true,
			'schedule_id'     => (string) $schedule['id'],
		);

		update_option( 'ai_importer_batch_' . $batch_id, $batch, false );

		$index   = get_option( 'ai_importer_batch_index', array() );
		$index   = is_array( $index ) ? $index : array();
		$index[] = $batch_id;
		update_option( 'ai_importer_batch_index', $index, false );

		/**
		 * Fires when a new import batch is created.
		 *
		 * The import processor hooks into this action to begin processing.
		 *
		 * @param string               $batch_id The batch UUID.
		 * @param array<string, mixed> $batch    The batch data.
		 */
		do_action( 'ai_importer_batch_created', $batch_id, $batch );

		$this->log(
			sprintf(
				'Scheduled import for "%s" created batch %s with %d item(s).',
				$source_adapter,
				$batch_id,
				count( $item_ids )
			)
		);

		return $batch_id;
	}

	/**
	 * Determine whether a source already has a processing batch.
	 *
	 * @param string $source_adapter Adapter ID.
	 * @return bool True when an active (processing/paused) batch exists.
	 */
	private function has_active_batch( string $source_adapter ): bool {
		$index = get_option( 'ai_importer_batch_index', array() );

		if ( ! is_array( $index ) ) {
			return false;
		}

		foreach ( $index as $batch_id ) {
			$batch = get_option( 'ai_importer_batch_' . $batch_id, false );

			if ( ! is_array( $batch ) ) {
				continue;
			}

			if ( ( $batch['source_adapter'] ?? '' ) !== $source_adapter ) {
				continue;
			}

			if ( in_array( $batch['state'] ?? '', array( 'processing', 'paused' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Record a run: set last_run and refresh next_run for a schedule.
	 *
	 * @param string $schedule_id Schedule ID.
	 * @return void
	 */
	private function record_run( string $schedule_id ): void {
		$schedules = $this->get_schedules();

		foreach ( $schedules as $index => $schedule ) {
			if ( ( $schedule['id'] ?? '' ) !== $schedule_id ) {
				continue;
			}

			$schedules[ $index ]['last_run'] = gmdate( 'c' );
			$schedules[ $index ]['next_run'] = $this->next_scheduled( $schedule_id );
			break;
		}

		$this->persist( $schedules );
	}

	/**
	 * (Re)register the recurring Action Scheduler action for a schedule.
	 *
	 * Any existing action for the schedule is cleared first so interval
	 * changes take effect. Disabled schedules are unscheduled and not
	 * re-registered.
	 *
	 * @param array<string, mixed> $schedule Schedule record.
	 * @return int|null Next run Unix timestamp, or null when not scheduled.
	 */
	private function register_action( array $schedule ): ?int {
		$schedule_id = (string) $schedule['id'];

		$this->unregister_action( $schedule_id );

		if ( empty( $schedule['enabled'] ) ) {
			return null;
		}

		$interval_seconds = self::INTERVALS[ $schedule['interval'] ] ?? self::INTERVALS['daily'];
		$start            = time() + $interval_seconds;

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			as_schedule_recurring_action(
				$start,
				$interval_seconds,
				self::HOOK_NAME,
				array( $schedule_id ),
				self::AS_GROUP
			);
		}

		return $this->next_scheduled( $schedule_id ) ?? $start;
	}

	/**
	 * Unschedule the recurring action for a schedule.
	 *
	 * @param string $schedule_id Schedule ID.
	 * @return void
	 */
	private function unregister_action( string $schedule_id ): void {
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::HOOK_NAME, array( $schedule_id ), self::AS_GROUP );
		}
	}

	/**
	 * Get the next scheduled run timestamp for a schedule.
	 *
	 * @param string $schedule_id Schedule ID.
	 * @return int|null Unix timestamp, or null when nothing is scheduled.
	 */
	private function next_scheduled( string $schedule_id ): ?int {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return null;
		}

		$next = as_next_scheduled_action( self::HOOK_NAME, array( $schedule_id ), self::AS_GROUP );

		return is_int( $next ) ? $next : null;
	}

	/**
	 * Persist the schedule list to the database.
	 *
	 * @param array<int, array<string, mixed>> $schedules Schedule list.
	 * @return void
	 */
	private function persist( array $schedules ): void {
		update_option( self::OPTION_KEY, array_values( $schedules ), false );
	}

	/**
	 * Log a scheduler message when debugging is enabled.
	 *
	 * @param string $message Message to log.
	 * @return void
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'AI Importer [scheduler]: ' . $message );
		}
	}
}
