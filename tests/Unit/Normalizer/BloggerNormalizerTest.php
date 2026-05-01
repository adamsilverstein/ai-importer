<?php
/**
 * BloggerNormalizer class tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\BloggerNormalizer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the BloggerNormalizer class.
 */
class BloggerNormalizerTest extends TestCase {

	/**
	 * Normalizer under test.
	 *
	 * @var BloggerNormalizer
	 */
	private BloggerNormalizer $normalizer;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->normalizer = new BloggerNormalizer();

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
	 * Build a minimal raw item.
	 *
	 * @param array<string, mixed> $overrides Override defaults.
	 * @return array<string, mixed>
	 */
	private function make_raw( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 'tag:blogger.com,1999:blog-1.post-1001',
				'type'         => 'post',
				'content'      => '<p>Hello</p>',
				'title'        => 'Hello',
				'created_at'   => '2024-01-15T10:30:00.000-08:00',
				'media_urls'   => array(),
				'metadata'     => array(
					'kind' => 'http://schemas.google.com/blogger/2008/kind#post',
					'tags' => array( 'WordPress' ),
				),
				'original_url' => 'https://example.blogspot.com/2024/01/hello.html',
				'tags'         => array( 'WordPress' ),
				'author'       => array(
					'name' => 'Test Author',
					'url'  => 'https://www.blogger.com/profile/1234',
				),
			),
			$overrides
		);
	}

	/**
	 * Test the normalizer reports the correct adapter ID.
	 *
	 * @return void
	 */
	public function test_supports_blogger(): void {
		$this->assertSame( 'blogger', $this->normalizer->get_adapter_id() );
		$this->assertTrue( $this->normalizer->supports( 'blogger' ) );
	}

	/**
	 * Test normalize maps the basic fields.
	 *
	 * @return void
	 */
	public function test_normalize_basic_fields(): void {
		$item = $this->normalizer->normalize( $this->make_raw() );

		$this->assertSame( 'tag:blogger.com,1999:blog-1.post-1001', $item->source_id );
		$this->assertSame( 'blogger', $item->source_adapter );
		$this->assertSame( 'Hello', $item->title );
		$this->assertSame( 'Test Author', $item->author_name );
		$this->assertSame(
			'https://www.blogger.com/profile/1234',
			$item->author_url
		);
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
	 * Test normalize forwards tags onto the NormalizedItem.
	 *
	 * @return void
	 */
	public function test_normalize_forwards_tags(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'tags' => array( 'WordPress', 'PHP' ) ) )
		);

		$this->assertSame( array( 'WordPress', 'PHP' ), $item->tags );
	}

	/**
	 * Test normalize creates media references from URL list.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_media(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw(
				array( 'media_urls' => array( 'https://example.com/img/a.jpg' ) )
			)
		);

		$this->assertCount( 1, $item->media );
		$this->assertSame(
			'https://example.com/img/a.jpg',
			$item->media[0]->source_url
		);
	}
}
