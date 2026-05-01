<?php
/**
 * InstagramNormalizer class tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\InstagramNormalizer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the InstagramNormalizer class.
 */
class InstagramNormalizerTest extends TestCase {

	/**
	 * Normalizer under test.
	 *
	 * @var InstagramNormalizer
	 */
	private InstagramNormalizer $normalizer;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->normalizer = new InstagramNormalizer();

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_kses_post' )->alias(
			static function ( $content ) {
				return $content;
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_basename' )->alias( 'basename' );
		Functions\when( 'wpautop' )->alias(
			static function ( $text ) {
				return '<p>' . str_replace( "\n\n", "</p>\n\n<p>", (string) $text ) . '</p>';
			}
		);
	}

	/**
	 * Build a minimal raw item for normalization.
	 *
	 * @param array<string, mixed> $overrides Override defaults.
	 * @return array<string, mixed>
	 */
	private function make_raw( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 'posts_1705320000_abcdef0123ab',
				'type'         => 'post',
				'content'      => 'Hello world',
				'title'        => 'Hello world',
				'created_at'   => '2024-01-15T12:00:00Z',
				'media_urls'   => array( 'media/posts/202401/a.jpg' ),
				'media_paths'  => array( 'media/posts/202401/a.jpg' ),
				'metadata'     => array(
					'source_kind' => 'posts',
					'media_count' => 1,
				),
				'original_url' => null,
				'tags'         => array( 'wordpress' ),
			),
			$overrides
		);
	}

	/**
	 * Test the normalizer reports the correct adapter ID.
	 *
	 * @return void
	 */
	public function test_supports_instagram(): void {
		$this->assertSame( 'instagram', $this->normalizer->get_adapter_id() );
		$this->assertTrue( $this->normalizer->supports( 'instagram' ) );
	}

	/**
	 * Test normalize maps the basic fields.
	 *
	 * @return void
	 */
	public function test_normalize_basic_fields(): void {
		$item = $this->normalizer->normalize( $this->make_raw() );

		$this->assertSame( 'posts_1705320000_abcdef0123ab', $item->source_id );
		$this->assertSame( 'instagram', $item->source_adapter );
		$this->assertSame( 'Hello world', $item->title );
	}

	/**
	 * Test normalize wraps the caption in HTML paragraphs.
	 *
	 * @return void
	 */
	public function test_normalize_converts_caption_to_html(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw(
				array(
					'content' => "Line one\n\nLine two",
				)
			)
		);

		$this->assertStringContainsString( 'Line one', $item->content );
		$this->assertStringContainsString( 'Line two', $item->content );
	}

	/**
	 * Test normalize resolves the video content type.
	 *
	 * @return void
	 */
	public function test_normalize_resolves_video_type(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'type' => 'video' ) )
		);

		$this->assertSame( ContentType::VIDEO, $item->content_type );
	}

	/**
	 * Test normalize creates media references with local paths.
	 *
	 * @return void
	 */
	public function test_normalize_attaches_local_paths(): void {
		$item = $this->normalizer->normalize( $this->make_raw() );

		$this->assertCount( 1, $item->media );
		$this->assertSame( 'media/posts/202401/a.jpg', $item->media[0]->local_path );
	}

	/**
	 * Test normalize forwards tags onto the NormalizedItem.
	 *
	 * @return void
	 */
	public function test_normalize_forwards_tags(): void {
		$item = $this->normalizer->normalize(
			$this->make_raw( array( 'tags' => array( 'wordpress', 'ai' ) ) )
		);

		$this->assertSame( array( 'wordpress', 'ai' ), $item->tags );
	}
}
