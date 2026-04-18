<?php
/**
 * AI service wrapper.
 *
 * @package AI_Importer\AI
 */

namespace AI_Importer\AI;

use WP_Error;

/**
 * Wrapper around WordPress native AI client (wp_get_ai_client()).
 *
 * Provides migration-specific methods for structured output generation.
 * Content analysis, mapping suggestions, and enhancement handlers build
 * on top of the low-level generate_structured() primitive exposed here.
 */
class AIService {

	/**
	 * Check whether an AI client is available on this site.
	 *
	 * @return bool True if wp_get_ai_client() exists and returns a usable client.
	 */
	public function is_available(): bool {
		return ! is_wp_error( $this->get_client() ) && null !== $this->get_client();
	}

	/**
	 * Generate a structured (JSON) response that conforms to the given schema.
	 *
	 * @param string               $prompt  Prompt text to send to the model.
	 * @param array<string, mixed> $schema  JSON schema describing the expected output.
	 * @param array<string, mixed> $options Additional options forwarded to the client.
	 * @return array<string, mixed>|WP_Error Decoded response or error.
	 */
	public function generate_structured( string $prompt, array $schema, array $options = array() ) {
		$client = $this->get_client();

		if ( null === $client || is_wp_error( $client ) ) {
			return new WP_Error(
				'ai_unavailable',
				__( 'WordPress AI client is not available. Configure an AI provider in site settings.', 'ai-importer' )
			);
		}

		$request_options = array_merge(
			$options,
			array(
				'response_format' => array(
					'type'   => 'json_schema',
					'schema' => $schema,
				),
			)
		);

		$response = $client->generate_text( $prompt, $request_options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( (string) $response, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'ai_invalid_response',
				__( 'AI provider returned a response that was not valid JSON.', 'ai-importer' ),
				array( 'response' => $response )
			);
		}

		return $decoded;
	}

	/**
	 * Retrieve the WordPress AI client, if available.
	 *
	 * @return object|WP_Error|null Client instance, WP_Error on provider failure, null when unavailable.
	 */
	private function get_client() {
		if ( ! function_exists( 'wp_get_ai_client' ) ) {
			return null;
		}

		return wp_get_ai_client();
	}
}
