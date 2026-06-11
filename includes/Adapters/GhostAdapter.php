<?php
/**
 * Ghost adapter.
 *
 * Imports posts and pages from a Ghost JSON export or the Ghost
 * Content API.
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
use WP_Error;

/**
 * Adapter for importing content from Ghost (F7.4).
 *
 * Supports two connection modes:
 *
 * 1. File upload — Ghost's JSON export (Settings → Labs → Export).
 *    The export wraps everything in `db[0].data` with `posts`, `tags`,
 *    `posts_tags`, `users`, and `posts_authors` tables that we join
 *    locally.
 * 2. Content API — an `api_url` plus a read-only `content_api_key`.
 *    Posts and pages are fetched with `limit=all` and `formats=html`,
 *    with tags and authors already embedded by the API.
 *
 * Only published posts and pages are surfaced; drafts are excluded.
 */
class GhostAdapter extends AbstractAdapter {

	/**
	 * Connection modes.
	 */
	private const MODE_FILE = 'file';
	private const MODE_API  = 'api';

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
		return 'ghost';
	}

	/**
	 * Get the human-readable name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Ghost';
	}

	/**
	 * Get a description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Import posts and pages from a Ghost JSON export or the Ghost Content API.', 'ai-importer' );
	}

	/**
	 * Get the icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'dashicons-admin-site-alt3';
	}

	/**
	 * Get the authentication type.
	 *
	 * File upload is the primary mode; an API key connection is also
	 * accepted by authenticate() when api_url + content_api_key are
	 * provided instead of a file.
	 *
	 * @return string
	 */
	public function get_auth_type(): string {
		return self::AUTH_TYPE_FILE_UPLOAD;
	}

	/**
	 * Authenticate with either a Ghost JSON export or Content API credentials.
	 *
	 * @param array<string, mixed> $credentials Either 'file'/'attachment_id'
	 *                                          for an export upload, or
	 *                                          'api_url' + 'content_api_key'.
	 * @return bool True on success.
	 */
	public function authenticate( array $credentials ): bool {
		$file_path = $credentials['file'] ?? '';

		if ( ! empty( $credentials['attachment_id'] ) ) {
			$file_path = get_attached_file( (int) $credentials['attachment_id'] );
		}

		if ( ! empty( $file_path ) ) {
			return $this->authenticate_file( (string) $file_path );
		}

		$api_url = trim( (string) ( $credentials['api_url'] ?? '' ) );
		$api_key = trim( (string) ( $credentials['content_api_key'] ?? '' ) );

		if ( '' !== $api_url && '' !== $api_key ) {
			return $this->authenticate_api( $api_url, $api_key );
		}

		$this->log_error( 'Ghost authentication requires an export file or api_url + content_api_key.' );

		return false;
	}

	/**
	 * Fetch the content manifest.
	 *
	 * @return ContentManifest
	 * @throws RuntimeException If not authenticated or fetching fails.
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
				excerpt: $this->build_excerpt( $post ),
				updated_at: $post['updated_at'],
				media_urls: $post['media_urls'],
				metadata: array(
					'slug'           => $post['slug'],
					'custom_excerpt' => $post['custom_excerpt'],
					'type'           => $post['type'],
					'tags'           => $post['tags'],
					'authors'        => $post['authors'],
				),
				original_url: $post['original_url'],
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
	 * @throws RuntimeException If not authenticated or not found.
	 */
	public function fetch_item( string $item_id ): array {
		$this->ensure_authenticated();

		$posts = $this->get_posts();

		if ( ! isset( $posts[ $item_id ] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				sprintf(
					/* translators: %s: post ID */
					__( 'Ghost post with ID "%s" not found.', 'ai-importer' ),
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
				'slug'           => $post['slug'],
				'custom_excerpt' => $post['custom_excerpt'],
				'type'           => $post['type'],
				'tags'           => $post['tags'],
				'authors'        => $post['authors'],
			),
			'original_url' => $post['original_url'],
			'tags'         => $post['tags'],
			'author'       => $this->build_author( $post ),
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
		);
	}

	/**
	 * Build the settings schema.
	 *
	 * Fields are optional individually because either the export file
	 * or the api_url + content_api_key pair satisfies authentication.
	 *
	 * @return SettingsSchema
	 */
	protected function build_settings_schema(): SettingsSchema {
		$schema = new SettingsSchema();

		$schema->add_field(
			'archive_file',
			array(
				'type'        => 'file',
				'label'       => __( 'Ghost JSON Export', 'ai-importer' ),
				'description' => __( 'Upload your Ghost JSON export. Get it from Settings > Labs > Export your content.', 'ai-importer' ),
				'required'    => false,
				'accept'      => '.json',
			)
		);

		$schema->add_field(
			'api_url',
			array(
				'type'        => 'url',
				'label'       => __( 'Ghost Site URL', 'ai-importer' ),
				'description' => __( 'Your Ghost site URL, e.g. https://example.ghost.io. Used with a Content API key instead of a file upload.', 'ai-importer' ),
				'required'    => false,
			)
		);

		$schema->add_field(
			'content_api_key',
			array(
				'type'        => 'password',
				'label'       => __( 'Content API Key', 'ai-importer' ),
				'description' => __( 'A Ghost Content API key. Create one under Settings > Integrations > Add custom integration.', 'ai-importer' ),
				'required'    => false,
			)
		);

		return $schema;
	}

	/**
	 * Authenticate by validating an uploaded Ghost JSON export.
	 *
	 * @param string $file_path Path to the JSON export.
	 * @return bool True on success.
	 */
	private function authenticate_file( string $file_path ): bool {
		if ( ! file_exists( $file_path ) ) {
			$this->log_error( 'Ghost export file not found.', array( 'file' => $file_path ) );
			return false;
		}

		$validation = $this->validate_export( $file_path );

		if ( is_wp_error( $validation ) ) {
			$messages = $validation->get_error_messages();
			$this->log_error( ! empty( $messages ) ? $messages[0] : 'Ghost export validation failed.' );
			return false;
		}

		$this->store_credentials(
			array(
				'mode'         => self::MODE_FILE,
				'file_path'    => $file_path,
				'connected_at' => gmdate( 'c' ),
			)
		);

		$this->delete_cache( 'manifest' );
		$this->posts = null;

		return true;
	}

	/**
	 * Authenticate against the Ghost Content API.
	 *
	 * Performs a lightweight probe request to confirm the URL and key
	 * are valid before storing them.
	 *
	 * @param string $api_url Ghost site URL.
	 * @param string $api_key Content API key.
	 * @return bool True on success.
	 */
	private function authenticate_api( string $api_url, string $api_key ): bool {
		$api_url = rtrim( $api_url, '/' );

		$probe = $this->api_request( $api_url, $api_key, 'posts', array( 'limit' => '1' ) );

		if ( is_wp_error( $probe ) ) {
			$messages = $probe->get_error_messages();
			$this->log_error( ! empty( $messages ) ? $messages[0] : 'Ghost Content API probe failed.' );
			return false;
		}

		$this->store_credentials(
			array(
				'mode'            => self::MODE_API,
				'api_url'         => $api_url,
				'content_api_key' => $api_key,
				'connected_at'    => gmdate( 'c' ),
			)
		);

		$this->delete_cache( 'manifest' );
		$this->posts = null;

		return true;
	}

	/**
	 * Validate that a file is a Ghost JSON export.
	 *
	 * @param string $file_path Path to the JSON file.
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

		$decoded = json_decode( $contents, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'invalid_json',
				__( 'The uploaded file is not valid JSON.', 'ai-importer' )
			);
		}

		$data = $this->extract_export_data( $decoded );

		if ( null === $data || ! isset( $data['posts'] ) || ! is_array( $data['posts'] ) ) {
			return new WP_Error(
				'not_ghost',
				__( 'This JSON does not appear to be a Ghost export. Could not find db[0].data.posts.', 'ai-importer' )
			);
		}

		return true;
	}

	/**
	 * Extract the data table set from a decoded Ghost export.
	 *
	 * @param array<string, mixed> $decoded Decoded export JSON.
	 * @return array<string, mixed>|null The data tables or null.
	 */
	private function extract_export_data( array $decoded ): ?array {
		$data = $decoded['db'][0]['data'] ?? null;

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Get all parsed posts, loading them if needed.
	 *
	 * @return array<string, array<string, mixed>> Posts keyed by ID.
	 * @throws RuntimeException If loading fails.
	 */
	private function get_posts(): array {
		if ( null !== $this->posts ) {
			return $this->posts;
		}

		$credentials = $this->get_stored_credentials();
		$mode        = $credentials['mode'] ?? self::MODE_FILE;

		if ( self::MODE_API === $mode ) {
			$this->posts = $this->fetch_posts_from_api( $credentials );
		} else {
			$this->posts = $this->parse_export_file( $credentials );
		}

		return $this->posts;
	}

	/**
	 * Parse the Ghost JSON export and extract published posts/pages.
	 *
	 * @param array<string, mixed> $credentials Stored credentials.
	 * @return array<string, array<string, mixed>> Posts keyed by ID.
	 * @throws RuntimeException If the file cannot be read or parsed.
	 */
	private function parse_export_file( array $credentials ): array {
		$file_path = $credentials['file_path'] ?? '';

		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Ghost export file not found. Please re-upload your export.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local uploaded file, wp_remote_get is for HTTP.
		$contents = file_get_contents( $file_path );
		$decoded  = is_string( $contents ) ? json_decode( $contents, true ) : null;
		$data     = is_array( $decoded ) ? $this->extract_export_data( $decoded ) : null;

		if ( null === $data || ! isset( $data['posts'] ) || ! is_array( $data['posts'] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'Failed to parse Ghost JSON export.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$tags_by_post    = $this->join_tags( $data );
		$authors_by_post = $this->join_authors( $data );

		$posts = array();

		foreach ( $data['posts'] as $raw_post ) {
			if ( ! is_array( $raw_post ) ) {
				continue;
			}

			$parsed = $this->parse_post(
				$raw_post,
				$tags_by_post[ $raw_post['id'] ?? '' ] ?? array(),
				$authors_by_post[ $raw_post['id'] ?? '' ] ?? array()
			);

			if ( null === $parsed ) {
				continue;
			}

			$posts[ $parsed['id'] ] = $parsed;
		}

		if ( empty( $posts ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				__( 'No published posts or pages found in the Ghost export.', 'ai-importer' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $posts;
	}

	/**
	 * Join tag names onto posts via the posts_tags table.
	 *
	 * @param array<string, mixed> $data Export data tables.
	 * @return array<string, array<string>> Tag names keyed by post ID.
	 */
	private function join_tags( array $data ): array {
		$tag_names = array();

		foreach ( (array) ( $data['tags'] ?? array() ) as $tag ) {
			if ( is_array( $tag ) && isset( $tag['id'], $tag['name'] ) ) {
				$tag_names[ $tag['id'] ] = (string) $tag['name'];
			}
		}

		$tags_by_post = array();

		foreach ( (array) ( $data['posts_tags'] ?? array() ) as $join ) {
			if ( ! is_array( $join ) || ! isset( $join['post_id'], $join['tag_id'] ) ) {
				continue;
			}

			if ( isset( $tag_names[ $join['tag_id'] ] ) ) {
				$tags_by_post[ $join['post_id'] ][] = $tag_names[ $join['tag_id'] ];
			}
		}

		return $tags_by_post;
	}

	/**
	 * Join author names onto posts via the posts_authors and users tables.
	 *
	 * @param array<string, mixed> $data Export data tables.
	 * @return array<string, array<array<string, string>>> Authors keyed by post ID.
	 */
	private function join_authors( array $data ): array {
		$users = array();

		foreach ( (array) ( $data['users'] ?? array() ) as $user ) {
			if ( is_array( $user ) && isset( $user['id'], $user['name'] ) ) {
				$users[ $user['id'] ] = array(
					'name' => (string) $user['name'],
					'slug' => (string) ( $user['slug'] ?? '' ),
				);
			}
		}

		$authors_by_post = array();

		foreach ( (array) ( $data['posts_authors'] ?? array() ) as $join ) {
			if ( ! is_array( $join ) || ! isset( $join['post_id'], $join['author_id'] ) ) {
				continue;
			}

			if ( isset( $users[ $join['author_id'] ] ) ) {
				$authors_by_post[ $join['post_id'] ][] = $users[ $join['author_id'] ];
			}
		}

		return $authors_by_post;
	}

	/**
	 * Parse one Ghost post record into the internal post shape.
	 *
	 * Returns null for drafts, posts without an ID, or posts whose
	 * content cannot be extracted from html/lexical/mobiledoc.
	 *
	 * @param array<string, mixed>         $raw_post Raw post record.
	 * @param array<string>                $tags     Tag names for this post.
	 * @param array<array<string, string>> $authors  Authors for this post.
	 * @return array<string, mixed>|null
	 */
	private function parse_post( array $raw_post, array $tags, array $authors ): ?array {
		$id = (string) ( $raw_post['id'] ?? '' );

		if ( '' === $id ) {
			return null;
		}

		if ( 'published' !== ( $raw_post['status'] ?? '' ) ) {
			return null;
		}

		$content = $this->extract_content( $raw_post );

		if ( null === $content ) {
			return null;
		}

		// The Content API embeds tags/authors directly on the post.
		if ( empty( $tags ) && isset( $raw_post['tags'] ) && is_array( $raw_post['tags'] ) ) {
			foreach ( $raw_post['tags'] as $tag ) {
				if ( is_array( $tag ) && ! empty( $tag['name'] ) ) {
					$tags[] = (string) $tag['name'];
				}
			}
		}

		if ( empty( $authors ) && isset( $raw_post['authors'] ) && is_array( $raw_post['authors'] ) ) {
			foreach ( $raw_post['authors'] as $author ) {
				if ( is_array( $author ) && ! empty( $author['name'] ) ) {
					$authors[] = array(
						'name' => (string) $author['name'],
						'slug' => (string) ( $author['slug'] ?? '' ),
					);
				}
			}
		}

		$slug = (string) ( $raw_post['slug'] ?? '' );

		return array(
			'id'             => $id,
			'title'          => trim( (string) ( $raw_post['title'] ?? '' ) ),
			'slug'           => $slug,
			'type'           => (string) ( $raw_post['type'] ?? 'post' ),
			'content'        => $content,
			'custom_excerpt' => $raw_post['custom_excerpt'] ?? null,
			'feature_image'  => $raw_post['feature_image'] ?? null,
			'published_at'   => $this->parse_date( (string) ( $raw_post['published_at'] ?? $raw_post['created_at'] ?? '' ) ),
			'updated_at'     => ! empty( $raw_post['updated_at'] ) ? $this->parse_date( (string) $raw_post['updated_at'] ) : null,
			'tags'           => array_values( array_unique( $tags ) ),
			'authors'        => $authors,
			'media_urls'     => $this->collect_media_urls( $content, $raw_post['feature_image'] ?? null ),
			'original_url'   => $this->build_original_url( $raw_post, $slug ),
		);
	}

	/**
	 * Extract HTML content from a post record.
	 *
	 * Prefers the `html` field. Falls back to a conservative text
	 * extraction from `lexical` or `mobiledoc` JSON. Returns null when
	 * no content can be extracted.
	 *
	 * @param array<string, mixed> $raw_post Raw post record.
	 * @return string|null HTML content or null.
	 */
	private function extract_content( array $raw_post ): ?string {
		$html = $raw_post['html'] ?? null;

		if ( is_string( $html ) && '' !== trim( $html ) ) {
			return $html;
		}

		if ( ! empty( $raw_post['lexical'] ) && is_string( $raw_post['lexical'] ) ) {
			$extracted = $this->extract_lexical_text( $raw_post['lexical'] );

			if ( null !== $extracted ) {
				return $extracted;
			}
		}

		if ( ! empty( $raw_post['mobiledoc'] ) && is_string( $raw_post['mobiledoc'] ) ) {
			$extracted = $this->extract_mobiledoc_text( $raw_post['mobiledoc'] );

			if ( null !== $extracted ) {
				return $extracted;
			}
		}

		return null;
	}

	/**
	 * Conservatively extract paragraphs of text from lexical JSON.
	 *
	 * Walks the node tree and collects `text` values per top-level
	 * child, wrapping each non-empty group in a paragraph.
	 *
	 * @param string $lexical Lexical JSON string.
	 * @return string|null HTML or null when nothing usable was found.
	 */
	private function extract_lexical_text( string $lexical ): ?string {
		$decoded = json_decode( $lexical, true );

		if ( ! is_array( $decoded ) || empty( $decoded['root']['children'] ) || ! is_array( $decoded['root']['children'] ) ) {
			return null;
		}

		$paragraphs = array();

		foreach ( $decoded['root']['children'] as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$text = trim( implode( ' ', $this->collect_text_values( $child ) ) );

			if ( '' !== $text ) {
				$paragraphs[] = '<p>' . esc_html( $text ) . '</p>';
			}
		}

		return ! empty( $paragraphs ) ? implode( "\n", $paragraphs ) : null;
	}

	/**
	 * Recursively collect `text` values from a lexical node.
	 *
	 * @param array<string, mixed> $node Lexical node.
	 * @return array<string> Text fragments.
	 */
	private function collect_text_values( array $node ): array {
		$texts = array();

		if ( isset( $node['text'] ) && is_string( $node['text'] ) ) {
			$texts[] = $node['text'];
		}

		if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child ) {
				if ( is_array( $child ) ) {
					$texts = array_merge( $texts, $this->collect_text_values( $child ) );
				}
			}
		}

		return $texts;
	}

	/**
	 * Conservatively extract paragraphs of text from mobiledoc JSON.
	 *
	 * Only markup sections (type 1) are read; cards and other section
	 * types are skipped.
	 *
	 * @param string $mobiledoc Mobiledoc JSON string.
	 * @return string|null HTML or null when nothing usable was found.
	 */
	private function extract_mobiledoc_text( string $mobiledoc ): ?string {
		$decoded = json_decode( $mobiledoc, true );

		if ( ! is_array( $decoded ) || empty( $decoded['sections'] ) || ! is_array( $decoded['sections'] ) ) {
			return null;
		}

		$paragraphs = array();

		foreach ( $decoded['sections'] as $section ) {
			if ( ! is_array( $section ) || 1 !== ( $section[0] ?? null ) || ! is_array( $section[2] ?? null ) ) {
				continue;
			}

			$fragments = array();

			foreach ( $section[2] as $marker ) {
				if ( is_array( $marker ) && isset( $marker[3] ) && is_string( $marker[3] ) ) {
					$fragments[] = $marker[3];
				}
			}

			$text = trim( implode( '', $fragments ) );

			if ( '' !== $text ) {
				$paragraphs[] = '<p>' . esc_html( $text ) . '</p>';
			}
		}

		return ! empty( $paragraphs ) ? implode( "\n", $paragraphs ) : null;
	}

	/**
	 * Collect absolute media URLs from the feature image and content.
	 *
	 * @param string      $content_html  Content HTML.
	 * @param string|null $feature_image Feature image URL.
	 * @return array<string>
	 */
	private function collect_media_urls( string $content_html, ?string $feature_image ): array {
		$urls = array();

		if ( is_string( $feature_image ) && $this->is_absolute_url( $feature_image ) ) {
			$urls[] = $feature_image;
		}

		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content_html, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				if ( $this->is_absolute_url( $url ) ) {
					$urls[] = $url;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Check whether a URL is absolute (http or https).
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private function is_absolute_url( string $url ): bool {
		return 1 === preg_match( '#^https?://#i', $url );
	}

	/**
	 * Build the original URL for a post.
	 *
	 * The Content API provides a `url` field directly. For exports, the
	 * URL can only be derived when an api_url is stored (API mode), so
	 * file-mode posts return null.
	 *
	 * @param array<string, mixed> $raw_post Raw post record.
	 * @param string               $slug     Post slug.
	 * @return string|null
	 */
	private function build_original_url( array $raw_post, string $slug ): ?string {
		if ( ! empty( $raw_post['url'] ) && is_string( $raw_post['url'] ) && $this->is_absolute_url( $raw_post['url'] ) ) {
			return $raw_post['url'];
		}

		$credentials = $this->get_stored_credentials();
		$api_url     = $credentials['api_url'] ?? '';

		if ( '' !== $api_url && '' !== $slug ) {
			return rtrim( (string) $api_url, '/' ) . '/' . $slug . '/';
		}

		return null;
	}

	/**
	 * Fetch published posts and pages from the Ghost Content API.
	 *
	 * Uses limit=all so no pagination is required. Pages are fetched
	 * best-effort: a failure fetching pages is logged but does not
	 * abort the import.
	 *
	 * @param array<string, mixed> $credentials Stored credentials.
	 * @return array<string, array<string, mixed>> Posts keyed by ID.
	 * @throws RuntimeException If the posts request fails.
	 */
	private function fetch_posts_from_api( array $credentials ): array {
		$api_url = (string) ( $credentials['api_url'] ?? '' );
		$api_key = (string) ( $credentials['content_api_key'] ?? '' );

		$args = array(
			'include' => 'tags,authors',
			'limit'   => 'all',
			'formats' => 'html',
		);

		$posts_response = $this->api_request( $api_url, $api_key, 'posts', $args );

		if ( is_wp_error( $posts_response ) ) {
			$messages = $posts_response->get_error_messages();
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to fetch posts from the Ghost Content API: %s', 'ai-importer' ),
					! empty( $messages ) ? $messages[0] : __( 'Unknown error', 'ai-importer' )
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$records = (array) ( $posts_response['posts'] ?? array() );

		$pages_response = $this->api_request( $api_url, $api_key, 'pages', $args );

		if ( is_wp_error( $pages_response ) ) {
			$this->log_error( 'Failed to fetch pages from the Ghost Content API.' );
		} else {
			$records = array_merge( $records, (array) ( $pages_response['pages'] ?? array() ) );
		}

		$posts = array();

		foreach ( $records as $raw_post ) {
			if ( ! is_array( $raw_post ) ) {
				continue;
			}

			// The Content API only returns published content but may
			// omit the status field; default it for parse_post().
			if ( ! isset( $raw_post['status'] ) ) {
				$raw_post['status'] = 'published';
			}

			$parsed = $this->parse_post( $raw_post, array(), array() );

			if ( null !== $parsed ) {
				$posts[ $parsed['id'] ] = $parsed;
			}
		}

		return $posts;
	}

	/**
	 * Make a Ghost Content API request and parse the JSON response.
	 *
	 * @param string                $api_url  Ghost site URL.
	 * @param string                $api_key  Content API key.
	 * @param string                $endpoint Resource name (posts, pages).
	 * @param array<string, string> $args     Extra query args.
	 * @return array<string, mixed>|WP_Error Parsed body or error.
	 */
	private function api_request( string $api_url, string $api_key, string $endpoint, array $args = array() ): array|WP_Error {
		$query = array_merge( array( 'key' => $api_key ), $args );
		$url   = rtrim( $api_url, '/' ) . '/ghost/api/content/' . $endpoint . '/?' . http_build_query( $query );

		$response = $this->http_get( $url );

		return $this->parse_json_response( $response );
	}

	/**
	 * Classify a Ghost post as POST or ARTICLE.
	 *
	 * Heuristic: 500+ words → ARTICLE, otherwise POST. ContentType has
	 * no page concept, so pages classify the same way and keep their
	 * Ghost `type` in metadata for mapping. Threshold matches the
	 * Blogger and Medium adapters for cross-source consistency.
	 *
	 * @param array<string, mixed> $post Parsed post.
	 * @return ContentType
	 */
	private function classify_post( array $post ): ContentType {
		$word_count = str_word_count( wp_strip_all_tags( (string) $post['content'] ) );

		return $word_count >= 500 ? ContentType::ARTICLE : ContentType::POST;
	}

	/**
	 * Build the manifest author array for a post.
	 *
	 * Uses the first author; co-authors remain in metadata.authors.
	 *
	 * @param array<string, mixed> $post Parsed post.
	 * @return array<string, string>|null
	 */
	private function build_author( array $post ): ?array {
		if ( empty( $post['authors'] ) || ! is_array( $post['authors'] ) ) {
			return null;
		}

		$first = $post['authors'][0];

		if ( ! is_array( $first ) || empty( $first['name'] ) ) {
			return null;
		}

		return array( 'name' => (string) $first['name'] );
	}

	/**
	 * Build a short excerpt for a post.
	 *
	 * Prefers the custom excerpt, falling back to stripped content.
	 *
	 * @param array<string, mixed> $post Parsed post.
	 * @return string|null Plain-text excerpt or null when empty.
	 */
	private function build_excerpt( array $post ): ?string {
		$custom = $post['custom_excerpt'] ?? null;
		$plain  = is_string( $custom ) && '' !== trim( $custom )
			? trim( $custom )
			: wp_strip_all_tags( (string) $post['content'] );

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
	 * Parse a Ghost date string.
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
