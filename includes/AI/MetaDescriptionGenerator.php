<?php
/**
 * SEO meta description generator.
 *
 * @package AI_Importer\AI
 */

namespace AI_Importer\AI;

use WP_Error;

/**
 * Generates an SEO meta description for imported content via AIService.
 *
 * Targets the 150-160 character SERP snippet window. The output is
 * intended to be saved to a meta-description field (e.g. Yoast, Rank
 * Math, or a custom post meta key) by the caller.
 */
class MetaDescriptionGenerator {

	/**
	 * Maximum description length in characters.
	 *
	 * Chosen to stay within the typical SERP snippet truncation window;
	 * the prompt and JSON schema both enforce this upper bound.
	 */
	private const MAX_LENGTH = 160;

	/**
	 * Minimum description length in characters.
	 *
	 * Extremely short descriptions tend to underperform in SERPs and are
	 * rarely what the user wants; reject them explicitly.
	 */
	private const MIN_LENGTH = 50;

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
	 * Generate an SEO meta description for the given content.
	 *
	 * @param string               $content Post body (HTML or plain text).
	 * @param array<string, mixed> $options Options: 'title' (string), 'keywords' (string|string[]).
	 * @return string|WP_Error Description or error.
	 */
	public function generate( string $content, array $options = array() ) {
		if ( '' === trim( $content ) ) {
			return new WP_Error(
				'ai_meta_empty_content',
				__( 'Content is required to generate a meta description.', 'ai-importer' )
			);
		}

		$title    = isset( $options['title'] ) ? (string) $options['title'] : '';
		$keywords = $this->normalize_keywords( $options['keywords'] ?? null );
		$prompt   = $this->build_prompt( $content, $title, $keywords );
		$schema   = $this->build_schema();

		$response = $this->service->generate_structured( $prompt, $schema );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! array_key_exists( 'description', $response ) || ! is_string( $response['description'] ) ) {
			return new WP_Error(
				'ai_meta_malformed',
				__( 'AI response did not include a description string.', 'ai-importer' ),
				array( 'response' => $response )
			);
		}

		$description = trim( $response['description'] );
		$length      = mb_strlen( $description );

		if ( '' === $description ) {
			return new WP_Error(
				'ai_meta_empty',
				__( 'AI returned an empty meta description.', 'ai-importer' )
			);
		}

		if ( $length < self::MIN_LENGTH ) {
			return new WP_Error(
				'ai_meta_too_short',
				sprintf(
					/* translators: %d: minimum description length. */
					__( 'AI returned a meta description shorter than %d characters.', 'ai-importer' ),
					self::MIN_LENGTH
				),
				array(
					'min_length' => self::MIN_LENGTH,
					'length'     => $length,
				)
			);
		}

		if ( $length > self::MAX_LENGTH ) {
			return new WP_Error(
				'ai_meta_too_long',
				sprintf(
					/* translators: %d: maximum description length. */
					__( 'AI returned a meta description longer than %d characters.', 'ai-importer' ),
					self::MAX_LENGTH
				),
				array(
					'max_length' => self::MAX_LENGTH,
					'length'     => $length,
				)
			);
		}

		return $description;
	}

	/**
	 * Coerce a keywords option into a clean list of strings.
	 *
	 * @param mixed $keywords Raw input: string, array, or null.
	 * @return array<int, string> Non-empty list of keyword strings.
	 */
	private function normalize_keywords( $keywords ): array {
		if ( is_string( $keywords ) ) {
			$keywords = array_map( 'trim', explode( ',', $keywords ) );
		} elseif ( is_array( $keywords ) ) {
			$keywords = array_map(
				static fn( $k ) => is_string( $k ) ? trim( $k ) : '',
				$keywords
			);
		} else {
			return array();
		}

		return array_values( array_filter( $keywords, static fn( $k ) => '' !== $k ) );
	}

	/**
	 * Build the prompt.
	 *
	 * @param string             $content  Post content.
	 * @param string             $title    Optional title hint.
	 * @param array<int, string> $keywords Optional keyword list.
	 * @return string Prompt.
	 */
	private function build_prompt( string $content, string $title, array $keywords ): string {
		$prompt = sprintf(
			'Write an SEO meta description between %d and %d characters for the content below. '
			. 'Summarize the main value proposition; do not wrap in quotes; do not exceed one or two sentences.',
			self::MIN_LENGTH,
			self::MAX_LENGTH
		);

		if ( '' !== $title ) {
			$prompt .= "\n\nPost title: " . $title;
		}

		if ( ! empty( $keywords ) ) {
			$prompt .= "\n\nTry to include these keywords naturally where they fit: " . implode( ', ', $keywords );
		}

		$prompt .= "\n\nContent:\n" . $content;

		return $prompt;
	}

	/**
	 * JSON schema for the meta description response.
	 *
	 * @return array<string, mixed>
	 */
	private function build_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'description' => array(
					'type'        => 'string',
					'description' => 'SEO meta description for the post.',
					'minLength'   => self::MIN_LENGTH,
					'maxLength'   => self::MAX_LENGTH,
				),
			),
			'required'   => array( 'description' ),
		);
	}
}
