<?php
/**
 * Image alt text generator.
 *
 * @package AI_Importer\AI
 */

namespace AI_Importer\AI;

use WP_Error;

/**
 * Proposes descriptive alt text for an image via AIService.
 *
 * Intended for accessibility: when a source item ships an image without
 * alt text, this generator asks the model to describe the image based
 * on its URL (and optional surrounding post context) so the resulting
 * WordPress media item meets accessibility requirements.
 */
class AltTextGenerator {

	/**
	 * Maximum alt text length in characters.
	 *
	 * Screen readers and SEO guides commonly recommend staying under
	 * ~125 characters; we allow a little headroom.
	 */
	private const MAX_LENGTH = 150;

	/**
	 * AI service.
	 *
	 * @var AIService
	 */
	private AIService $service;

	/**
	 * Constructor.
	 *
	 * @param AIService $service AI service wrapper.
	 */
	public function __construct( AIService $service ) {
		$this->service = $service;
	}

	/**
	 * Generate alt text for the image at the given URL.
	 *
	 * @param string               $url     Absolute http(s) image URL.
	 * @param array<string, mixed> $options Options: 'context' (string) surrounding post context.
	 * @return string|WP_Error Alt text or error.
	 */
	public function generate( string $url, array $options = array() ) {
		$url = trim( $url );

		if ( '' === $url ) {
			return new WP_Error(
				'ai_alt_text_empty_url',
				__( 'An image URL is required to generate alt text.', 'ai-importer' )
			);
		}

		if ( ! $this->is_valid_http_url( $url ) ) {
			return new WP_Error(
				'ai_alt_text_invalid_url',
				__( 'Image URL must be an absolute http(s) URL.', 'ai-importer' )
			);
		}

		$context = isset( $options['context'] ) ? (string) $options['context'] : '';
		$prompt  = $this->build_prompt( $url, $context );
		$schema  = $this->build_schema();

		$response = $this->service->generate_structured( $prompt, $schema );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! array_key_exists( 'alt_text', $response ) || ! is_string( $response['alt_text'] ) ) {
			return new WP_Error(
				'ai_alt_text_malformed',
				__( 'AI response did not include an alt_text string.', 'ai-importer' ),
				array( 'response' => $response )
			);
		}

		$alt = trim( $response['alt_text'] );
		if ( '' === $alt ) {
			return new WP_Error(
				'ai_alt_text_empty',
				__( 'AI returned empty alt text.', 'ai-importer' )
			);
		}

		if ( mb_strlen( $alt ) > self::MAX_LENGTH ) {
			return new WP_Error(
				'ai_alt_text_too_long',
				sprintf(
					/* translators: %d: maximum alt text length. */
					__( 'AI returned alt text longer than %d characters.', 'ai-importer' ),
					self::MAX_LENGTH
				),
				array(
					'max_length' => self::MAX_LENGTH,
					'length'     => mb_strlen( $alt ),
				)
			);
		}

		return $alt;
	}

	/**
	 * Validate that $url is an absolute http(s) URL.
	 *
	 * @param string $url URL to validate.
	 * @return bool True when valid.
	 */
	private function is_valid_http_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return false;
		}

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		return in_array( $scheme, array( 'http', 'https' ), true );
	}

	/**
	 * Build the prompt for alt text generation.
	 *
	 * @param string $url     Image URL.
	 * @param string $context Optional surrounding post context.
	 * @return string Prompt.
	 */
	private function build_prompt( string $url, string $context ): string {
		$prompt = sprintf(
			'Write a concise, descriptive alt text (under %d characters) for the image at the URL below. '
			. 'Describe what is visible in plain language suitable for a screen reader. '
			. 'Do not prefix with "Image of" or "Picture of". Do not wrap the text in quotes.',
			self::MAX_LENGTH
		);

		$prompt .= "\n\nImage URL: " . $url;

		if ( '' !== trim( $context ) ) {
			$prompt .= "\n\nSurrounding post context:\n" . $context;
		}

		return $prompt;
	}

	/**
	 * JSON schema for the alt text response.
	 *
	 * @return array<string, mixed>
	 */
	private function build_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'alt_text' => array(
					'type'        => 'string',
					'description' => 'Concise, descriptive alt text for the image.',
					'maxLength'   => self::MAX_LENGTH,
				),
			),
			'required'   => array( 'alt_text' ),
		);
	}
}
