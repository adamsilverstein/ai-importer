<?php
/**
 * ItemEnhancer class tests.
 *
 * @package AI_Importer\Tests\Unit\Processor
 */

namespace AI_Importer\Tests\Unit\Processor;

use AI_Importer\AI\MetaDescriptionGenerator;
use AI_Importer\AI\TitleGenerator;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Normalizer\NormalizedItem;
use AI_Importer\Processor\ItemEnhancer;
use AI_Importer\Tests\Unit\TestCase;
use DateTimeImmutable;
use WP_Error;

/**
 * Tests for the ItemEnhancer class.
 */
class ItemEnhancerTest extends TestCase {

	/**
	 * Build a minimal NormalizedItem for tests.
	 *
	 * @param string|null $title   Title.
	 * @param string      $content Content.
	 * @return NormalizedItem
	 */
	private function make_item( ?string $title, string $content, array $tags = array() ): NormalizedItem {
		return new NormalizedItem(
			source_id: 'src-1',
			source_adapter: 'twitter',
			content_type: ContentType::POST,
			content: $content,
			publish_date: new DateTimeImmutable( '2026-04-01T00:00:00Z' ),
			title: $title,
			tags: $tags,
		);
	}

	/**
	 * Test enhance assigns a generated title when the item has none.
	 *
	 * @return void
	 */
	public function test_enhance_sets_title_when_missing(): void {
		$title_gen = $this->createMock( TitleGenerator::class );
		$title_gen->expects( $this->once() )
			->method( 'generate' )
			->willReturn( 'A generated title' );

		$meta_gen = $this->createMock( MetaDescriptionGenerator::class );
		$meta_gen->method( 'generate' )->willReturn( new WP_Error( 'skip', 'skip' ) );

		$enhancer = new ItemEnhancer( $title_gen, $meta_gen );
		$item     = $this->make_item( null, 'Some tweet content that needs a title.' );

		$enhancer->enhance( $item );

		$this->assertSame( 'A generated title', $item->title );
	}

	/**
	 * Test enhance leaves an existing title alone.
	 *
	 * @return void
	 */
	public function test_enhance_preserves_existing_title(): void {
		$title_gen = $this->createMock( TitleGenerator::class );
		$title_gen->expects( $this->never() )->method( 'generate' );

		$meta_gen = $this->createMock( MetaDescriptionGenerator::class );
		$meta_gen->method( 'generate' )->willReturn( new WP_Error( 'skip', 'skip' ) );

		$enhancer = new ItemEnhancer( $title_gen, $meta_gen );
		$item     = $this->make_item( 'My existing title', 'Body content.' );

		$enhancer->enhance( $item );

		$this->assertSame( 'My existing title', $item->title );
	}

	/**
	 * Test enhance treats whitespace-only title as missing.
	 *
	 * @return void
	 */
	public function test_enhance_treats_whitespace_title_as_missing(): void {
		$title_gen = $this->createMock( TitleGenerator::class );
		$title_gen->expects( $this->once() )
			->method( 'generate' )
			->willReturn( 'Generated title' );

		$meta_gen = $this->createMock( MetaDescriptionGenerator::class );
		$meta_gen->method( 'generate' )->willReturn( new WP_Error( 'skip', 'skip' ) );

		$enhancer = new ItemEnhancer( $title_gen, $meta_gen );
		$item     = $this->make_item( '   ', 'Body content.' );

		$enhancer->enhance( $item );

		$this->assertSame( 'Generated title', $item->title );
	}

	/**
	 * Test enhance skips title generation when content is empty.
	 *
	 * @return void
	 */
	public function test_enhance_skips_title_when_content_empty(): void {
		$title_gen = $this->createMock( TitleGenerator::class );
		$title_gen->expects( $this->never() )->method( 'generate' );

		$meta_gen = $this->createMock( MetaDescriptionGenerator::class );
		$meta_gen->method( 'generate' )->willReturn( new WP_Error( 'skip', 'skip' ) );

		$enhancer = new ItemEnhancer( $title_gen, $meta_gen );
		$item     = $this->make_item( null, '   ' );

		$enhancer->enhance( $item );

		$this->assertNull( $item->title );
	}

	/**
	 * Test enhance tolerates a title generator failure silently.
	 *
	 * @return void
	 */
	public function test_enhance_title_failure_is_non_fatal(): void {
		$title_gen = $this->createMock( TitleGenerator::class );
		$title_gen->method( 'generate' )->willReturn(
			new WP_Error( 'ai_title_too_long', 'Title too long.' )
		);

		$meta_gen = $this->createMock( MetaDescriptionGenerator::class );
		$meta_gen->method( 'generate' )->willReturn( new WP_Error( 'skip', 'skip' ) );

		$enhancer = new ItemEnhancer( $title_gen, $meta_gen );
		$item     = $this->make_item( null, 'Body content.' );

		$enhancer->enhance( $item );

		$this->assertNull( $item->title );
	}

