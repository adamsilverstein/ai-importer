<?php
/**
 * Twitter/X content normalizer.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;

/**
 * Normalizes raw Twitter/X archive data from TwitterAdapter::fetch_item()
 * into the universal NormalizedItem format.
 *
 * Expected raw item shape from TwitterAdapter:
 *   id, type, content, title, created_at, media_urls, media_paths,
 *   metadata, parent_id, original_url, raw
 */
class TwitterNormalizer extends ContentNormalizer {

	/**
	 * Get the adapter ID this normalizer handles.
	 *
	 * @return string
	 */
	public function get_adapter_id(): string {
		return 'twitter';
	}

	/**
	 * Normalize a raw Twitter item into a NormalizedItem.
	 *
	 * @param array<string, mixed> $raw_item Raw item from TwitterAdapter::fetch_item().
	 * @return NormalizedItem
	 */
	public function normalize( array $raw_item ): NormalizedItem {
		$content = $raw_item['content'] ?? '';

		// Convert plain text tweets to HTML paragraphs.
		if ( wp_strip_all_tags( $content ) === $content ) {
			$content = $this->text_to_html( $content );
		} else {
			$content = $this->clean_content( $content );
		}

		// Build media references with local paths when available.
		$media       = array();
		$media_urls  = $raw_item['media_urls'] ?? array();
		$media_paths = $raw_item['media_paths'] ?? array();

		foreach ( $media_urls as $i => $url ) {
			$ref = MediaReference::from_url( $url );

			if ( isset( $media_paths[ $i ] ) && ! empty( $media_paths[ $i ] ) ) {
				$ref->local_path = $media_paths[ $i ];
			}

			$media[] = $ref;
		}

		// Extract engagement metrics from metadata.
		$metadata   = $raw_item['metadata'] ?? array();
		$engagement = $this->extract_engagement( $metadata );

		// Extract hashtags from the raw content text.
		$raw_text = $raw_item['content'] ?? '';
		$tags     = array();

		if ( ! empty( $metadata['hashtags'] ) && is_array( $metadata['hashtags'] ) ) {
			$tags = $metadata['hashtags'];
		} else {
			$tags = $this->extract_hashtags( $raw_text );
		}

		// Determine content type from the adapter's classification.
		$content_type = $this->resolve_content_type( $raw_item['type'] ?? 'post' );

		// Extract author info.
		$author = $this->extract_author( $raw_item );

		return new NormalizedItem(
			source_id: $raw_item['id'],
			source_adapter: 'twitter',
			content_type: $content_type,
			content: $content,
			publish_date: $this->convert_date( $raw_item['created_at'] ?? '' ),
			title: $raw_item['title'] ?? null,
			source_url: $raw_item['original_url'] ?? null,
			media: $media,
			metadata: $metadata,
			engagement: $engagement,
			author_name: $author['name'],
			author_url: $author['url'],
			parent_id: $raw_item['parent_id'] ?? null,
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
