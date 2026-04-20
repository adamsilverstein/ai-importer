<?php
/**
 * HashtagMapper class tests.
 *
 * @package AI_Importer\Tests\Unit\AI
 */

namespace AI_Importer\Tests\Unit\AI;

use AI_Importer\AI\AIService;
use AI_Importer\AI\HashtagMapper;
use AI_Importer\Tests\Unit\TestCase;
use WP_Error;

/**
 * Tests for the HashtagMapper class.
 */
class HashtagMapperTest extends TestCase {

	/**
	 * Test map returns WP_Error when no hashtags are supplied.
	 *
	 * @return void
	 */
	public function test_map_returns_error_when_input_is_empty(): void {
		$service = $this->createMock( AIService::class );
		$mapper  = new HashtagMapper( $service );

		$result = $mapper->map( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_hashtag_empty', $result->get_error_codes() );
	}

	/**
	 * Test map returns WP_Error when all hashtags are filtered out as invalid.
	 *
	 * @return void
	 */
	public function test_map_returns_error_when_all_hashtags_invalid(): void {
		$service = $this->createMock( AIService::class );
		$mapper  = new HashtagMapper( $service );

		$result = $mapper->map( array( '#', '  ', '#a', '123' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_hashtag_empty', $result->get_error_codes() );
	}

	/**
	 * Test map propagates AIService errors.
	 *
	 * @return void
	 */
	public function test_map_propagates_service_errors(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			new WP_Error( 'ai_unavailable', 'No provider configured.' )
		);

		$mapper = new HashtagMapper( $service );

		$result = $mapper->map( array( '#ClimateChange', '#WordPress' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_unavailable', $result->get_error_codes() );
	}

	/**
	 * Test map returns cleaned tags on success.
	 *
	 * @return void
	 */
	public function test_map_returns_cleaned_tags(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'tags' => array( 'Climate Change', 'WordPress' ) )
		);

		$mapper = new HashtagMapper( $service );

		$result = $mapper->map( array( '#ClimateChange', '#WordPress' ) );

		$this->assertSame( array( 'Climate Change', 'WordPress' ), $result );
	}

	/**
	 * Test map strips leading # before sending hashtags in the prompt.
	 *
	 * @return void
	 */
	public function test_map_strips_leading_hash_in_prompt(): void {
		$captured_prompt = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $schema ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;

				return array( 'tags' => array( 'Climate Change' ) );
			}
		);

		$mapper = new HashtagMapper( $service );
		$mapper->map( array( '#ClimateChange' ) );

		$this->assertStringContainsString( 'ClimateChange', $captured_prompt );
		$this->assertStringNotContainsString( '#ClimateChange', $captured_prompt );
	}

	/**
	 * Test map deduplicates input hashtags case-insensitively.
	 *
	 * @return void
	 */
	public function test_map_deduplicates_input_case_insensitively(): void {
		$captured_prompt = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $schema ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;

				return array( 'tags' => array( 'WordPress' ) );
			}
		);

		$mapper = new HashtagMapper( $service );
		$mapper->map( array( '#ClimateChange', '#climatechange', '#CLIMATECHANGE' ) );

		$this->assertSame( 1, substr_count( strtolower( $captured_prompt ), 'climatechange' ) );
	}

	/**
	 * Test map rejects a malformed response that lacks the tags array.
	 *
	 * @return void
	 */
	public function test_map_rejects_malformed_response(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'result' => 'nope' )
		);

		$mapper = new HashtagMapper( $service );

		$result = $mapper->map( array( '#ClimateChange' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_hashtag_malformed', $result->get_error_codes() );
	}

	/**
	 * Test map rejects a response where the tags field is not an array of strings.
	 *
	 * @return void
	 */
	public function test_map_rejects_response_with_non_string_tags(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'tags' => array( 'WordPress', 42, null ) )
		);

		$mapper = new HashtagMapper( $service );

		$result = $mapper->map( array( '#WordPress' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_hashtag_malformed', $result->get_error_codes() );
	}

	/**
	 * Test map filters empty strings out of a valid response.
	 *
	 * @return void
	 */
	public function test_map_filters_empty_strings_from_response(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'tags' => array( 'WordPress', '   ', 'Climate Change', '' ) )
		);

		$mapper = new HashtagMapper( $service );

		$result = $mapper->map( array( '#WordPress', '#ClimateChange' ) );

		$this->assertSame( array( 'WordPress', 'Climate Change' ), $result );
	}

	/**
	 * Test map caps the returned tags at the configured max.
	 *
	 * @return void
	 */
	public function test_map_caps_tags_at_max(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'tags' => array( 'A', 'B', 'C', 'D', 'E' ) )
		);

		$mapper = new HashtagMapper( $service );

		$result = $mapper->map( array( '#alpha', '#bravo', '#charlie', '#delta', '#echo' ), array( 'max_tags' => 3 ) );

		$this->assertSame( array( 'A', 'B', 'C' ), $result );
	}

	/**
	 * Test map includes post context in the prompt when provided.
	 *
	 * @return void
	 */
	public function test_map_includes_context_in_prompt(): void {
		$captured_prompt = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $schema ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;

				return array( 'tags' => array( 'WordPress' ) );
			}
		);

		$mapper = new HashtagMapper( $service );
		$mapper->map(
			array( '#WordPress' ),
			array( 'context' => 'Post about the WordPress 7 launch.' )
		);

		$this->assertStringContainsString( 'WordPress 7 launch', $captured_prompt );
	}

	/**
	 * Test map returns empty array when the AI returns an empty tags list.
	 *
	 * @return void
	 */
	public function test_map_returns_empty_array_when_ai_returns_no_tags(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'tags' => array() )
		);

		$mapper = new HashtagMapper( $service );

		$result = $mapper->map( array( '#wordpress' ) );

		$this->assertSame( array(), $result );
	}
}
