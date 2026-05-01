<?php
/**
 * Instagram content normalizer.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;

/**
 * Normalizes raw Instagram archive data from InstagramAdapter::fetch_item()
 * into the universal NormalizedItem format.
 *
 * Expected raw item shape from InstagramAdapter:
 *   id, type, content (caption), title, created_at, media_urls, media_paths,
 *   metadata, original_url, tags, raw
 */
class InstagramNormalizer extends ContentNormalizer {

	/**
	 * Get the adapter ID this normalizer handles.
	 *
	 * @return string
	 */
	public function get_adapter_id(): string {
		return 'instagram';
	}

	/**
	 * Normalize a raw Instagram item into a NormalizedItem.
	 *
	 * @param array<string, mixed> $raw_item Raw item from InstagramAdapter::fetch_item().
	 * @return NormalizedItem
	 */
	public function normalize( array $raw_item ): NormalizedItem {
		$caption = (string) ( $raw_item['content'] ?? '' );
		$content = '' !== $caption ? $this->text_to_html( $caption ) : '';

		$media       = array();
		$media_urls  = $raw_item['media_urls'] ?? array();
		$media_paths = $raw_item['media_paths'] ?? array();

		if ( is_array( $media_urls ) ) {
			foreach ( $media_urls as $idx => $url ) {
				$ref = MediaReference::from_url( (string) $url );

				if ( is_array( $media_paths ) && ! empty( $media_paths[ $idx ] ) ) {
					$ref->local_path = (string) $media_paths[ $idx ];
				}

				$media[] = $ref;
			}
		}

		$metadata     = is_array( $raw_item['metadata'] ?? null ) ? $raw_item['metadata'] : array();
		$tags         = is_array( $raw_item['tags'] ?? null ) ? $raw_item['tags'] : array();
		$content_type = $this->resolve_content_type( $raw_item['type'] ?? 'post' );

		$created_at   = (string) ( $raw_item['created_at'] ?? '' );
		$publish_date = '' !== $created_at
			? $this->convert_date( $created_at )
			: new \DateTimeImmutable();

		return new NormalizedItem(
			source_id: (string) $raw_item['id'],
			source_adapter: 'instagram',
			content_type: $content_type,
			content: $content,
			publish_date: $publish_date,
			title: $raw_item['title'] ?? null,
			source_url: $raw_item['original_url'] ?? null,
			media: $media,
			metadata: $metadata,
			engagement: array(),
			author_name: null,
			author_url: null,
			parent_id: null,
			tags: $tags,
		);
	}

	/**
	 * Resolve a content type string to the ContentType enum.
	 *
	 * @param string $type Content type string from the adapter.
	 * @return ContentType
	 */
	private function resolve_content_type( string $type ): ContentType {
		return ContentType::tryFrom( $type ) ?? ContentType::POST;
	}
}
