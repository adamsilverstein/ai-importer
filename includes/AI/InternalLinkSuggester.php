<?php
/**
 * Internal link suggester.
 *
 * @package AI_Importer\AI
 */

namespace AI_Importer\AI;

use WP_Error;

/**
 * Suggests and applies internal links to imported content via AIService (PRD F8.4).
 *
 * Imported posts rarely link to the destination site's existing content. This
 * suggester samples a capped set of already-published posts (titles and
 * permalinks), passes them alongside the item body to the model, and asks for
 * a small set of {anchor_phrase, target} pairs where the anchor phrase appears
 * verbatim in the content. Suggestions are then applied conservatively: only
 * the first occurrence of each verbatim anchor phrase is linked, up to a cap,
 * and never inside an existing tag or anchor.
 *
 * Per the PRD cost table this is designed to run as a single AI call per item
 * (the candidate post set can be fetched once and reused across a batch). The
 * enhancement is opt-in and non-destructive: the original content is returned
 * unchanged when AI is unavailable, when the provider errors, or when no usable
 * suggestions are produced.
 */
class InternalLinkSuggester {

	/**
	 * Default maximum number of internal links to insert into a single item.
	 */
	public const DEFAULT_MAX_LINKS = 5;

	/**
	 * Default maximum number of candidate posts fetched as link targets.
	 */
	public const DEFAULT_CANDIDATE_LIMIT = 50;

	/**
	 * AI service.
	 *
	 * @var AIService
	 */
	private AIService $service;

	/**
	 * Maximum number of links to insert per item.
	 *
	 * @var int
	 */
	private int $max_links;

	/**
	 * Maximum number of candidate posts to consider as link targets.
	 *
	 * @var int
	 */
	private int $candidate_limit;

	/**
	 * Constructor.
	 *
	 * @param AIService|null $service         AI service wrapper. A default instance is created when omitted.
	 * @param int            $max_links       Maximum links to insert per item.
	 * @param int            $candidate_limit Maximum candidate posts to consider as targets.
	 */
	public function __construct(
		?AIService $service = null,
		int $max_links = self::DEFAULT_MAX_LINKS,
		int $candidate_limit = self::DEFAULT_CANDIDATE_LIMIT
	) {
		$this->service         = $service ?? new AIService();
		$this->max_links       = max( 1, $max_links );
		$this->candidate_limit = max( 1, $candidate_limit );
	}

	/**
	 * Suggest and apply internal links to the given content.
	 *
	 * Non-destructive: returns the original content unchanged when AI is
	 * unavailable, when there are no candidate posts, when the provider errors,
	 * or when no verbatim anchor phrases can be linked.
	 *
	 * @param string                                $content    Post body (HTML).
	 * @param array<int, array<string, mixed>>|null $candidates Optional pre-fetched candidate posts
	 *                                                          (each with 'id', 'title', 'url'). When null,
	 *                                                          candidates are queried.
	 * @return string Content with internal links applied, or the original content.
	 */
	public function enhance( string $content, ?array $candidates = null ): string {
		if ( '' === trim( $content ) ) {
			return $content;
		}

		if ( ! $this->service->is_available() ) {
			return $content;
		}

		$candidates = null === $candidates ? $this->get_candidate_posts() : $candidates;

		if ( empty( $candidates ) ) {
			return $content;
		}

		$suggestions = $this->suggest( $content, $candidates );

		if ( is_wp_error( $suggestions ) || empty( $suggestions ) ) {
			return $content;
		}

		return $this->apply( $content, $suggestions );
	}

	/**
	 * Ask the model for internal-link suggestions.
	 *
	 * The returned suggestions are validated against the candidate set: every
	 * suggestion's URL must belong to a supplied candidate, and the anchor
	 * phrase must be a non-empty string. Verbatim-occurrence checking happens
	 * during application, not here.
	 *
	 * @param string                           $content    Post body.
	 * @param array<int, array<string, mixed>> $candidates Candidate target posts (each with 'id', 'title', 'url').
	 * @return array<int, array{anchor:string,url:string}>|WP_Error Suggestions or error.
	 */
	public function suggest( string $content, array $candidates ) {
		$valid_urls = array();
		foreach ( $candidates as $candidate ) {
			if ( isset( $candidate['url'] ) && is_string( $candidate['url'] ) && '' !== $candidate['url'] ) {
				$valid_urls[ $candidate['url'] ] = true;
			}
		}

		if ( empty( $valid_urls ) ) {
			return array();
		}

		$prompt = $this->build_prompt( $content, $candidates );
		$schema = $this->build_schema();

		$response = $this->service->generate_structured( $prompt, $schema );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! array_key_exists( 'links', $response ) || ! is_array( $response['links'] ) ) {
			return new WP_Error(
				'ai_internal_links_malformed',
				__( 'AI response did not include a links array.', 'ai-importer' ),
				array( 'response' => $response )
			);
		}

		$suggestions = array();
		foreach ( $response['links'] as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}

			$anchor = isset( $link['anchor'] ) && is_string( $link['anchor'] ) ? trim( $link['anchor'] ) : '';
			$url    = isset( $link['url'] ) && is_string( $link['url'] ) ? trim( $link['url'] ) : '';

			if ( '' === $anchor || '' === $url ) {
				continue;
			}

			// Only trust URLs that came from the candidate set.
			if ( ! isset( $valid_urls[ $url ] ) ) {
				continue;
			}

