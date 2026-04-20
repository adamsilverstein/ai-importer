<?php
/**
 * Hashtag-to-WordPress-tag mapper.
 *
 * @package AI_Importer\AI
 */

namespace AI_Importer\AI;

use WP_Error;

/**
 * Maps a batch of raw source hashtags into clean, WordPress-friendly tag names.
 *
 * Source platforms generally emit hashtags as packed tokens
 * (e.g. "ClimateChange", "wordpress_7_launch"). This mapper asks the AI
 * to normalize them in a single batch call: expand CamelCase, strip
 * punctuation, consolidate near-duplicates, and drop nonsense
 * tokens that would pollute the site's tag taxonomy.
 */
class HashtagMapper {

	/**
	 * Default maximum number of output tags.
	 */
	private const DEFAULT_MAX_TAGS = 10;

	/**
	 * Minimum length for an input hashtag to be considered valid.
	 */
	private const MIN_INPUT_LENGTH = 2;

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
	 * Map a list of raw hashtags into cleaned WordPress tag names.
	 *
	 * @param array<string>        $hashtags Raw hashtags (with or without leading #).
	 * @param array<string, mixed> $options  Options: 'context' (string), 'max_tags' (int).
	 * @return array<string>|WP_Error Array of cleaned tag names or error.
	 */
	public function map( array $hashtags, array $options = array() ) {
		$normalized = $this->normalize_input( $hashtags );

		if ( empty( $normalized ) ) {
			return new WP_Error(
				'ai_hashtag_empty',
				__( 'No valid hashtags supplied for mapping.', 'ai-importer' )
			);
		}

		$max_tags = isset( $options['max_tags'] ) ? max( 1, (int) $options['max_tags'] ) : self::DEFAULT_MAX_TAGS;
		$context  = isset( $options['context'] ) ? (string) $options['context'] : '';

		$prompt = $this->build_prompt( $normalized, $context, $max_tags );
		$schema = $this->build_schema( $max_tags );

		$response = $this->service->generate_structured( $prompt, $schema );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! array_key_exists( 'tags', $response ) || ! is_array( $response['tags'] ) ) {
			return new WP_Error(
				'ai_hashtag_malformed',
				__( 'AI response did not include a tags array.', 'ai-importer' ),
				array( 'response' => $response )
			);
		}

		$tags = array();
		$seen = array();
		foreach ( $response['tags'] as $raw ) {
			if ( ! is_string( $raw ) ) {
				return new WP_Error(
					'ai_hashtag_malformed',
					__( 'AI response contained a non-string tag value.', 'ai-importer' ),
					array( 'response' => $response )
				);
			}

			$tag = trim( $raw );
			if ( '' === $tag ) {
				continue;
			}

			$key = strtolower( $tag );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$tags[] = $tag;

			if ( count( $tags ) >= $max_tags ) {
				break;
			}
		}

		return $tags;
	}

	/**
	 * Normalize and deduplicate the input hashtag list.
	 *
	 * Strips a leading '#', drops entries that are empty, too short, or
	 * purely numeric, and deduplicates case-insensitively.
	 *
	 * @param array<string> $hashtags Raw input.
	 * @return array<string> Normalized tokens, preserving input order.
	 */
	private function normalize_input( array $hashtags ): array {
		$out  = array();
		$seen = array();

		foreach ( $hashtags as $raw ) {
			if ( ! is_string( $raw ) ) {
				continue;
			}

			$token = ltrim( trim( $raw ), '#' );
			$token = trim( $token );

			if ( '' === $token || mb_strlen( $token ) < self::MIN_INPUT_LENGTH ) {
				continue;
			}

			if ( ctype_digit( $token ) ) {
				continue;
			}

			$key = strtolower( $token );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$out[] = $token;
		}

		return $out;
	}

	/**
	 * Build the prompt for hashtag mapping.
	 *
	 * @param array<string> $hashtags Normalized hashtag tokens.
	 * @param string        $context  Optional post context.
	 * @param int           $max_tags Maximum tags to return.
	 * @return string Prompt.
	 */
	private function build_prompt( array $hashtags, string $context, int $max_tags ): string {
		$prompt  = sprintf(
			'Convert the following social-media hashtags into a clean list of WordPress tag names. '
			. 'Expand CamelCase and snake_case into properly spaced words, use title case where '
			. 'appropriate, consolidate near-duplicates, and drop tokens that are nonsense or too '
			. 'narrow to be useful as a site taxonomy term. Return no more than %d tags.',
			$max_tags
		);
		$prompt .= "\n\nHashtags:\n- " . implode( "\n- ", $hashtags );

		if ( '' !== trim( $context ) ) {
			$prompt .= "\n\nSurrounding post context:\n" . $context;
		}

		return $prompt;
	}

	/**
	 * JSON schema for the mapper response.
	 *
	 * @param int $max_tags Maximum tags.
	 * @return array<string, mixed>
	 */
	private function build_schema( int $max_tags ): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tags' => array(
					'type'        => 'array',
					'description' => 'Cleaned WordPress tag names.',
					'maxItems'    => $max_tags,
					'items'       => array(
						'type' => 'string',
					),
				),
			),
			'required'   => array( 'tags' ),
		);
	}
}
