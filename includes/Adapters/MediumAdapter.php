<?php
/**
 * Medium export adapter.
 *
 * Parses a Medium export ZIP file and extracts stories and responses.
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
use DOMXPath;
use RuntimeException;
use WP_Error;

/**
 * Adapter for importing content from a Medium export ZIP archive.
 *
 * Medium exports (Settings → Account → Download your information) ship
 * each story as an HTML file under `posts/{YYYY-MM-DD}_{slug}-{id}.html`,
 * with microformat-style classes (p-name, dt-published, p-canonical,
 * e-content, p-category) that we parse via DOM + XPath.
 */
class MediumAdapter extends AbstractAdapter {

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
		return 'medium';
	}

	/**
	 * Get the human-readable name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Medium';
	}

	/**
	 * Get a description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Import stories and responses from your Medium export ZIP.', 'ai-importer' );
	}

	/**
	 * Get the icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'dashicons-edit';
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
	 * Authenticate by validating an uploaded export ZIP.
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
			$this->log_error( 'Medium export file not found.', $credentials );
			return false;
		}

		$validation = $this->validate_archive( $file_path );

		if ( is_wp_error( $validation ) ) {
			$messages = $validation->get_error_messages();
			$this->log_error( ! empty( $messages ) ? $messages[0] : 'Medium export validation failed.' );
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
					'is_draft' => $post['is_draft'],
					'tags'     => $post['tags'],
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
	 * @return array<string, mixed> Post data.
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
					__( 'Medium post with ID "%s" not found in export.', 'ai-importer' ),
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
				'is_draft' => $post['is_draft'],
				'tags'     => $post['tags'],
			),
			'original_url' => $post['canonical_url'],
			'tags'         => $post['tags'],
			'author'       => array(
				'name' => $post['author_name'],
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
			ContentType::ARTICLE->value,
			ContentType::POST->value,
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
				'label'       => __( 'Medium Export', 'ai-importer' ),
				'description' => __( 'Upload your Medium export ZIP. Request it from medium.com/me/export.', 'ai-importer' ),
				'required'    => true,
				'accept'      => '.zip',
			)
		);

		return $schema;
	}

	/**
	 * Validate that a ZIP file is a Medium export.
	 *
	 * Medium exports always contain a posts/ directory with at least one
	 * .html file (or a draft_ prefixed file). They also contain a
	 * profile/profile.html, but we accept the export as soon as we see a
	 * post — drafts-only or stories-only exports are valid.
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
				__( 'This ZIP does not appear to be a Medium export. Could not find any posts/*.html files.', 'ai-importer' )
			);
		}

		return true;
	}

	/**
	 * Get all parsed posts from the archive, loading them if needed.
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
				__( 'Medium export file not found. Please re-upload your archive.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->posts = $this->parse_archive( $file_path );

		return $this->posts;
	}

	/**
	 * Parse the Medium export ZIP and extract all posts.
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
				__( 'Failed to open Medium export ZIP file.', 'ai-importer' )
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
				__( 'No posts found in the Medium export. The archive may be empty or in an unsupported format.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $posts;
	}

	/**
	 * Parse one Medium post HTML file.
	 *
	 * @param string $html     Raw HTML.
	 * @param string $filename The filename within posts/ (without .html).
	 * @return array<string, mixed>|null Parsed post or null on failure.
	 */
	private function parse_post( string $html, string $filename ): ?array {
		$is_draft = str_starts_with( $filename, 'draft_' );
		$id       = $this->extract_id_from_filename( $filename );

		$dom = new DOMDocument();
		// Suppress libxml warnings on imperfect HTML.
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath = new DOMXPath( $dom );

		$title = $this->xpath_text( $xpath, "//*[contains(concat(' ', normalize-space(@class), ' '), ' p-name ')]" );

		$published_raw = $this->xpath_attr( $xpath, "//time[contains(concat(' ', normalize-space(@class), ' '), ' dt-published ')]", 'datetime' );
		if ( '' === $published_raw ) {
			$published_raw = $this->xpath_text( $xpath, "//time[contains(concat(' ', normalize-space(@class), ' '), ' dt-published ')]" );
		}
		$published_at = $this->parse_date( $published_raw );

		$canonical_url = $this->xpath_attr( $xpath, "//a[contains(concat(' ', normalize-space(@class), ' '), ' p-canonical ')]", 'href' );

		$author_name = $this->xpath_text( $xpath, "//a[contains(concat(' ', normalize-space(@class), ' '), ' p-author ')]" );

		$content = $this->extract_content_html( $dom, $xpath );

		$tags       = $this->extract_tags( $xpath );
		$media_urls = $this->extract_media_urls_from_content( $xpath );

		// Skip posts with neither title nor content — these are noise.
		if ( '' === trim( $title ) && '' === trim( wp_strip_all_tags( $content ) ) ) {
			return null;
		}

		// Use a stable ID even when the slug doesn't include a hash.
		$stable_id = '' !== $id ? $id : md5( $filename );

		return array(
			'id'            => $stable_id,
			'filename'      => $filename,
			'title'         => trim( $title ),
			'published_at'  => $published_at,
			'canonical_url' => '' !== $canonical_url ? $canonical_url : null,
			'author_name'   => '' !== trim( $author_name ) ? trim( $author_name ) : null,
			'content'       => $content,
			'tags'          => $tags,
			'media_urls'    => $media_urls,
			'is_draft'      => $is_draft,
		);
	}

	/**
	 * Build the manifest author array for a parsed post.
	 *
	 * @param array<string, mixed> $post Parsed post.
	 * @return array<string, string>|null Author array or null when no name is set.
	 */
	private function build_author( array $post ): ?array {
		$name = $post['author_name'] ?? null;

		if ( empty( $name ) ) {
			return null;
		}

		return array( 'name' => (string) $name );
	}

	/**
	 * Extract the trailing 12-char Medium ID from a filename.
	 *
	 * Medium filenames look like `2018-01-15_Story-title-7a3f9b2c1d4e` — the
	 * final hyphen-separated token is the unique post ID. Drafts are prefixed
	 * with `draft_` and may not have a slug at all.
	 *
	 * @param string $filename Filename without .html.
	 * @return string Extracted ID, or empty string if none could be derived.
	 */
	private function extract_id_from_filename( string $filename ): string {
		$base = preg_replace( '/^draft_/', '', $filename ) ?? $filename;

		if ( preg_match( '/-([0-9a-f]{8,})$/i', $base, $matches ) ) {
			return $matches[1];
		}

		return $base;
	}

	/**
	 * Extract the post content as HTML from the e-content section.
	 *
	 * @param DOMDocument $dom   The DOM document.
	 * @param DOMXPath    $xpath The XPath helper.
	 * @return string Inner HTML of the content section, or empty string.
	 */
	private function extract_content_html( DOMDocument $dom, DOMXPath $xpath ): string {
		$nodes = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' e-content ')]" );

		if ( false === $nodes || 0 === $nodes->length ) {
			return '';
		}

		$node = $nodes->item( 0 );

		if ( ! $node instanceof DOMElement ) {
			return '';
		}

		$html = '';
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMElement API.
		foreach ( $node->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}

		return trim( $html );
	}

	/**
	 * Extract tag names from `<ul class="tags"><li><a class="p-category">...</a></li></ul>`.
	 *
	 * @param DOMXPath $xpath The XPath helper.
	 * @return array<string> Tag names.
	 */
	private function extract_tags( DOMXPath $xpath ): array {
		$tags  = array();
		$nodes = $xpath->query( "//a[contains(concat(' ', normalize-space(@class), ' '), ' p-category ')]" );

		if ( false !== $nodes ) {
			foreach ( $nodes as $node ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode API.
				$text = trim( (string) $node->textContent );
				if ( '' !== $text ) {
					$tags[] = $text;
				}
			}
		}

		return array_values( array_unique( $tags ) );
	}

	/**
	 * Pull media URLs from `<img src>` and `<figure><img>` inside the content.
	 *
	 * @param DOMXPath $xpath The XPath helper.
	 * @return array<string> Media URLs.
	 */
	private function extract_media_urls_from_content( DOMXPath $xpath ): array {
		$urls  = array();
		$nodes = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' e-content ')]//img/@src" );

		if ( false !== $nodes ) {
			foreach ( $nodes as $attr ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMAttr API.
				$src = trim( (string) $attr->nodeValue );
				if ( '' !== $src ) {
					$urls[] = $src;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Classify a post as POST or ARTICLE.
	 *
	 * Heuristic: 500+ words → ARTICLE, otherwise POST. Medium does not
	 * flag responses or replies in the export, so REPLY detection isn't
	 * reliable here.
	 *
	 * @param array<string, mixed> $post Parsed post.
	 * @return ContentType
	 */
	private function classify_post( array $post ): ContentType {
		$word_count = str_word_count( wp_strip_all_tags( (string) $post['content'] ) );

		return $word_count >= 500 ? ContentType::ARTICLE : ContentType::POST;
	}

	/**
	 * Build a short excerpt from the content HTML.
	 *
	 * @param string $content_html Full content HTML.
	 * @return string|null Plain-text excerpt, or null when content is short.
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

		return (string) $nodes->item( 0 )->textContent;
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
	 * Parse a Medium ISO-8601 date string into DateTimeImmutable.
	 *
	 * @param string $date_string ISO date string.
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
