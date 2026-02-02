<?php
/**
 * Twitter content normalizer.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use DateTimeImmutable;

/**
 * Normalizer for Twitter/X content.
 *
 * Transforms raw Twitter archive data into the universal NormalizedItem format.
 */
class TwitterNormalizer extends ContentNormalizer {

	/**
	 * Get the adapter ID this normalizer handles.
	 *
	 * @return string Adapter ID.
	 */
	public function get_adapter_id(): string {
		return 'twitter';
	}

	/**
	 * Normalize a raw content item from Twitter.
	 *
	 * @param array<string, mixed> $raw_item Raw item data from adapter.
	 * @return NormalizedItem The normalized item.
	 */
	public function normalize( array $raw_item ): NormalizedItem {
		$source_id    = (string) ( $raw_item['id'] ?? '' );
		$text         = $raw_item['full_text'] ?? $raw_item['text'] ?? '';
		$publish_date = $this->parse_publish_date( $raw_item );
		$content_type = $this->determine_content_type( $raw_item );

		// Convert tweet text to HTML.
		$content = $this->convert_tweet_to_html( $text, $raw_item );

		// Extract media references.
		$media = $this->extract_twitter_media( $raw_item );

		// Extract engagement metrics.
		$engagement = $this->extract_twitter_engagement( $raw_item );

		// Extract author information.
		$author = $this->extract_author( $raw_item );

		// Extract hashtags as tags.
		$tags = $raw_item['hashtags'] ?? $this->extract_hashtags_from_entities( $raw_item );

		// Build source URL.
		$source_url = $raw_item['source_url'] ?? null;

		// Get parent ID for replies.
		$parent_id = $raw_item['in_reply_to_status'] ?? null;

		// Build metadata.
		$metadata = array(
			'source'        => $this->strip_html_tags( $raw_item['source'] ?? '' ),
			'lang'          => $raw_item['lang'] ?? '',
			'is_retweet'    => $raw_item['is_retweet'] ?? false,
			'is_reply'      => $raw_item['is_reply'] ?? false,
			'is_self_reply' => $raw_item['is_self_reply'] ?? false,
			'mentions'      => $raw_item['mentions'] ?? array(),
			'urls'          => $raw_item['urls'] ?? array(),
		);

		return new NormalizedItem(
			source_id: $source_id,
			source_adapter: $this->get_adapter_id(),
			content_type: $content_type,
			content: $content,
			publish_date: $publish_date,
			title: null, // Twitter posts typically don't have titles.
			source_url: $source_url,
			media: $media,
			metadata: $metadata,
			engagement: $engagement,
			author_name: $author['name'],
			author_url: $author['url'],
			parent_id: $parent_id,
			tags: $tags
		);
	}

	/**
	 * Determine content type from raw Twitter item data.
	 *
	 * @param array<string, mixed> $raw_item Raw item data.
	 * @return ContentType The content type.
	 */
	protected function determine_content_type( array $raw_item ): ContentType {
		if ( ! empty( $raw_item['is_retweet'] ) ) {
			return ContentType::REPOST;
		}

		if ( ! empty( $raw_item['is_self_reply'] ) ) {
			return ContentType::THREAD;
		}

		if ( ! empty( $raw_item['is_reply'] ) ) {
			return ContentType::REPLY;
		}

		return ContentType::POST;
	}

	/**
	 * Parse the publish date from raw item data.
	 *
	 * @param array<string, mixed> $raw_item Raw item data.
	 * @return DateTimeImmutable The publish date.
	 */
	private function parse_publish_date( array $raw_item ): DateTimeImmutable {
		if ( isset( $raw_item['created_at'] ) ) {
			// Check if already ISO format.
			if ( str_contains( $raw_item['created_at'], 'T' ) ) {
				return new DateTimeImmutable( $raw_item['created_at'] );
			}

			// Twitter format: "Mon Jan 15 10:15:00 +0000 2024".
			$date = DateTimeImmutable::createFromFormat( 'D M d H:i:s O Y', $raw_item['created_at'] );
			if ( false !== $date ) {
				return $date;
			}

			// Try to parse as generic string.
			return $this->convert_date( $raw_item['created_at'] );
		}

		return new DateTimeImmutable();
	}

