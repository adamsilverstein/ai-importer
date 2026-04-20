<?php
/**
 * ContentCleaner class tests.
 *
 * @package AI_Importer\Tests\Unit\Processor
 */

namespace AI_Importer\Tests\Unit\Processor;

use AI_Importer\Processor\ContentCleaner;
use AI_Importer\Tests\Unit\TestCase;

/**
 * Tests for the ContentCleaner class.
 */
class ContentCleanerTest extends TestCase {

	/**
	 * Test clean returns empty string for empty input.
	 *
	 * @return void
	 */
	public function test_clean_returns_empty_string_for_empty_input(): void {
		$cleaner = new ContentCleaner();

		$this->assertSame( '', $cleaner->clean( '' ) );
		$this->assertSame( '', $cleaner->clean( '   ' ) );
	}

	/**
	 * Test clean strips zero-width characters by default.
	 *
	 * @return void
	 */
	public function test_clean_strips_zero_width_characters(): void {
		$cleaner = new ContentCleaner();

		// U+200B zero-width space, U+FEFF BOM, U+200C zero-width non-joiner.
		$input = "Hello\u{200B}world\u{FEFF}!\u{200C}";

		$this->assertSame( 'Helloworld!', $cleaner->clean( $input ) );
	}

	/**
	 * Test clean collapses runs of blank lines by default.
	 *
	 * @return void
	 */
	public function test_clean_collapses_blank_line_runs(): void {
		$cleaner = new ContentCleaner();

		$input    = "First paragraph.\n\n\n\nSecond paragraph.\n\n\n\n\nThird paragraph.";
		$expected = "First paragraph.\n\nSecond paragraph.\n\nThird paragraph.";

		$this->assertSame( $expected, $cleaner->clean( $input ) );
	}

	/**
	 * Test clean trims leading and trailing whitespace.
	 *
	 * @return void
	 */
	public function test_clean_trims_outer_whitespace(): void {
		$cleaner = new ContentCleaner();

		$input = "\n\n  Content here.  \n\n";

		$this->assertSame( 'Content here.', $cleaner->clean( $input ) );
	}

	/**
	 * Test clean strips trailing hashtag chains by default.
	 *
	 * @return void
	 */
	public function test_clean_strips_trailing_hashtag_chain(): void {
		$cleaner = new ContentCleaner();

		$input = "Great launch today! #WordPress #AI #PHP";

		$this->assertSame( 'Great launch today!', $cleaner->clean( $input ) );
	}

	/**
	 * Test clean strips trailing hashtag block on its own line.
	 *
	 * @return void
	 */
	public function test_clean_strips_trailing_hashtag_line(): void {
		$cleaner = new ContentCleaner();

		$input    = "Great launch today!\n\n#WordPress #AI #PHP";
		$expected = 'Great launch today!';

		$this->assertSame( $expected, $cleaner->clean( $input ) );
	}

	/**
	 * Test clean does not strip hashtags embedded mid-sentence.
	 *
	 * @return void
	 */
	public function test_clean_preserves_inline_hashtags(): void {
		$cleaner = new ContentCleaner();

		$input = 'Loving #WordPress right now — so good.';

		$this->assertSame( $input, $cleaner->clean( $input ) );
	}

	/**
	 * Test clean removes bare t.co URLs by default.
	 *
	 * @return void
	 */
	public function test_clean_removes_short_urls(): void {
		$cleaner = new ContentCleaner();

		$input    = 'Check it out https://t.co/abc123 it is great';
		$expected = 'Check it out it is great';

		$this->assertSame( $expected, $cleaner->clean( $input ) );
	}

	/**
	 * Test clean removes common short URL hosts.
	 *
	 * @return void
	 */
	public function test_clean_removes_multiple_short_url_hosts(): void {
		$cleaner = new ContentCleaner();

		$input    = 'See https://bit.ly/xyz and https://t.co/abc and https://buff.ly/q9 plus text';
		$expected = 'See and and plus text';

		$this->assertSame( $expected, $cleaner->clean( $input ) );
	}

	/**
	 * Test clean leaves long URLs alone.
	 *
	 * @return void
	 */
	public function test_clean_preserves_long_urls(): void {
		$cleaner = new ContentCleaner();

		$input = 'Docs at https://developer.wordpress.org/rest-api/ here.';

		$this->assertSame( $input, $cleaner->clean( $input ) );
	}

	/**
	 * Test clean can be opted out of hashtag stripping.
	 *
	 * @return void
	 */
	public function test_clean_can_disable_trailing_hashtag_strip(): void {
		$cleaner = new ContentCleaner();

		$input = 'Great launch today! #WordPress #AI';

		$this->assertSame(
			$input,
			$cleaner->clean( $input, array( 'strip_trailing_hashtags' => false ) )
		);
	}

	/**
	 * Test clean can be opted out of short URL stripping.
	 *
	 * @return void
	 */
	public function test_clean_can_disable_short_url_strip(): void {
		$cleaner = new ContentCleaner();

		$input = 'Check it https://t.co/abc123 out.';

		$this->assertSame(
			$input,
			$cleaner->clean( $input, array( 'strip_short_urls' => false ) )
		);
	}

	/**
	 * Test clean strips @ prefix from mentions when strip_mentions is enabled.
	 *
	 * @return void
	 */
	public function test_clean_strips_mention_prefix_when_enabled(): void {
		$cleaner = new ContentCleaner();

		$input    = 'Thanks @alice and @bob_42 for the help!';
		$expected = 'Thanks alice and bob_42 for the help!';

		$this->assertSame(
			$expected,
			$cleaner->clean( $input, array( 'strip_mentions' => true ) )
		);
	}

	/**
	 * Test clean leaves mentions alone by default.
	 *
	 * @return void
	 */
	public function test_clean_preserves_mentions_by_default(): void {
		$cleaner = new ContentCleaner();

		$input = 'Thanks @alice for the help!';

		$this->assertSame( $input, $cleaner->clean( $input ) );
	}

	/**
	 * Test clean does not alter plain email addresses when stripping mentions.
	 *
	 * @return void
	 */
	public function test_clean_does_not_touch_emails_when_stripping_mentions(): void {
		$cleaner = new ContentCleaner();

		$input = 'Email me at alice@example.com please.';

		$this->assertSame(
			$input,
			$cleaner->clean( $input, array( 'strip_mentions' => true ) )
		);
	}

	/**
	 * Test clean handles all cleanup steps together in a realistic input.
	 *
	 * @return void
	 */
	public function test_clean_end_to_end(): void {
		$cleaner = new ContentCleaner();

		$input = "  WordPress 7 launches native AI! \u{200B}\n\n\n\nRead more at https://t.co/xyz 🎉\n\n#WordPress #AI #Launch  ";

		$expected = "WordPress 7 launches native AI!\n\nRead more at 🎉";

		$this->assertSame( $expected, $cleaner->clean( $input ) );
	}
}
