<?php
/**
 * Alt text generator.
 *
 * @package AI_Importer\AI
 */

namespace AI_Importer\AI;

use WP_Error;

/**
 * Generates accessibility alt text for an image via AIService.
 *
 * The AI is asked to produce a short, descriptive alt text for the image
 * at the given URL. Optional context (e.g. the surrounding post text)
 * improves relevance when the image alone is ambiguous.
 */
class AltTextGenerator {

	/**
	 * Maximum alt text length in characters.
	 *
	 * Screen readers start truncating around 125 characters; the prompt asks
	 * the model to stay under this.
	 */
	private const MAX_LENGTH = 125;

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
	 * Generate alt text for an image URL.
	 *
	 * @param string               $image_url HTTP(S) URL of the image.
	 * @param array<string, mixed> $options   Options: 'context' (string) for surrounding text.
	 * @return string|WP_Error Alt text or error.
	 */
	public function generate( string $image_url, array $options = array() ) {
		if ( '' === trim( $image_url ) ) {
			return new WP_Error(
				'ai_alt_text_empty_url',
				__( 'Image URL is required to generate alt text.', 'ai-importer' )
			);
		}

		$scheme = wp_parse_url( $image_url, PHP_URL_SCHEME );
		if ( false === filter_var( $image_url, FILTER_VALIDATE_URL )
			|| ! is_string( $scheme )
			|| ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true )
		) {
			return new WP_Error(
				'ai_alt_text_invalid_url',
				__( 'A valid http(s) URL is required to generate alt text.', 'ai-importer' )
			);
		}

		$context = isset( $options['context'] ) ? (string) $options['context'] : '';
		$prompt  = $this->build_prompt( $image_url, $context );
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
				__( 'AI returned an empty alt text.', 'ai-importer' )
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
	 * Build the alt-text prompt.
	 *
	 * @param string $image_url Image URL to describe.
	 * @param string $context   Surrounding content, if any.
	 * @return string Prompt.
	 */
	private function build_prompt( string $image_url, string $context ): string {
		$prompt = sprintf(
			'Generate concise accessibility alt text (under %d characters) for the image at %s. '
			. 'Describe what a sighted viewer would see; do not start with "Image of" or "Picture of".',
			self::MAX_LENGTH,
			$image_url
		);

		if ( '' !== $context ) {
			$prompt .= "\n\nSurrounding context (may help disambiguate, but describe the image, not the context):\n" . $context;
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
					'description' => 'Short, descriptive alt text for the image.',
					'maxLength'   => self::MAX_LENGTH,
				),
			),
			'required'   => array( 'alt_text' ),
		);
	}
}
