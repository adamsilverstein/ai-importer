<?php
/**
 * TitleGenerator class tests.
 *
 * @package AI_Importer\Tests\Unit\AI
 */

namespace AI_Importer\Tests\Unit\AI;

use AI_Importer\AI\AIService;
use AI_Importer\AI\TitleGenerator;
use AI_Importer\Tests\Unit\TestCase;
use WP_Error;

/**
 * Tests for the TitleGenerator class.
 */
class TitleGeneratorTest extends TestCase {

	/**
	 * Test generate returns WP_Error on empty content.
	 *
	 * @return void
	 */
	public function test_generate_returns_error_on_empty_content(): void {
		$service   = $this->createMock( AIService::class );
		$generator = new TitleGenerator( $service );

		$result = $generator->generate( '   ' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_title_empty_content', $result->get_error_codes() );
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

		$generator = new TitleGenerator( $service );

		$result = $generator->generate( 'Some post content.' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_unavailable', $result->get_error_codes() );
	}

	/**
	 * Test generate returns the title on success.
	 *
	 * @return void
	 */
	public function test_generate_returns_title(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'title' => 'WordPress 7 launches native AI capabilities' )
		);

		$generator = new TitleGenerator( $service );

		$result = $generator->generate( 'WordPress 7 has shipped with wp_get_ai_client...' );

		$this->assertSame( 'WordPress 7 launches native AI capabilities', $result );
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

				return array( 'title' => 'Some title' );
			}
		);

		$generator = new TitleGenerator( $service );
		$generator->generate( 'UNIQUE_MARKER_STRING_xyz content here.' );

		$this->assertStringContainsString( 'UNIQUE_MARKER_STRING_xyz', $captured_prompt );
	}

	/**
	 * Test generate includes a style hint when provided.
	 *
	 * @return void
	 */
	public function test_generate_includes_style_option(): void {
		$captured_prompt = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $_schema ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;

				return array( 'title' => 'Headline-style title' );
			}
		);

		$generator = new TitleGenerator( $service );
		$generator->generate( 'Body content.', array( 'style' => 'news' ) );

		$this->assertStringContainsString( 'news', $captured_prompt );
	}

	/**
	 * Test generate rejects responses missing title field.
	 *
	 * @return void
	 */
	public function test_generate_rejects_malformed_response(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'headline' => 'Hello' )
		);

		$generator = new TitleGenerator( $service );

		$result = $generator->generate( 'Body content.' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_title_malformed', $result->get_error_codes() );
	}

	/**
	 * Test generate rejects empty title.
	 *
	 * @return void
	 */
	public function test_generate_rejects_empty_title(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'title' => '   ' )
		);

		$generator = new TitleGenerator( $service );

		$result = $generator->generate( 'Body content.' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_title_empty', $result->get_error_codes() );
	}

	/**
	 * Test generate rejects overly long titles.
	 *
	 * @return void
	 */
	public function test_generate_rejects_overly_long_title(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array( 'title' => str_repeat( 'a', 100 ) )
		);

		$generator = new TitleGenerator( $service );

		$result = $generator->generate( 'Body content.' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'ai_title_too_long', $result->get_error_codes() );
	}
}
