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
	 * Build a summary of the site's public post types and taxonomies.
	 *
	 * @return array{post_types: array<int, array<string, mixed>>, taxonomies: array<int, array<string, mixed>>}
	 */
	public function get_schema(): array {
		return array(
			'post_types' => $this->collect_post_types(),
			'taxonomies' => $this->collect_taxonomies(),
		);
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
