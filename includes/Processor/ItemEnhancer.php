<?php
/**
 * Item enhancer.
 *
 * @package AI_Importer\Processor
 */

namespace AI_Importer\Processor;

use AI_Importer\AI\MetaDescriptionGenerator;
use AI_Importer\AI\TitleGenerator;
use AI_Importer\Normalizer\NormalizedItem;

/**
 * Applies AI enhancements to a NormalizedItem before it becomes a WordPress post.
 *
 * Currently supports title generation (for source items with no native title,
 * e.g. tweets) and SEO meta description generation. Enhancement failures are
 * non-fatal — they are logged when WP_DEBUG is on but never stop the import.
 *
 * Alt text, hashtag mapping, and thread stitching are handled elsewhere in
 * the pipeline because they operate on media or pre-normalization input.
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
	 * Enhancement flags.
	 *
	 * @var array{title: bool, meta_description: bool}
	 */
	private array $flags;

	/**
	 * Constructor.
	 *
	 * @param TitleGenerator           $title_generator Title generator.
	 * @param MetaDescriptionGenerator $meta_generator  Meta description generator.
	 * @param array<string, bool>      $flags           Optional per-enhancement flags: 'title', 'meta_description'.
	 */
	public function __construct(
		TitleGenerator $title_generator,
		MetaDescriptionGenerator $meta_generator,
		array $flags = array()
	) {
		$this->title_generator = $title_generator;
		$this->meta_generator  = $meta_generator;
		$this->flags           = array(
			'title'            => (bool) ( $flags['title'] ?? true ),
			'meta_description' => (bool) ( $flags['meta_description'] ?? true ),
		);
	}

	/**
	 * Apply enabled enhancements to the item in place.
	 *
	 * @param NormalizedItem $item Item to enhance (mutated).
	 * @return void
	 */
	public function enhance( NormalizedItem $item ): void {
		if ( $this->flags['title'] ) {
			$this->enhance_title( $item );
		}

		if ( $this->flags['meta_description'] ) {
			$this->enhance_meta_description( $item );
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
