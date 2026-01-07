<?php
/**
 * Abstract adapter class.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Adapters;

use AI_Importer\Schema\SettingsSchema;

/**
 * Abstract base class for source adapters.
 *
 * Provides common functionality for credential storage, HTTP requests,
 * and error handling that concrete adapters can use.
 */
abstract class AbstractAdapter implements AdapterInterface {

	/**
	 * Option name prefix for storing adapter data.
	 */
	protected const OPTION_PREFIX = 'ai_importer_adapter_';

	/**
	 * Cached credentials.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $credentials = null;

	/**
	 * Settings schema instance.
	 *
	 * @var SettingsSchema|null
	 */
	protected ?SettingsSchema $settings_schema = null;

	/**
	 * Get the option name for storing this adapter's data.
	 *
	 * @return string Option name.
	 */
	protected function get_option_name(): string {
		return self::OPTION_PREFIX . $this->get_id();
	}

	/**
	 * Get stored credentials for this adapter.
	 *
	 * @return array<string, mixed> Stored credentials or empty array.
	 */
	protected function get_stored_credentials(): array {
		if ( null === $this->credentials ) {
			$this->credentials = get_option( $this->get_option_name(), array() );
		}
		return $this->credentials;
	}

	/**
	 * Store credentials for this adapter.
	 *
	 * @param array<string, mixed> $credentials Credentials to store.
	 * @return bool True on success.
	 */
	protected function store_credentials( array $credentials ): bool {
		$this->credentials = $credentials;
		return update_option( $this->get_option_name(), $credentials );
	}

	/**
	 * Clear stored credentials.
	 *
	 * @return bool True on success.
	 */
	protected function clear_credentials(): bool {
		$this->credentials = null;
		return delete_option( $this->get_option_name() );
	}

	/**
	 * Check if the adapter is currently authenticated.
	 *
	 * Default implementation checks if credentials are stored.
	 * Override in concrete adapters for more specific checks.
	 *
	 * @return bool True if authenticated.
	 */
	public function is_authenticated(): bool {
		$credentials = $this->get_stored_credentials();
		return ! empty( $credentials );
	}

	/**
	 * Disconnect from the source platform.
	 *
	 * Default implementation clears stored credentials.
	 * Override in concrete adapters to perform additional cleanup.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		$this->clear_credentials();
	}

	/**
	 * Get the settings schema.
	 *
	 * Caches the schema after first build.
	 *
	 * @return SettingsSchema The settings schema.
	 */
	public function get_settings_schema(): SettingsSchema {
		if ( null === $this->settings_schema ) {
			$this->settings_schema = $this->build_settings_schema();
		}
		return $this->settings_schema;
	}

	/**
	 * Build the settings schema for this adapter.
	 *
	 * Override in concrete adapters to define settings fields.
	 *
	 * @return SettingsSchema The settings schema.
	 */
	abstract protected function build_settings_schema(): SettingsSchema;

	/**
	 * Make an HTTP request.
	 *
	 * @param string               $url     Request URL.
	 * @param array<string, mixed> $args    Request arguments.
	 * @return array<string, mixed>|\WP_Error Response or error.
	 */
	protected function http_request( string $url, array $args = array() ): array|\WP_Error {
		$defaults = array(
			'timeout'    => 30,
			'user-agent' => 'AI-Importer/' . AI_IMPORTER_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
		);

		$args = wp_parse_args( $args, $defaults );

		return wp_remote_request( $url, $args );
	}

	/**
	 * Make a GET request.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args Additional arguments.
	 * @return array<string, mixed>|\WP_Error Response or error.
	 */
	protected function http_get( string $url, array $args = array() ): array|\WP_Error {
		$args['method'] = 'GET';
		return $this->http_request( $url, $args );
	}

	/**
	 * Make a POST request.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $body Request body.
	 * @param array<string, mixed> $args Additional arguments.
	 * @return array<string, mixed>|\WP_Error Response or error.
	 */
	protected function http_post( string $url, array $body = array(), array $args = array() ): array|\WP_Error {
		$args['method'] = 'POST';
		$args['body']   = $body;
		return $this->http_request( $url, $args );
	}

	/**
	 * Parse JSON response from HTTP request.
	 *
	 * @param array<string, mixed>|\WP_Error $response HTTP response.
	 * @return array<string, mixed>|\WP_Error Parsed data or error.
	 */
	protected function parse_json_response( array|\WP_Error $response ): array|\WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP request failed with status %d', 'ai-importer' ),
					$code
				),
				array(
					'status' => $code,
					'body'   => $body,
				)
			);
		}

		$data = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'json_error',
				__( 'Failed to parse JSON response', 'ai-importer' ),
				array( 'body' => $body )
			);
		}

		return $data;
	}

	/**
	 * Ensure the adapter is authenticated before operations.
	 *
	 * @throws \RuntimeException If not authenticated.
	 */
	protected function ensure_authenticated(): void {
		if ( ! $this->is_authenticated() ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: adapter name */
					__( '%s adapter is not authenticated.', 'ai-importer' ),
					$this->get_name()
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Log an error message.
	 *
	 * @param string               $message Error message.
	 * @param array<string, mixed> $context Additional context.
	 * @return void
	 */
	protected function log_error( string $message, array $context = array() ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'AI Importer [%s]: %s %s',
					$this->get_id(),
					$message,
					! empty( $context ) ? wp_json_encode( $context ) : ''
				)
			);
		}
	}

	/**
	 * Generate a cache key for this adapter.
	 *
	 * @param string $suffix Key suffix.
	 * @return string Cache key.
	 */
	protected function get_cache_key( string $suffix ): string {
		return 'ai_importer_' . $this->get_id() . '_' . $suffix;
	}

	/**
	 * Get cached data.
	 *
	 * @param string $key Cache key suffix.
	 * @return mixed|false Cached data or false.
	 */
	protected function get_cache( string $key ): mixed {
		return get_transient( $this->get_cache_key( $key ) );
	}

	/**
	 * Set cached data.
	 *
	 * @param string $key        Cache key suffix.
	 * @param mixed  $value      Data to cache.
	 * @param int    $expiration Expiration in seconds.
	 * @return bool True on success.
	 */
	protected function set_cache( string $key, mixed $value, int $expiration = 3600 ): bool {
		return set_transient( $this->get_cache_key( $key ), $value, $expiration );
	}

	/**
	 * Delete cached data.
	 *
	 * @param string $key Cache key suffix.
	 * @return bool True on success.
	 */
	protected function delete_cache( string $key ): bool {
		return delete_transient( $this->get_cache_key( $key ) );
	}
}
