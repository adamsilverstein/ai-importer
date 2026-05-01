<?php
/**
 * Item enhancer.
 *
 * @package AI_Importer\Processor
 */

namespace AI_Importer\Processor;

use AI_Importer\AI\HashtagMapper;
use AI_Importer\AI\MetaDescriptionGenerator;
use AI_Importer\AI\TitleGenerator;
use AI_Importer\Normalizer\NormalizedItem;

/**
 * Applies enhancements to a NormalizedItem before it becomes a WordPress post.
 *
 * Local cleanup (ContentCleaner) runs first so AI calls operate on already-
 * sanitized text. Then optional AI enhancements run: title generation, SEO
 * meta description, and hashtag-to-tag mapping. Enhancement failures are
 * non-fatal — they are logged when WP_DEBUG is on but never stop the import.
 *
 * Alt text and thread stitching are handled elsewhere in the pipeline because
 * they operate on media or pre-normalization input.
 */
class ItemEnhancer {

	/**
	 * Metadata key for the AI-generated SEO description on the normalized item.
	 */
	public const META_KEY_SEO_DESCRIPTION = 'seo_description';

	/**
	 * Title generator.
	 *
	 * @var TitleGenerator
	 */
	private TitleGenerator $title_generator;

	/**
	 * Meta description generator.
	 *
	 * @var MetaDescriptionGenerator
	 */
	private MetaDescriptionGenerator $meta_generator;

	/**
	 * Optional content cleaner (local, no AI cost).
	 *
	 * @var ContentCleaner|null
	 */
	private ?ContentCleaner $content_cleaner;

	/**
	 * Optional hashtag mapper.
	 *
	 * @var HashtagMapper|null
	 */
	private ?HashtagMapper $hashtag_mapper;

	/**
	 * Enhancement flags.
	 *
	 * @var array{title: bool, meta_description: bool, content_cleanup: bool, hashtag_mapping: bool}
	 */
	private array $flags;

	/**
	 * Constructor.
	 *
	 * @param TitleGenerator           $title_generator Title generator.
	 * @param MetaDescriptionGenerator $meta_generator  Meta description generator.
	 * @param ContentCleaner|null      $content_cleaner Optional content cleaner.
	 * @param HashtagMapper|null       $hashtag_mapper  Optional hashtag mapper.
	 * @param array<string, bool>      $flags           Per-enhancement flags: 'title', 'meta_description', 'content_cleanup', 'hashtag_mapping'.
	 */
	public function __construct(
		TitleGenerator $title_generator,
		MetaDescriptionGenerator $meta_generator,
		?ContentCleaner $content_cleaner = null,
		?HashtagMapper $hashtag_mapper = null,
		array $flags = array()
	) {
		$this->title_generator = $title_generator;
		$this->meta_generator  = $meta_generator;
		$this->content_cleaner = $content_cleaner;
		$this->hashtag_mapper  = $hashtag_mapper;
		$this->flags           = array(
			'title'            => (bool) ( $flags['title'] ?? true ),
			'meta_description' => (bool) ( $flags['meta_description'] ?? true ),
			'content_cleanup'  => (bool) ( $flags['content_cleanup'] ?? true ),
			'hashtag_mapping'  => (bool) ( $flags['hashtag_mapping'] ?? true ),
		);
	}

	/**
	 * Apply enabled enhancements to the item in place.
	 *
	 * Order: local cleanup → title → meta description → hashtag mapping.
	 * Cleanup runs first so AI calls see sanitized text.
	 *
	 * @param NormalizedItem $item Item to enhance (mutated).
	 * @return void
	 */
	public function enhance( NormalizedItem $item ): void {
		if ( $this->flags['content_cleanup'] && null !== $this->content_cleaner ) {
			$item->content = $this->content_cleaner->clean( $item->content );
		}

		if ( $this->flags['title'] ) {
			$this->enhance_title( $item );
		}

		if ( $this->flags['meta_description'] ) {
			$this->enhance_meta_description( $item );
		}

		if ( $this->flags['hashtag_mapping'] && null !== $this->hashtag_mapper ) {
			$this->enhance_tags( $item );
		}
	}

	/**
	 * Generate a title when the item has none.
	 *
	 * @param NormalizedItem $item Item (mutated).
	 * @return void
	 */
	private function enhance_title( NormalizedItem $item ): void {
		if ( null !== $item->title && '' !== trim( $item->title ) ) {
			return;
		}

		if ( '' === trim( $item->content ) ) {
			return;
		}

		$result = $this->title_generator->generate( $item->content );

		if ( is_wp_error( $result ) ) {
			$this->log_failure( 'title', $item->source_id, $this->first_message( $result ) );
			return;
		}

		$item->title = $result;
	}

	/**
	 * Generate an SEO meta description and stash it in $item->metadata.
	 *
	 * @param NormalizedItem $item Item (mutated).
	 * @return void
	 */
	private function enhance_meta_description( NormalizedItem $item ): void {
		if ( '' === trim( $item->content ) ) {
			return;
		}

		$options = array();
		if ( null !== $item->title && '' !== trim( $item->title ) ) {
			$options['title'] = $item->title;
		}

		if ( ! empty( $item->tags ) ) {
			$options['keywords'] = $item->tags;
		}

		$result = $this->meta_generator->generate( $item->content, $options );

		if ( is_wp_error( $result ) ) {
			$this->log_failure( 'meta_description', $item->source_id, $this->first_message( $result ) );
			return;
		}

		$item->metadata[ self::META_KEY_SEO_DESCRIPTION ] = $result;
	}

	/**
	 * Map raw hashtags on the item to clean WordPress tag names.
	 *
	 * Only runs when the item has at least one tag. Replaces $item->tags
	 * with the cleaned list on success; leaves it untouched on failure.
	 *
	 * @param NormalizedItem $item Item (mutated).
	 * @return void
	 */
	private function enhance_tags( NormalizedItem $item ): void {
		if ( null === $this->hashtag_mapper || empty( $item->tags ) ) {
			return;
		}

		$options = array();
		if ( '' !== trim( $item->content ) ) {
			$options['context'] = $item->content;
		}

		$result = $this->hashtag_mapper->map( $item->tags, $options );

		if ( is_wp_error( $result ) ) {
			$this->log_failure( 'hashtag_mapping', $item->source_id, $this->first_message( $result ) );
			return;
		}

		$item->tags = $result;
	}

	/**
	 * Extract the first error message from a WP_Error for logging.
	 *
	 * @param \WP_Error $error Error.
	 * @return string First message or empty string.
	 */
	private function first_message( \WP_Error $error ): string {
		$messages = $error->get_error_messages();

		return is_array( $messages ) && ! empty( $messages ) ? (string) $messages[0] : '';
	}

	/**
	 * Log a non-fatal enhancement failure (debug only).
	 *
	 * @param string $kind      Enhancement kind.
	 * @param string $source_id Source item ID.
	 * @param string $message   Error message.
	 * @return void
	 */
	private function log_failure( string $kind, string $source_id, string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic log.
			error_log(
				sprintf(
					'[ai-importer] %s enhancement failed for item %s: %s',
					$kind,
					$source_id,
					$message
				)
			);
		}
	}
}
