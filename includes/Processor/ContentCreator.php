<?php
/**
 * Content creator for importing normalized items into WordPress.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Processor;

use AI_Importer\Normalizer\NormalizedItem;
use AI_Importer\Schema\CustomTaxonomyRegistrar;
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
	 * Registrar used to create custom taxonomies on demand (F9.3).
	 *
	 * @var CustomTaxonomyRegistrar
	 */
	private CustomTaxonomyRegistrar $taxonomy_registrar;

	/**
	 * Constructor.
	 *
	 * @param CustomTaxonomyRegistrar|null $taxonomy_registrar Optional registrar (injectable for tests).
	 */
	public function __construct( ?CustomTaxonomyRegistrar $taxonomy_registrar = null ) {
		$this->taxonomy_registrar = $taxonomy_registrar ?? new CustomTaxonomyRegistrar();
	}

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

		$post_type = $this->resolve_post_type( $item, $mapping );

		$post_args = array(
			'post_title'    => $item->title ? $item->title : $item->generate_title(),
			'post_content'  => $item->content,
			'post_status'   => $this->resolve_post_status( $mapping ),
			'post_date'     => $item->publish_date->format( 'Y-m-d H:i:s' ),
			'post_date_gmt' => $gmt_date->format( 'Y-m-d H:i:s' ),
			'post_type'     => $post_type,
		);

		// Apply author mapping (F9.2): a matching source-author mapping wins
		// over the default_author_id, which wins over WordPress's default
		// (current user). Only validated, existing users are applied.
		$author_id = $this->resolve_author_id( $item, $mapping );
		if ( null !== $author_id ) {
			$post_args['post_author'] = $author_id;
		}

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

		// Assign a post format (F9.4) when the destination post type supports it.
		$this->apply_post_format( $post_id, $post_type, $item, $mapping );

		// Copy mapped item metadata to post meta (F9.1, generic post-meta).
		$this->apply_meta_field_mappings( $post_id, $item, $mapping );

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
	 * When an entry sets create_if_missing and the destination taxonomy does
	 * not yet exist, it is registered on demand (F9.3) before terms are set.
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

			$taxonomy = $entry['destination_taxonomy'];

			// Create the taxonomy on demand when requested and missing (F9.3).
			if ( ! empty( $entry['create_if_missing'] ) && ! taxonomy_exists( $taxonomy ) ) {
				$label = isset( $entry['taxonomy_label'] ) && is_string( $entry['taxonomy_label'] ) && '' !== $entry['taxonomy_label']
					? $entry['taxonomy_label']
					: $taxonomy;

				$object_type = get_post_type( $post_id );

				$this->taxonomy_registrar->ensure_registered(
					$taxonomy,
					$label,
					array( is_string( $object_type ) && '' !== $object_type ? $object_type : 'post' )
				);
			}

			// Skip taxonomies that still do not exist to avoid silent failures.
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			wp_set_object_terms( $post_id, $terms, $taxonomy, true );
		}

		return $hashtags_handled;
	}

	/**
	 * Resolve the destination author user ID for an item (F9.2).
	 *
	 * A source-author mapping whose source_author matches the item's author
	 * name wins. Otherwise the mapping's default_author_id applies. Only
	 * users that actually exist are returned; everything else falls back to
	 * WordPress's default behavior (null is returned).
	 *
	 * @param NormalizedItem       $item    The normalized item.
	 * @param array<string, mixed> $mapping Mapping configuration.
	 * @return int|null Author user ID, or null to use the default.
	 */
	private function resolve_author_id( NormalizedItem $item, array $mapping ): ?int {
		if ( ! empty( $mapping['author_mappings'] ) && is_array( $mapping['author_mappings'] ) && null !== $item->author_name ) {
			foreach ( $mapping['author_mappings'] as $entry ) {
				if ( ! is_array( $entry )
					|| empty( $entry['source_author'] )
					|| ! is_string( $entry['source_author'] )
					|| ! isset( $entry['destination_user_id'] )
				) {
					continue;
				}

				if ( $entry['source_author'] === $item->author_name ) {
					$user_id = (int) $entry['destination_user_id'];

					if ( $this->user_exists( $user_id ) ) {
						return $user_id;
					}

					break;
				}
			}
		}

		if ( isset( $mapping['default_author_id'] ) ) {
			$default_id = (int) $mapping['default_author_id'];

			if ( $default_id > 0 && $this->user_exists( $default_id ) ) {
				return $default_id;
			}
		}

		return null;
	}

	/**
	 * Check that a WordPress user exists.
	 *
	 * @param int $user_id User ID.
	 * @return bool True when the user exists.
	 */
	private function user_exists( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}

		return false !== get_userdata( $user_id );
	}

	/**
	 * Assign a WordPress post format to the created post (F9.4).
	 *
	 * A per-content-type mapping wins over the mapping's default_post_format.
	 * The format is only applied when the destination post type supports the
	 * 'post-formats' feature. The 'standard' pseudo-format clears any format.
	 *
	 * @param int                  $post_id   The created post ID.
	 * @param string               $post_type The destination post type.
	 * @param NormalizedItem       $item      The normalized item.
	 * @param array<string, mixed> $mapping   Mapping configuration.
	 * @return void
	 */
	private function apply_post_format( int $post_id, string $post_type, NormalizedItem $item, array $mapping ): void {
		$format = null;

		if ( ! empty( $mapping['post_format_mappings'] ) && is_array( $mapping['post_format_mappings'] ) ) {
			foreach ( $mapping['post_format_mappings'] as $entry ) {
				if ( is_array( $entry )
					&& isset( $entry['source_content_type'], $entry['post_format'] )
					&& $entry['source_content_type'] === $item->content_type->value
					&& is_string( $entry['post_format'] )
					&& '' !== $entry['post_format']
				) {
					$format = $entry['post_format'];
					break;
				}
			}
		}

		if ( null === $format && isset( $mapping['default_post_format'] ) && is_string( $mapping['default_post_format'] ) && '' !== $mapping['default_post_format'] ) {
			$format = $mapping['default_post_format'];
		}

		if ( null === $format ) {
			return;
		}

		// Only post types declaring post-formats support can carry a format.
		if ( ! post_type_supports( $post_type, 'post-formats' ) ) {
			return;
		}

		// set_post_format() treats 'standard' as clearing the format.
		set_post_format( $post_id, 'standard' === $format ? false : $format );
	}

	/**
	 * Copy mapped item metadata to post meta (F9.1, generic post-meta only).
	 *
	 * For each meta_field_mappings entry, the value at item->metadata[
	 * source_field] is written to the destination post meta key. ACF and
	 * Meta Box fields are stored as ordinary post meta, so this covers their
	 * basic value storage; full ACF field-group detection (field keys,
	 * repeaters, sub-fields) is out of scope for this generic mapping.
	 *
	 * @param int                  $post_id The created post ID.
	 * @param NormalizedItem       $item    The normalized item.
	 * @param array<string, mixed> $mapping Mapping configuration.
	 * @return void
	 */
	private function apply_meta_field_mappings( int $post_id, NormalizedItem $item, array $mapping ): void {
		if ( empty( $mapping['meta_field_mappings'] ) || ! is_array( $mapping['meta_field_mappings'] ) ) {
			return;
		}

		foreach ( $mapping['meta_field_mappings'] as $entry ) {
			if ( ! is_array( $entry )
				|| empty( $entry['source_field'] )
				|| ! is_string( $entry['source_field'] )
				|| empty( $entry['destination_meta_key'] )
				|| ! is_string( $entry['destination_meta_key'] )
			) {
				continue;
			}

			if ( ! array_key_exists( $entry['source_field'], $item->metadata ) ) {
				continue;
			}

			$value = $item->metadata[ $entry['source_field'] ];

			// Only persist scalar or array values; objects/resources are skipped.
			if ( ! is_scalar( $value ) && ! is_array( $value ) && null !== $value ) {
				continue;
			}

			update_post_meta( $post_id, sanitize_key( $entry['destination_meta_key'] ), $value );
		}
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
