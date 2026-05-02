<?php
/**
 * Tumblr export adapter.
 *
 * Parses a Tumblr backup ZIP and extracts posts.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Adapters;

use AI_Importer\Adapters\Manifest\ContentManifest;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\Manifest\ManifestItem;
use AI_Importer\Schema\SettingsSchema;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use WP_Error;

/**
 * Adapter for importing content from a Tumblr backup ZIP.
 *
 * Tumblr exports (Settings → Export → Export your blog) ship each post
 * as `posts/{id}.html` plus a `media/` directory of attachments. The
 * HTML uses a small set of conventions we parse defensively:
 *
 *   - `<meta name="post-type">` or a wrapper class like `post type-photo`
 *     or `<article data-post-type="photo">` carries the post kind.
 *   - Tags appear as `<a class="tag">` or `<meta name="tags">`.
 *   - The canonical post URL is on `<link rel="canonical">` or
 *     `<a class="source-url">`.
 *   - Reblogs link the upstream post via `<a class="reblog-source">` /
 *     `<a class="reblog-from">`.
 *
 * Tumblr post types map onto the existing ContentType enum: text →
 * POST/ARTICLE (by length), photo/audio → MEDIA, video → VIDEO, and
 * quote/chat/link/answer → POST. Reblogs override the type to REPOST.
 * The original Tumblr type is preserved on the manifest item under
 * `metadata.post_type` for downstream consumers.
 */
class TumblrAdapter extends AbstractAdapter {

	/**
	 * Tumblr post types we surface.
	 */
	private const TYPE_TEXT   = 'text';
	private const TYPE_PHOTO  = 'photo';
	private const TYPE_QUOTE  = 'quote';
	private const TYPE_LINK   = 'link';
	private const TYPE_CHAT   = 'chat';
	private const TYPE_AUDIO  = 'audio';
	private const TYPE_VIDEO  = 'video';
	private const TYPE_ANSWER = 'answer';

	/**
	 * Known Tumblr post type tokens. Anything else falls back to text.
	 *
	 * @var array<string>
	 */
	private const KNOWN_TYPES = array(
		self::TYPE_TEXT,
		self::TYPE_PHOTO,
		self::TYPE_QUOTE,
		self::TYPE_LINK,
		self::TYPE_CHAT,
		self::TYPE_AUDIO,
		self::TYPE_VIDEO,
		self::TYPE_ANSWER,
	);

	/**
	 * Parsed posts keyed by ID.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $posts = null;

	/**
	 * Get the unique identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'tumblr';
	}

	/**
	 * Get the human-readable name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Tumblr';
	}

	/**
	 * Get a description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Import posts from your Tumblr backup ZIP.', 'ai-importer' );
	}

	/**
	 * Get the icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'dashicons-format-status';
	}

	/**
	 * Get the authentication type.
	 *
	 * @return string
	 */
	public function get_auth_type(): string {
		return self::AUTH_TYPE_FILE_UPLOAD;
	}

	/**
	 * Authenticate by validating an uploaded Tumblr export ZIP.
	 *
	 * @param array<string, mixed> $credentials Must contain 'file' path or 'attachment_id'.
	 * @return bool True on success.
	 */
	public function authenticate( array $credentials ): bool {
		$file_path = $credentials['file'] ?? '';

		if ( ! empty( $credentials['attachment_id'] ) ) {
			$file_path = get_attached_file( (int) $credentials['attachment_id'] );
		}

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			$this->log_error( 'Tumblr export file not found.', $credentials );
			return false;
		}

		$validation = $this->validate_archive( $file_path );

		if ( is_wp_error( $validation ) ) {
			$messages = $validation->get_error_messages();
			$this->log_error( ! empty( $messages ) ? $messages[0] : 'Tumblr export validation failed.' );
			return false;
		}

		$this->store_credentials(
			array(
				'file_path'    => $file_path,
				'connected_at' => gmdate( 'c' ),
			)
		);

		$this->delete_cache( 'manifest' );
		$this->posts = null;