	/**
	 * Test enhance stores the generated SEO description in metadata.
	 *
	 * @return void
	 */
	public function test_enhance_stores_meta_description(): void {
		$title_gen = $this->createMock( TitleGenerator::class );
		$title_gen->method( 'generate' )->willReturn( new WP_Error( 'skip', 'skip' ) );

		$meta_gen = $this->createMock( MetaDescriptionGenerator::class );
		$meta_gen->expects( $this->once() )
			->method( 'generate' )
			->willReturn( 'A 100-ish character SEO description that fits within typical SERP snippet windows and reads well.' );

		$enhancer = new ItemEnhancer( $title_gen, $meta_gen );
		$item     = $this->make_item( 'My title', 'Body content for the post.' );

		$enhancer->enhance( $item );

		$this->assertArrayHasKey( ItemEnhancer::META_KEY_SEO_DESCRIPTION, $item->metadata );
		$this->assertSame(
			'A 100-ish character SEO description that fits within typical SERP snippet windows and reads well.',
			$item->metadata[ ItemEnhancer::META_KEY_SEO_DESCRIPTION ]
		);
	}

	/**
	 * Test enhance forwards title and tags to the meta description generator.
	 *
	 * @return void
	 */
	public function test_enhance_forwards_context_to_meta_generator(): void {
		$title_gen = $this->createMock( TitleGenerator::class );
		$title_gen->method( 'generate' )->willReturn( new WP_Error( 'skip', 'skip' ) );

		$captured_options = null;

		$meta_gen = $this->createMock( MetaDescriptionGenerator::class );
		$meta_gen->method( 'generate' )->willReturnCallback(
			function ( $_content, $options ) use ( &$captured_options ) {
				$captured_options = $options;

				return 'A 100-ish character SEO description that fits within typical SERP snippet windows and reads well.';
			}
		);

		$enhancer = new ItemEnhancer( $title_gen, $meta_gen );
		$item     = $this->make_item( 'My title', 'Body content.', array( 'wordpress', 'ai' ) );

		$enhancer->enhance( $item );

		$this->assertSame( 'My title', $captured_options['title'] );
		$this->assertSame( array( 'wordpress', 'ai' ), $captured_options['keywords'] );
	}

	/**
	 * Test enhance skips meta description when content is empty.
	 *
	 * @return void
	 */
	public function test_enhance_skips_meta_when_content_empty(): void {
		$title_gen = $this->createMock( TitleGenerator::class );
		$title_gen->method( 'generate' )->willReturn( new WP_Error( 'skip', 'skip' ) );

		$meta_gen = $this->createMock( MetaDescriptionGenerator::class );
		$meta_gen->expects( $this->never() )->method( 'generate' );

		$enhancer = new ItemEnhancer( $title_gen, $meta_gen );
		$item     = $this->make_item( 'Title', '   ' );

		$enhancer->enhance( $item );

		$this->assertArrayNotHasKey( ItemEnhancer::META_KEY_SEO_DESCRIPTION, $item->metadata );
	}

	/**
	 * Test enhance tolerates a meta generator failure silently.
	 *
	 * @return void
	 */
	public function test_enhance_meta_failure_is_non_fatal(): void {
		$title_gen = $this->createMock( TitleGenerator::class );
		$title_gen->method( 'generate' )->willReturn( new WP_Error( 'skip', 'skip' ) );

		$meta_gen = $this->createMock( MetaDescriptionGenerator::class );
		$meta_gen->method( 'generate' )->willReturn(
			new WP_Error( 'ai_meta_too_short', 'Too short.' )
		);

		$enhancer = new ItemEnhancer( $title_gen, $meta_gen );
		$item     = $this->make_item( 'Title', 'Body content.' );

		$enhancer->enhance( $item );

		$this->assertArrayNotHasKey( ItemEnhancer::META_KEY_SEO_DESCRIPTION, $item->metadata );
	}

	/**
	 * Test disabled title flag skips title generation.
	 *
	 * @return void
	 */
	public function test_title_flag_disables_title_generation(): void {
		$title_gen = $this->createMock( TitleGenerator::class );
		$title_gen->expects( $this->never() )->method( 'generate' );

		$meta_gen = $this->createMock( MetaDescriptionGenerator::class );
		$meta_gen->method( 'generate' )->willReturn( new WP_Error( 'skip', 'skip' ) );

		$enhancer = new ItemEnhancer( $title_gen, $meta_gen, array( 'title' => false ) );
		$item     = $this->make_item( null, 'Body content.' );

		$enhancer->enhance( $item );

		$this->assertNull( $item->title );
	}

	/**
	 * Test disabled meta flag skips meta description generation.
	 *
	 * @return void
	 */
	public function test_meta_flag_disables_meta_generation(): void {
		$title_gen = $this->createMock( TitleGenerator::class );
		$title_gen->method( 'generate' )->willReturn( new WP_Error( 'skip', 'skip' ) );

		$meta_gen = $this->createMock( MetaDescriptionGenerator::class );
		$meta_gen->expects( $this->never() )->method( 'generate' );

		$enhancer = new ItemEnhancer(
			$title_gen,
			$meta_gen,
			array( 'meta_description' => false )
		);
		$item = $this->make_item( 'Title', 'Body content.' );

		$enhancer->enhance( $item );

		$this->assertArrayNotHasKey( ItemEnhancer::META_KEY_SEO_DESCRIPTION, $item->metadata );
	}
}
