<?php
/**
 * InternalLinkSuggester class tests.
 *
 * @package AI_Importer\Tests\Unit\AI
 */

namespace AI_Importer\Tests\Unit\AI;

use AI_Importer\AI\AIService;
use AI_Importer\AI\InternalLinkSuggester;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;
use WP_Error;

/**
 * Tests for the InternalLinkSuggester class.
 */
class InternalLinkSuggesterTest extends TestCase {

	/**
	 * Set up shared WordPress function stubs.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof WP_Error
		);
		Functions\when( 'esc_url' )->alias(
			static fn( $url ) => (string) $url
		);
	}

	/**
	 * Sample candidate posts used across tests.
	 *
	 * @return array<int, array{id:int,title:string,url:string}>
	 */
	private function candidates(): array {
		return array(
			array(
				'id'    => 10,
				'title' => 'WordPress Performance',
				'url'   => 'https://example.com/performance',
			),
			array(
				'id'    => 20,
				'title' => 'AI Tooling',
				'url'   => 'https://example.com/ai-tooling',
			),
		);
	}

	/**
	 * Test enhance returns content unchanged when AI is unavailable.
	 *
	 * @return void
	 */
	public function test_enhance_returns_original_when_ai_unavailable(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'is_available' )->willReturn( false );
		$service->expects( $this->never() )->method( 'generate_structured' );

		$suggester = new InternalLinkSuggester( $service );
		$content   = '<p>Some content about performance.</p>';

		$this->assertSame( $content, $suggester->enhance( $content, $this->candidates() ) );
	}

	/**
	 * Test enhance returns content unchanged when there are no candidates.
	 *
	 * @return void
	 */
	public function test_enhance_returns_original_with_no_candidates(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'is_available' )->willReturn( true );
		$service->expects( $this->never() )->method( 'generate_structured' );

		$suggester = new InternalLinkSuggester( $service );
		$content   = '<p>Some content.</p>';

		$this->assertSame( $content, $suggester->enhance( $content, array() ) );
	}

	/**
	 * Test enhance links a verbatim anchor phrase to its target.
	 *
	 * @return void
	 */
	public function test_enhance_links_verbatim_anchor(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'is_available' )->willReturn( true );
		$service->method( 'generate_structured' )->willReturn(
			array(
				'links' => array(
					array(
						'anchor' => 'performance',
						'url'    => 'https://example.com/performance',
					),
				),
			)
		);

		$suggester = new InternalLinkSuggester( $service );
		$content   = '<p>Some content about performance and other topics.</p>';

		$result = $suggester->enhance( $content, $this->candidates() );

		$this->assertStringContainsString(
			'<a href="https://example.com/performance">performance</a>',
			$result
		);
	}

	/**
	 * Test apply ignores anchor phrases that do not occur in the content.
	 *
	 * @return void
	 */
	public function test_apply_ignores_non_occurring_anchor(): void {
		$service   = $this->createMock( AIService::class );
		$suggester = new InternalLinkSuggester( $service );

		$content = '<p>This text mentions nothing relevant.</p>';

		$result = $suggester->apply(
			$content,
			array(
				array(
					'anchor' => 'performance',
					'url'    => 'https://example.com/performance',
				),
			)
		);

		$this->assertSame( $content, $result );
	}

	/**
	 * Test apply only links the first occurrence of an anchor phrase.
	 *
	 * @return void
	 */
	public function test_apply_links_only_first_occurrence(): void {
		$service   = $this->createMock( AIService::class );
		$suggester = new InternalLinkSuggester( $service );

		$content = '<p>performance now and performance later.</p>';

		$result = $suggester->apply(
			$content,
			array(
				array(
					'anchor' => 'performance',
					'url'    => 'https://example.com/performance',
				),
			)
		);

		$this->assertSame( 1, substr_count( $result, '<a href=' ) );
		$this->assertStringContainsString(
			'<a href="https://example.com/performance">performance</a> now',
			$result
		);
	}

	/**
	 * Test apply respects the maximum link cap.
	 *
	 * @return void
	 */
	public function test_apply_respects_max_links_cap(): void {
		$service   = $this->createMock( AIService::class );
		$suggester = new InternalLinkSuggester( $service, 1 );

		$content = '<p>alpha and beta are both here.</p>';

		$result = $suggester->apply(
			$content,
			array(
				array(
					'anchor' => 'alpha',
					'url'    => 'https://example.com/performance',
				),
				array(
					'anchor' => 'beta',
					'url'    => 'https://example.com/ai-tooling',
				),
			)
		);

		$this->assertSame( 1, substr_count( $result, '<a href=' ) );
	}

	/**
	 * Test apply never links inside an existing anchor element.
	 *
	 * @return void
	 */
	public function test_apply_skips_text_inside_existing_anchor(): void {
		$service   = $this->createMock( AIService::class );
		$suggester = new InternalLinkSuggester( $service );

		// "performance" only appears inside an existing link.
		$content = '<p>See <a href="https://other.test/perf">performance</a> here.</p>';

		$result = $suggester->apply(
			$content,
			array(
				array(
					'anchor' => 'performance',
					'url'    => 'https://example.com/performance',
				),
			)
		);

		$this->assertSame( $content, $result );
	}

	/**
	 * Test suggest drops suggestions whose URL is not in the candidate set.
	 *
	 * @return void
	 */
	public function test_suggest_drops_unknown_urls(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			array(
				'links' => array(
					array(
						'anchor' => 'performance',
						'url'    => 'https://evil.test/inject',
					),
					array(
						'anchor' => 'tooling',
						'url'    => 'https://example.com/ai-tooling',
					),
				),
			)
		);

		$suggester = new InternalLinkSuggester( $service );

		$result = $suggester->suggest( 'content', $this->candidates() );

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.com/ai-tooling', $result[0]['url'] );
	}

	/**
	 * Test suggest propagates AIService errors.
	 *
	 * @return void
	 */
	public function test_suggest_propagates_service_error(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'generate_structured' )->willReturn(
			new WP_Error( 'ai_unavailable', 'No provider.' )
		);

		$suggester = new InternalLinkSuggester( $service );

		$result = $suggester->suggest( 'content', $this->candidates() );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test enhance returns the original content when suggestions yield no matches.
	 *
	 * @return void
	 */
	public function test_enhance_returns_original_when_no_matches(): void {
		$service = $this->createMock( AIService::class );
		$service->method( 'is_available' )->willReturn( true );
		$service->method( 'generate_structured' )->willReturn(
			array(
				'links' => array(
					array(
						'anchor' => 'not present here',
						'url'    => 'https://example.com/performance',
					),
				),
			)
		);

		$suggester = new InternalLinkSuggester( $service );
		$content   = '<p>Unrelated text body.</p>';

		$this->assertSame( $content, $suggester->enhance( $content, $this->candidates() ) );
	}
}
