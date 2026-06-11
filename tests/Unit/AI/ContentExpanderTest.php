<?php
/**
 * ContentExpander class tests.
 *
 * @package AI_Importer\Tests\Unit\AI
 */

namespace AI_Importer\Tests\Unit\AI;

use AI_Importer\AI\AIService;
use AI_Importer\AI\ContentExpander;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use WP_Error;

/**
 * Tests for the ContentExpander class.
 */
class ContentExpanderTest extends TestCase {

	/**
	 * Set up shared WordPress function stubs.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $text ) => trim( (string) preg_replace( '/<[^>]*>/', '', (string) $text ) )
		);
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof WP_Error
		);
	}

	/**
	 * Build a short body of the given word count.
	 *
	 * @param int $words Number of words.
	 * @return string Content.
	 */
	private function words( int $words ): string {
		return trim( str_repeat( 'word ', $words ) );
	}

	/**
	 * Test expand returns the original content when AI is unavailable.
	 *
	 * @return void
	 */
	public function test_expand_returns_original_when_ai_unavailable(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'is_available' )->willReturn( false );
		$service->expects( $this->never() )->method( 'generate_structured' );

		$expander = new ContentExpander( $service );
		$original = $this->words( 20 );

		$this->assertSame( $original, $expander->expand( $original ) );
	}

	/**
	 * Test expand leaves long content untouched and never calls the AI.
	 *
	 * @return void
	 */
	public function test_expand_leaves_long_content_untouched(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'is_available' )->willReturn( true );
		$service->expects( $this->never() )->method( 'generate_structured' );

		$expander = new ContentExpander( $service, 50 );
		$original = $this->words( 200 );

		$this->assertSame( $original, $expander->expand( $original ) );
	}

	/**
	 * Test expand returns expanded content for a short post when AI is available.
	 *
	 * @return void
	 */
	public function test_expand_returns_expanded_content_when_short(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'is_available' )->willReturn( true );
		$service->method( 'generate_structured' )->willReturn(
			array( 'content' => '<p>A much fuller article built from the short post.</p>' )
		);

		$expander = new ContentExpander( $service, 150 );

		$result = $expander->expand( $this->words( 20 ) );

		$this->assertSame( '<p>A much fuller article built from the short post.</p>', $result );
	}

	/**
	 * Test expand returns the original content when the provider errors.
	 *
	 * @return void
	 */
	public function test_expand_returns_original_on_ai_error(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'is_available' )->willReturn( true );
		$service->method( 'generate_structured' )->willReturn(
			new WP_Error( 'ai_unavailable', 'No provider.' )
		);

		$expander = new ContentExpander( $service );
		$original = $this->words( 20 );

		$this->assertSame( $original, $expander->expand( $original ) );
	}

	/**
	 * Test expand returns the original content on a malformed response.
	 *
	 * @return void
	 */
	public function test_expand_returns_original_on_malformed_response(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'is_available' )->willReturn( true );
		$service->method( 'generate_structured' )->willReturn(
			array( 'body' => 'wrong key' )
		);

		$expander = new ContentExpander( $service );
		$original = $this->words( 20 );

		$this->assertSame( $original, $expander->expand( $original ) );
	}

	/**
	 * Test expand returns the original content when the model returns an empty string.
	 *
	 * @return void
	 */
	public function test_expand_returns_original_on_empty_expansion(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'is_available' )->willReturn( true );
		$service->method( 'generate_structured' )->willReturn(
			array( 'content' => '   ' )
		);

		$expander = new ContentExpander( $service );
		$original = $this->words( 20 );

		$this->assertSame( $original, $expander->expand( $original ) );
	}

	/**
	 * Test expand returns empty content untouched without calling the AI.
	 *
	 * @return void
	 */
	public function test_expand_returns_empty_content_untouched(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'is_available' )->willReturn( true );
		$service->expects( $this->never() )->method( 'generate_structured' );

		$expander = new ContentExpander( $service );

		$this->assertSame( '   ', $expander->expand( '   ' ) );
	}

	/**
	 * Test the threshold boundary: content equal to the threshold is expanded.
	 *
	 * @return void
	 */
	public function test_is_short_respects_threshold_boundary(): void {
		$service = $this->createMock( AIService::class );

		$expander = new ContentExpander( $service, 10 );

		$this->assertTrue( $expander->is_short( $this->words( 10 ) ) );
		$this->assertFalse( $expander->is_short( $this->words( 11 ) ) );
	}

	/**
	 * Test the prompt instructs the model not to fabricate facts.
	 *
	 * @return void
	 */
	public function test_prompt_warns_against_fabrication(): void {
		$captured = null;

		$service = $this->createMock( AIService::class );
		$service->method( 'is_available' )->willReturn( true );
		$service->method( 'generate_structured' )->willReturnCallback(
			function ( $prompt, $_schema ) use ( &$captured ) {
				$captured = $prompt;
				return array( 'content' => '<p>Expanded.</p>' );
			}
		);

		$expander = new ContentExpander( $service );
		$expander->expand( $this->words( 20 ), array( 'title' => 'My title' ) );

		$this->assertStringContainsString( 'Do not fabricate', $captured );
		$this->assertStringContainsString( 'My title', $captured );
	}
}
