<?php
/**
 * Content creator for importing normalized items into WordPress.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Processor;

use AI_Importer\Normalizer\NormalizedItem;
use DateTimeZone;
use RuntimeException;

/**
 * Creates WordPress posts from NormalizedItem objects and sets
 * tracking metadata for rollback and provenance.
 */
class ContentCreator {

	/**
	 * Post meta key for source adapter ID.
	 */
	public const META_SOURCE = '_ai_importer_source';

	/**
	 * Post meta key for original platform item ID.
	 */
	public const META_SOURCE_ID = '_ai_importer_source_id';

	/**
	 * Post meta key for import batch UUID.
	 */
	public const META_BATCH_ID = '_ai_importer_batch_id';

	/**
	 * Post meta key for link to original content.
	 */
	public const META_ORIGINAL_URL = '_ai_importer_original_url';

	/**
	 * Post meta key for import timestamp.
	 */
	public const META_IMPORTED_AT = '_ai_importer_imported_at';

	/**
	 * Post meta key for the AI-generated SEO meta description.
	 */
	public const META_SEO_DESCRIPTION = '_ai_importer_seo_description';

	/**
	 * Create a WordPress post from a normalized item.
	 *
	 * @param NormalizedItem       $item     The normalized content.
	 * @param string               $batch_id The import batch UUID.
	 * @param array<string, mixed> $mapping  Optional mapping configuration (see MappingConfig).
	 * @return int The created post ID.
	 * @throws RuntimeException On wp_insert_post failure.
	 */
	public function create( NormalizedItem $item, string $batch_id, array $mapping = array() ): int {
		$gmt_date = $item->publish_date->setTimezone( new DateTimeZone( 'UTC' ) );

		$post_args = array(
			'post_title'    => $item->title ? $item->title : $item->generate_title(),
			'post_content'  => $item->content,
			'post_status'   => $this->resolve_post_status( $mapping ),
			'post_date'     => $item->publish_date->format( 'Y-m-d H:i:s' ),
			'post_date_gmt' => $gmt_date->format( 'Y-m-d H:i:s' ),
			'post_type'     => $this->resolve_post_type( $item, $mapping ),
		);

		/**
		 * Filters the post arguments before inserting an imported item.
		 *
		 * @param array<string, mixed> $post_args WordPress post arguments.
		 * @param NormalizedItem       $item      The normalized item.
		 * @param string               $batch_id  The batch UUID.
		 */
		$post_args = apply_filters( 'ai_importer_post_args', $post_args, $item, $batch_id );

		$post_id = wp_insert_post( $post_args, true );

		if ( is_wp_error( $post_id ) ) {
			$messages = $post_id->get_error_messages();
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new RuntimeException(
				! empty( $messages ) ? $messages[0] : 'Failed to create post.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// Set tracking meta.
		update_post_meta( $post_id, self::META_SOURCE, $item->source_adapter );
		update_post_meta( $post_id, self::META_SOURCE_ID, $item->source_id );
		update_post_meta( $post_id, self::META_BATCH_ID, $batch_id );
		update_post_meta( $post_id, self::META_IMPORTED_AT, gmdate( 'c' ) );

		if ( $item->source_url ) {
			update_post_meta( $post_id, self::META_ORIGINAL_URL, $item->source_url );
		}

		$seo_key = ItemEnhancer::META_KEY_SEO_DESCRIPTION;
		if ( isset( $item->metadata[ $seo_key ] )
			&& is_string( $item->metadata[ $seo_key ] )
			&& '' !== $item->metadata[ $seo_key ]
		) {
			update_post_meta( $post_id, self::META_SEO_DESCRIPTION, $item->metadata[ $seo_key ] );
		}

		// Apply taxonomy mappings; default hashtag handling applies unless
		// a mapping explicitly routes hashtags elsewhere.
		$hashtags_handled = $this->apply_taxonomy_mappings( $post_id, $item, $mapping );

		// Set tags from hashtags (default behavior).
		if ( ! $hashtags_handled && $item->has_tags() ) {
			wp_set_post_tags( $post_id, $item->tags );
		}

		// Set featured image from first imported image.
		$images = $item->get_images();

		if ( ! empty( $images ) && $images[0]->is_imported() ) {
			set_post_thumbnail( $post_id, $images[0]->attachment_id );
		}

		/**
		 * Fires after a post is created from an imported item.
		 *
		 * @param int            $post_id  The new post ID.
		 * @param NormalizedItem $item     The normalized item.
		 * @param string         $batch_id The batch UUID.
		 */
		do_action( 'ai_importer_post_created', $post_id, $item, $batch_id );

		return $post_id;
	}

	/**
	 * Resolve the destination post type for an item.
	 *
	 * Per-content-type overrides from the mapping win over the mapping's
	 * default post type, which wins over the built-in 'post' default.
	 *
	 * @param NormalizedItem       $item    The normalized item.
	 * @param array<string, mixed> $mapping Mapping configuration.
	 * @return string Post type slug.
	 */
	private function resolve_post_type( NormalizedItem $item, array $mapping ): string {
		$post_type = 'post';

		if ( ! empty( $mapping['post_type'] ) && is_string( $mapping['post_type'] ) ) {
			$post_type = $mapping['post_type'];
		}

		if ( ! empty( $mapping['post_type_mappings'] ) && is_array( $mapping['post_type_mappings'] ) ) {
			foreach ( $mapping['post_type_mappings'] as $entry ) {
				if ( is_array( $entry )
					&& isset( $entry['source_content_type'], $entry['destination_post_type'] )
					&& $entry['source_content_type'] === $item->content_type->value
					&& is_string( $entry['destination_post_type'] )
					&& '' !== $entry['destination_post_type']
				) {
					$post_type = $entry['destination_post_type'];
					break;
				}
			}
		}

		return $post_type;
	}

	/**
	 * Resolve the post status from the mapping, defaulting to 'draft'.
	 *
	 * @param array<string, mixed> $mapping Mapping configuration.
	 * @return string Post status.
	 */
	private function resolve_post_status( array $mapping ): string {
		if ( ! empty( $mapping['post_status'] ) && is_string( $mapping['post_status'] ) ) {
			return $mapping['post_status'];
		}

		return 'draft';
	}

	/**
	 * Apply configured taxonomy mappings to a created post.
	 *
	 * A mapping with source_signal "hashtags" assigns the item's tags to
	 * the destination taxonomy (taking over default hashtag handling).
	 * Other mappings assign their fixed destination_terms.
	 *
	 * @param int                  $post_id The created post ID.
	 * @param NormalizedItem       $item    The normalized item.
	 * @param array<string, mixed> $mapping Mapping configuration.
	 * @return bool True when a mapping handled the item's hashtags.
	 */
	private function apply_taxonomy_mappings( int $post_id, NormalizedItem $item, array $mapping ): bool {
		if ( empty( $mapping['taxonomy_mappings'] ) || ! is_array( $mapping['taxonomy_mappings'] ) ) {
			return false;
		}

		$hashtags_handled = false;

		foreach ( $mapping['taxonomy_mappings'] as $entry ) {
			if ( ! is_array( $entry )
				|| empty( $entry['source_signal'] )
				|| empty( $entry['destination_taxonomy'] )
				|| ! is_string( $entry['destination_taxonomy'] )
			) {
				continue;
			}

			if ( 'hashtags' === $entry['source_signal'] ) {
				$hashtags_handled = true;
				$terms            = $item->tags;
			} else {
				$terms = isset( $entry['destination_terms'] ) && is_array( $entry['destination_terms'] )
					? $entry['destination_terms']
					: array();
			}

			if ( empty( $terms ) ) {
				continue;
			}

			wp_set_object_terms( $post_id, $terms, $entry['destination_taxonomy'], true );
		}

		return $hashtags_handled;
	}
}