	/**
	 * Convert tweet text to HTML with proper formatting.
	 *
	 * @param string               $text     Tweet text.
	 * @param array<string, mixed> $raw_item Raw item data with entities.
	 * @return string HTML content.
	 */
	private function convert_tweet_to_html( string $text, array $raw_item ): string {
		// Start with the original text.
		$html = $text;

		// Convert URLs to links.
		$html = $this->linkify_urls( $html, $raw_item );

		// Convert @mentions to links.
		$html = $this->linkify_mentions( $html );

		// Convert #hashtags to links.
		$html = $this->linkify_hashtags( $html );

		// Convert line breaks to HTML.
		$html = $this->text_to_html( $html );

		// Clean up the HTML.
		return $this->clean_content( $html );
	}

	/**
	 * Convert URLs in text to HTML links.
	 *
	 * @param string               $text     Text content.
	 * @param array<string, mixed> $raw_item Raw item data with URL entities.
	 * @return string Text with linked URLs.
	 */
	private function linkify_urls( string $text, array $raw_item ): string {
		$urls = $raw_item['urls'] ?? array();

		// Replace t.co URLs with their expanded versions.
		foreach ( $urls as $url_data ) {
			$short_url    = $url_data['url'] ?? '';
			$expanded_url = $url_data['expanded_url'] ?? $short_url;
			$display_url  = $url_data['display_url'] ?? $expanded_url;

			if ( empty( $short_url ) ) {
				continue;
			}

			// Create link with display text.
			$link = sprintf(
				'<a href="%s" rel="nofollow">%s</a>',
				esc_url( $expanded_url ),
				esc_html( $display_url )
			);

			$text = str_replace( $short_url, $link, $text );
		}

		// Also linkify any remaining bare URLs.
		$text = preg_replace(
			'/(?<!href="|">)(https?:\/\/[^\s<]+)/i',
			'<a href="$1" rel="nofollow">$1</a>',
			$text
		);

		return $text;
	}

	/**
	 * Convert @mentions in text to Twitter profile links.
	 *
	 * @param string $text Text content.
	 * @return string Text with linked mentions.
	 */
	private function linkify_mentions( string $text ): string {
		return preg_replace(
			'/@(\w+)/',
			'<a href="https://twitter.com/$1" rel="nofollow">@$1</a>',
			$text
		);
	}

	/**
	 * Convert #hashtags in text to Twitter search links.
	 *
	 * @param string $text Text content.
	 * @return string Text with linked hashtags.
	 */
	private function linkify_hashtags( string $text ): string {
		return preg_replace(
			'/#(\w+)/u',
			'<a href="https://twitter.com/hashtag/$1" rel="nofollow">#$1</a>',
			$text
		);
	}

