<?php
/**
 * Content normalizer abstract class.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use DateTimeImmutable;

/**
 * Abstract base class for content normalizers.
 *
 * Provides common functionality for transforming platform-specific
 * content into the universal NormalizedItem format.
 */
abstract class ContentNormalizer {

	/**
	 * HTML sanitizer instance.
	 *
	 * @var HtmlSanitizer
	 */
	protected HtmlSanitizer $sanitizer;

	/**
	 * Date converter instance.
	 *
	 * @var DateConverter
	 */
	protected DateConverter $date_converter;

	/**
	 * Constructor.
	 *
	 * @param HtmlSanitizer|null $sanitizer      HTML sanitizer instance.
	 * @param DateConverter|null $date_converter Date converter instance.
	 */
	public function __construct( ?HtmlSanitizer $sanitizer = null, ?DateConverter $date_converter = null ) {
		$this->sanitizer      = $sanitizer ?? new HtmlSanitizer();
		$this->date_converter = $date_converter ?? new DateConverter();
	}

	/**
	 * Get the adapter ID this normalizer handles.
	 *
	 * @return string Adapter ID.
	 */
	abstract public function get_adapter_id(): string;

	/**
	 * Check if this normalizer supports the given adapter.
	 *
	 * @param string $adapter_id Adapter ID to check.
	 * @return bool True if supported.
	 */
	public function supports( string $adapter_id ): bool {
		return $this->get_adapter_id() === $adapter_id;
	}

	/**
	 * Normalize a raw content item.
	 *
	 * @param array<string, mixed> $raw_item Raw item data from adapter.
	 * @return NormalizedItem The normalized item.
	 */
	abstract public function normalize( array $raw_item ): NormalizedItem;

	/**
	 * Clean HTML content.
	 *
	 * @param string $html Raw HTML content.
	 * @return string Sanitized HTML.
	 */
	protected function clean_content( string $html ): string {
		return $this->sanitizer->sanitize( $html );
	}

	/**
	 * Convert a date string to DateTimeImmutable.
	 *
	 * @param string      $date_string Date string.
	 * @param string|null $format      Date format hint.
	 * @return DateTimeImmutable The converted date.
	 */
	protected function convert_date( string $date_string, ?string $format = null ): DateTimeImmutable {
		return $this->date_converter->convert( $date_string, $format );
	}

	/**
	 * Extract media references from HTML content.
	 *
	 * @param string $html HTML content.
	 * @return array<MediaReference> Media references.
	 */
	protected function extract_media_from_html( string $html ): array {
		$references = array();
		$urls       = $this->sanitizer->extract_urls( $html );

		foreach ( $urls as $url ) {
			// Only create references for likely media URLs.
			if ( $this->is_media_url( $url ) ) {
				$references[] = MediaReference::from_url( $url );
			}
		}

		return $references;
	}

	/**
	 * Extract media references from an array of URLs.
	 *
	 * @param array<string> $urls Media URLs.
	 * @return array<MediaReference> Media references.
	 */
	protected function extract_media_from_urls( array $urls ): array {
		$references = array();

		foreach ( $urls as $url ) {
			if ( ! empty( $url ) ) {
				$references[] = MediaReference::from_url( $url );
			}
		}

		return $references;
	}