		return true;
	}

	/**
	 * Fetch the content manifest from the export.
	 *
	 * @return ContentManifest
	 * @throws RuntimeException If not authenticated or parsing fails.
	 */
	public function fetch_manifest(): ContentManifest {
		$this->ensure_authenticated();

		$cached = $this->get_cache( 'manifest' );

		if ( $cached instanceof ContentManifest ) {
			return $cached;
		}

		$posts    = $this->get_posts();
		$manifest = new ContentManifest( $this->get_id() );

		foreach ( $posts as $id => $post ) {
			$item = new ManifestItem(
				id: $id,
				type: $this->classify_post( $post ),
				title: $post['title'],
				created_at: $post['published_at'],
				excerpt: $this->build_excerpt( $post['content'] ),
				media_urls: $post['media_urls'],
				metadata: array(
					'post_type'    => $post['post_type'],
					'tags'         => $post['tags'],
					'is_reblog'    => $post['is_reblog'],
					'reblog_from'  => $post['reblog_from'],
					'source_title' => $post['source_title'],
				),
				original_url: $post['canonical_url'],
				author: $this->build_author( $post ),
			);

			$manifest->add_item( $item );
		}

		$this->set_cache( 'manifest', $manifest, 86400 );

		return $manifest;
	}

	/**
	 * Fetch a single post by ID with full content.
	 *
	 * @param string $item_id Post ID.
	 * @return array<string, mixed>
	 * @throws RuntimeException If not authenticated or post not found.
	 */
	public function fetch_item( string $item_id ): array {
		$this->ensure_authenticated();

		$posts = $this->get_posts();

		if ( ! isset( $posts[ $item_id ] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				sprintf(
					/* translators: %s: post ID */
					__( 'Tumblr post with ID "%s" not found in export.', 'ai-importer' ),
					$item_id
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$post = $posts[ $item_id ];

		return array(
			'id'           => $item_id,
			'type'         => $this->classify_post( $post )->value,
			'content'      => $post['content'],
			'title'        => $post['title'],
			'created_at'   => $post['published_at']->format( 'c' ),
			'media_urls'   => $post['media_urls'],
			'metadata'     => array(
				'post_type'    => $post['post_type'],
				'tags'         => $post['tags'],
				'is_reblog'    => $post['is_reblog'],
				'reblog_from'  => $post['reblog_from'],
				'source_title' => $post['source_title'],
			),
			'original_url' => $post['canonical_url'],
			'tags'         => $post['tags'],
			'author'       => array(
				'name' => $post['author_name'],
				'url'  => $post['author_url'],
			),
			'raw'          => $post,
		);
	}

	/**
	 * Get supported content types.
	 *
	 * @return array<string>
	 */
	public function get_supported_content_types(): array {
		return array(
			ContentType::POST->value,
			ContentType::ARTICLE->value,
			ContentType::MEDIA->value,
			ContentType::VIDEO->value,
			ContentType::REPOST->value,
		);
	}

	/**
	 * Build the settings schema.
	 *
	 * @return SettingsSchema
	 */
	protected function build_settings_schema(): SettingsSchema {
		$schema = new SettingsSchema();

		$schema->add_field(
			'archive_file',
			array(
				'type'        => 'file',
				'label'       => __( 'Tumblr Backup', 'ai-importer' ),
				'description' => __( 'Upload your Tumblr backup ZIP. Get it from Settings > Export your blog.', 'ai-importer' ),
				'required'    => true,
				'accept'      => '.zip',
			)
		);

		return $schema;
	}

	/**
	 * Validate that a ZIP file is a Tumblr backup.
	 *
	 * Tumblr backups always contain a posts/ directory with at least one
	 * .html file. That's the only structural marker we rely on.
	 *
	 * @param string $file_path Path to the ZIP file.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_archive( string $file_path ): bool|WP_Error {
		$zip = new \ZipArchive();
		$res = $zip->open( $file_path );

		if ( true !== $res ) {
			return new WP_Error(
				'invalid_zip',
				__( 'The uploaded file is not a valid ZIP archive.', 'ai-importer' )
			);
		}

		$found = false;

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
		$num_files = $zip->numFiles;

		for ( $i = 0; $i < $num_files; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );

			if ( preg_match( '#(?:^|/)posts/[^/]+\.html$#', $name ) ) {
				$found = true;
				break;
			}
		}

		$zip->close();

		if ( ! $found ) {
			return new WP_Error(
				'missing_posts',
				__( 'This ZIP does not appear to be a Tumblr backup. Could not find any posts/*.html files.', 'ai-importer' )
			);
		}

		return true;
	}

	/**
	 * Get all parsed posts, loading them if needed.
	 *
	 * @return array<string, array<string, mixed>> Posts keyed by ID.
	 * @throws RuntimeException If archive cannot be read.
	 */
	private function get_posts(): array {
		if ( null !== $this->posts ) {
			return $this->posts;
		}

		$credentials = $this->get_stored_credentials();
		$file_path   = $credentials['file_path'] ?? '';

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Tumblr export file not found. Please re-upload your archive.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->posts = $this->parse_archive( $file_path );

		return $this->posts;
	}

	/**
	 * Parse the Tumblr backup ZIP and extract posts.
	 *
	 * @param string $file_path Path to the ZIP file.
	 * @return array<string, array<string, mixed>> Posts keyed by ID.
	 * @throws RuntimeException If parsing fails.
	 */
	private function parse_archive( string $file_path ): array {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $file_path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Failed to open Tumblr backup ZIP file.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$posts = array();

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
		$num_files = $zip->numFiles;

		for ( $i = 0; $i < $num_files; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );

			if ( ! preg_match( '#(?:^|/)posts/([^/]+)\.html$#', $name, $matches ) ) {
				continue;
			}

			$html = $zip->getFromName( $name );

			if ( false === $html ) {
				$this->log_error( 'Failed to read post file from archive.', array( 'file' => $name ) );
				continue;
			}

			$parsed = $this->parse_post( $html, $matches[1] );

			if ( null === $parsed ) {
				continue;
			}

			$posts[ $parsed['id'] ] = $parsed;
		}

		$zip->close();

		if ( empty( $posts ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'No posts found in the Tumblr backup. The archive may be empty or in an unsupported format.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $posts;
	}

	/**
	 * Parse one Tumblr post HTML file.
	 *
	 * @param string $html     Raw HTML.
	 * @param string $filename The filename within posts/ (without .html).
	 * @return array<string, mixed>|null Parsed post or null on failure.
	 */
	private function parse_post( string $html, string $filename ): ?array {
		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath = new DOMXPath( $dom );

		$post_type     = $this->extract_post_type( $xpath );
		$published_at  = $this->extract_published_at( $xpath );
		$tags          = $this->extract_tags( $xpath );
		$canonical_url = $this->extract_canonical_url( $xpath );
		$author_name   = $this->xpath_text( $xpath, "//*[contains(concat(' ', normalize-space(@class), ' '), ' post-author ')]" );
		$author_url    = $this->xpath_attr( $xpath, "//*[contains(concat(' ', normalize-space(@class), ' '), ' post-author ')]", 'href' );

		$reblog_info  = $this->extract_reblog_info( $xpath );
		$content_html = $this->extract_content_html( $dom, $xpath );
		$title        = $this->extract_title( $xpath, $content_html );
		$media_urls   = $this->extract_media_urls( $xpath, $content_html );

		// Skip empty posts (no content and no media) — these are noise.
		$has_content = '' !== trim( wp_strip_all_tags( $content_html ) );
		if ( '' === trim( $title ) && ! $has_content && empty( $media_urls ) ) {
			return null;
		}

		return array(
			'id'            => $filename,
			'post_type'     => $post_type,
			'title'         => trim( $title ),
			'content'       => $content_html,
			'published_at'  => $published_at,
			'tags'          => $tags,
			'media_urls'    => $media_urls,
			'canonical_url' => '' !== $canonical_url ? $canonical_url : null,
			'is_reblog'     => $reblog_info['is_reblog'],
			'reblog_from'   => $reblog_info['from'],
			'source_title'  => $reblog_info['source_title'],
			'author_name'   => '' !== trim( $author_name ) ? trim( $author_name ) : null,
			'author_url'    => '' !== trim( $author_url ) ? trim( $author_url ) : null,
		);
	}

	/**
	 * Detect the Tumblr post type from class hooks, data attributes, or meta.
	 *
	 * Falls back to "text" when no marker is found, which is what Tumblr
	 * itself does for un-typed posts.
	 *
	 * @param DOMXPath $xpath The XPath helper.
	 * @return string One of the KNOWN_TYPES.
	 */
	private function extract_post_type( DOMXPath $xpath ): string {
		// Prefer an explicit data-post-type attribute.
		$attr = $this->xpath_attr( $xpath, '//*[@data-post-type]', 'data-post-type' );
		if ( '' !== $attr && in_array( strtolower( $attr ), self::KNOWN_TYPES, true ) ) {
			return strtolower( $attr );
		}

		// <meta name="post-type" content="..."> in the head.
		$meta = $this->xpath_attr( $xpath, "//meta[@name='post-type']", 'content' );
		if ( '' !== $meta && in_array( strtolower( $meta ), self::KNOWN_TYPES, true ) ) {
			return strtolower( $meta );
		}

		// `class="post type-photo"` style markers on a wrapper element.
		$nodes = $xpath->query( "//*[contains(@class, 'type-')]" );
		if ( false !== $nodes ) {
			foreach ( $nodes as $node ) {
				if ( ! $node instanceof DOMElement ) {
					continue;
				}
				$class = $node->getAttribute( 'class' );
				if ( preg_match( '/(?:^|\s)type-([a-z]+)(?:\s|$)/i', $class, $matches ) ) {
					$candidate = strtolower( $matches[1] );
					if ( in_array( $candidate, self::KNOWN_TYPES, true ) ) {
						return $candidate;
					}
				}
			}
		}

		return self::TYPE_TEXT;
	}

	/**
	 * Extract the post's published date.
	 *
	 * Tumblr backups store dates either as ISO strings on `<time datetime>`
	 * or as a `<meta name="date">` tag. Falls back to "now" when neither
	 * is present so the post still imports.
	 *
	 * @param DOMXPath $xpath The XPath helper.
	 * @return DateTimeImmutable
	 */
	private function extract_published_at( DOMXPath $xpath ): DateTimeImmutable {
		$datetime = $this->xpath_attr( $xpath, '//time[@datetime]', 'datetime' );
		if ( '' !== $datetime ) {
			return $this->parse_date( $datetime );
		}

		$meta = $this->xpath_attr( $xpath, "//meta[@name='date']", 'content' );
		if ( '' !== $meta ) {
			return $this->parse_date( $meta );
		}

		$time_text = $this->xpath_text( $xpath, '//time' );
		if ( '' !== $time_text ) {
			return $this->parse_date( $time_text );
		}

		return new DateTimeImmutable();
	}

	/**
	 * Extract tags from the post.
	 *
	 * Tumblr exports surface tags as either `<a class="tag">#foo</a>`
	 * inside the post body or a comma-separated `<meta name="tags">` in
	 * the head. We strip the leading `#` and dedupe.
	 *
	 * @param DOMXPath $xpath The XPath helper.
	 * @return array<string>
	 */
	private function extract_tags( DOMXPath $xpath ): array {
		$tags  = array();
		$nodes = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' tag ')]" );

		if ( false !== $nodes ) {
			foreach ( $nodes as $node ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode API.
				$text = trim( (string) $node->textContent );
				$text = ltrim( $text, '#' );
				if ( '' !== $text ) {
					$tags[] = $text;
				}
			}
		}

		if ( empty( $tags ) ) {
			$meta = $this->xpath_attr( $xpath, "//meta[@name='tags']", 'content' );
			if ( '' !== $meta ) {
				foreach ( explode( ',', $meta ) as $part ) {
					$part = ltrim( trim( $part ), '#' );
					if ( '' !== $part ) {
						$tags[] = $part;
					}
				}
			}
		}

		return array_values( array_unique( $tags ) );
	}

	/**
	 * Extract the canonical post URL.
	 *
	 * @param DOMXPath $xpath The XPath helper.
	 * @return string Canonical URL or empty string when missing.
	 */
	private function extract_canonical_url( DOMXPath $xpath ): string {
		$url = $this->xpath_attr( $xpath, "//link[@rel='canonical']", 'href' );
		if ( '' !== $url ) {
			return $url;
		}

		return $this->xpath_attr(
			$xpath,
			"//a[contains(concat(' ', normalize-space(@class), ' '), ' source-url ')]",
			'href'
		);
	}

	/**
	 * Detect whether the post is a reblog and capture the upstream details.
	 *
	 * @param DOMXPath $xpath The XPath helper.
	 * @return array{is_reblog: bool, from: string|null, source_title: string|null}
	 */
	private function extract_reblog_info( DOMXPath $xpath ): array {
		$href = $this->xpath_attr(
			$xpath,
			"//a[contains(concat(' ', normalize-space(@class), ' '), ' reblog-source ')"
				. " or contains(concat(' ', normalize-space(@class), ' '), ' reblog-from ')]",
			'href'
		);

		$text = $this->xpath_text(
			$xpath,
			"//a[contains(concat(' ', normalize-space(@class), ' '), ' reblog-source ')"
				. " or contains(concat(' ', normalize-space(@class), ' '), ' reblog-from ')]"
		);

		if ( '' === $href && '' === $text ) {
			return array(
				'is_reblog'    => false,
				'from'         => null,
				'source_title' => null,
			);
		}

		return array(
			'is_reblog'    => true,
			'from'         => '' !== $href ? $href : null,
			'source_title' => '' !== $text ? trim( $text ) : null,
		);
	}

	/**
	 * Extract the post content as HTML.
	 *
	 * Looks for an explicit `.post-content` (or `.content` inside a post
	 * wrapper) first, then falls back to `<article>`, then to `<body>`.
	 *
	 * @param DOMDocument $dom   The DOM document.
	 * @param DOMXPath    $xpath The XPath helper.
	 * @return string Inner HTML of the content section, or empty string.
	 */
	private function extract_content_html( DOMDocument $dom, DOMXPath $xpath ): string {
		$queries = array(
			"//*[contains(concat(' ', normalize-space(@class), ' '), ' post-content ')]",
			"//article//*[contains(concat(' ', normalize-space(@class), ' '), ' content ')]",
			'//article',
			'//body',
		);

		foreach ( $queries as $query ) {
			$nodes = $xpath->query( $query );
			if ( false === $nodes || 0 === $nodes->length ) {
				continue;
			}

			$node = $nodes->item( 0 );
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$html = $this->inner_html( $dom, $node );
			if ( '' !== trim( $html ) ) {
				return trim( $html );
			}
		}

		return '';
	}

	/**
	 * Render the inner HTML of a DOM element.
	 *
	 * @param DOMDocument $dom  The DOM document.
	 * @param DOMElement  $node The element whose children to render.
	 * @return string
	 */
	private function inner_html( DOMDocument $dom, DOMElement $node ): string {
		$html = '';
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMElement API.
		foreach ( $node->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Extract a sensible title for the post.
	 *
	 * Prefers `<meta name="post-title">`, then the document `<title>`,
	 * then the first `<h1>`/`<h2>`/`<h3>` in the content. Falls back to
	 * an empty string — text/photo posts often have no title.
	 *
	 * @param DOMXPath $xpath        The XPath helper.
	 * @param string   $content_html The extracted content HTML.
	 * @return string
	 */
	private function extract_title( DOMXPath $xpath, string $content_html ): string {
		$meta = $this->xpath_attr( $xpath, "//meta[@name='post-title']", 'content' );
		if ( '' !== trim( $meta ) ) {
			return $meta;
		}

		$doc_title = $this->xpath_text( $xpath, '//head/title' );
		if ( '' !== trim( $doc_title ) ) {
			return $doc_title;
		}

		foreach ( array( '//h1', '//h2', '//h3' ) as $query ) {
			$heading = $this->xpath_text( $xpath, $query );
			if ( '' !== trim( $heading ) ) {
				return $heading;
			}
		}

		// Last resort: first non-empty line of plain text content.
		$plain = trim( wp_strip_all_tags( $content_html ) );
		if ( '' === $plain ) {
			return '';
		}

		$first_line = strtok( $plain, "\n" );
		if ( ! is_string( $first_line ) || '' === trim( $first_line ) ) {
			return '';
		}

		return mb_strlen( $first_line ) <= 80
			? $first_line
			: mb_substr( $first_line, 0, 77 ) . '...';
	}

	/**
	 * Pull media URLs from `<img src>`, `<video src>`, and `<audio src>` inside the post.
	 *
	 * Falls back to scanning the content HTML when DOM queries miss
	 * (e.g. when media is referenced as a relative path).
	 *
	 * @param DOMXPath $xpath        The XPath helper.
	 * @param string   $content_html The content HTML for fallback regex scan.
	 * @return array<string>
	 */
	private function extract_media_urls( DOMXPath $xpath, string $content_html ): array {
		$urls = array();

		foreach ( array( '//img/@src', '//video/@src', '//audio/@src', '//source/@src' ) as $query ) {
			$nodes = $xpath->query( $query );
			if ( false === $nodes ) {
				continue;
			}
			foreach ( $nodes as $attr ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMAttr API.
				$src = trim( (string) $attr->nodeValue );
				if ( '' !== $src ) {
					$urls[] = $src;
				}
			}
		}

		// Some Tumblr exports inline poster images on `<video poster="...">`.
		$nodes = $xpath->query( '//video/@poster' );
		if ( false !== $nodes ) {
			foreach ( $nodes as $attr ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMAttr API.
				$src = trim( (string) $attr->nodeValue );
				if ( '' !== $src ) {
					$urls[] = $src;
				}
			}
		}

		if ( empty( $urls ) && '' !== $content_html
			&& preg_match_all( '/<(?:img|video|audio|source)[^>]+src=["\']([^"\']+)["\']/i', $content_html, $matches )
		) {
			$urls = $matches[1];
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Build the manifest author array for a parsed post.
	 *
	 * @param array<string, mixed> $post Parsed post.
	 * @return array<string, string>|null
	 */
	private function build_author( array $post ): ?array {
		if ( empty( $post['author_name'] ) && empty( $post['author_url'] ) ) {
			return null;
		}

		$author = array();
		if ( ! empty( $post['author_name'] ) ) {
			$author['name'] = (string) $post['author_name'];
		}
		if ( ! empty( $post['author_url'] ) ) {
			$author['url'] = (string) $post['author_url'];
		}

		return $author;
	}

	/**
	 * Map a parsed Tumblr post onto a ContentType.
	 *
	 * Reblogs always classify as REPOST, regardless of underlying type,
	 * so the original attribution is preserved for the importer. Long
	 * text posts (≥500 words) become ARTICLE for cross-source consistency
	 * with the Medium and Blogger adapters.
	 *
	 * @param array<string, mixed> $post Parsed post.
	 * @return ContentType
	 */
	private function classify_post( array $post ): ContentType {
		if ( ! empty( $post['is_reblog'] ) ) {
			return ContentType::REPOST;
		}

		switch ( $post['post_type'] ) {
			case self::TYPE_PHOTO:
			case self::TYPE_AUDIO:
				return ContentType::MEDIA;
			case self::TYPE_VIDEO:
				return ContentType::VIDEO;
			case self::TYPE_QUOTE:
			case self::TYPE_LINK:
			case self::TYPE_CHAT:
			case self::TYPE_ANSWER:
				return ContentType::POST;
			case self::TYPE_TEXT:
			default:
				$word_count = str_word_count( wp_strip_all_tags( (string) $post['content'] ) );
				return $word_count >= 500 ? ContentType::ARTICLE : ContentType::POST;
		}
	}

	/**
	 * Build a short excerpt from the content HTML.
	 *
	 * @param string $content_html Full content HTML.
	 * @return string|null Plain-text excerpt or null when content is empty.
	 */
	private function build_excerpt( string $content_html ): ?string {
		$plain = wp_strip_all_tags( $content_html );
		$plain = trim( preg_replace( '/\s+/', ' ', $plain ) ?? '' );

		if ( '' === $plain ) {
			return null;
		}

		if ( mb_strlen( $plain ) <= 160 ) {
			return $plain;
		}

		return mb_substr( $plain, 0, 157 ) . '...';
	}

	/**
	 * Pull a single text node via XPath.
	 *
	 * @param DOMXPath $xpath The XPath helper.
	 * @param string   $query XPath query.
	 * @return string Text content, or empty string when no match.
	 */
	private function xpath_text( DOMXPath $xpath, string $query ): string {
		$nodes = $xpath->query( $query );

		if ( false === $nodes || 0 === $nodes->length ) {
			return '';
		}

		$node = $nodes->item( 0 );
		if ( ! $node instanceof DOMNode ) {
			return '';
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode API.
		return (string) $node->textContent;
	}

	/**
	 * Pull a single attribute value via XPath.
	 *
	 * @param DOMXPath $xpath     The XPath helper.
	 * @param string   $query     XPath query for the element.
	 * @param string   $attribute Attribute to read.
	 * @return string Attribute value, or empty string when no match.
	 */
	private function xpath_attr( DOMXPath $xpath, string $query, string $attribute ): string {
		$nodes = $xpath->query( $query );

		if ( false === $nodes || 0 === $nodes->length ) {
			return '';
		}

		$node = $nodes->item( 0 );

		if ( ! $node instanceof DOMElement ) {
			return '';
		}

		return (string) $node->getAttribute( $attribute );
	}

	/**
	 * Parse an ISO-8601 (or other) date string into DateTimeImmutable.
	 *
	 * @param string $date_string Date string.
	 * @return DateTimeImmutable
	 */
	private function parse_date( string $date_string ): DateTimeImmutable {
		if ( '' === $date_string ) {
			return new DateTimeImmutable();
		}

		try {
			return new DateTimeImmutable( $date_string );
		} catch ( \Exception $e ) {
			return new DateTimeImmutable();
		}
	}
}
