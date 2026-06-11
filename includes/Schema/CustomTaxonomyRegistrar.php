<?php
/**
 * Custom taxonomy registrar.
 *
 * @package AI_Importer\Schema
 */

namespace AI_Importer\Schema;

/**
 * Persists and registers custom taxonomies requested during import (F9.3).
 *
 * Taxonomies must be registered on the `init` hook to be fully functional
 * (admin UI, REST exposure, rewrite rules). When a mapping requests creation
 * of a taxonomy that does not yet exist, we persist its definition in the
 * {@see MappingConfig::OPTION_CUSTOM_TAXONOMIES} option and register it on
 * every subsequent `init`. During the import request itself the taxonomy is
 * also registered on the fly (see ensure_registered) so wp_set_object_terms()
 * succeeds immediately, before the next page load picks up the persisted
 * definition.
 */
class CustomTaxonomyRegistrar {

	/**
	 * Hook registration on the init action.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_persisted_taxonomies' ) );
	}

	/**
	 * Register every taxonomy persisted in the option.
	 *
	 * @return void
	 */
	public function register_persisted_taxonomies(): void {
		foreach ( $this->get_persisted() as $definition ) {
			$this->register( $definition );
		}
	}

	/**
	 * Get the persisted list of custom taxonomy definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_persisted(): array {
		$stored = get_option( MappingConfig::OPTION_CUSTOM_TAXONOMIES, array() );

		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/**
	 * Persist a custom taxonomy definition, de-duplicating by slug.
	 *
	 * @param string             $slug       Taxonomy slug.
	 * @param string             $label      Human-readable label.
	 * @param array<int, string> $post_types Object types the taxonomy applies to.
	 * @return void
	 */
	public function persist( string $slug, string $label, array $post_types ): void {
		$slug = sanitize_key( $slug );

		if ( '' === $slug ) {
			return;
		}

		$definitions = $this->get_persisted();

		foreach ( $definitions as $definition ) {
			if ( isset( $definition['slug'] ) && $definition['slug'] === $slug ) {
				return;
			}
		}

		$definitions[] = array(
			'slug'       => $slug,
			'label'      => '' !== $label ? sanitize_text_field( $label ) : $slug,
			'post_types' => array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) ),
		);

		update_option( MappingConfig::OPTION_CUSTOM_TAXONOMIES, $definitions, false );
	}

	/**
	 * Ensure a taxonomy is registered for the current request.
	 *
	 * Registers the taxonomy on the fly (so wp_set_object_terms works now)
	 * and persists its definition so it remains registered on later
	 * requests via the init hook.
	 *
	 * @param string             $slug       Taxonomy slug.
	 * @param string             $label      Human-readable label.
	 * @param array<int, string> $post_types Object types the taxonomy applies to.
	 * @return void
	 */
	public function ensure_registered( string $slug, string $label, array $post_types ): void {
		$slug = sanitize_key( $slug );

		if ( '' === $slug ) {
			return;
		}

		$this->persist( $slug, $label, $post_types );

		if ( ! taxonomy_exists( $slug ) ) {
			$this->register(
				array(
					'slug'       => $slug,
					'label'      => '' !== $label ? $label : $slug,
					'post_types' => $post_types,
				)
			);
		}
	}

	/**
	 * Register a single taxonomy from its persisted definition.
	 *
	 * @param array<string, mixed> $definition Definition.
	 * @return void
	 */
	private function register( array $definition ): void {
		$slug = isset( $definition['slug'] ) ? sanitize_key( (string) $definition['slug'] ) : '';

		if ( '' === $slug || taxonomy_exists( $slug ) ) {
			return;
		}

		$label      = isset( $definition['label'] ) && '' !== $definition['label'] ? (string) $definition['label'] : $slug;
		$post_types = isset( $definition['post_types'] ) && is_array( $definition['post_types'] ) && ! empty( $definition['post_types'] )
			? array_values( array_filter( array_map( 'sanitize_key', $definition['post_types'] ) ) )
			: array( 'post' );

		register_taxonomy(
			$slug,
			$post_types,
			array(
				'label'             => $label,
				'public'            => true,
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
			)
		);
	}
}
