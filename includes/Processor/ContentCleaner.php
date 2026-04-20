<?php
/**
 * Content cleaner.
 *
 * @package AI_Importer\Processor
 */

namespace AI_Importer\Processor;

/**
 * Strips platform-specific cruft from imported content.
 *
 * Local (regex/string-based) cleanup — no API calls. Intended to be
 * applied to NormalizedItem content before it becomes a WordPress post
 * so that social-media artifacts (short URLs, trailing hashtag chains,
 * zero-width characters, excessive blank lines) don't leak into the
 * final article.
 */
class ContentCleaner {

	/**
	 * Short URL hosts commonly injected by social platforms.
	 *
	 * @var array<string>
	 */
	private const SHORT_URL_HOSTS = array(
		't.co',
		'bit.ly',
		'buff.ly',
		'ow.ly',
		'goo.gl',
		'tinyurl.com',
		'ift.tt',
		'dlvr.it',
	);

	/**
	 * Characters to strip (zero-width and BOM family).
	 *
	 * @var array<string>
	 */
	private const INVISIBLE_CHARS = array(
		"\u{200B}", // Zero-width space.
		"\u{200C}", // Zero-width non-joiner.
		"\u{200D}", // Zero-width joiner.
		"\u{FEFF}", // Byte-order mark.
		"\u{2060}", // Word joiner.
	);

	/**
	 * Clean a content string.
	 *
	 * @param string               $content Raw content.
	 * @param array<string, mixed> $options Options:
	 *                                      - 'strip_trailing_hashtags' (bool, default true)
	 *                                      - 'strip_short_urls'        (bool, default true)
	 *                                      - 'strip_mentions'          (bool, default false)
	 *                                      - 'collapse_whitespace'     (bool, default true).
	 * @return string Cleaned content.
	 */
	public function clean( string $content, array $options = array() ): string {
		$opts = array(
			'strip_trailing_hashtags' => (bool) ( $options['strip_trailing_hashtags'] ?? true ),
			'strip_short_urls'        => (bool) ( $options['strip_short_urls'] ?? true ),
			'strip_mentions'          => (bool) ( $options['strip_mentions'] ?? false ),
			'collapse_whitespace'     => (bool) ( $options['collapse_whitespace'] ?? true ),
		);

		$content = str_replace( self::INVISIBLE_CHARS, '', $content );

		if ( $opts['strip_short_urls'] ) {
			$content = $this->strip_short_urls( $content );
		}

		if ( $opts['strip_mentions'] ) {
			$content = $this->strip_mentions( $content );
		}

		if ( $opts['strip_trailing_hashtags'] ) {
			$content = $this->strip_trailing_hashtags( $content );
		}

		if ( $opts['collapse_whitespace'] ) {
			$content = $this->collapse_whitespace( $content );
		}

		return trim( $content );
	}

	/**
	 * Remove standalone short-URL occurrences.
	 *
	 * @param string $content Content.
	 * @return string Content with short URLs removed.
	 */
	private function strip_short_urls( string $content ): string {
		$hosts   = array_map( 'preg_quote', self::SHORT_URL_HOSTS );
		$pattern = '#https?://(?:' . implode( '|', $hosts ) . ')/[^\s<]*#i';

		return (string) preg_replace( $pattern, '', $content );
	}

	/**
	 * Strip the '@' prefix from handles while leaving email addresses alone.
	 *
	 * Matches an '@' only when it is preceded by whitespace or start-of-string
	 * (so emails like "alice@example.com" remain intact).
	 *
	 * @param string $content Content.
	 * @return string Content with mention prefixes stripped.
	 */
	private function strip_mentions( string $content ): string {
		$pattern = '/(^|\s)@([A-Za-z0-9_]{1,30})/';

		return (string) preg_replace( $pattern, '$1$2', $content );
	}

	/**
	 * Remove a trailing run of hashtags at the end of the content.
	 *
	 * Matches one or more '#tag' tokens at the very end of the string,
	 * optionally separated from the body by whitespace or newlines.
	 *
	 * @param string $content Content.
	 * @return string Content with the trailing hashtag run removed.
	 */
	private function strip_trailing_hashtags( string $content ): string {
		$pattern = '/(?:\s*#[A-Za-z0-9_]+)+\s*$/u';

		return (string) preg_replace( $pattern, '', $content );
	}

	/**
	 * Collapse runs of 3+ newlines into exactly two and trim per-line whitespace.
	 *
	 * @param string $content Content.
	 * @return string Content with normalized whitespace.
	 */
	private function collapse_whitespace( string $content ): string {
		$content = str_replace( array( "\r\n", "\r" ), "\n", $content );
		$content = (string) preg_replace( "/[ \t]+/", ' ', $content );
		$content = (string) preg_replace( "/ *\n */", "\n", $content );
		$content = (string) preg_replace( "/\n{3,}/", "\n\n", $content );

		return $content;
	}
}
