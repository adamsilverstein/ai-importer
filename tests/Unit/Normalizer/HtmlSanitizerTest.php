<?php
/**
 * HtmlSanitizer class tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Normalizer\HtmlSanitizer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the HtmlSanitizer class.
 */
class HtmlSanitizerTest extends TestCase {

	/**
	 * HtmlSanitizer instance.
	 *
	 * @var HtmlSanitizer
	 */
	private HtmlSanitizer $sanitizer;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->sanitizer = new HtmlSanitizer();
	}

	/**
	 * Test sanitize with clean HTML.
	 *
	 * @return void
	 */
	public function test_sanitize_clean_html(): void {
		$html = '<p>Hello <strong>World</strong>!</p>';

		Functions\expect( 'wp_kses_post' )
			->once()
			->with( $html )
			->andReturn( $html );

		$result = $this->sanitizer->sanitize( $html );

		$this->assertSame( $html, $result );
	}

	/**
	 * Test sanitize with empty string.
	 *
	 * @return void
	 */
	public function test_sanitize_empty_string(): void {
		$result = $this->sanitizer->sanitize( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test strip_scripts removes script tags.
	 *
	 * @return void
	 */
	public function test_strip_scripts_removes_script_tags(): void {
		$html     = '<p>Hello</p><script>alert("xss")</script><p>World</p>';
		$expected = '<p>Hello</p><p>World</p>';

		$result = $this->sanitizer->strip_scripts( $html );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Test strip_scripts removes style tags.
	 *
	 * @return void
	 */
	public function test_strip_scripts_removes_style_tags(): void {
		$html     = '<p>Hello</p><style>body { color: red; }</style><p>World</p>';
		$expected = '<p>Hello</p><p>World</p>';

		$result = $this->sanitizer->strip_scripts( $html );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Test strip_scripts removes event handlers.
	 *
	 * @return void
	 */
	public function test_strip_scripts_removes_event_handlers(): void {
		$html = '<img src="x" onerror="alert(1)" onclick="evil()">';

		$result = $this->sanitizer->strip_scripts( $html );

		$this->assertStringNotContainsString( 'onerror', $result );
		$this->assertStringNotContainsString( 'onclick', $result );
	}

	/**
	 * Test strip_scripts removes iframe tags.
	 *
	 * @return void
	 */
	public function test_strip_scripts_removes_iframes(): void {
		$html     = '<p>Hello</p><iframe src="evil.com"></iframe><p>World</p>';
		$expected = '<p>Hello</p><p>World</p>';

		$result = $this->sanitizer->strip_scripts( $html );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Test convert_line_breaks with wpautop.
	 *
	 * @return void
	 */
	public function test_convert_line_breaks_uses_wpautop(): void {
		$text     = "Hello\n\nWorld";
		$expected = "<p>Hello</p>\n<p>World</p>\n";

		Functions\expect( 'wpautop' )
			->once()
			->with( $text )
			->andReturn( $expected );

		$result = $this->sanitizer->convert_line_breaks( $text );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Test convert_line_breaks with empty string.
	 *
	 * @return void
	 */
	public function test_convert_line_breaks_empty_string(): void {
		$result = $this->sanitizer->convert_line_breaks( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test fix_encoding with valid UTF-8.
	 *
	 * @return void
	 */
	public function test_fix_encoding_valid_utf8(): void {
		$text = 'Hello World';

		$result = $this->sanitizer->fix_encoding( $text );

		$this->assertSame( $text, $result );
	}

	/**
	 * Test fix_encoding replaces non-breaking spaces.
	 *
	 * @return void
	 */
	public function test_fix_encoding_replaces_nbsp(): void {
		$text = "Hello\xC2\xA0World";

		$result = $this->sanitizer->fix_encoding( $text );

		$this->assertSame( 'Hello World', $result );
	}

	/**
	 * Test fix_encoding with empty string.
	 *
	 * @return void
	 */
	public function test_fix_encoding_empty_string(): void {
		$result = $this->sanitizer->fix_encoding( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test remove_tracking_params removes UTM parameters.
	 *
	 * @return void
	 */
	public function test_remove_tracking_params_removes_utm(): void {
		Functions\expect( 'wp_parse_url' )
			->andReturnUsing(
				function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				}
			);

		$url      = 'https://example.com/page?foo=bar&utm_source=twitter&utm_medium=social';
		$expected = 'https://example.com/page?foo=bar';

		$result = $this->sanitizer->remove_tracking_params( $url );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Test remove_tracking_params removes Facebook tracking.
	 *
	 * @return void
	 */
	public function test_remove_tracking_params_removes_fbclid(): void {
		Functions\expect( 'wp_parse_url' )
			->andReturnUsing(
				function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				}
			);

		$url      = 'https://example.com/page?fbclid=abc123';
		$expected = 'https://example.com/page';

		$result = $this->sanitizer->remove_tracking_params( $url );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Test remove_tracking_params with empty URL.
	 *
	 * @return void
	 */
	public function test_remove_tracking_params_empty_url(): void {
		$result = $this->sanitizer->remove_tracking_params( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test extract_text removes HTML tags.
	 *
	 * @return void
	 */
	public function test_extract_text_removes_html(): void {
		$html = '<p>Hello <strong>World</strong>!</p>';

		Functions\expect( 'wp_strip_all_tags' )
			->once()
			->andReturn( 'Hello World!' );

		$result = $this->sanitizer->extract_text( $html );

		$this->assertSame( 'Hello World!', $result );
	}

	/**
	 * Test extract_text with empty string.
	 *
	 * @return void
	 */
	public function test_extract_text_empty_string(): void {
		$result = $this->sanitizer->extract_text( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test normalize_whitespace.
	 *
	 * @return void
	 */
	public function test_normalize_whitespace(): void {
		$text = "Hello    World\n\n\n\nTest";

		$result = $this->sanitizer->normalize_whitespace( $text );

		$this->assertStringContainsString( 'Hello World', $result );
		$this->assertStringNotContainsString( "\n\n\n", $result );
	}

	/**
	 * Test linkify_urls with make_clickable.
	 *
	 * @return void
	 */
	public function test_linkify_urls_uses_make_clickable(): void {
		$text     = 'Check out https://example.com for more info';
		$expected = 'Check out <a href="https://example.com">https://example.com</a> for more info';

		Functions\expect( 'make_clickable' )
			->once()
			->with( $text )
			->andReturn( $expected );

		$result = $this->sanitizer->linkify_urls( $text );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Test linkify_urls with empty string.
	 *
	 * @return void
	 */
	public function test_linkify_urls_empty_string(): void {
		$result = $this->sanitizer->linkify_urls( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test linkify_hashtags.
	 *
	 * @return void
	 */
	public function test_linkify_hashtags(): void {
		$text     = 'Hello #world #test';
		$base_url = 'https://twitter.com/hashtag/';

		Functions\stubEscapeFunctions();

		$result = $this->sanitizer->linkify_hashtags( $text, $base_url );

		$this->assertStringContainsString( 'href="https://twitter.com/hashtag/world"', $result );
		$this->assertStringContainsString( 'href="https://twitter.com/hashtag/test"', $result );
	}

	/**
	 * Test linkify_hashtags with empty string.
	 *
	 * @return void
	 */
	public function test_linkify_hashtags_empty_string(): void {
		$result = $this->sanitizer->linkify_hashtags( '', 'https://example.com/' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test linkify_mentions.
	 *
	 * @return void
	 */
	public function test_linkify_mentions(): void {
		$text     = 'Hello @john and @jane';
		$base_url = 'https://twitter.com/';

		Functions\stubEscapeFunctions();

		$result = $this->sanitizer->linkify_mentions( $text, $base_url );

		$this->assertStringContainsString( 'href="https://twitter.com/john"', $result );
		$this->assertStringContainsString( 'href="https://twitter.com/jane"', $result );
	}

	/**
	 * Test linkify_mentions with empty string.
	 *
	 * @return void
	 */
	public function test_linkify_mentions_empty_string(): void {
		$result = $this->sanitizer->linkify_mentions( '', 'https://example.com/' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test extract_urls extracts href attributes.
	 *
	 * @return void
	 */
	public function test_extract_urls_extracts_hrefs(): void {
		$html = '<a href="https://example.com">Link</a><a href="https://test.com">Test</a>';

		$result = $this->sanitizer->extract_urls( $html );

		$this->assertContains( 'https://example.com', $result );
		$this->assertContains( 'https://test.com', $result );
	}

	/**
	 * Test extract_urls extracts src attributes.
	 *
	 * @return void
	 */
	public function test_extract_urls_extracts_srcs(): void {
		$html = '<img src="https://example.com/image.jpg"><video src="https://test.com/video.mp4">';

		$result = $this->sanitizer->extract_urls( $html );

		$this->assertContains( 'https://example.com/image.jpg', $result );
		$this->assertContains( 'https://test.com/video.mp4', $result );
	}

	/**
	 * Test extract_urls with empty string.
	 *
	 * @return void
	 */
	public function test_extract_urls_empty_string(): void {
		$result = $this->sanitizer->extract_urls( '' );
		$this->assertSame( array(), $result );
	}

	/**
	 * Test extract_urls filters invalid URLs.
	 *
	 * @return void
	 */
	public function test_extract_urls_filters_invalid(): void {
		$html = '<a href="javascript:alert(1)">Bad</a><a href="https://valid.com">Good</a>';

		$result = $this->sanitizer->extract_urls( $html );

		$this->assertContains( 'https://valid.com', $result );
		$this->assertNotContains( 'javascript:alert(1)', $result );
	}
}
