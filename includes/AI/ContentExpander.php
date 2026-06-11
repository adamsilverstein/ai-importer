<?php
/**
 * Short-content expander.
 *
 * @package AI_Importer\AI
 */

namespace AI_Importer\AI;

use WP_Error;

/**
 * Expands short posts into fuller articles via AIService (PRD F8.3).
 *
 * Social posts and status updates are frequently too short to read as
 * standalone WordPress articles. When a post's plain-text body falls below
 * a configurable word threshold, this expander asks the model to develop it
 * into a fuller piece while preserving the original meaning, facts, and
 * authorial voice.
 *
 * This enhancement is opt-in and non-destructive: when the content is already
 * long enough, when AI is unavailable, or when the provider errors out, the
 * caller receives the original content unchanged. The expander never fabricates
 * facts — the prompt instructs the model to elaborate only on what is present.
 */
class ContentExpander {

	/**
	 * Default word-count threshold below which content is considered "short".
	 *
	 * Posts at or below this many words are candidates for expansion; longer
	 * posts are left untouched. Roughly the length of a long tweet thread or a
	 * brief status update.
	 */
	public const DEFAULT_WORD_THRESHOLD = 150;

	/**
	 * AI service.
	 *
	 * @var AIService
	 */
	private AIService $service;

	/**
	 * Word-count threshold below which content is expanded.
	 *
	 * @var int
	 */
	private int $word_threshold;

	/**
	 * Constructor.
	 *
	 * @param AIService|null $service        AI service wrapper. A default instance is created when omitted.
	 * @param int            $word_threshold Word-count threshold below which content is expanded.
	 */
	public function __construct( ?AIService $service = null, int $word_threshold = self::DEFAULT_WORD_THRESHOLD ) {
		$this->service        = $service ?? new AIService();
		$this->word_threshold = max( 1, $word_threshold );
	}

	/**
	 * Expand short content into a fuller article.
	 *
	 * Behaviour is deliberately conservative and non-destructive: the original
	 * content is returned unchanged when it is already long enough, when AI is
	 * unavailable, when the provider errors, or when the model returns an
	 * unusable response. Callers can treat the return value as a drop-in
	 * replacement for the input content.
	 *
	 * @param string               $content Post body (HTML or plain text).
	 * @param array<string, mixed> $options Options: 'title' (string) for additional context.
	 * @return string Expanded HTML, or the original content when expansion is skipped or fails.
	 */
	public function expand( string $content, array $options = array() ): string {
		if ( '' === trim( $content ) ) {
			return $content;
		}

		if ( ! $this->is_short( $content ) ) {
			return $content;
		}

		if ( ! $this->service->is_available() ) {
			return $content;
		}

		$title  = isset( $options['title'] ) ? (string) $options['title'] : '';
		$prompt = $this->build_prompt( $content, $title );
		$schema = $this->build_schema();

		$response = $this->service->generate_structured( $prompt, $schema );

		if ( is_wp_error( $response ) ) {
			return $content;
		}

		if ( ! array_key_exists( 'content', $response ) || ! is_string( $response['content'] ) ) {
			return $content;
		}

		$expanded = trim( $response['content'] );

		if ( '' === $expanded ) {
			return $content;
		}

		return $expanded;
	}

	/**
	 * Whether the given content is below the word threshold.
	 *
	 * Tags are stripped before counting so HTML markup does not inflate the
	 * word count.
	 *
	 * @param string $content Post body (HTML or plain text).
	 * @return bool True when the content is short enough to expand.
	 */
	public function is_short( string $content ): bool {
		return $this->count_words( $content ) <= $this->word_threshold;
	}

	/**
	 * Count the words in a piece of content, ignoring HTML markup.
	 *
	 * @param string $content Post body (HTML or plain text).
	 * @return int Word count.
	 */
	private function count_words( string $content ): int {
		$text = wp_strip_all_tags( $content );

		return str_word_count( $text );
	}

	/**
	 * Build the prompt for content expansion.
	 *
	 * @param string $content Original post content.
	 * @param string $title   Optional title for context.
	 * @return string Prompt.
	 */
	private function build_prompt( string $content, string $title ): string {
		$prompt = 'Expand the short post below into a fuller, well-structured article suitable for a blog. '
			. 'Preserve the original meaning, tone, and authorial voice. '
			. 'Do not fabricate facts, statistics, quotes, names, dates, or events that are not present in the original; '
			. 'only elaborate on, clarify, and add natural transitions around the ideas the author already expressed. '
			. 'Return clean HTML using paragraph tags (and lists or subheadings only where they genuinely help). '
			. 'Do not add a title heading, and do not wrap the output in code fences.';

		if ( '' !== trim( $title ) ) {
			$prompt .= "\n\nPost title: " . $title;
		}

		$prompt .= "\n\nOriginal post:\n" . $content;

		return $prompt;
	}

	/**
	 * JSON schema for the expansion response.
	 *
	 * @return array<string, mixed>
	 */
	private function build_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content' => array(
					'type'        => 'string',
					'description' => 'The expanded article as clean HTML.',
				),
			),
			'required'   => array( 'content' ),
		);
	}
}
