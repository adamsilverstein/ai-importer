<?php
/**
 * Item processor for creating WordPress posts from normalized content.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Processor;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\NormalizedItem;
use RuntimeException;

/**
 * Processes a single NormalizedItem into a WordPress post.
 */
class ItemProcessor {

	/**
	 * Media sideloader instance.
	 *
	 * @var MediaSideloader
	 */
	private MediaSideloader $media_sideloader;

	/**
	 * Constructor.
	 *
	 * @param MediaSideloader $media_sideloader The media sideloader.
	 */
	public function __construct( MediaSideloader $media_sideloader ) {
		$this->media_sideloader = $media_sideloader;
	}

	/**
	 * Process a normalized item into a WordPress post.
	 *
	 * @param NormalizedItem $item     The normalized content item.
	 * @param string         $batch_id The import batch UUID.
	 * @return int The created WordPress post ID.
	 * @throws RuntimeException On failure.
	 */
	public function process( NormalizedItem $item, string $batch_id ): int {
		$post_id = $this->create_post( $item );
		$this->set_post_meta( $post_id, $item, $batch_id );
		$this->set_taxonomies( $post_id, $item );
		$this->process_media( $post_id, $item );

		return $post_id;
	}

	/**
	 * Create a WordPress post from a normalized item.
	 *
	 * @param NormalizedItem $item The normalized item.
	 * @return int The post ID.
	 * @throws RuntimeException On failure.
	 */
	private function create_post( NormalizedItem $item ): int {
		$post_data = array(
			'post_title'    => $item->title ?? $item->generate_title(),
			'post_content'  => $item->content,
			'post_status'   => 'draft',
			'post_type'     => $this->map_post_type( $item ),
			'post_date'     => $item->publish_date->format( 'Y-m-d H:i:s' ),
			'post_date_gmt' => $item->publish_date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException(
				sprintf(
					'Failed to create post for item %s: %s',
					esc_html( $item->source_id ),
					esc_html( $post_id->get_error_message() )
				)
			);
		}

		return $post_id;
	}

	/**
	 * Set import tracking metadata on the post.
	 *
	 * @param int            $post_id  The post ID.
	 * @param NormalizedItem $item     The normalized item.
	 * @param string         $batch_id The batch UUID.
	 * @return void
	 */
	private function set_post_meta( int $post_id, NormalizedItem $item, string $batch_id ): void {
		update_post_meta( $post_id, '_ai_importer_source', $item->source_adapter );
		update_post_meta( $post_id, '_ai_importer_source_id', $item->source_id );
		update_post_meta( $post_id, '_ai_importer_batch_id', $batch_id );
		update_post_meta( $post_id, '_ai_importer_imported_at', current_time( 'mysql' ) );

		if ( ! empty( $item->source_url ) ) {
			update_post_meta( $post_id, '_ai_importer_original_url', $item->source_url );
		}

		if ( ! empty( $item->engagement ) ) {
			update_post_meta( $post_id, '_ai_importer_engagement', $item->engagement );
		}
	}

	/**
	 * Set taxonomies (tags) on the post.
	 *
	 * @param int            $post_id The post ID.
	 * @param NormalizedItem $item    The normalized item.
	 * @return void
	 */
	private function set_taxonomies( int $post_id, NormalizedItem $item ): void {
		if ( $item->has_tags() ) {
			wp_set_post_tags( $post_id, $item->tags );
		}
	}

	/**
	 * Process and sideload media for the post.
	 *
	 * @param int            $post_id The post ID.
	 * @param NormalizedItem $item    The normalized item.
	 * @return void
	 */
	private function process_media( int $post_id, NormalizedItem $item ): void {
		if ( ! $item->has_media() ) {
			return;
		}

		$featured_set = false;
		$url_map      = array();

		foreach ( $item->media as $media_ref ) {
			try {
				$attachment_id = $this->media_sideloader->sideload( $media_ref, $post_id );

				// Set first image as featured image.
				if ( ! $featured_set && $media_ref->is_image() ) {
					set_post_thumbnail( $post_id, $attachment_id );
					$featured_set = true;
				}

				// Map source URL to local URL for content replacement.
				$local_url = wp_get_attachment_url( $attachment_id );
				if ( $local_url ) {
					$url_map[ $media_ref->source_url ] = $local_url;
				}
			} catch ( RuntimeException $e ) {
				// Log media failure but continue processing other media.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for import diagnostics.
				error_log(
					sprintf(
						'AI Importer: Failed to sideload media %s: %s',
						$media_ref->source_url,
						$e->getMessage()
					)
				);
			}
		}

		// Replace source URLs with local URLs in post content.
		if ( ! empty( $url_map ) ) {
			$content = get_post_field( 'post_content', $post_id );
			$updated = str_replace(
				array_keys( $url_map ),
				array_values( $url_map ),
				$content
			);

			if ( $updated !== $content ) {
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $updated,
					)
				);
			}
		}
	}

	/**
	 * Map a content type to a WordPress post type.
	 *
	 * Currently returns 'post' for all content types. Will be extended
	 * when custom post type mapping is implemented.
	 *
	 * @param NormalizedItem $item The normalized item.
	 * @return string The WordPress post type.
	 */
	private function map_post_type( NormalizedItem $item ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Reserved for future content type mapping.
		return 'post';
	}
}
