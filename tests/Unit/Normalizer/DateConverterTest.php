<?php
/**
 * DateConverter class tests.
 *
 * @package AI_Importer\Tests\Unit\Normalizer
 */

namespace AI_Importer\Tests\Unit\Normalizer;

use AI_Importer\Normalizer\DateConverter;
use AI_Importer\Tests\Unit\TestCase;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Brain\Monkey\Functions;

/**
 * Tests for the DateConverter class.
 */
class DateConverterTest extends TestCase {

	/**
	 * DateConverter instance.
	 *
	 * @var DateConverter
	 */
	private DateConverter $converter;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->converter = new DateConverter();
	}

	/**
	 * Test converting ISO 8601 date string.
	 *
	 * @return void
	 */
	public function test_convert_iso8601_date(): void {
		$date = $this->converter->convert( '2024-01-15T10:30:00+00:00' );

		$this->assertInstanceOf( DateTimeImmutable::class, $date );
		$this->assertSame( '2024-01-15', $date->format( 'Y-m-d' ) );
		$this->assertSame( '10:30:00', $date->format( 'H:i:s' ) );
	}

	/**
	 * Test converting Twitter date format.
	 *
	 * @return void
	 */
	public function test_convert_twitter_date_format(): void {
		$date = $this->converter->convert(
			'Mon Jan 15 10:30:00 +0000 2024',
			DateConverter::TWITTER_FORMAT
		);

		$this->assertInstanceOf( DateTimeImmutable::class, $date );
		$this->assertSame( '2024-01-15', $date->format( 'Y-m-d' ) );
	}

	/**
	 * Test converting WordPress date format.
	 *
	 * @return void
	 */
	public function test_convert_wordpress_date_format(): void {
		$date = $this->converter->convert(
			'2024-01-15 10:30:00',
			DateConverter::WORDPRESS
		);

		$this->assertInstanceOf( DateTimeImmutable::class, $date );
		$this->assertSame( '2024-01-15 10:30:00', $date->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Test converting Unix timestamp in seconds.
	 *
	 * @return void
	 */
	public function test_convert_unix_timestamp_seconds(): void {
		$timestamp = '1705315800'; // 2024-01-15 10:30:00 UTC.
		$date      = $this->converter->convert( $timestamp );

		$this->assertInstanceOf( DateTimeImmutable::class, $date );
		$this->assertSame( '2024-01-15', $date->format( 'Y-m-d' ) );
	}

	/**
	 * Test converting Unix timestamp in milliseconds.
	 *
	 * @return void
	 */
	public function test_convert_unix_timestamp_milliseconds(): void {
		$timestamp = '1705315800000'; // Same time but in milliseconds.
		$date      = $this->converter->convert( $timestamp );

		$this->assertInstanceOf( DateTimeImmutable::class, $date );
		$this->assertSame( '2024-01-15', $date->format( 'Y-m-d' ) );
	}

	/**
	 * Test auto-detection of date formats.
	 *
	 * @return void
	 */
	public function test_convert_auto_detects_format(): void {
		// ISO 8601 with microseconds.
		$date1 = $this->converter->convert( '2024-01-15T10:30:00.123456+00:00' );
		$this->assertSame( '2024-01-15', $date1->format( 'Y-m-d' ) );

		// ISO 8601 UTC with Z suffix.
		$date2 = $this->converter->convert( '2024-01-15T10:30:00Z' );
		$this->assertSame( '2024-01-15', $date2->format( 'Y-m-d' ) );

		// Date only.
		$date3 = $this->converter->convert( '2024-01-15' );
		$this->assertSame( '2024-01-15', $date3->format( 'Y-m-d' ) );
	}

	/**
	 * Test empty date string throws exception.
	 *
	 * @return void
	 */
	public function test_convert_throws_exception_for_empty_string(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Date string cannot be empty' );

		$this->converter->convert( '' );
	}

	/**
	 * Test invalid date string throws exception.
	 *
	 * @return void
	 */
	public function test_convert_throws_exception_for_invalid_date(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Could not parse date string' );

		$this->converter->convert( 'not a valid date format xyz' );
	}

	/**
	 * Test invalid format throws exception.
	 *
	 * @return void
	 */
	public function test_convert_throws_exception_for_wrong_format(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Could not parse date' );

		$this->converter->convert( '2024-01-15', 'Y/m/d' );
	}

	/**
	 * Test to_wordpress_format method.
	 *
	 * @return void
	 */
	public function test_to_wordpress_format(): void {
		$date   = new DateTimeImmutable( '2024-01-15T10:30:00+00:00' );
		$result = $this->converter->to_wordpress_format( $date );

		$this->assertSame( '2024-01-15 10:30:00', $result );
	}

	/**
	 * Test to_gmt method.
	 *
	 * @return void
	 */
	public function test_to_gmt(): void {
		$date   = new DateTimeImmutable( '2024-01-15T10:30:00-05:00' );
		$result = $this->converter->to_gmt( $date );

		$this->assertSame( 'UTC', $result->getTimezone()->getName() );
		$this->assertSame( '15:30:00', $result->format( 'H:i:s' ) );
	}

	/**
	 * Test to_timezone method.
	 *
	 * @return void
	 */
	public function test_to_timezone(): void {
		$date   = new DateTimeImmutable( '2024-01-15T10:30:00+00:00' );
		$result = $this->converter->to_timezone( $date, 'America/New_York' );

		$this->assertSame( 'America/New_York', $result->getTimezone()->getName() );
		$this->assertSame( '05:30:00', $result->format( 'H:i:s' ) );
	}

	/**
	 * Test parse_relative with common terms.
	 *
	 * @return void
	 */
	public function test_parse_relative_common_terms(): void {
		$now       = new DateTimeImmutable( 'now' );
		$today     = $this->converter->parse_relative( 'today' );
		$yesterday = $this->converter->parse_relative( 'yesterday' );

		$this->assertSame( $now->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) );
		$this->assertSame(
			$now->modify( '-1 day' )->format( 'Y-m-d' ),
			$yesterday->format( 'Y-m-d' )
		);
	}

	/**
	 * Test parse_relative with "X days ago" pattern.
	 *
	 * @return void
	 */
	public function test_parse_relative_days_ago(): void {
		$now    = new DateTimeImmutable( 'now' );
		$result = $this->converter->parse_relative( '5 days ago' );

		$expected = $now->modify( '-5 days' )->format( 'Y-m-d' );
		$this->assertSame( $expected, $result->format( 'Y-m-d' ) );
	}

	/**
	 * Test parse_relative with various time units.
	 *
	 * @return void
	 */
	public function test_parse_relative_various_units(): void {
		$now = new DateTimeImmutable( 'now' );

		$hours = $this->converter->parse_relative( '2 hours ago' );
		$weeks = $this->converter->parse_relative( '1 week ago' );

		$this->assertLessThan(
			$now->getTimestamp(),
			$hours->getTimestamp()
		);
		$this->assertLessThan(
			$now->getTimestamp(),
			$weeks->getTimestamp()
		);
	}

	/**
	 * Test parse_relative with empty string throws exception.
	 *
	 * @return void
	 */
	public function test_parse_relative_throws_exception_for_empty(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Relative date string cannot be empty' );

		$this->converter->parse_relative( '' );
	}

	/**
	 * Test get_site_timezone with timezone string.
	 *
	 * @return void
	 */
	public function test_get_site_timezone_with_string(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'timezone_string' )
			->andReturn( 'America/New_York' );

		$timezone = $this->converter->get_site_timezone();

		$this->assertInstanceOf( DateTimeZone::class, $timezone );
		$this->assertSame( 'America/New_York', $timezone->getName() );
	}

	/**
	 * Test get_site_timezone with GMT offset.
	 *
	 * @return void
	 */
	public function test_get_site_timezone_with_offset(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'timezone_string' )
			->andReturn( '' );

		Functions\expect( 'get_option' )
			->once()
			->with( 'gmt_offset', 0 )
			->andReturn( -5 );

		$timezone = $this->converter->get_site_timezone();

		$this->assertInstanceOf( DateTimeZone::class, $timezone );
	}

	/**
	 * Test to_site_timezone method.
	 *
	 * @return void
	 */
	public function test_to_site_timezone(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'timezone_string' )
			->andReturn( 'America/Los_Angeles' );

		$date   = new DateTimeImmutable( '2024-01-15T10:30:00+00:00' );
		$result = $this->converter->to_site_timezone( $date );

		$this->assertSame( 'America/Los_Angeles', $result->getTimezone()->getName() );
	}

	/**
	 * Test format constants are defined correctly.
	 *
	 * @return void
	 */
	public function test_format_constants(): void {
		$this->assertSame( 'D M d H:i:s O Y', DateConverter::TWITTER_FORMAT );
		$this->assertSame( 'c', DateConverter::ISO8601 );
		$this->assertSame( 'Y-m-d H:i:s', DateConverter::WORDPRESS );
	}
}
