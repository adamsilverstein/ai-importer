<?php
/**
 * SiteSchemaAnalyzer class tests.
 *
 * @package AI_Importer\Tests\Unit\Schema
 */

namespace AI_Importer\Tests\Unit\Schema;

use AI_Importer\Schema\SiteSchemaAnalyzer;
use AI_Importer\Tests\Unit\TestCase;
use Brain\Monkey\Functions;

/**
 * Tests for the SiteSchemaAnalyzer class.
 */
class SiteSchemaAnalyzerTest extends TestCase {

	/**
	 * Set up each test with permissive defaults for the new collectors.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'get_post_format_strings' )->justReturn(
			array(
				'standard' => 'Standard',
				'aside'    => 'Aside',
				'gallery'  => 'Gallery',
			)
		);
	}

	/**
	 * Build a minimal WP_Post_Type-like stub.
	 *
	 * @param string $name   Slug.
	 * @param string $label  Display label.
	 * @param bool   $public Public flag.
	 * @return object
	 */
	private function make_post_type( string $name, string $label, bool $public = true ): object {
		$type = new \stdClass();
		$type->name   = $name;
		$type->label  = $label;
		$type->public = $public;
		$type->labels = (object) array( 'singular_name' => $label );

		return $type;
	}

	/**
	 * Build a minimal WP_Taxonomy-like stub.
	 *
	 * @param string        $name       Slug.
	 * @param string        $label      Label.
	 * @param array<string> $post_types Associated post type slugs.
	 * @return object
	 */
	private function make_taxonomy( string $name, string $label, array $post_types ): object {
		$tax = new \stdClass();
		$tax->name       = $name;
		$tax->label      = $label;
		$tax->public     = true;
		$tax->object_type = $post_types;

		return $tax;
	}

	/**
	 * Test empty site returns empty structure.
	 *
	 * @return void
	 */
	public function test_empty_site_returns_empty_structure(): void {
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_taxonomies' )->justReturn( array() );

		$analyzer = new SiteSchemaAnalyzer();
		$schema   = $analyzer->get_schema();

		$this->assertSame( array(), $schema['post_types'] );
		$this->assertSame( array(), $schema['taxonomies'] );
	}

	/**
	 * Test post types are returned in the expected shape.
	 *
	 * @return void
	 */
	public function test_returns_public_post_types(): void {
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'     => $this->make_post_type( 'post', 'Posts' ),
				'tutorial' => $this->make_post_type( 'tutorial', 'Tutorials' ),
			)
		);
		Functions\when( 'get_taxonomies' )->justReturn( array() );

		$analyzer = new SiteSchemaAnalyzer();
		$schema   = $analyzer->get_schema();

		$this->assertCount( 2, $schema['post_types'] );
		$this->assertSame(
			array(
				'slug'   => 'post',
				'name'   => 'Posts',
				'public' => true,
			),
			$schema['post_types'][0]
		);
		$this->assertSame( 'tutorial', $schema['post_types'][1]['slug'] );
	}

	/**
	 * Test taxonomies include their associated post types.
	 *
	 * @return void
	 */
	public function test_returns_taxonomies_with_post_types(): void {
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_taxonomies' )->justReturn(
			array(
				'category' => $this->make_taxonomy( 'category', 'Categories', array( 'post', 'tutorial' ) ),
				'post_tag' => $this->make_taxonomy( 'post_tag', 'Tags', array( 'post' ) ),
			)
		);

		$analyzer = new SiteSchemaAnalyzer();
		$schema   = $analyzer->get_schema();

		$this->assertCount( 2, $schema['taxonomies'] );
		$this->assertSame(
			array(
				'slug'       => 'category',
				'name'       => 'Categories',
				'post_types' => array( 'post', 'tutorial' ),
			),
			$schema['taxonomies'][0]
		);
	}

	/**
	 * Test built-in WordPress internals are excluded by default.
	 *
	 * @return void
	 */
	public function test_excludes_wordpress_internals(): void {
		// WordPress get_post_types filters by args passed in; the analyzer passes
		// public => true, so internals like attachment aren't returned. We verify
		// the args by capturing what the analyzer calls get_post_types with.
		$captured_args = null;
		Functions\expect( 'get_post_types' )
			->once()
			->with(
				\Mockery::on(
					function ( $args ) use ( &$captured_args ) {
						$captured_args = $args;
						return true;
					}
				),
				'objects'
			)
			->andReturn( array() );
		Functions\when( 'get_taxonomies' )->justReturn( array() );

		$analyzer = new SiteSchemaAnalyzer();
		$analyzer->get_schema();

		$this->assertIsArray( $captured_args );
		$this->assertArrayHasKey( 'public', $captured_args );
		$this->assertTrue( $captured_args['public'] );
	}

	/**
	 * Test produces output compatible with MappingSuggester consumer.
	 *
	 * @return void
	 */
	public function test_output_shape_matches_mapping_suggester_contract(): void {
		Functions\when( 'get_post_types' )->justReturn(
			array( 'post' => $this->make_post_type( 'post', 'Posts' ) )
		);
		Functions\when( 'get_taxonomies' )->justReturn(
			array( 'category' => $this->make_taxonomy( 'category', 'Categories', array( 'post' ) ) )
		);

		$analyzer = new SiteSchemaAnalyzer();
		$schema   = $analyzer->get_schema();

		// Top-level keys MappingSuggester expects.
		$this->assertArrayHasKey( 'post_types', $schema );
		$this->assertArrayHasKey( 'taxonomies', $schema );

		// Per-entry keys MappingSuggester reads.
		$this->assertArrayHasKey( 'slug', $schema['post_types'][0] );
		$this->assertArrayHasKey( 'name', $schema['post_types'][0] );
		$this->assertArrayHasKey( 'slug', $schema['taxonomies'][0] );
		$this->assertArrayHasKey( 'name', $schema['taxonomies'][0] );
		$this->assertArrayHasKey( 'post_types', $schema['taxonomies'][0] );
	}

	/**
	 * Test the schema exposes users for author mapping (F9.2).
	 *
	 * @return void
	 */
	public function test_exposes_users(): void {
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_taxonomies' )->justReturn( array() );
		Functions\when( 'get_users' )->justReturn(
			array(
				(object) array(
					'ID'           => 5,
					'display_name' => 'Jane Doe',
				),
				(object) array(
					'ID'           => 8,
					'display_name' => 'John Roe',
				),
			)
		);

		$analyzer = new SiteSchemaAnalyzer();
		$schema   = $analyzer->get_schema();

		$this->assertSame(
			array(
				array(
					'id'           => 5,
					'display_name' => 'Jane Doe',
				),
				array(
					'id'           => 8,
					'display_name' => 'John Roe',
				),
			),
			$schema['users']
		);
	}

	/**
	 * Test the schema exposes post formats with 'standard' first (F9.4).
	 *
	 * @return void
	 */
	public function test_exposes_post_formats(): void {
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_taxonomies' )->justReturn( array() );

		$analyzer = new SiteSchemaAnalyzer();
		$schema   = $analyzer->get_schema();

		$this->assertSame( 'standard', $schema['post_formats'][0]['slug'] );
		$slugs = array_column( $schema['post_formats'], 'slug' );
		$this->assertContains( 'aside', $slugs );
		$this->assertContains( 'gallery', $slugs );
		// 'standard' appears exactly once.
		$this->assertSame( 1, count( array_keys( $slugs, 'standard', true ) ) );
	}
}
