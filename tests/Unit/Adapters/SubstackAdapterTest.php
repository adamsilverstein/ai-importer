<?php
/**
 * SubstackAdapter class tests.
 *
 * @package AI_Importer\Tests\Unit\Adapters
 */

namespace AI_Importer\Tests\Unit\Adapters;

use AI_Importer\Adapters\Manifest\ContentType;
use AI_Importer\Adapters\SubstackAdapter;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the SubstackAdapter class.
 */
class SubstackAdapterTest extends TestCase {

	/**
	 * Path to the fixture archive.
	 *
	 * @var string
	 */
	private string $fixture_path;

	/**
	 * Adapter under test.
	 *
	 * @var SubstackAdapter
	 */
	private SubstackAdapter $adapter;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->fixture_path = dirname( __DIR__, 2 ) . '/fixtures/substack-export.zip';
		$this->adapter      = new SubstackAdapter();

		$stored = array();

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( &$stored ) {
				return $stored[ $key ] ?? $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$stored ) {
				$stored[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $key ) use ( &$stored ) {
				unset( $stored[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( '6.7' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_basename' )->alias( 'basename' );
		Functions\when( 'wp_tempnam' )->alias(
			static function () {
				return tempnam( sys_get_temp_dir(), 'ai_importer_test_' );
			}
		);
	}

	/**
	 * Authenticate the adapter against the fixture.
	 *
	 * @return void
	 */
	private function authenticate_adapter(): void {
		$this->assertTrue(
			$this->adapter->authenticate( array( 'file' => $this->fixture_path ) )
		);
	}

	/**
	 * Find a manifest item by title.
	 *
	 * @param array<string, \AI_Importer\Adapters\Manifest\ManifestItem> $items Items.
	 * @param string                                                     $title Title to find.
	 * @return array{0: string, 1: \AI_Importer\Adapters\Manifest\ManifestItem}|null Tuple of (id, item).
	 */
	private function find_by_title( array $items, string $title ): ?array {
		foreach ( $items as $id => $item ) {
			if ( $title === $item->title ) {
				return array( (string) $id, $item );
			}
		}
		return null;
	}

	/**
	 * Test adapter identity methods.
	 *
	 * @return void
	 */
	public function test_identity(): void {
		$this->assertSame( 'substack', $this->adapter->get_id() );
		$this->assertSame( 'Substack', $this->adapter->get_name() );
		$this->assertSame( 'file_upload', $this->adapter->get_auth_type() );
		$this->assertSame( 'dashicons-email-alt', $this->adapter->get_icon() );
	}

	/**
	 * Test supported content types include the types Substack maps onto.
	 *
	 * @return void
	 */
	public function test_supported_content_types(): void {
		$types = $this->adapter->get_supported_content_types();

		$this->assertContains( ContentType::POST->value, $types );
		$this->assertContains( ContentType::ARTICLE->value, $types );
		$this->assertContains( ContentType::MEDIA->value, $types );
		$this->assertContains( ContentType::THREAD->value, $types );
	}

	/**
	 * Test settings schema exposes a file-upload field for the export ZIP.
	 *
	 * @return void
	 */
	public function test_settings_schema(): void {
		$schema = $this->adapter->get_settings_schema();

		$this->assertTrue( $schema->has_field( 'archive_file' ) );
		$field = $schema->get_field( 'archive_file' );
		$this->assertSame( 'file', $field['type'] );
		$this->assertSame( '.zip', $field['accept'] );
	}

	/**
	 * Test authenticate succeeds with a valid Substack export.
	 *
	 * @return void
	 */
	public function test_authenticate_with_valid_export(): void {
		$this->assertTrue(
			$this->adapter->authenticate( array( 'file' => $this->fixture_path ) )
		);
		$this->assertTrue( $this->adapter->is_authenticated() );
	}

	/**
	 * Test authenticate fails with a missing file.
	 *
	 * @return void
	 */
	public function test_authenticate_with_missing_file(): void {
		$this->assertFalse(
			$this->adapter->authenticate( array( 'file' => '/nonexistent/substack.zip' ) )
		);
	}

	/**
	 * Test authenticate fails with a ZIP that has no posts.csv.
	 *
	 * @return void
	 */
	public function test_authenticate_with_invalid_archive(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ai_importer_substack_' ) . '.zip';
		$zip = new \ZipArchive();
		$zip->open( $tmp, \ZipArchive::CREATE );
		$zip->addFromString( 'meta.json', '{}' );
		$zip->close();

		$this->assertFalse(
			$this->adapter->authenticate( array( 'file' => $tmp ) )
		);

		unlink( $tmp );
	}

	/**
	 * Test fetch_manifest returns the expected post count with the draft
	 * row excluded.
	 *
	 * @return void
	 */
	public function test_fetch_manifest_excludes_drafts(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$items    = $manifest->get_items();

		// 6 rows in posts.csv: 1 long newsletter + 1 short newsletter
		// + 1 podcast + 1 multiline-subtitle newsletter + 1 local-image
		// newsletter. The unpublished draft is excluded.
		$this->assertCount( 5, $items );
		$this->assertNull( $this->find_by_title( $items, 'Unfinished Draft' ) );
	}

	/**
	 * Test long newsletters classify as ARTICLE.
	 *
	 * @return void
	 */
	public function test_long_newsletter_classified_as_article(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Owning Your Words' );

		$this->assertNotNull( $found );
		$this->assertSame( ContentType::ARTICLE, $found[1]->type );
		$this->assertSame( 'newsletter', $found[1]->metadata['post_type'] );
	}

	/**
	 * Test short newsletters classify as POST.
	 *
	 * @return void
	 */
	public function test_short_newsletter_classified_as_post(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Quick Update' );

		$this->assertNotNull( $found );
		$this->assertSame( ContentType::POST, $found[1]->type );
		$this->assertSame( 'only_paid', $found[1]->metadata['audience'] );
	}

	/**
	 * Test podcasts classify as MEDIA and surface the podcast URL.
	 *
	 * @return void
	 */
	public function test_podcast_classified_as_media(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Episode 1: Getting Started' );

		$this->assertNotNull( $found );
		$this->assertSame( ContentType::MEDIA, $found[1]->type );
		$this->assertSame( 'podcast', $found[1]->metadata['post_type'] );
		$this->assertSame(
			'https://api.substack.com/feed/podcast/1003/episode-1.mp3',
			$found[1]->metadata['podcast_url']
		);
	}

	/**
	 * Test manifest dates come from the post_date CSV column.
	 *
	 * @return void
	 */
	public function test_manifest_dates_parsed_from_csv(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Owning Your Words' );

		$this->assertNotNull( $found );
		$this->assertSame(
			'2023-05-01T12:00:00+00:00',
			$found[1]->created_at->format( 'c' )
		);
	}

	/**
	 * Test the subtitle becomes the manifest excerpt.
	 *
	 * @return void
	 */
	public function test_subtitle_used_as_excerpt(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Quick Update' );

		$this->assertNotNull( $found );
		$this->assertSame( 'A short note for subscribers', $found[1]->excerpt );
	}

	/**
	 * Test quoted multiline subtitles survive CSV parsing.
	 *
	 * @return void
	 */
	public function test_multiline_subtitle_parsed_correctly(): void {
		$this->authenticate_adapter();

		$manifest = $this->adapter->fetch_manifest();
		$found    = $this->find_by_title( $manifest->get_items(), 'Multiline Subtitle' );

		$this->assertNotNull( $found );
		$this->assertSame(
			"First line of the subtitle,\nwith a comma, and a second line",
			$found[1]->metadata['subtitle']
		);

		// Excerpts collapse whitespace, so the newline becomes a space.
		$this->assertSame(
			'First line of the subtitle, with a comma, and a second line',
			$found[1]->excerpt
		);
	}

	/**
	 * Test fetch_item returns full content and Substack metadata.
	 *
	 * @return void
	 */
	public function test_fetch_item_returns_full_data(): void {
		$this->authenticate_adapter();

		$item = $this->adapter->fetch_item( '1001.owning-your-words' );

		$this->assertSame( 'Owning Your Words', $item['title'] );
		$this->assertSame( 'article', $item['type'] );
		$this->assertStringContainsString( 'Owning your words', $item['content'] );
		$this->assertSame( '2023-05-01T12:00:00+00:00', $item['created_at'] );
		$this->assertSame( 'newsletter', $item['metadata']['post_type'] );
		$this->assertSame( 'everyone', $item['metadata']['audience'] );
		$this->assertSame( 'A long-form essay on durable publishing', $item['metadata']['subtitle'] );
		$this->assertNull( $item['metadata']['podcast_url'] );
	}

	/**
	 * Test absolute https media URLs are surfaced but not extracted from
	 * the archive (they flow through the download path).
	 *
	 * @return void
	 */
	public function test_fetch_item_leaves_remote_media_urls_untouched(): void {
		$this->authenticate_adapter();

		$item = $this->adapter->fetch_item( '1001.owning-your-words' );

		$this->assertContains(
			'https://substackcdn.com/image/fetch/w_1456/https%3A%2F%2Fexample.com%2Fhero.jpg',
			$item['media_urls']
		);
		$this->assertSameSize( $item['media_urls'], $item['media_paths'] );
		$this->assertSame( array( null ), $item['media_paths'] );
	}

	/**
	 * Test archive-relative media references are extracted to local
	 * temp files exposed via media_paths.
	 *
	 * @return void
	 */
	public function test_fetch_item_extracts_local_media(): void {
		$this->authenticate_adapter();

		$item = $this->adapter->fetch_item( '1006.local-image' );

		$this->assertContains( 'media/bundled.jpg', $item['media_urls'] );

		$index = array_search( 'media/bundled.jpg', $item['media_urls'], true );
		$this->assertIsString( $item['media_paths'][ $index ] );
		$this->assertFileExists( $item['media_paths'][ $index ] );
		$this->assertSame(
			'bundled-image-stub',
			file_get_contents( $item['media_paths'][ $index ] )
		);
	}

	/**
	 * Test parsing tolerates exports nested in a subdirectory and CSV
	 * columns in a different order with extras.
	 *
	 * @return void
	 */
	public function test_parses_nested_export_with_reordered_columns(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ai_importer_substack_' ) . '.zip';
		$zip = new \ZipArchive();
		$zip->open( $tmp, \ZipArchive::CREATE );
		$zip->addFromString(
			'export/posts.csv',
			"title,post_id,type,is_published,post_date,extra_column\n"
			. "Nested Post,42.nested-post,newsletter,true,2024-02-02T10:00:00.000Z,ignored\n"
		);
		$zip->addFromString( 'export/posts/42.nested-post.html', '<p>Nested body.</p>' );
		$zip->close();

		$this->assertTrue( $this->adapter->authenticate( array( 'file' => $tmp ) ) );

		$manifest = $this->adapter->fetch_manifest();
		$items    = $manifest->get_items();

		$this->assertCount( 1, $items );

		$found = $this->find_by_title( $items, 'Nested Post' );
		$this->assertNotNull( $found );
		$this->assertSame( '42.nested-post', $found[0] );

		$item = $this->adapter->fetch_item( '42.nested-post' );
		$this->assertStringContainsString( 'Nested body', $item['content'] );

		unlink( $tmp );
	}

	/**
	 * Test a podcast row without an HTML body file still imports, while
	 * a published row with no content at all is skipped.
	 *
	 * @return void
	 */
	public function test_rows_without_body_handled_gracefully(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ai_importer_substack_' ) . '.zip';
		$zip = new \ZipArchive();
		$zip->open( $tmp, \ZipArchive::CREATE );
		$zip->addFromString(
			'posts.csv',
			"post_id,post_date,is_published,email_sent_at,type,audience,title,subtitle,podcast_url\n"
			. "7.audio-only,2024-03-03T10:00:00.000Z,true,,podcast,everyone,Audio Only,,https://api.substack.com/feed/podcast/7/audio.mp3\n"
			. "8.empty-row,2024-03-04T10:00:00.000Z,true,,newsletter,everyone,,,\n"
		);
		$zip->close();

		$this->assertTrue( $this->adapter->authenticate( array( 'file' => $tmp ) ) );

		$manifest = $this->adapter->fetch_manifest();
		$items    = $manifest->get_items();

		$this->assertCount( 1, $items );

		$found = $this->find_by_title( $items, 'Audio Only' );
		$this->assertNotNull( $found );
		$this->assertSame( ContentType::MEDIA, $found[1]->type );

		unlink( $tmp );
	}

	/**
	 * Test fetch_item throws on unknown ID.
	 *
	 * @return void
	 */
	public function test_fetch_item_throws_on_missing_id(): void {
		$this->authenticate_adapter();

		$this->expectException( \RuntimeException::class );
		$this->adapter->fetch_item( 'no-such-id' );
	}

	/**
	 * Test fetch_item throws on a draft ID (drafts are excluded).
	 *
	 * @return void
	 */
	public function test_fetch_item_throws_on_draft_id(): void {
		$this->authenticate_adapter();

		$this->expectException( \RuntimeException::class );
		$this->adapter->fetch_item( '1004.unfinished-draft' );
	}

	/**
	 * Test disconnect clears stored credentials.
	 *
	 * @return void
	 */
	public function test_disconnect_clears_credentials(): void {
		$this->authenticate_adapter();
		$this->assertTrue( $this->adapter->is_authenticated() );

		$this->adapter->disconnect();

		$this->assertFalse( $this->adapter->is_authenticated() );
	}
}
