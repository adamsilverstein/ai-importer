<?php
/**
 * MetaDescriptionGenerator class tests.
 *
 * @package AI_Importer\Tests\Unit\AI
 */

namespace AI_Importer\Tests\Unit\AI;

use AI_Importer\AI\AIService;
use AI_Importer\AI\MetaDescriptionGenerator;
use AI_Importer\Tests\Unit\TestCase;
use WP_Error;

/**
 * Tests for the MetaDescriptionGenerator class.
 */
class MetaDescriptionGeneratorTest extends TestCase {

	/**
	 * A valid-length description for reuse across tests (exactly 100 chars).
	 *
	 * @var string
	 */
	private string $valid_description = 'A compelling, SEO-friendly summary of the post content that sits safely within the SERP snippet.';

	/**
	 * Test generate returns WP_Error on empty content.
	 *
	 * @return void
	 */
	public function test_generate_returns_error_on_empty_content(): void {
		$service   = $this->createMock( AIService::class );
		$generator = new MetaDescriptionGenerator( $service );

		$result = $generator->generate( '   ' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_meta_empty_content', $result->get_error_codes() );
	}

	/**
	 * Test generate propagates AIService errors.
	 *
	 * @return void
	 */
	public function test_generate_propagates_service_errors(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			new WP_Error( 'ai_unavailable', 'No provider configured.' )
		);

		$generator = new MetaDescriptionGenerator( $service );

		$result = $generator->generate( 'Some post content long enough.' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_unavailable', $result->get_error_codes() );
	}

	/**
	 * Test generate returns the description on success.
	 *
	 * @return void
	 */
	public function test_generate_returns_description(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'description' => $this->valid_description )
		);

		$generator = new MetaDescriptionGenerator( $service );

		$result = $generator->generate( 'Body content for the post.' );

		$this->assertSame( $this->valid_description, $result );
	}

	/**
	 * Test generate forwards content into the prompt.
	 *
	 * @return void
	 */
	public function test_generate_includes_content_in_prompt(): void {
		$captured_prompt = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $_schema ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;

				return array( 'description' => $this->valid_description );
			}
		);

		$generator = new MetaDescriptionGenerator( $service );
		$generator->generate( 'UNIQUE_MARKER_abc content about the launch.' );

		$this->assertStringContainsString( 'UNIQUE_MARKER_abc', $captured_prompt );
	}

	/**
	 * Test generate includes title when provided.
	 *
	 * @return void
	 */
	public function test_generate_includes_title(): void {
		$captured_prompt = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $_schema ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;

				return array( 'description' => $this->valid_description );
			}
		);

		$generator = new MetaDescriptionGenerator( $service );
		$generator->generate( 'Body.', array( 'title' => 'My Awesome Headline' ) );

		$this->assertStringContainsString( 'My Awesome Headline', $captured_prompt );
	}

	/**
	 * Test generate includes keywords when provided as array.
	 *
	 * @return void
	 */
	public function test_generate_includes_keywords_array(): void {
		$captured_prompt = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $_schema ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;

				return array( 'description' => $this->valid_description );
			}
		);

		$generator = new MetaDescriptionGenerator( $service );
		$generator->generate(
			'Body.',
			array( 'keywords' => array( 'wordpress', 'ai', 'import' ) )
		);

		$this->assertStringContainsString( 'wordpress', $captured_prompt );
		$this->assertStringContainsString( 'ai', $captured_prompt );
		$this->assertStringContainsString( 'import', $captured_prompt );
	}

	/**
	 * Test generate accepts a comma-delimited keywords string.
	 *
	 * @return void
	 */
	public function test_generate_accepts_keywords_string(): void {
		$captured_prompt = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $_schema ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;

				return array( 'description' => $this->valid_description );
			}
		);

		$generator = new MetaDescriptionGenerator( $service );
		$generator->generate(
			'Body.',
			array( 'keywords' => ' gutenberg, blocks ,rest ' )
		);

		$this->assertStringContainsString( 'gutenberg', $captured_prompt );
		$this->assertStringContainsString( 'blocks', $captured_prompt );
		$this->assertStringContainsString( 'rest', $captured_prompt );
	}

	/**
	 * Test generate rejects responses missing the description field.
	 *
	 * @return void
	 */
	public function test_generate_rejects_malformed_response(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'meta' => 'not the right key' )
		);

		$generator = new MetaDescriptionGenerator( $service );

		$result = $generator->generate( 'Body content.' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_meta_malformed', $result->get_error_codes() );
	}

	/**
	 * Test generate rejects an empty description string.
	 *
	 * @return void
	 */
	public function test_generate_rejects_empty_description(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'description' => '   ' )
		);

		$generator = new MetaDescriptionGenerator( $service );

		$result = $generator->generate( 'Body content.' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_meta_empty', $result->get_error_codes() );
	}

	/**
	 * Test generate rejects a description shorter than min length.
	 *
	 * @return void
	 */
	public function test_generate_rejects_too_short_description(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'description' => 'Too short.' )
		);

		$generator = new MetaDescriptionGenerator( $service );

		$result = $generator->generate( 'Body content.' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_meta_too_short', $result->get_error_codes() );
	}

	/**
	 * Test generate rejects an overly long description.
	 *
	 * @return void
	 */
	public function test_generate_rejects_too_long_description(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'description' => str_repeat( 'a', 200 ) )
		);

		$generator = new MetaDescriptionGenerator( $service );

		$result = $generator->generate( 'Body content.' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_meta_too_long', $result->get_error_codes() );
	}
}
