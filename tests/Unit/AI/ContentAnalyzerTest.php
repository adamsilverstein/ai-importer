<?php
/**
 * ContentAnalyzer class tests.
 *
 * @package AI_Importer\Tests\Unit\AI
 */

namespace AI_Importer\Tests\Unit\AI;

use AI_Importer\AI\AIService;
use AI_Importer\AI\ContentAnalyzer;
use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\Manifest\ManifestItem;
use AI_Importer\Tests\Unit\TestCase;
use DateTimeImmutable;
use WP_Error;

/**
 * Tests for the ContentAnalyzer class.
 */
class ContentAnalyzerTest extends TestCase {

	/**
	 * Sample items used across tests.
	 *
	 * @return array<ManifestItem>
	 */
	private function sample_items(): array {
		return array(
			new ManifestItem(
				id: 'tweet_1',
				type: ContentType::POST,
				title: 'Thoughts on WordPress 7',
				created_at: new DateTimeImmutable( '2026-01-15 10:00:00' ),
				excerpt: 'WordPress 7 introduces native AI capabilities.',
				metadata: array( 'favorite_count' => 120 ),
			),
			new ManifestItem(
				id: 'tweet_2',
				type: ContentType::POST,
				title: 'Quick thought',
				created_at: new DateTimeImmutable( '2026-01-16 12:00:00' ),
				excerpt: 'Coffee.',
				metadata: array( 'favorite_count' => 2 ),
			),
		);
	}

	/**
	 * Test analyze returns WP_Error when given empty items.
	 *
	 * @return void
	 */
	public function test_analyze_returns_error_on_empty_items(): void {
		$service  = $this->createMock( AIService::class );
		$analyzer = new ContentAnalyzer( $service );

		$result = $analyzer->analyze( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_analyze_empty', $result->get_error_codes() );
	}

	/**
	 * Test analyze returns WP_Error when AIService is unavailable.
	 *
	 * @return void
	 */
	public function test_analyze_returns_error_when_service_errors(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			new WP_Error( 'ai_unavailable', 'No provider.' )
		);

		$analyzer = new ContentAnalyzer( $service );

		$result = $analyzer->analyze( $this->sample_items() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_unavailable', $result->get_error_codes() );
	}

	/**
	 * Test analyze returns structured analysis on success.
	 *
	 * @return void
	 */
	public function test_analyze_returns_structured_analysis(): void {
		$expected = array(
			'content_types'        => array(
				'long_form'      => 1,
				'quick_thoughts' => 1,
			),
			'top_topics'           => array( 'wordpress', 'coffee' ),
			'writing_style'        => 'casual, technical',
			'suggested_categories' => array( 'Development', 'Thoughts' ),
			'high_value_content'   => array( 'tweet_1' ),
		);

		$service = $this->createMock( AIService::class );
		$service->expects( $this->once() )
			->method( 'generate_structured' )
			->willReturn( $expected );

		$analyzer = new ContentAnalyzer( $service );

		$result = $analyzer->analyze( $this->sample_items() );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Test analyze passes a valid schema covering the documented fields.
	 *
	 * @return void
	 */
	public function test_analyze_passes_expected_schema(): void {
		$captured_schema = null;
		$captured_prompt = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $schema ) use ( &$captured_prompt, &$captured_schema ) {
				$captured_prompt = $prompt;
				$captured_schema = $schema;

				return array(
					'content_types'        => array(),
					'top_topics'           => array(),
					'writing_style'        => '',
					'suggested_categories' => array(),
					'high_value_content'   => array(),
				);
			}
		);

		$analyzer = new ContentAnalyzer( $service );
		$analyzer->analyze( $this->sample_items() );

		$this->assertIsArray( $captured_schema );
		$this->assertSame( 'object', $captured_schema['type'] );
		$this->assertArrayHasKey( 'content_types', $captured_schema['properties'] );
		$this->assertArrayHasKey( 'top_topics', $captured_schema['properties'] );
		$this->assertArrayHasKey( 'writing_style', $captured_schema['properties'] );
		$this->assertArrayHasKey( 'suggested_categories', $captured_schema['properties'] );
		$this->assertArrayHasKey( 'high_value_content', $captured_schema['properties'] );

		$this->assertIsString( $captured_prompt );
		$this->assertStringContainsString( 'tweet_1', $captured_prompt );
		$this->assertStringContainsString( 'tweet_2', $captured_prompt );
	}

	/**
	 * Test analyze returns error when AI response misses required fields.
	 *
	 * @return void
	 */
	public function test_analyze_rejects_malformed_response(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'top_topics' => array( 'wordpress' ) )
		);

		$analyzer = new ContentAnalyzer( $service );

		$result = $analyzer->analyze( $this->sample_items() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_analyze_malformed', $result->get_error_codes() );
	}

	/**
	 * Test analyze respects the sample_size option by truncating items in the prompt.
	 *
	 * @return void
	 */
	public function test_analyze_truncates_to_sample_size(): void {
		$items = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$items[] = new ManifestItem(
				id: "item_$i",
				type: ContentType::POST,
				title: "Item $i",
				created_at: new DateTimeImmutable( '2026-01-01' ),
				excerpt: "Body $i",
			);
		}

		$captured_prompt = null;
		$service         = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $schema ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;

				return array(
					'content_types'        => array(),
					'top_topics'           => array(),
					'writing_style'        => '',
					'suggested_categories' => array(),
					'high_value_content'   => array(),
				);
			}
		);

		$analyzer = new ContentAnalyzer( $service );
		$analyzer->analyze( $items, array( 'sample_size' => 3 ) );

		$this->assertStringContainsString( 'item_1', $captured_prompt );
		$this->assertStringContainsString( 'item_3', $captured_prompt );
		$this->assertStringNotContainsString( 'item_4', $captured_prompt );
	}
}
