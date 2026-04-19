<?php
/**
 * Post title generator.
 *
 * @package AI_Importer\AI
 */

namespace AI_Importer\AI;

use WP_Error;

/**
 * Proposes a concise post title for imported content via AIService.
 *
 * Useful when the source item has no native title (e.g. a tweet or a short
 * status update) or when the source title is unsuitable for a long-form post.
 */
class TitleGenerator {

	/**
	 * Maximum title length in characters.
	 *
	 * Roughly the upper bound for SERP title display before truncation; the
	 * prompt asks the model to stay under this.
	 */
	private const MAX_LENGTH = 70;

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
	 * Generate a title for the given content.
	 *
	 * @param string               $content Post body (HTML or plain text).
	 * @param array<string, mixed> $options Options: 'style' (string) e.g. "news", "casual".
	 * @return string|WP_Error Title or error.
	 */
	public function generate( string $content, array $options = array() ) {
		if ( '' === trim( $content ) ) {
			return new WP_Error(
				'ai_title_empty_content',
				__( 'Content is required to generate a title.', 'ai-importer' )
			);
		}

		$style  = isset( $options['style'] ) ? (string) $options['style'] : '';
		$prompt = $this->build_prompt( $content, $style );
		$schema = $this->build_schema();

		$response = $this->service->generate_structured( $prompt, $schema );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! array_key_exists( 'title', $response ) || ! is_string( $response['title'] ) ) {
			return new WP_Error(
				'ai_title_malformed',
				__( 'AI response did not include a title string.', 'ai-importer' ),
				array( 'response' => $response )
			);
		}

		$title = trim( $response['title'] );
		if ( '' === $title ) {
			return new WP_Error(
				'ai_title_empty',
				__( 'AI returned an empty title.', 'ai-importer' )
			);
		}

		if ( mb_strlen( $title ) > self::MAX_LENGTH ) {
			return new WP_Error(
				'ai_title_too_long',
				sprintf(
					/* translators: %d: maximum title length. */
					__( 'AI returned a title longer than %d characters.', 'ai-importer' ),
					self::MAX_LENGTH
				),
				array(
					'max_length' => self::MAX_LENGTH,
					'length'     => mb_strlen( $title ),
				)
			);
		}

		return $title;
	}

	/**
	 * Build the prompt for title generation.
	 *
	 * @param string $content Post content.
	 * @param string $style   Optional style hint.
	 * @return string Prompt.
	 */
	private function build_prompt( string $content, string $style ): string {
		$prompt = sprintf(
			'Propose a concise, compelling post title (under %d characters) for the content below. '
			. 'Do not wrap the title in quotes. Do not include a trailing period.',
			self::MAX_LENGTH
		);

		if ( '' !== $style ) {
			$prompt .= sprintf( ' Match this style: %s.', $style );
		}

		$prompt .= "\n\nContent:\n" . $content;

		return $prompt;
	}

	/**
	 * JSON schema for the title response.
	 *
	 * @return array<string, mixed>
	 */
	private function build_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'title' => array(
					'type'        => 'string',
					'description' => 'A concise post title.',
					'maxLength'   => self::MAX_LENGTH,
				),
			),
			'required'   => array( 'title' ),
		);
	}
}
