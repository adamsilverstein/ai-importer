<?php
/**
 * AltTextGenerator class tests.
 *
 * @package AI_Importer\Tests\Unit\AI
 */

namespace AI_Importer\Tests\Unit\AI;

use AI_Importer\AI\AIService;
use AI_Importer\AI\AltTextGenerator;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use WP_Error;

/**
 * Tests for the AltTextGenerator class.
 */
class AltTextGeneratorTest extends TestCase {

	/**
	 * Set up shared stubs.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	/**
	 * Test generate returns WP_Error when URL is empty.
	 *
	 * @return void
	 */
	public function test_generate_returns_error_on_empty_url(): void {
		$service   = $this->createMock( AIService::class );
		$generator = new AltTextGenerator( $service );

		$result = $generator->generate( '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_alt_text_empty_url', $result->get_error_codes() );
	}

	/**
	 * Test generate returns WP_Error on clearly invalid URL.
	 *
	 * @return void
	 */
	public function test_generate_returns_error_on_invalid_url(): void {
		$service   = $this->createMock( AIService::class );
		$generator = new AltTextGenerator( $service );

		$result = $generator->generate( 'not-a-url' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_alt_text_invalid_url', $result->get_error_codes() );
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

		$generator = new AltTextGenerator( $service );

		$result = $generator->generate( 'https://example.com/cat.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_unavailable', $result->get_error_codes() );
	}

	/**
	 * Test generate returns alt text on success.
	 *
	 * @return void
	 */
	public function test_generate_returns_alt_text(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'alt_text' => 'A tabby cat sitting on a windowsill.' )
		);

		$generator = new AltTextGenerator( $service );

		$result = $generator->generate( 'https://example.com/cat.jpg' );

		$this->assertSame( 'A tabby cat sitting on a windowsill.', $result );
	}

	/**
	 * Test generate includes image URL in prompt.
	 *
	 * @return void
	 */
	public function test_generate_includes_url_in_prompt(): void {
		$captured_prompt = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $schema ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;

				return array( 'alt_text' => 'description' );
			}
		);

		$generator = new AltTextGenerator( $service );
		$generator->generate( 'https://example.com/photo.png' );

		$this->assertStringContainsString( 'https://example.com/photo.png', $captured_prompt );
	}

	/**
	 * Test generate includes context option in the prompt when provided.
	 *
	 * @return void
	 */
	public function test_generate_includes_context(): void {
		$captured_prompt = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $schema ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;

				return array( 'alt_text' => 'description' );
			}
		);

		$generator = new AltTextGenerator( $service );
		$generator->generate(
			'https://example.com/photo.png',
			array( 'context' => 'Post about WordPress 7 launch.' )
		);

		$this->assertStringContainsString( 'WordPress 7 launch', $captured_prompt );
	}

	/**
	 * Test generate rejects malformed responses missing the alt_text field.
	 *
	 * @return void
	 */
	public function test_generate_rejects_malformed_response(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'description' => 'A cat.' )
		);

		$generator = new AltTextGenerator( $service );

		$result = $generator->generate( 'https://example.com/cat.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_alt_text_malformed', $result->get_error_codes() );
	}

	/**
	 * Test generate returns WP_Error when response alt_text is empty.
	 *
	 * @return void
	 */
	public function test_generate_rejects_empty_alt_text(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'alt_text' => '' )
		);

		$generator = new AltTextGenerator( $service );

		$result = $generator->generate( 'https://example.com/cat.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_alt_text_empty', $result->get_error_codes() );
	}

	/**
	 * Test generate rejects non-http(s) URL schemes.
	 *
	 * @return void
	 */
	public function test_generate_rejects_non_http_schemes(): void {
		$service   = $this->createMock( AIService::class );
		$generator = new AltTextGenerator( $service );

		$result = $generator->generate( 'ftp://example.com/cat.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_alt_text_invalid_url', $result->get_error_codes() );
	}

	/**
	 * Test generate rejects responses longer than the max length.
	 *
	 * @return void
	 */
	public function test_generate_rejects_overly_long_alt_text(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'alt_text' => str_repeat( 'a', 200 ) )
		);

		$generator = new AltTextGenerator( $service );

		$result = $generator->generate( 'https://example.com/cat.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_alt_text_too_long', $result->get_error_codes() );
	}
}
