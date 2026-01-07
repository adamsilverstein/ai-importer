<?php
/**
 * Date converter utility class.
 *
 * @package AI_Importer
 */

namespace AI_Importer\Normalizer;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Converts date strings from various platform formats to WordPress-compatible dates.
 */
class DateConverter {

	/**
	 * Twitter/X date format.
	 */
	public const TWITTER_FORMAT = 'D M d H:i:s O Y';

	/**
	 * ISO 8601 format.
	 */
	public const ISO8601 = 'c';

	/**
	 * WordPress database format.
	 */
	public const WORDPRESS = 'Y-m-d H:i:s';

	/**
	 * Medium date format.
	 */
	public const MEDIUM_FORMAT = 'Y-m-d\TH:i:s.v\Z';

	/**
	 * Instagram timestamp (Unix epoch in milliseconds).
	 */
	public const INSTAGRAM_TIMESTAMP = 'U';

	/**
	 * Common date formats to try when auto-detecting.
	 *
	 * @var array<string>
	 */
	private const AUTO_DETECT_FORMATS = array(
		'Y-m-d\TH:i:s.uP',    // ISO 8601 with microseconds.
		'Y-m-d\TH:i:sP',      // ISO 8601.
		'Y-m-d\TH:i:s.v\Z',   // Medium format.
		'Y-m-d\TH:i:s\Z',     // ISO 8601 UTC.
		'D M d H:i:s O Y',    // Twitter format.
		'Y-m-d H:i:s',        // WordPress format.
		'Y-m-d',              // Date only.
		'U',                  // Unix timestamp.
	);

	/**
	 * Convert a date string to DateTimeImmutable.
	 *
	 * @param string      $date_string The date string to convert.
	 * @param string|null $format      Optional format hint. If null, auto-detection is used.
	 * @return DateTimeImmutable The converted date.
	 * @throws InvalidArgumentException If the date cannot be parsed.
	 */
	public function convert( string $date_string, ?string $format = null ): DateTimeImmutable {
		$date_string = trim( $date_string );

		if ( empty( $date_string ) ) {
			throw new InvalidArgumentException( 'Date string cannot be empty.' );
		}

		// Handle Unix timestamps (seconds or milliseconds).
		if ( is_numeric( $date_string ) ) {
			return $this->convert_timestamp( $date_string );
		}

		// Try specific format if provided.
		if ( null !== $format ) {
			$date = DateTimeImmutable::createFromFormat( $format, $date_string );
			if ( false !== $date ) {
				return $date;
			}
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new InvalidArgumentException(
				sprintf( 'Could not parse date "%s" with format "%s".', $date_string, $format )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// Auto-detect format.
		foreach ( self::AUTO_DETECT_FORMATS as $try_format ) {
			$date = DateTimeImmutable::createFromFormat( $try_format, $date_string );
			if ( false !== $date ) {
				return $date;
			}
		}

		// Try PHP's built-in parser as a last resort.
		try {
			return new DateTimeImmutable( $date_string );
		} catch ( \Exception $e ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new InvalidArgumentException(
				sprintf( 'Could not parse date string: %s', $date_string )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Convert a Unix timestamp to DateTimeImmutable.
	 *
	 * Handles both seconds and milliseconds timestamps.
	 *
	 * @param string $timestamp The timestamp string.
	 * @return DateTimeImmutable The converted date.
	 */
	private function convert_timestamp( string $timestamp ): DateTimeImmutable {
		$ts = (int) $timestamp;

		// If the timestamp is in milliseconds (13+ digits), convert to seconds.
		if ( strlen( $timestamp ) >= 13 ) {
			$ts = (int) ( $ts / 1000 );
		}

		return ( new DateTimeImmutable() )->setTimestamp( $ts );
	}

	/**
	 * Convert a DateTimeImmutable to WordPress database format.
	 *
	 * @param DateTimeImmutable $date The date to format.
	 * @return string The formatted date string.
	 */
	public function to_wordpress_format( DateTimeImmutable $date ): string {
		return $date->format( self::WORDPRESS );
	}

	/**
	 * Convert a DateTimeImmutable to GMT/UTC.
	 *
	 * @param DateTimeImmutable $date The date to convert.
	 * @return DateTimeImmutable The date in UTC timezone.
	 */
	public function to_gmt( DateTimeImmutable $date ): DateTimeImmutable {
		return $date->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Convert a DateTimeImmutable to a specific timezone.
	 *
	 * @param DateTimeImmutable $date     The date to convert.
	 * @param string            $timezone The target timezone (e.g., 'America/New_York').
	 * @return DateTimeImmutable The date in the specified timezone.
	 */
	public function to_timezone( DateTimeImmutable $date, string $timezone ): DateTimeImmutable {
		return $date->setTimezone( new DateTimeZone( $timezone ) );
	}

	/**
	 * Parse a relative date string (e.g., "2 days ago", "yesterday").
	 *
	 * @param string $relative The relative date string.
	 * @return DateTimeImmutable The calculated date.
	 * @throws InvalidArgumentException If the relative string cannot be parsed.
	 */
	public function parse_relative( string $relative ): DateTimeImmutable {
		$relative = strtolower( trim( $relative ) );

		if ( empty( $relative ) ) {
			throw new InvalidArgumentException( 'Relative date string cannot be empty.' );
		}

		// Handle common relative terms.
		$mappings = array(
			'now'       => 'now',
			'today'     => 'today',
			'yesterday' => 'yesterday',
			'tomorrow'  => 'tomorrow',
		);

		if ( isset( $mappings[ $relative ] ) ) {
			return new DateTimeImmutable( $mappings[ $relative ] );
		}

		// Try to parse patterns like "2 days ago", "1 hour ago", etc.
		if ( preg_match( '/^(\d+)\s+(second|minute|hour|day|week|month|year)s?\s+ago$/i', $relative, $matches ) ) {
			$number = (int) $matches[1];
			$unit   = $matches[2];
			return new DateTimeImmutable( "-{$number} {$unit}" );
		}

		// Try PHP's built-in relative date parser.
		try {
			return new DateTimeImmutable( $relative );
		} catch ( \Exception $e ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't need escaping.
			throw new InvalidArgumentException(
				sprintf( 'Could not parse relative date: %s', $relative )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Get the WordPress site timezone.
	 *
	 * @return DateTimeZone The site's timezone.
	 */
	public function get_site_timezone(): DateTimeZone {
		$timezone_string = get_option( 'timezone_string' );

		if ( ! empty( $timezone_string ) ) {
			return new DateTimeZone( $timezone_string );
		}

		// Fall back to offset if timezone string is not set.
		$offset  = (float) get_option( 'gmt_offset', 0 );
		$hours   = (int) $offset;
		$minutes = abs( ( $offset - $hours ) * 60 );
		$sign    = $offset >= 0 ? '+' : '-';

		return new DateTimeZone( sprintf( '%s%02d:%02d', $sign, abs( $hours ), $minutes ) );
	}

	/**
	 * Convert a date to the WordPress site timezone.
	 *
	 * @param DateTimeImmutable $date The date to convert.
	 * @return DateTimeImmutable The date in site timezone.
	 */
	public function to_site_timezone( DateTimeImmutable $date ): DateTimeImmutable {
		return $date->setTimezone( $this->get_site_timezone() );
	}
}
