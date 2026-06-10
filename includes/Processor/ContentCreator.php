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
	 * @param NormalizedItem $item     The normalized content.
	 * @param string         $batch_id The import batch UUID.
	 * @return int The created post ID.
	 * @throws RuntimeException On wp_insert_post failure.
	 */
	public function create( NormalizedItem $item, string $batch_id ): int {
		$gmt_date = $item->publish_date->setTimezone( new DateTimeZone( 'UTC' ) );

		$post_args = array(
			'post_title'    => $item->title ? $item->title : $item->generate_title(),
			'post_content'  => $item->content,
			'post_status'   => 'draft',
			'post_date'     => $item->publish_date->format( 'Y-m-d H:i:s' ),
			'post_date_gmt' => $gmt_date->format( 'Y-m-d H:i:s' ),
			'post_type'     => 'post',
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

		// Set tags from hashtags.
		if ( $item->has_tags() ) {
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
	 * Find an existing post imported from the same source item.
	 *
	 * Matches on both the source adapter ID and the original platform
	 * item ID so identical IDs from different platforms don't collide.
	 *
	 * @param string $source_adapter The source adapter ID.
	 * @param string $source_id      The original platform item ID.
	 * @return int|null The existing post ID, or null if none found.
	 */
	public function find_existing( string $source_adapter, string $source_id ): ?int {
		if ( '' === $source_adapter || '' === $source_id ) {
			return null;
		}

		$post_ids = get_posts(
			array(
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for duplicate detection; bounded to one result.
				'meta_query'             => array(
					array(
						'key'   => self::META_SOURCE,
						'value' => $source_adapter,
					),
					array(
						'key'   => self::META_SOURCE_ID,
						'value' => $source_id,
					),
				),
			)
		);

		return ! empty( $post_ids ) ? (int) $post_ids[0] : null;
	}

	/**
	 * Update an existing imported post from a normalized item.
	 *
	 * Refreshes the post title, content, and dates, and records the
	 * batch that last touched the post.
	 *
	 * @param int            $post_id  The existing post ID.
	 * @param NormalizedItem $item     The normalized content.
	 * @param string         $batch_id The import batch UUID.
	 * @return int The updated post ID.
	 * @throws RuntimeException On wp_update_post failure.
	 */
	public function update( int $post_id, NormalizedItem $item, string $batch_id ): int {
		$gmt_date = $item->publish_date->setTimezone( new DateTimeZone( 'UTC' ) );

		$post_args = array(
			'ID'            => $post_id,
			'post_title'    => $item->title ? $item->title : $item->generate_title(),
			'post_content'  => $item->content,
			'post_date'     => $item->publish_date->format( 'Y-m-d H:i:s' ),
			'post_date_gmt' => $gmt_date->format( 'Y-m-d H:i:s' ),
		);

		/**
		 * Filters the post arguments before updating an existing imported item.
		 *
		 * @param array<string, mixed> $post_args WordPress post arguments.
		 * @param NormalizedItem       $item      The normalized item.
		 * @param string               $batch_id  The batch UUID.
		 */
		$post_args = apply_filters( 'ai_importer_update_post_args', $post_args, $item, $batch_id );

		$result = wp_update_post( $post_args, true );

		if ( is_wp_error( $result ) ) {
			$messages = $result->get_error_messages();
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new RuntimeException(
				! empty( $messages ) ? $messages[0] : 'Failed to update post.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		update_post_meta( $post_id, self::META_BATCH_ID, $batch_id );
		update_post_meta( $post_id, self::META_IMPORTED_AT, gmdate( 'c' ) );

		/**
		 * Fires after an existing post is updated from a re-imported item.
		 *
		 * @param int            $post_id  The updated post ID.
		 * @param NormalizedItem $item     The normalized item.
		 * @param string         $batch_id The batch UUID.
		 */
		do_action( 'ai_importer_post_updated', $post_id, $item, $batch_id );

		return $post_id;
	}
}
