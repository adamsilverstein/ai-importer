<?php
/**
 * GhostNormalizer class tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\GhostNormalizer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the GhostNormalizer class.
 */
class GhostNormalizerTest extends TestCase {

	/**
	 * Normalizer under test.
	 *
	 * @var GhostNormalizer
	 */
	private GhostNormalizer $normalizer;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->normalizer = new GhostNormalizer();

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_kses_post' )->alias(
			static function ( $content ) {
				return $content;
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_basename' )->alias( 'basename' );
	}

	/**
	 * Build a minimal raw item as produced by GhostAdapter::fetch_item().
	 *
	 * @param array<string, mixed> $overrides Override defaults.
	 * @return array<string, mixed>
	 */
	private function make_raw( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 'post-1',
				'type'         => 'post',
				'content'      => '<p>Welcome to my Ghost blog.</p>',
				'title'        => 'Hello Ghost',
				'created_at'   => '2024-01-10T10:00:00+00:00',
				'media_urls'   => array(),
				'metadata'     => array(
					'slug'           => 'hello-ghost',
					'custom_excerpt' => 'A short welcome post.',
					'type'           => 'post',
					'tags'           => array( 'News', 'Updates' ),
					'authors'        => array(
						array(
							'name' => 'Jane Doe',
							'slug' => 'jane',
						),
					),
				),
				'original_url' => 'https://example.ghost.io/hello-ghost/',
				'tags'         => array( 'News', 'Updates' ),
				'author'       => array( 'name' => 'Jane Doe' ),
			),
			$overrides
		);
	}

	/**
	 * Test the normalizer reports the correct adapter ID.
	 *
	 * @return void
	 */
	public function test_supports_ghost(): void {
		$this->assertSame( 'ghost', $this->normalizer->get_adapter_id() );
		$this->assertTrue( $this->normalizer->supports( 'ghost' ) );
		$this->assertFalse( $this->normalizer->supports( 'blogger' ) );
	}

	/**
	 * Test normalize maps the basic fields.
	 *
	 * @return void
	 */
	public function test_normalize_basic_fields(): void {
		$item = $this->normalizer->normalize( $this->make_raw() );

		$this->assertSame( 'post-1', $item->source_id );
		$this->assertSame( 'ghost', $item->source_adapter );
		$this->assertSame( 'Hello Ghost', $item->title );
		$this->assertSame( '<p>Welcome to my Ghost blog.</p>', $item->content );
		$this->assertSame( 'https://example.ghost.io/hello-ghost/', $item->source_url );
		$this->assertSame( 'Jane Doe', $item->author_name );
		$this->assertSame( '2024-01-10T10:00:00+00:00', $item->publish_date->format( 'c' ) );
	}

	/**
	 * Test normalize uses tags from metadata.tags.
	 *
	 * @return void
	 */
	public function test_normalize_uses_metadata_tags(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw(
				array(
					'metadata' => array(
						'slug' => 'hello-ghost',
						'tags' => array( 'Ghost', 'Migration' ),
					),
					'tags'     => array( 'Ignored' ),
				)
			)
		);

		$this->assertSame( array( 'Ghost', 'Migration' ), $item->tags );
	}

	/**
	 * Test normalize falls back to top-level tags when metadata has none.
	 *
	 * @return void
	 */
	public function test_normalize_falls_back_to_top_level_tags(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw(
				array(
					'metadata' => array( 'slug' => 'hello-ghost' ),
					'tags'     => array( 'Fallback' ),
				)
			)
		);

		$this->assertSame( array( 'Fallback' ), $item->tags );
	}

	/**
	 * Test normalize resolves the article content type.
	 *
	 * @return void
	 */
	public function test_normalize_resolves_article_type(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'type' => 'article' ) )
		);

		$this->assertSame( ContentType::ARTICLE, $item->content_type );
	}

	/**
	 * Test normalize unknown type falls back to POST.
	 *
	 * @return void
	 */
	public function test_normalize_unknown_type_falls_back_to_post(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'type' => 'whatever' ) )
		);

		$this->assertSame( ContentType::POST, $item->content_type );
	}

	/**
	 * Test normalize creates media references from absolute URLs.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_media(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw(
				array(
					'media_urls' => array(
						'https://example.ghost.io/content/images/feature.jpg',
						'https://example.ghost.io/content/images/inline-photo.jpg',
					),
				)
			)
		);

		$this->assertCount( 2, $item->media );
		$this->assertSame(
			'https://example.ghost.io/content/images/feature.jpg',
			$item->media[0]->source_url
		);
		$this->assertSame( 'image', $item->media[0]->type );
	}

	/**
	 * Test normalize preserves Ghost metadata for mapping.
	 *
	 * @return void
	 */
	public function test_normalize_preserves_metadata(): void {
		$item = $this->normalizer->normalize( $this->make_raw() );

		$this->assertSame( 'hello-ghost', $item->get_meta( 'slug' ) );
		$this->assertSame( 'A short welcome post.', $item->get_meta( 'custom_excerpt' ) );
		$this->assertSame( 'post', $item->get_meta( 'type' ) );
		$this->assertSame( 'Jane Doe', $item->get_meta( 'authors' )[0]['name'] );
	}
}
