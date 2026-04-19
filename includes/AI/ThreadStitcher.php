<?php
/**
 * Thread stitcher.
 *
 * @package AI_Importer\AI
 */

namespace AI_Importer\AI;

use WP_Error;

/**
 * Stitches a multi-item social thread (e.g. a Twitter/X thread) into a
 * single coherent long-form post body via AIService.
 *
 * The caller passes the ordered list of items. Each item must have a
 * non-empty 'text' key. The AI is asked to fuse them into readable
 * paragraphs without losing meaning, removing the tweet-style numbering
 * ("1/", "2/", "🧵") while preserving the original voice.
 */
class ThreadStitcher {

	/**
	 * Minimum number of items required to stitch.
	 */
	private const MIN_ITEMS = 2;

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
	 * Stitch an ordered list of thread items into a single post body.
	 *
	 * @param array<int, array<string, mixed>> $items   Ordered thread items. Each requires a 'text' key; 'author'/'created_at' are optional.
	 * @param array<string, mixed>             $options Options: 'voice' (string) e.g. "first-person casual".
	 * @return array{body: string, summary: string}|WP_Error Stitched output or error.
	 */
	public function stitch( array $items, array $options = array() ) {
		if ( count( $items ) < self::MIN_ITEMS ) {
			return new WP_Error(
				'ai_thread_too_few_items',
				sprintf(
					/* translators: %d: minimum items. */
					__( 'At least %d thread items are required to stitch.', 'ai-importer' ),
					self::MIN_ITEMS
				)
			);
		}

		$normalized = array();
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['text'] ) || ! is_string( $item['text'] ) ) {
				return new WP_Error(
					'ai_thread_invalid_item',
					sprintf(
						/* translators: %d: item index. */
						__( 'Thread item at index %d is missing a text field.', 'ai-importer' ),
						$index
					)
				);
			}

			$text = trim( $item['text'] );
			if ( '' === $text ) {
				return new WP_Error(
					'ai_thread_empty_item',
					sprintf(
						/* translators: %d: item index. */
						__( 'Thread item at index %d has empty text.', 'ai-importer' ),
						$index
					)
				);
			}

			$normalized[] = $text;
		}

		$voice  = isset( $options['voice'] ) ? (string) $options['voice'] : '';
		$prompt = $this->build_prompt( $normalized, $voice );
		$schema = $this->build_schema();

		$response = $this->service->generate_structured( $prompt, $schema );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! array_key_exists( 'body', $response ) || ! is_string( $response['body'] ) ) {
			return new WP_Error(
				'ai_thread_malformed',
				__( 'AI response did not include a body string.', 'ai-importer' ),
				array( 'response' => $response )
			);
		}

		$body = trim( $response['body'] );
		if ( '' === $body ) {
			return new WP_Error(
				'ai_thread_empty_body',
				__( 'AI returned an empty thread body.', 'ai-importer' )
			);
		}

		$summary = isset( $response['summary'] ) && is_string( $response['summary'] )
			? trim( $response['summary'] )
			: '';

		return array(
			'body'    => $body,
			'summary' => $summary,
		);
	}

	/**
	 * Build the stitching prompt.
	 *
	 * @param array<int, string> $texts Ordered item texts.
	 * @param string             $voice Optional voice hint.
	 * @return string Prompt.
	 */
	private function build_prompt( array $texts, string $voice ): string {
		$numbered = array();
		foreach ( $texts as $index => $text ) {
			$numbered[] = sprintf( '[%d] %s', $index + 1, $text );
		}

		$prompt = 'You are stitching a social media thread into a coherent long-form post. '
			. 'Fuse the numbered items below into readable paragraphs that preserve the author\'s meaning and voice. '
			. 'Remove thread markers like "1/", "2/10", or "🧵". Do not add new claims not present in the source. '
			. 'Also produce a one-sentence summary.';

		if ( '' !== $voice ) {
			$prompt .= ' Match this voice: ' . $voice . '.';
		}

		$prompt .= "\n\nThread items (in order):\n" . implode( "\n", $numbered );

		return $prompt;
	}

	/**
	 * JSON schema for the stitched response.
	 *
	 * @return array<string, mixed>
	 */
	private function build_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'body'    => array(
					'type'        => 'string',
					'description' => 'Stitched post body in readable paragraphs.',
				),
				'summary' => array(
					'type'        => 'string',
					'description' => 'One-sentence summary of the thread.',
				),
			),
			'required'   => array( 'body' ),
		);
	}
}
