<?php
/**
 * MappingSuggester class tests.
 *
 * @package AI_Importer\Tests\Unit\AI
 */

namespace AI_Importer\Tests\Unit\AI;

use AI_Importer\AI\AIService;
use AI_Importer\AI\MappingSuggester;
use AI_Importer\Tests\Unit\TestCase;
use WP_Error;

/**
 * Tests for the MappingSuggester class.
 */
class MappingSuggesterTest extends TestCase {

	/**
	 * Sample analysis output (as produced by ContentAnalyzer).
	 *
	 * @return array<string, mixed>
	 */
	private function sample_analysis(): array {
		return array(
			'content_types'        => array(
				'long_form'      => 45,
				'quick_thoughts' => 892,
			),
			'top_topics'           => array( 'wordpress', 'javascript' ),
			'writing_style'        => 'technical but accessible',
			'suggested_categories' => array( 'Development', 'Tutorials' ),
			'high_value_content'   => array(),
		);
	}

	/**
	 * Sample site schema summary.
	 *
	 * @return array<string, mixed>
	 */
	private function sample_schema(): array {
		return array(
			'post_types' => array(
				array(
					'slug'   => 'post',
					'name'   => 'Posts',
					'public' => true,
				),
				array(
					'slug'   => 'tutorial',
					'name'   => 'Tutorials',
					'public' => true,
				),
			),
			'taxonomies' => array(
				array(
					'slug'       => 'category',
					'name'       => 'Categories',
					'post_types' => array( 'post', 'tutorial' ),
				),
				array(
					'slug'       => 'post_tag',
					'name'       => 'Tags',
					'post_types' => array( 'post' ),
				),
			),
		);
	}

	/**
	 * Sample valid response from the AI.
	 *
	 * @return array<string, mixed>
	 */
	private function sample_response(): array {
		return array(
			'post_type_mappings'      => array(
				array(
					'source_content_type'   => 'long_form',
					'destination_post_type' => 'tutorial',
					'reasoning'             => 'Long-form tech content fits Tutorials.',
				),
			),
			'taxonomy_mappings'       => array(
				array(
					'source_signal'        => 'hashtag #javascript',
					'destination_taxonomy' => 'category',
					'destination_terms'    => array( 'Development' ),
					'reasoning'            => 'Matches existing Development category.',
				),
			),
			'content_transformations' => array(),
			'summary'                 => 'Map long-form to tutorials, threads to posts.',
		);
	}

	/**
	 * Test suggest returns WP_Error when analysis is empty.
	 *
	 * @return void
	 */
	public function test_suggest_returns_error_on_empty_analysis(): void {
		$service   = $this->createMock( AIService::class );
		$suggester = new MappingSuggester( $service );

		$result = $suggester->suggest( array(), $this->sample_schema() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_mapping_empty_analysis', $result->get_error_codes() );
	}

	/**
	 * Test suggest returns WP_Error when site schema is empty.
	 *
	 * @return void
	 */
	public function test_suggest_returns_error_on_empty_schema(): void {
		$service   = $this->createMock( AIService::class );
		$suggester = new MappingSuggester( $service );

		$result = $suggester->suggest( $this->sample_analysis(), array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_mapping_empty_schema', $result->get_error_codes() );
	}

	/**
	 * Test suggest propagates AIService errors.
	 *
	 * @return void
	 */
	public function test_suggest_propagates_service_errors(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			new WP_Error( 'ai_unavailable', 'No provider configured.' )
		);

		$suggester = new MappingSuggester( $service );

		$result = $suggester->suggest( $this->sample_analysis(), $this->sample_schema() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_unavailable', $result->get_error_codes() );
	}

	/**
	 * Test suggest returns structured mappings on success.
	 *
	 * @return void
	 */
	public function test_suggest_returns_structured_mappings(): void {
		$expected = $this->sample_response();

		$service = $this->createMock( AIService::class );
		$service->expects( $this->once() )
			->method( 'generate_structured' )
			->willReturn( $expected );

		$suggester = new MappingSuggester( $service );

		$result = $suggester->suggest( $this->sample_analysis(), $this->sample_schema() );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Test suggest sends analysis and schema in the prompt and builds a usable schema.
	 *
	 * @return void
	 */
	public function test_suggest_sends_analysis_and_schema_to_model(): void {
		$captured_prompt = null;
		$captured_schema = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $schema ) use ( &$captured_prompt, &$captured_schema ) {
				$captured_prompt = $prompt;
				$captured_schema = $schema;

				return $this->sample_response();
			}
		);

		$suggester = new MappingSuggester( $service );
		$suggester->suggest( $this->sample_analysis(), $this->sample_schema() );

		$this->assertIsString( $captured_prompt );
		$this->assertStringContainsString( 'long_form', $captured_prompt );
		$this->assertStringContainsString( 'tutorial', $captured_prompt );

		$this->assertIsArray( $captured_schema );
		$this->assertSame( 'object', $captured_schema['type'] );
		$this->assertArrayHasKey( 'post_type_mappings', $captured_schema['properties'] );
		$this->assertArrayHasKey( 'taxonomy_mappings', $captured_schema['properties'] );
		$this->assertArrayHasKey( 'content_transformations', $captured_schema['properties'] );
		$this->assertArrayHasKey( 'summary', $captured_schema['properties'] );
	}

	/**
	 * Test suggest rejects malformed responses missing required fields.
	 *
	 * @return void
	 */
	public function test_suggest_rejects_malformed_response(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'post_type_mappings' => array() )
		);

		$suggester = new MappingSuggester( $service );

		$result = $suggester->suggest( $this->sample_analysis(), $this->sample_schema() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_mapping_malformed', $result->get_error_codes() );
	}

	/**
	 * Test suggest rejects responses where required fields have the wrong type.
	 *
	 * @return void
	 */
	public function test_suggest_rejects_wrong_field_types(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array(
				'post_type_mappings'      => array(),
				'taxonomy_mappings'       => array(),
				'content_transformations' => array(),
				'summary'                 => array( 'wrong', 'type' ), // Should be string.
			)
		);

		$suggester = new MappingSuggester( $service );

		$result = $suggester->suggest( $this->sample_analysis(), $this->sample_schema() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_mapping_malformed', $result->get_error_codes() );
	}
}
