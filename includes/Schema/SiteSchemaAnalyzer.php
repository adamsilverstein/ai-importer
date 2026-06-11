<?php
/**
 * Site schema analyzer.
 *
 * @package AI_Importer\Schema
 */

namespace AI_Importer\Schema;

/**
 * Introspects the destination WordPress site's public structure.
 *
 * Produces a compact summary of post types and taxonomies in the exact
 * shape that MappingSuggester consumes, so the AI has enough context to
 * recommend how source content maps onto what actually exists on the site.
 */
class SiteSchemaAnalyzer {

	/**
	 * Maximum number of users to expose for author mapping.
	 */
	private const MAX_USERS = 100;

	/**
	 * Build a summary of the site's public post types and taxonomies.
	 *
	 * Also exposes the users available for author mapping (F9.2) and the
	 * registered post formats (F9.4) so the mapping UI can populate its
	 * advanced controls.
	 *
	 * @return array{post_types: array<int, array<string, mixed>>, taxonomies: array<int, array<string, mixed>>, users: array<int, array<string, mixed>>, post_formats: array<int, array<string, string>>}
	 */
	public function get_schema(): array {
		return array(
			'post_types'   => $this->collect_post_types(),
			'taxonomies'   => $this->collect_taxonomies(),
			'users'        => $this->collect_users(),
			'post_formats' => $this->collect_post_formats(),
		);
	}

	/**
	 * Collect users that can be assigned as post authors (F9.2).
	 *
	 * Limited to users who can edit posts and capped at a sensible maximum
	 * so large sites do not return unbounded data.
	 *
	 * @return array<int, array{id: int, display_name: string}>
	 */
	private function collect_users(): array {
		$users = get_users(
			array(
				'capability' => 'edit_posts',
				'number'     => self::MAX_USERS,
				'orderby'    => 'display_name',
				'order'      => 'ASC',
				'fields'     => array( 'ID', 'display_name' ),
			)
		);

		$result = array();
		foreach ( $users as $user ) {
			$result[] = array(
				'id'           => (int) $user->ID,
				'display_name' => (string) $user->display_name,
			);
		}

		return $result;
	}

	/**
	 * Collect the registered WordPress post formats (F9.4).
	 *
	 * Always includes the 'standard' (no format) option first.
	 *
	 * @return array<int, array{slug: string, name: string}>
	 */
	private function collect_post_formats(): array {
		$result = array(
			array(
				'slug' => 'standard',
				'name' => __( 'Standard', 'ai-importer' ),
			),
		);

		foreach ( get_post_format_strings() as $slug => $name ) {
			if ( 'standard' === $slug ) {
				continue;
			}

			$result[] = array(
				'slug' => (string) $slug,
				'name' => (string) $name,
			);
		}

		return $result;
	}

	/**
	 * Collect public post types into the expected output shape.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_post_types(): array {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		$result = array();
		foreach ( $post_types as $post_type ) {
			$result[] = array(
				'slug'   => $post_type->name,
				'name'   => $this->resolve_label( $post_type, $post_type->name ),
				'public' => (bool) $post_type->public,
			);
		}

		return $result;
	}

	/**
	 * Collect public taxonomies, recording which post types they apply to.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_taxonomies(): array {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		$result = array();
		foreach ( $taxonomies as $taxonomy ) {
			$result[] = array(
				'slug'       => $taxonomy->name,
				'name'       => $this->resolve_label( $taxonomy, $taxonomy->name ),
				'post_types' => array_values( $taxonomy->object_type ),
			);
		}

		return $result;
	}

	/**
	 * Resolve a human-readable label for a post type or taxonomy object.
	 *
	 * @param object $entity   Post type or taxonomy object.
	 * @param string $fallback Fallback slug.
	 * @return string
	 */
	private function resolve_label( object $entity, string $fallback ): string {
		if ( isset( $entity->label ) && is_string( $entity->label ) && '' !== $entity->label ) {
			return $entity->label;
		}

		if ( isset( $entity->labels->name ) && is_string( $entity->labels->name ) ) {
			return $entity->labels->name;
		}

		return $fallback;
	}
}
