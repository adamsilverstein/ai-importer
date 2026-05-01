<?php
/**
 * Blogger Atom export adapter.
 *
 * Parses a Blogger Atom XML export and extracts posts and pages.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Adapters;

use AI_Importer\Adapters\Manifest\ContentManifest;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\Manifest\ManifestItem;
use AI_Importer\Schema\SettingsSchema;
use DateTimeImmutable;
use RuntimeException;
use SimpleXMLElement;
use WP_Error;

/**
 * Adapter for importing content from a Blogger Atom XML export.
 *
 * Blogger exports (Settings → Manage Blog → Back up content) are an
 * Atom feed of "entries" identified by a category whose term encodes
 * the kind: `http://schemas.google.com/blogger/2008/kind#post`,
 * `#comment`, `#page`, `#template`, or `#settings`. We only surface
 * posts and pages; comments are filtered out (they're tracked under
 * Epic #20 — F12.4 Comment import).
 *
 * OAuth is also planned per F1.4 but is intentionally not yet wired —
 * file upload covers the MVP need and avoids the OAuth review burden.
 */
class BloggerAdapter extends AbstractAdapter {

	/**
	 * Blogger kind URIs.
	 */
	private const KIND_POST    = 'http://schemas.google.com/blogger/2008/kind#post';
	private const KIND_PAGE    = 'http://schemas.google.com/blogger/2008/kind#page';
	private const KIND_COMMENT = 'http://schemas.google.com/blogger/2008/kind#comment';

	/**
	 * Blogger atom tag namespace, used to identify user-applied labels.
	 */
	private const NS_TAGS = 'http://www.blogger.com/atom/ns#';

