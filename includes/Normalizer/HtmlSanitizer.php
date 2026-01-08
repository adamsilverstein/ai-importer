<?php
/**
 * HTML sanitizer utility class.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Normalizer;

/**
 * Sanitizes and cleans HTML content for safe use in WordPress.
 */
class HtmlSanitizer {

	/**
	 * Tracking parameters to remove from URLs.
	 *
	 * @var array<string>
	 */
	private const TRACKING_PARAMS = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'fbclid',
		'gclid',
		'ref',
		'ref_src',
		'ref_url',
		's', // Twitter share parameter.
		't', // Twitter share parameter.
	);

	/**
	 * Sanitize HTML content for WordPress.
	 *
	 * Removes scripts, unsafe attributes, and applies WordPress sanitization.
	 *
	 * @param string $html The HTML content to sanitize.
	 * @return string The sanitized HTML.
	 */
	public function sanitize( string $html ): string {
		if ( empty( $html ) ) {
			return '';
		}

		// First strip any script/style tags and their content.
		$html = $this->strip_scripts( $html );

		// Fix any encoding issues.
		$html = $this->fix_encoding( $html );

		// Use WordPress's kses to sanitize.
		$html = wp_kses_post( $html );

		// Clean up extra whitespace.
		$html = $this->normalize_whitespace( $html );

		return $html;
	}

	/**
	 * Strip script, style, and other dangerous tags along with their content.
	 *
	 * @param string $html The HTML to clean.
	 * @return string The cleaned HTML.
	 */
	public function strip_scripts( string $html ): string {
		// Remove script tags and content.
		$html = (string) preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $html );

		// Remove style tags and content.
		$html = (string) preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $html );

		// Remove noscript tags and content.
		$html = (string) preg_replace( '/<noscript\b[^>]*>.*?<\/noscript>/is', '', $html );

		// Remove iframe tags.
		$html = (string) preg_replace( '/<iframe\b[^>]*>.*?<\/iframe>/is', '', $html );

		// Remove object/embed tags.
		$html = (string) preg_replace( '/<object\b[^>]*>.*?<\/object>/is', '', $html );
		$html = (string) preg_replace( '/<embed\b[^>]*\/?>/is', '', $html );

		// Remove event handlers (onclick, onerror, etc.).
		$html = (string) preg_replace( '/\s*on\w+\s*=\s*["\'][^"\']*["\']/', '', $html );
		$html = (string) preg_replace( '/\s*on\w+\s*=\s*\S+/', '', $html );

		return $html;
	}

	/**
	 * Convert plain text line breaks to HTML paragraphs.
	 *
	 * @param string $text The text to convert.
	 * @return string The HTML with paragraph tags.
	 */
	public function convert_line_breaks( string $text ): string {
		if ( empty( $text ) ) {
			return '';
		}

		// Use WordPress's autop function if available.
		if ( function_exists( 'wpautop' ) ) {
			return wpautop( $text );
		}

		// Fallback: Simple conversion.
		$paragraphs = preg_split( '/\n\s*\n/', $text );
		if ( ! is_array( $paragraphs ) ) {
			return '<p>' . nl2br( $text ) . '</p>';
		}

		$paragraphs = array_filter( array_map( 'trim', $paragraphs ) );

		if ( empty( $paragraphs ) ) {
			return '<p>' . nl2br( $text ) . '</p>';
		}

		return '<p>' . implode( "</p>\n<p>", $paragraphs ) . '</p>';
	}

	/**
	 * Fix character encoding issues in text.
	 *
	 * @param string $text The text to fix.
	 * @return string The text with fixed encoding.
	 */
	public function fix_encoding( string $text ): string {
		if ( empty( $text ) ) {
			return '';
		}

		// Convert to UTF-8 if needed.
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'auto' );
		}

		// Fix common encoding issues.
		$replacements = array(
			"\xC2\xA0"     => ' ',       // Non-breaking space.
			"\xE2\x80\x99" => "'",   // Right single quote.
			"\xE2\x80\x98" => "'",   // Left single quote.
			"\xE2\x80\x9C" => '"',   // Left double quote.
			"\xE2\x80\x9D" => '"',   // Right double quote.
			"\xE2\x80\x93" => '-',   // En dash.
			"\xE2\x80\x94" => '--',  // Em dash.
			"\xE2\x80\xA6" => '...', // Ellipsis.
		);

		foreach ( $replacements as $search => $replace ) {
			$text = str_replace( $search, $replace, $text );
		}

		// Only replace non-breaking spaces while preserving other Unicode characters.
		$text = str_replace( "\xC2\xA0", ' ', $text );

		// Remove null bytes and other control characters (except newlines and tabs).
		$result = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		return is_string( $result ) ? $result : $text;
	}

	/**
	 * Remove tracking parameters from a URL.
	 *
	 * @param string $url The URL to clean.
	 * @return string The cleaned URL.
	 */
	public function remove_tracking_params( string $url ): string {
		if ( empty( $url ) ) {
			return '';
		}

		$parsed = wp_parse_url( $url );

		if ( false === $parsed || ! is_array( $parsed ) || ! isset( $parsed['host'] ) ) {
			return $url;
		}

		if ( ! isset( $parsed['query'] ) ) {
			return $url;
		}

		parse_str( $parsed['query'], $query_params );

		// Remove tracking parameters.
		foreach ( self::TRACKING_PARAMS as $param ) {
			unset( $query_params[ $param ] );
		}

		// Rebuild the URL.
		$new_query = http_build_query( $query_params );

		$result = '';
		if ( isset( $parsed['scheme'] ) ) {
			$result .= $parsed['scheme'] . '://';
		}
		if ( isset( $parsed['user'] ) ) {
			$result .= $parsed['user'];
			if ( isset( $parsed['pass'] ) ) {
				$result .= ':' . $parsed['pass'];
			}
			$result .= '@';
		}
		$result .= $parsed['host'];
		if ( isset( $parsed['port'] ) ) {
			$result .= ':' . $parsed['port'];
		}
		if ( isset( $parsed['path'] ) ) {
			$result .= $parsed['path'];
		}
		if ( ! empty( $new_query ) ) {
			$result .= '?' . $new_query;
		}
		if ( isset( $parsed['fragment'] ) ) {
			$result .= '#' . $parsed['fragment'];
		}

		return $result;
	}

	/**
	 * Extract plain text from HTML.
	 *
	 * @param string $html The HTML to extract text from.
	 * @return string The plain text content.
	 */
	public function extract_text( string $html ): string {
		if ( empty( $html ) ) {
			return '';
		}

		// Strip scripts and styles first.
		$html = $this->strip_scripts( $html );

		// Use WordPress function if available.
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$text = wp_strip_all_tags( $html, true );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback for non-WP environments.
			$text = strip_tags( $html );
		}

		// Normalize whitespace.
		$text = $this->normalize_whitespace( $text );

		return trim( $text );
	}

	/**
	 * Normalize whitespace in text.
	 *
	 * @param string $text The text to normalize.
	 * @return string The text with normalized whitespace.
	 */
	public function normalize_whitespace( string $text ): string {
		// Replace multiple spaces with single space.
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );

		// Replace multiple newlines with double newline.
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );

		// Trim whitespace from each line.
		$lines = explode( "\n", $text );
		$lines = array_map( 'trim', $lines );

		return implode( "\n", $lines );
	}

	/**
	 * Convert URLs in text to HTML links.
	 *
	 * @param string $text The text to linkify.
	 * @return string The text with URLs converted to links.
	 */
	public function linkify_urls( string $text ): string {
		if ( empty( $text ) ) {
			return '';
		}

		// Use WordPress function if available.
		if ( function_exists( 'make_clickable' ) ) {
			return make_clickable( $text );
		}

		// Fallback: Simple URL pattern matching.
		$pattern = '/(https?:\/\/[^\s<>"\']+)/i';
		$result  = preg_replace( $pattern, '<a href="$1">$1</a>', $text );

		return is_string( $result ) ? $result : $text;
	}

	/**
	 * Convert hashtags to links.
	 *
	 * @param string $text     The text containing hashtags.
	 * @param string $base_url The base URL for hashtag links (e.g., 'https://twitter.com/hashtag/').
	 * @return string The text with hashtags converted to links.
	 */
	public function linkify_hashtags( string $text, string $base_url ): string {
		if ( empty( $text ) ) {
			return '';
		}

		$pattern = '/#(\w+)/u';
		$result  = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $base_url ) {
				$tag = $matches[1];
				$url = rtrim( $base_url, '/' ) . '/' . rawurlencode( $tag );
				return '<a href="' . esc_url( $url ) . '">#' . esc_html( $tag ) . '</a>';
			},
			$text
		);

		return is_string( $result ) ? $result : $text;
	}

	/**
	 * Convert @mentions to links.
	 *
	 * @param string $text     The text containing mentions.
	 * @param string $base_url The base URL for mention links (e.g., 'https://twitter.com/').
	 * @return string The text with mentions converted to links.
	 */
	public function linkify_mentions( string $text, string $base_url ): string {
		if ( empty( $text ) ) {
			return '';
		}

		$pattern = '/@(\w+)/u';
		$result  = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $base_url ) {
				$username = $matches[1];
				$url      = rtrim( $base_url, '/' ) . '/' . rawurlencode( $username );
				return '<a href="' . esc_url( $url ) . '">@' . esc_html( $username ) . '</a>';
			},
			$text
		);

		return is_string( $result ) ? $result : $text;
	}

	/**
	 * Extract all URLs from HTML content.
	 *
	 * @param string $html The HTML to extract URLs from.
	 * @return array<string> Array of URLs found.
	 */
	public function extract_urls( string $html ): array {
		if ( empty( $html ) ) {
			return array();
		}

		$urls = array();

		// Extract href attributes.
		if ( preg_match_all( '/href=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		// Extract src attributes.
		if ( preg_match_all( '/src=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		// Remove duplicates and filter valid URLs.
		$urls = array_unique( $urls );
		$urls = array_filter(
			$urls,
			function ( $url ) {
				return filter_var( $url, FILTER_VALIDATE_URL ) !== false;
			}
		);

		return array_values( $urls );
	}
}
