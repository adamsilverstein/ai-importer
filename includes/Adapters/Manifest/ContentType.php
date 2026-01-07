<?php
/**
 * Content type enum.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Adapters\Manifest;

/**
 * Enum representing different types of content that can be imported.
 */
enum ContentType: string {
	/**
	 * A standard post (tweet, status update, etc.).
	 */
	case POST = 'post';

	/**
	 * A thread of connected posts.
	 */
	case THREAD = 'thread';

	/**
	 * A reply to another post.
	 */
	case REPLY = 'reply';

	/**
	 * A repost/retweet of another post.
	 */
	case REPOST = 'repost';

	/**
	 * Standalone media content.
	 */
	case MEDIA = 'media';

	/**
	 * A long-form article.
	 */
	case ARTICLE = 'article';

	/**
	 * Video content.
	 */
	case VIDEO = 'video';

	/**
	 * Story content (ephemeral).
	 */
	case STORY = 'story';

	/**
	 * Get a human-readable label for the content type.
	 *
	 * @return string The label.
	 */
	public function get_label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid in PHP 8.1+ enums.
		return match ( $this ) {
			self::POST    => __( 'Post', 'ai-importer' ),
			self::THREAD  => __( 'Thread', 'ai-importer' ),
			self::REPLY   => __( 'Reply', 'ai-importer' ),
			self::REPOST  => __( 'Repost', 'ai-importer' ),
			self::MEDIA   => __( 'Media', 'ai-importer' ),
			self::ARTICLE => __( 'Article', 'ai-importer' ),
			self::VIDEO   => __( 'Video', 'ai-importer' ),
			self::STORY   => __( 'Story', 'ai-importer' ),
		};
	}

	/**
	 * Check if this content type represents primary content (not a derivative).
	 *
	 * @return bool True if primary content.
	 */
	public function is_primary(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid in PHP 8.1+ enums.
		return match ( $this ) {
			self::POST, self::THREAD, self::ARTICLE, self::VIDEO, self::MEDIA => true,
			self::REPLY, self::REPOST, self::STORY => false,
		};
	}
}