	/**
	 * Parsed entries keyed by ID.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $entries = null;

	/**
	 * Get the unique identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'blogger';
	}

	/**
	 * Get the human-readable name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Blogger';
	}

	/**
	 * Get a description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Import posts and pages from a Blogger Atom XML export.', 'ai-importer' );
	}

	/**
	 * Get the icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'dashicons-welcome-write-blog';
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
	 * Authenticate by validating an uploaded Blogger XML export.
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
			$this->log_error( 'Blogger export file not found.', $credentials );
			return false;
		}

		$validation = $this->validate_export( $file_path );

		if ( is_wp_error( $validation ) ) {
			$messages = $validation->get_error_messages();
			$this->log_error( ! empty( $messages ) ? $messages[0] : 'Blogger export validation failed.' );
			return false;
		}

		$this->store_credentials(
			array(
				'file_path'    => $file_path,
				'connected_at' => gmdate( 'c' ),
			)
		);

		$this->delete_cache( 'manifest' );
		$this->entries = null;

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

		$entries  = $this->get_entries();
		$manifest = new ContentManifest( $this->get_id() );

		foreach ( $entries as $id => $entry ) {
			$item = new ManifestItem(
				id: $id,
				type: $this->classify_entry( $entry ),
				title: $entry['title'],
				created_at: $entry['published_at'],
				excerpt: $this->build_excerpt( $entry['content'] ),
				media_urls: $entry['media_urls'],
				metadata: array(
					'kind' => $entry['kind'],
					'tags' => $entry['tags'],
				),
				original_url: $entry['original_url'],
				author: $this->build_author( $entry ),
			);

			$manifest->add_item( $item );
		}

		$this->set_cache( 'manifest', $manifest, 86400 );

		return $manifest;
	}

	/**
	 * Fetch a single entry by ID with full content.
	 *
	 * @param string $item_id Entry ID.
	 * @return array<string, mixed>
	 * @throws RuntimeException If not authenticated or not found.
	 */
	public function fetch_item( string $item_id ): array {
		$this->ensure_authenticated();

		$entries = $this->get_entries();

		if ( ! isset( $entries[ $item_id ] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				sprintf(
					/* translators: %s: entry ID */
					__( 'Blogger entry with ID "%s" not found in export.', 'ai-importer' ),
					$item_id
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$entry = $entries[ $item_id ];

		return array(
			'id'           => $item_id,
			'type'         => $this->classify_entry( $entry )->value,
			'content'      => $entry['content'],
			'title'        => $entry['title'],
			'created_at'   => $entry['published_at']->format( 'c' ),
			'media_urls'   => $entry['media_urls'],
			'metadata'     => array(
				'kind' => $entry['kind'],
				'tags' => $entry['tags'],
			),
			'original_url' => $entry['original_url'],
			'tags'         => $entry['tags'],
			'author'       => array(
				'name' => $entry['author_name'],
				'url'  => $entry['author_url'],
			),
			'raw'          => $entry,
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
				'label'       => __( 'Blogger XML Export', 'ai-importer' ),
				'description' => __( 'Upload your Blogger Atom XML export. Get it from Settings > Manage Blog > Back up content.', 'ai-importer' ),
				'required'    => true,
				'accept'      => '.xml',
			)
		);

		return $schema;
	}

	/**
	 * Validate that a file is a Blogger Atom XML export.
	 *
	 * Looks for the Atom feed root and at least one entry whose
	 * "kind" category points at Blogger's namespace.
	 *
	 * @param string $file_path Path to the XML file.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_export( string $file_path ): bool|WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local uploaded file, wp_remote_get is for HTTP.
		$contents = file_get_contents( $file_path );

		if ( false === $contents || '' === $contents ) {
			return new WP_Error(
				'unreadable_file',
				__( 'The uploaded file could not be read.', 'ai-importer' )
			);
		}

		$xml = $this->load_xml( $contents );

		if ( null === $xml ) {
			return new WP_Error(
				'invalid_xml',
				__( 'The uploaded file is not valid XML.', 'ai-importer' )
			);
		}

		// Confirm it's an Atom feed and references the Blogger kind scheme somewhere.
		if ( false === strpos( $contents, 'http://schemas.google.com/blogger/2008/kind' ) ) {
			return new WP_Error(
				'not_blogger',
				__( 'This XML does not appear to be a Blogger export. Could not find the Blogger kind scheme.', 'ai-importer' )
			);
		}

		return true;
	}

	/**
	 * Get all parsed entries, loading them if needed.
	 *
	 * @return array<string, array<string, mixed>> Entries keyed by ID.
	 * @throws RuntimeException If file cannot be read.
	 */
	private function get_entries(): array {
		if ( null !== $this->entries ) {
			return $this->entries;
		}

		$credentials = $this->get_stored_credentials();
		$file_path   = $credentials['file_path'] ?? '';

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Blogger export file not found. Please re-upload your archive.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->entries = $this->parse_export( $file_path );

		return $this->entries;
	}

	/**
	 * Parse the Blogger XML export and extract posts/pages.
	 *
	 * @param string $file_path Path to the XML file.
	 * @return array<string, array<string, mixed>> Entries keyed by ID.
	 * @throws RuntimeException If parsing fails.
	 */
	private function parse_export( string $file_path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local uploaded file, wp_remote_get is for HTTP.
		$contents = file_get_contents( $file_path );

		if ( false === $contents ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Failed to read Blogger export file.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$xml = $this->load_xml( $contents );

		if ( null === $xml ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Failed to parse Blogger XML export.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$entries = array();

		foreach ( $xml->entry as $atom_entry ) {
			$parsed = $this->parse_entry( $atom_entry );

			if ( null === $parsed ) {
				continue;
			}

			$entries[ $parsed['id'] ] = $parsed;
		}

		if ( empty( $entries ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'No posts or pages found in the Blogger export.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $entries;
	}

	/**
	 * Load XML defensively, returning null on parse failure.
	 *
	 * @param string $xml_string XML payload.
	 * @return SimpleXMLElement|null
	 */
	private function load_xml( string $xml_string ): ?SimpleXMLElement {
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $xml_string );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return false !== $xml ? $xml : null;
	}

	/**
	 * Parse one Atom entry into a normalized post shape.
	 *
	 * Returns null when the entry isn't a post or page (e.g. comments,
	 * settings, templates, or anything we don't currently surface).
	 *
	 * @param SimpleXMLElement $entry Atom entry.
	 * @return array<string, mixed>|null
	 */
	private function parse_entry( SimpleXMLElement $entry ): ?array {
		$kind = $this->extract_kind( $entry );

		if ( null === $kind || self::KIND_COMMENT === $kind ) {
			return null;
		}

		// Surface only posts and pages.
		if ( self::KIND_POST !== $kind && self::KIND_PAGE !== $kind ) {
			return null;
		}

		$id = (string) $entry->id;
		if ( '' === $id ) {
			return null;
		}

		$published_raw = (string) $entry->published;
		$published_at  = $this->parse_date( $published_raw );

		$title       = (string) $entry->title;
		$content     = (string) $entry->content;
		$tags        = $this->extract_tags( $entry );
		$media_urls  = $this->extract_media_urls( $content );
		$alt_link    = $this->extract_alternate_link( $entry );
		$author_info = $this->extract_author_info( $entry );

		return array(
			'id'           => $id,
			'kind'         => $kind,
			'title'        => trim( $title ),
			'content'      => $content,
			'published_at' => $published_at,
			'tags'         => $tags,
			'media_urls'   => $media_urls,
			'original_url' => $alt_link,
			'author_name'  => $author_info['name'],
			'author_url'   => $author_info['url'],
		);
	}

	/**
	 * Look up the entry kind from its category[scheme=#kind] term.
	 *
	 * @param SimpleXMLElement $entry Atom entry.
	 * @return string|null Kind URI or null when missing.
	 */
	private function extract_kind( SimpleXMLElement $entry ): ?string {
		foreach ( $entry->category as $category ) {
			$scheme = (string) $category['scheme'];
			$term   = (string) $category['term'];

			if ( 'http://schemas.google.com/g/2005#kind' === $scheme ) {
				return $term;
			}
		}

		return null;
	}

	/**
	 * Extract user-applied tags (Blogger labels).
	 *
	 * @param SimpleXMLElement $entry Atom entry.
	 * @return array<string>
	 */
	private function extract_tags( SimpleXMLElement $entry ): array {
		$tags = array();

		foreach ( $entry->category as $category ) {
			$scheme = (string) $category['scheme'];
			$term   = (string) $category['term'];

			if ( self::NS_TAGS === $scheme && '' !== $term ) {
				$tags[] = $term;
			}
		}

		return array_values( array_unique( $tags ) );
	}

	/**
	 * Extract `<img src>` URLs from the post content HTML.
	 *
	 * @param string $content_html Content HTML.
	 * @return array<string>
	 */
	private function extract_media_urls( string $content_html ): array {
		if ( '' === $content_html ) {
			return array();
		}

		$urls = array();

		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content_html, $matches ) ) {
			$urls = array_values( array_unique( $matches[1] ) );
		}

		return $urls;
	}

	/**
	 * Extract the alternate link (canonical post URL).
	 *
	 * @param SimpleXMLElement $entry Atom entry.
	 * @return string|null
	 */
	private function extract_alternate_link( SimpleXMLElement $entry ): ?string {
		foreach ( $entry->link as $link ) {
			if ( 'alternate' === (string) $link['rel'] ) {
				$href = (string) $link['href'];
				return '' !== $href ? $href : null;
			}
		}

		return null;
	}

	/**
	 * Extract author name + uri.
	 *
	 * @param SimpleXMLElement $entry Atom entry.
	 * @return array{name: string|null, url: string|null}
	 */
	private function extract_author_info( SimpleXMLElement $entry ): array {
		$name = null;
		$url  = null;

		if ( isset( $entry->author ) ) {
			$author = $entry->author;
			$name   = '' !== (string) $author->name ? (string) $author->name : null;
			$url    = '' !== (string) $author->uri ? (string) $author->uri : null;
		}

		return array(
			'name' => $name,
			'url'  => $url,
		);
	}

	/**
	 * Build the manifest author array for an entry.
	 *
	 * @param array<string, mixed> $entry Parsed entry.
	 * @return array<string, string>|null
	 */
	private function build_author( array $entry ): ?array {
		if ( empty( $entry['author_name'] ) && empty( $entry['author_url'] ) ) {
			return null;
		}

		$author = array();
		if ( ! empty( $entry['author_name'] ) ) {
			$author['name'] = (string) $entry['author_name'];
		}
		if ( ! empty( $entry['author_url'] ) ) {
			$author['url'] = (string) $entry['author_url'];
		}

		return $author;
	}

	/**
	 * Classify a Blogger entry as POST or ARTICLE.
	 *
	 * Heuristic: 500+ words → ARTICLE, otherwise POST. Pages classify
	 * the same way. Threshold matches the Medium adapter for cross-
	 * source consistency.
	 *
	 * @param array<string, mixed> $entry Parsed entry.
	 * @return ContentType
	 */
	private function classify_entry( array $entry ): ContentType {
		$word_count = str_word_count( wp_strip_all_tags( (string) $entry['content'] ) );

		return $word_count >= 500 ? ContentType::ARTICLE : ContentType::POST;
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
	 * Parse a Blogger ISO-8601 date string.
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