	/**
	 * Check if a URL is likely a media file.
	 *
	 * @param string $url URL to check.
	 * @return bool True if URL appears to be media.
	 */
	protected function is_media_url( string $url ): bool {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( false === $path || null === $path ) {
			// Check for common media hosting patterns even without a path.
			$media_hosts = array(
				'pbs.twimg.com',
				'video.twimg.com',
				'instagram.com/p/',
				'cdninstagram.com',
				'imgur.com',
				'i.imgur.com',
				'giphy.com',
				'media.tumblr.com',
			);

			foreach ( $media_hosts as $host ) {
				if ( strpos( $url, $host ) !== false ) {
					return true;
				}
			}

			return false;
		}

		$extension_info = pathinfo( $path, PATHINFO_EXTENSION );
		if ( ! is_string( $extension_info ) || empty( $extension_info ) ) {
			// Check for common media hosting patterns.
			$media_hosts = array(
				'pbs.twimg.com',
				'video.twimg.com',
				'instagram.com/p/',
				'cdninstagram.com',
				'imgur.com',
				'i.imgur.com',
				'giphy.com',
				'media.tumblr.com',
			);

			foreach ( $media_hosts as $host ) {
				if ( strpos( $url, $host ) !== false ) {
					return true;
				}
			}

			return false;
		}

		$extension = strtolower( $extension_info );

		$media_extensions = array(
			'jpg',
			'jpeg',
			'png',
			'gif',
			'webp',
			'svg',
			'ico',
			'bmp',
			'mp4',
			'webm',
			'mov',
			'avi',
			'wmv',
			'flv',
			'mkv',
			'mp3',
			'wav',
			'ogg',
			'flac',
			'm4a',
			'aac',
		);

		if ( in_array( $extension, $media_extensions, true ) ) {
			return true;
		}

		// Check for common media hosting patterns.
		$media_hosts = array(
			'pbs.twimg.com',
			'video.twimg.com',
			'instagram.com/p/',
			'cdninstagram.com',
			'imgur.com',
			'i.imgur.com',
			'giphy.com',
			'media.tumblr.com',
		);

		foreach ( $media_hosts as $host ) {
			if ( strpos( $url, $host ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract hashtags from text content.
	 *
	 * @param string $text Text content.
	 * @return array<string> Hashtags (without # prefix).
	 */
	protected function extract_hashtags( string $text ): array {
		$hashtags = array();

		if ( preg_match_all( '/#(\w+)/u', $text, $matches ) ) {
			$hashtags = array_unique( $matches[1] );
		}

		return array_values( $hashtags );
	}

	/**
	 * Extract @mentions from text content.
	 *
	 * @param string $text Text content.
	 * @return array<string> Mentions (without @ prefix).
	 */
	protected function extract_mentions( string $text ): array {
		$mentions = array();

		if ( preg_match_all( '/@(\w+)/u', $text, $matches ) ) {
			$mentions = array_unique( $matches[1] );
		}

		return array_values( $mentions );
	}

	/**
	 * Determine content type from raw item data.
	 *
	 * Override in subclasses for platform-specific logic.
	 *
	 * @param array<string, mixed> $raw_item Raw item data.
	 * @return ContentType The content type.
	 */
	protected function determine_content_type( array $raw_item ): ContentType {
		// Default implementation - can be overridden.
		if ( isset( $raw_item['parent_id'] ) || isset( $raw_item['in_reply_to'] ) ) {
			return ContentType::REPLY;
		}

		if ( isset( $raw_item['retweeted_status'] ) || isset( $raw_item['is_repost'] ) ) {
			return ContentType::REPOST;
		}

		if ( isset( $raw_item['is_thread'] ) && $raw_item['is_thread'] ) {
			return ContentType::THREAD;
		}

		return ContentType::POST;
	}

	/**
	 * Extract engagement metrics from raw item data.
	 *
	 * Override in subclasses for platform-specific metrics.
	 *
	 * @param array<string, mixed> $raw_item Raw item data.
	 * @return array<string, int> Engagement metrics.
	 */
	protected function extract_engagement( array $raw_item ): array {
		$engagement = array();

		// Common metric names to look for.
		$metric_keys = array(
			'likes'     => array( 'likes', 'like_count', 'favorite_count', 'favourites_count' ),
			'shares'    => array( 'shares', 'share_count', 'retweet_count', 'reblog_count' ),
			'comments'  => array( 'comments', 'comment_count', 'reply_count' ),
			'views'     => array( 'views', 'view_count', 'impression_count' ),
			'bookmarks' => array( 'bookmarks', 'bookmark_count', 'saves', 'save_count' ),
		);

		foreach ( $metric_keys as $normalized_key => $raw_keys ) {
			foreach ( $raw_keys as $raw_key ) {
				if ( isset( $raw_item[ $raw_key ] ) && is_numeric( $raw_item[ $raw_key ] ) ) {
					$engagement[ $normalized_key ] = (int) $raw_item[ $raw_key ];
					break;
				}
			}
		}

		return $engagement;
	}

	/**
	 * Build source URL from raw item data.
	 *
	 * Override in subclasses for platform-specific URL patterns.
	 *
	 * @param array<string, mixed> $raw_item Raw item data.
	 * @return string|null Source URL or null.
	 */
	protected function build_source_url( array $raw_item ): ?string {
		// Common URL field names.
		$url_keys = array( 'url', 'source_url', 'link', 'permalink', 'original_url' );

		foreach ( $url_keys as $key ) {
			if ( ! empty( $raw_item[ $key ] ) ) {
				return $raw_item[ $key ];
			}
		}

		return null;
	}

	/**
	 * Extract author information from raw item data.
	 *
	 * @param array<string, mixed> $raw_item Raw item data.
	 * @return array{name: string|null, url: string|null} Author info.
	 */
	protected function extract_author( array $raw_item ): array {
		$name = null;
		$url  = null;

		// Try different common structures.
		if ( isset( $raw_item['author'] ) && is_array( $raw_item['author'] ) ) {
			$name = $raw_item['author']['name'] ?? $raw_item['author']['display_name'] ?? null;
			$url  = $raw_item['author']['url'] ?? $raw_item['author']['profile_url'] ?? null;
		} elseif ( isset( $raw_item['user'] ) && is_array( $raw_item['user'] ) ) {
			$name = $raw_item['user']['name'] ?? $raw_item['user']['screen_name'] ?? null;
			$url  = $raw_item['user']['url'] ?? null;
		} else {
			$name = $raw_item['author_name'] ?? $raw_item['username'] ?? null;
			$url  = $raw_item['author_url'] ?? null;
		}

		return array(
			'name' => $name,
			'url'  => $url,
		);
	}

	/**
	 * Convert plain text to HTML with paragraphs.
	 *
	 * @param string $text Plain text content.
	 * @return string HTML content.
	 */
	protected function text_to_html( string $text ): string {
		return $this->sanitizer->convert_line_breaks( $text );
	}

	/**
	 * Get the sanitizer instance.
	 *
	 * @return HtmlSanitizer The sanitizer.
	 */
	public function get_sanitizer(): HtmlSanitizer {
		return $this->sanitizer;
	}

	/**
	 * Get the date converter instance.
	 *
	 * @return DateConverter The date converter.
	 */
	public function get_date_converter(): DateConverter {
		return $this->date_converter;
	}
}
