<?php
/**
 * Adapter registry class.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Adapters;

/**
 * Registry for managing source adapters.
 *
 * This is a singleton class that maintains the list of available adapters
 * and provides methods for registering, retrieving, and listing them.
 */
class AdapterRegistry {

	/**
	 * Singleton instance.
	 *
	 * @var AdapterRegistry|null
	 */
	private static ?AdapterRegistry $instance = null;

	/**
	 * Registered adapters.
	 *
	 * @var array<string, AdapterInterface>
	 */
	private array $adapters = array();

	/**
	 * Get singleton instance.
	 *
	 * @return AdapterRegistry
	 */
	public static function get_instance(): AdapterRegistry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for singleton pattern.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Register an adapter.
	 *
	 * @param AdapterInterface $adapter The adapter to register.
	 * @return void
	 * @throws \InvalidArgumentException If adapter with same ID already exists.
	 */
	public function register( AdapterInterface $adapter ): void {
		$id = $adapter->get_id();

		if ( isset( $this->adapters[ $id ] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: %s: adapter ID */
					__( 'Adapter with ID "%s" is already registered.', 'ai-importer' ),
					$id
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->adapters[ $id ] = $adapter;

		/**
		 * Fires when an adapter is registered.
		 *
		 * @param AdapterInterface $adapter The registered adapter.
		 * @param string           $id      The adapter ID.
		 */
		do_action( 'ai_importer_adapter_registered', $adapter, $id );
	}

	/**
	 * Unregister an adapter.
	 *
	 * @param string $adapter_id The adapter ID to unregister.
	 * @return bool True if adapter was unregistered, false if not found.
	 */
	public function unregister( string $adapter_id ): bool {
		if ( ! isset( $this->adapters[ $adapter_id ] ) ) {
			return false;
		}

		$adapter = $this->adapters[ $adapter_id ];
		unset( $this->adapters[ $adapter_id ] );

		/**
		 * Fires when an adapter is unregistered.
		 *
		 * @param AdapterInterface $adapter    The unregistered adapter.
		 * @param string           $adapter_id The adapter ID.
		 */
		do_action( 'ai_importer_adapter_unregistered', $adapter, $adapter_id );

		return true;
	}

	/**
	 * Get an adapter by ID.
	 *
	 * @param string $adapter_id The adapter ID.
	 * @return AdapterInterface|null The adapter or null if not found.
	 */
	public function get( string $adapter_id ): ?AdapterInterface {
		return $this->adapters[ $adapter_id ] ?? null;
	}

	/**
	 * Check if an adapter is registered.
	 *
	 * @param string $adapter_id The adapter ID.
	 * @return bool True if registered.
	 */
	public function has( string $adapter_id ): bool {
		return isset( $this->adapters[ $adapter_id ] );
	}

	/**
	 * Get all registered adapters.
	 *
	 * @return array<string, AdapterInterface> All adapters keyed by ID.
	 */
	public function get_all(): array {
		return $this->adapters;
	}

	/**
	 * Get all adapter IDs.
	 *
	 * @return array<string> Array of adapter IDs.
	 */
	public function get_ids(): array {
		return array_keys( $this->adapters );
	}

	/**
	 * Get adapters that are currently authenticated.
	 *
	 * @return array<string, AdapterInterface> Authenticated adapters.
	 */
	public function get_authenticated(): array {
		return array_filter(
			$this->adapters,
			fn( AdapterInterface $adapter ) => $adapter->is_authenticated()
		);
	}

	/**
	 * Get adapters by authentication type.
	 *
	 * @param string $auth_type The authentication type.
	 * @return array<string, AdapterInterface> Matching adapters.
	 */
	public function get_by_auth_type( string $auth_type ): array {
		return array_filter(
			$this->adapters,
			fn( AdapterInterface $adapter ) => $adapter->get_auth_type() === $auth_type
		);
	}

	/**
	 * Get the count of registered adapters.
	 *
	 * @return int Number of adapters.
	 */
	public function count(): int {
		return count( $this->adapters );
	}

	/**
	 * Convert all adapters to array format for API responses.
	 *
	 * @return array<string, array<string, mixed>> Adapter data.
	 */
	public function to_array(): array {
		$result = array();

		foreach ( $this->adapters as $id => $adapter ) {
			$result[ $id ] = array(
				'id'               => $adapter->get_id(),
				'name'             => $adapter->get_name(),
				'description'      => $adapter->get_description(),
				'icon'             => $adapter->get_icon(),
				'auth_type'        => $adapter->get_auth_type(),
				'is_authenticated' => $adapter->is_authenticated(),
				'content_types'    => $adapter->get_supported_content_types(),
			);
		}

		return $result;
	}

	/**
	 * Reset the registry (primarily for testing).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->adapters = array();
	}
}