			$suggestions[] = array(
				'anchor' => $anchor,
				'url'    => $url,
			);
		}

		return $suggestions;
	}

	/**
	 * Apply link suggestions to the content.
	 *
	 * Conservative by design: only the first occurrence of each anchor phrase
	 * that appears verbatim in the visible text is linked, up to the configured
	 * cap. Anchor phrases that do not occur verbatim are ignored. Occurrences
	 * inside an existing HTML tag or anchor element are skipped so links are
	 * never nested or broken.
	 *
	 * @param string                                      $content     Post body (HTML).
	 * @param array<int, array{anchor:string,url:string}> $suggestions Validated suggestions.
	 * @return string Content with links applied.
	 */
	public function apply( string $content, array $suggestions ): string {
		$applied      = 0;
		$used_anchors = array();

		foreach ( $suggestions as $suggestion ) {
			if ( $applied >= $this->max_links ) {
				break;
			}

			$anchor = $suggestion['anchor'];
			$url    = $suggestion['url'];

			// Skip duplicate anchor phrases (case-insensitive).
			$anchor_key = strtolower( $anchor );
			if ( isset( $used_anchors[ $anchor_key ] ) ) {
				continue;
			}

			$linked = $this->link_first_occurrence( $content, $anchor, $url );

			if ( null === $linked ) {
				continue;
			}

			$content                     = $linked;
			$used_anchors[ $anchor_key ] = true;
			++$applied;
		}

		return $content;
	}

	/**
	 * Link the first verbatim, outside-of-markup occurrence of an anchor phrase.
	 *
	 * @param string $content Content to modify.
	 * @param string $anchor  Anchor phrase (verbatim).
	 * @param string $url      Target URL.
	 * @return string|null Modified content, or null when the phrase does not occur in linkable text.
	 */
	private function link_first_occurrence( string $content, string $anchor, string $url ): ?string {
		$offset = 0;
		$length = strlen( $content );

		while ( $offset < $length ) {
			$pos = stripos( $content, $anchor, $offset );

			if ( false === $pos ) {
				return null;
			}

			if ( $this->is_inside_markup( $content, $pos ) ) {
				// Advance past this occurrence and keep looking.
				$offset = $pos + 1;
				continue;
			}

			// Preserve the original casing of the matched text.
			$matched = substr( $content, $pos, strlen( $anchor ) );

			$replacement = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				$matched
			);

			return substr( $content, 0, $pos ) . $replacement . substr( $content, $pos + strlen( $anchor ) );
		}

		return null;
	}

	/**
	 * Whether the position falls inside an HTML tag or an existing anchor element.
	 *
	 * Prevents linking inside attribute values (e.g. a phrase appearing in an
	 * href or alt attribute) and prevents nesting a link inside another link.
	 *
	 * @param string $content Content.
	 * @param int    $pos     Byte offset of the candidate match.
	 * @return bool True when the position should not be linked.
	 */
	private function is_inside_markup( string $content, int $pos ): bool {
		$before = substr( $content, 0, $pos );

		// Inside a tag: the nearest '<' comes after the nearest '>'.
		$last_open  = strrpos( $before, '<' );
		$last_close = strrpos( $before, '>' );

		if ( false !== $last_open && ( false === $last_close || $last_open > $last_close ) ) {
			return true;
		}

		// Inside an existing anchor: an <a ...> opened more recently than any </a>.
		$last_anchor_open  = strripos( $before, '<a ' );
		$last_anchor_close = strripos( $before, '</a>' );

		if ( false !== $last_anchor_open && ( false === $last_anchor_close || $last_anchor_open > $last_anchor_close ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Query a capped sample of published posts to use as link targets.
	 *
	 * @return array<int, array{id:int,title:string,url:string}> Candidate posts.
	 */
	public function get_candidate_posts(): array {
		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => $this->candidate_limit,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		$candidates = array();

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post );

			if ( ! is_string( $permalink ) || '' === $permalink ) {
				continue;
			}

			$candidates[] = array(
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
				'url'   => $permalink,
			);
		}

		return $candidates;
	}

	/**
	 * Build the prompt for internal-link suggestions.
	 *
	 * @param string                           $content    Post body.
	 * @param array<int, array<string, mixed>> $candidates Candidate posts (each with 'id', 'title', 'url').
	 * @return string Prompt.
	 */
	private function build_prompt( string $content, array $candidates ): string {
		$prompt = sprintf(
			'Suggest up to %d internal links from the article below to the most topically relevant existing posts. '
			. 'For each suggestion, choose an anchor phrase that appears verbatim in the article text and a target URL '
			. 'drawn ONLY from the candidate list. Use the URL exactly as given. Do not invent URLs or anchor phrases, '
			. 'do not suggest a link unless it is genuinely relevant, and never reuse the same anchor phrase twice.',
			$this->max_links
		);

		$prompt .= "\n\nCandidate posts (title — URL):";
		foreach ( $candidates as $candidate ) {
			$title   = isset( $candidate['title'] ) ? (string) $candidate['title'] : '';
			$url     = isset( $candidate['url'] ) ? (string) $candidate['url'] : '';
			$prompt .= sprintf( "\n- %s — %s", $title, $url );
		}

		$prompt .= "\n\nArticle:\n" . $content;

		return $prompt;
	}

	/**
	 * JSON schema for the internal-link response.
	 *
	 * @return array<string, mixed>
	 */
	private function build_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'links' => array(
					'type'        => 'array',
					'description' => 'Suggested internal links.',
					'maxItems'    => $this->max_links,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'anchor' => array(
								'type'        => 'string',
								'description' => 'A phrase that appears verbatim in the article.',
							),
							'url'    => array(
								'type'        => 'string',
								'description' => 'Target URL chosen from the candidate list.',
							),
						),
						'required'   => array( 'anchor', 'url' ),
					),
				),
			),
			'required'   => array( 'links' ),
		);
	}
}