	/**
	 * Extract media references from Twitter data.
	 *
	 * @param array<string, mixed> $raw_item Raw item data.
	 * @return array<MediaReference> Media references.
	 */
	private function extract_twitter_media( array $raw_item ): array {
		$media = array();

		// Get media from extended_entities (higher quality) or entities.
		$media_entities = $raw_item['extended_entities']['media']
			?? $raw_item['entities']['media']
			?? array();

		// Also use the extracted media_urls from the adapter.
		$media_urls = $raw_item['media_urls'] ?? array();

		// Create media references from entities (has more metadata).
		foreach ( $media_entities as $entity ) {
			$url = $entity['media_url_https'] ?? $entity['media_url'] ?? '';
			if ( empty( $url ) ) {
				continue;
			}

			$type = $this->determine_media_type( $entity );

			$media_ref = new MediaReference(
				id: md5( $url ),
				source_url: $url,
				type: $type,
				alt_text: $entity['ext_alt_text'] ?? null,
				width: isset( $entity['sizes']['large']['w'] ) ? (int) $entity['sizes']['large']['w'] : null,
				height: isset( $entity['sizes']['large']['h'] ) ? (int) $entity['sizes']['large']['h'] : null,
				metadata: array(
					'media_id'    => $entity['id_str'] ?? null,
					'media_type'  => $entity['type'] ?? null,
					'display_url' => $entity['display_url'] ?? null,
				)
			);

			$media[] = $media_ref;

			// If this is a video, also add the video file.
			if ( isset( $entity['video_info']['variants'] ) ) {
				$video_url = $this->get_best_video_url( $entity['video_info']['variants'] );
				if ( null !== $video_url && $video_url !== $url ) {
					$media[] = new MediaReference(
						id: md5( $video_url ),
						source_url: $video_url,
						type: MediaReference::TYPE_VIDEO,
						metadata: array(
							'parent_media_id' => $entity['id_str'] ?? null,
						)
					);
				}
			}
		}

		// Add any additional media URLs not in entities.
		$existing_urls = array_map( fn( $ref ) => $ref->source_url, $media );
		foreach ( $media_urls as $url ) {
			if ( ! in_array( $url, $existing_urls, true ) ) {
				$media[] = MediaReference::from_url( $url );
			}
		}

		return $media;
	}

	/**
	 * Determine media type from Twitter entity.
	 *
	 * @param array<string, mixed> $entity Media entity.
	 * @return string Media type constant.
	 */
	private function determine_media_type( array $entity ): string {
		$type = $entity['type'] ?? 'photo';

		return match ( $type ) {
			'video', 'animated_gif' => MediaReference::TYPE_VIDEO,
			default                 => MediaReference::TYPE_IMAGE,
		};
	}

	/**
	 * Get the best quality video URL from variants.
	 *
	 * @param array<array<string, mixed>> $variants Video variants.
	 * @return string|null Best video URL or null.
	 */
	private function get_best_video_url( array $variants ): ?string {
		$best_url     = null;
		$best_bitrate = 0;

		foreach ( $variants as $variant ) {
			$content_type = $variant['content_type'] ?? '';
			if ( 'video/mp4' !== $content_type ) {
				continue;
			}

			$bitrate = $variant['bitrate'] ?? 0;
			if ( $bitrate > $best_bitrate ) {
				$best_bitrate = $bitrate;
				$best_url     = $variant['url'] ?? null;
			}
		}

		return $best_url;
	}

	/**
	 * Extract engagement metrics from Twitter data.
	 *
	 * @param array<string, mixed> $raw_item Raw item data.
	 * @return array<string, int> Engagement metrics.
	 */
	private function extract_twitter_engagement( array $raw_item ): array {
		return array(
			'likes'    => (int) ( $raw_item['favorite_count'] ?? 0 ),
			'shares'   => (int) ( $raw_item['retweet_count'] ?? 0 ),
			'comments' => 0, // Not available in archive.
		);
	}

	/**
	 * Extract hashtags from entities.
	 *
	 * @param array<string, mixed> $raw_item Raw item data.
	 * @return array<string> Hashtags.
	 */
	private function extract_hashtags_from_entities( array $raw_item ): array {
		$hashtags = array();
		$entities = $raw_item['entities']['hashtags'] ?? array();

		foreach ( $entities as $hashtag ) {
			if ( isset( $hashtag['text'] ) ) {
				$hashtags[] = $hashtag['text'];
			}
		}

		return $hashtags;
	}

	/**
	 * Strip HTML tags from a string (for cleaning source field).
	 *
	 * @param string $html HTML string.
	 * @return string Plain text.
	 */
	private function strip_html_tags( string $html ): string {
		// Twitter's source field contains HTML like <a href="...">Twitter Web App</a>.
		return wp_strip_all_tags( $html );
	}
}
