<?php
/**
 * ContentType enum tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters\Manifest
 */

namespace AI_Importer\Tests\Unit\Adapters\Manifest;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Tests\Unit\TestCase;

/**
 * Tests for the ContentType enum.
 */
class ContentTypeTest extends TestCase {

	/**
	 * Test that all expected content types exist.
	 *
	 * @return void
	 */
	public function test_content_types_exist(): void {
		$expected_types = array(
			'post',
			'thread',
			'reply',
			'repost',
			'media',
			'article',
			'video',
			'story',
		);

		$actual_types = array_map(
			fn( ContentType $type ) => $type->value,
			ContentType::cases()
		);

		$this->assertSame( $expected_types, $actual_types );
	}

	/**
	 * Test get_label returns translated string.
	 *
	 * @return void
	 */
	public function test_get_label_returns_label(): void {
		$this->assertSame( 'Post', ContentType::POST->get_label() );
		$this->assertSame( 'Thread', ContentType::THREAD->get_label() );
		$this->assertSame( 'Article', ContentType::ARTICLE->get_label() );
	}

	/**
	 * Test is_primary for primary content types.
	 *
	 * @return void
	 */
	public function test_is_primary_returns_true_for_primary_types(): void {
		$this->assertTrue( ContentType::POST->is_primary() );
		$this->assertTrue( ContentType::THREAD->is_primary() );
		$this->assertTrue( ContentType::ARTICLE->is_primary() );
		$this->assertTrue( ContentType::VIDEO->is_primary() );
		$this->assertTrue( ContentType::MEDIA->is_primary() );
	}

	/**
	 * Test is_primary for non-primary content types.
	 *
	 * @return void
	 */
	public function test_is_primary_returns_false_for_non_primary_types(): void {
		$this->assertFalse( ContentType::REPLY->is_primary() );
		$this->assertFalse( ContentType::REPOST->is_primary() );
		$this->assertFalse( ContentType::STORY->is_primary() );
	}

	/**
	 * Test creating content type from string value.
	 *
	 * @return void
	 */
	public function test_from_returns_correct_type(): void {
		$this->assertSame( ContentType::POST, ContentType::from( 'post' ) );
		$this->assertSame( ContentType::THREAD, ContentType::from( 'thread' ) );
		$this->assertSame( ContentType::ARTICLE, ContentType::from( 'article' ) );
	}

	/**
	 * Test creating content type from invalid string throws exception.
	 *
	 * @return void
	 */
	public function test_from_with_invalid_value_throws_exception(): void {
		$this->expectException( \ValueError::class );
		ContentType::from( 'invalid' );
	}
}
