<?php
/**
 * MediumNormalizer class tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\MediumNormalizer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the MediumNormalizer class.
 */
class MediumNormalizerTest extends TestCase {

	/**
	 * Normalizer under test.
	 *
	 * @var MediumNormalizer
	 */
	private MediumNormalizer $normalizer;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->normalizer = new MediumNormalizer();

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
	 * Build a minimal raw item for normalization.
	 *
	 * @param array<string, mixed> $overrides Override default fields.
	 * @return array<string, mixed>
	 */
	private function make_raw( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 'abc123',
				'type'         => 'post',
				'content'      => '<p>Hello</p>',
				'title'        => 'Hello',
				'created_at'   => '2024-03-15T10:30:00.000Z',
				'media_urls'   => array(),
				'metadata'     => array( 'is_draft' => false, 'tags' => array() ),
				'original_url' => 'https://medium.com/@me/hello-abc123',
				'tags'         => array(),
				'author'       => array( 'name' => 'Test Author' ),
			),
			$overrides
		);
	}

	/**
	 * Test the normalizer reports the correct adapter ID.
	 *
	 * @return void
	 */
	public function test_supports_medium(): void {
		$this->assertSame( 'medium', $this->normalizer->get_adapter_id() );
		$this->assertTrue( $this->normalizer->supports( 'medium' ) );
		$this->assertFalse( $this->normalizer->supports( 'twitter' ) );
	}

	/**
	 * Test normalize maps simple fields onto NormalizedItem.
	 *
	 * @return void
	 */
	public function test_normalize_basic_fields(): void {
		$item = $this->normalizer->normalize( $this->make_raw() );

		$this->assertSame( 'abc123', $item->source_id );
		$this->assertSame( 'medium', $item->source_adapter );
		$this->assertSame( 'Hello', $item->title );
		$this->assertSame( 'https://medium.com/@me/hello-abc123', $item->source_url );
		$this->assertSame( 'Test Author', $item->author_name );
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
	 * Test normalize falls back to POST when type is unknown.
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
	 * Test normalize converts media URL list into MediaReferences.
	 *
	 * @return void
	 */
	public function test_normalize_extracts_media_references(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw(
				array(
					'media_urls' => array(
						'https://cdn-images-1.medium.com/max/1024/foo.png',
					),
				)
			)
		);

		$this->assertCount( 1, $item->media );
		$this->assertSame(
			'https://cdn-images-1.medium.com/max/1024/foo.png',
			$item->media[0]->source_url
		);
	}

	/**
	 * Test normalize forwards tags onto the NormalizedItem.
	 *
	 * @return void
	 */
	public function test_normalize_forwards_tags(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'tags' => array( 'WordPress', 'AI' ) ) )
		);

		$this->assertSame( array( 'WordPress', 'AI' ), $item->tags );
	}
}
