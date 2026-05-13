<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

final class TaxonomyColumn extends BaseColumn implements SortableColumn, FilterableColumn {
	public function get_type(): string {
		return 'taxonomy';
	}

	public function get_label(): string {
		return __( 'Taxonomy', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'List the terms in a chosen taxonomy.', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		return str_starts_with( $screen_key, 'post_type:' ) || $screen_key === 'media';
	}

	public function settings_fields(): array {
		return [
			[
				'key'      => 'taxonomy',
				'label'    => __( 'Taxonomy slug', 'columnkit' ),
				'type'     => 'text',
				'required' => true,
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( isset( $out['taxonomy'] ) ) {
			$out['taxonomy'] = sanitize_key( $out['taxonomy'] );
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$taxonomy = (string) ( $settings['taxonomy'] ?? '' );
		if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}
		$terms = get_the_terms( $object_id, $taxonomy );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}
		$names = array_map( static fn( $t ) => esc_html( $t->name ), $terms );
		return implode( ', ', $names );
	}

	// ------------------------------------------------------------------
	// SortableColumn
	// ------------------------------------------------------------------

	/**
	 * Sort posts by the alphabetical name of their first term in the chosen taxonomy. We GROUP BY
	 * post ID so a post with multiple terms doesn't appear duplicated.
	 */
	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		$taxonomy = (string) ( $settings['taxonomy'] ?? '' );
		if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		add_filter(
			'posts_clauses',
			static function ( array $clauses, $q ) use ( $taxonomy, $order, $query ) {
				if ( $q !== $query ) {
					return $clauses;
				}
				global $wpdb;
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->term_relationships} AS ck_tr ON {$wpdb->posts}.ID = ck_tr.object_id"
					. " LEFT JOIN {$wpdb->term_taxonomy} AS ck_tt ON ck_tr.term_taxonomy_id = ck_tt.term_taxonomy_id AND ck_tt.taxonomy = %s"
					. " LEFT JOIN {$wpdb->terms} AS ck_t ON ck_tt.term_id = ck_t.term_id",
					$taxonomy
				);
				$clauses['groupby'] = "{$wpdb->posts}.ID";
				$clauses['orderby'] = "MIN(ck_t.name) {$order}, {$wpdb->posts}.ID DESC";
				return $clauses;
			},
			10,
			2
		);
	}

	// ------------------------------------------------------------------
	// FilterableColumn
	// ------------------------------------------------------------------

	public function filter_value_keys(): array {
		return [ '' ];
	}

	public function render_filter( string $name_prefix, array $settings, array $current ): void {
		$taxonomy = (string) ( $settings['taxonomy'] ?? '' );
		if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$tax_obj = get_taxonomy( $taxonomy );
		wp_dropdown_categories(
			[
				'taxonomy'         => $taxonomy,
				/* translators: %s: taxonomy plural name */
				'show_option_all'  => sprintf( __( 'All %s', 'columnkit' ), $tax_obj ? $tax_obj->labels->name : $taxonomy ),
				'name'             => $name_prefix,
				'selected'         => (int) ( $current[''] ?? 0 ),
				'hide_empty'       => 0,
				'hierarchical'     => true,
				'value_field'      => 'term_id',
				'show_count'       => false,
			]
		);
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$taxonomy = (string) ( $settings['taxonomy'] ?? '' );
		$term_id  = (int) ( $values[''] ?? 0 );
		if ( $taxonomy === '' || $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$raw_tq = $query->get( 'tax_query' );
		$existing = is_array( $raw_tq ) ? $raw_tq : [];
		$existing[] = [
			'taxonomy' => $taxonomy,
			'field'    => 'term_id',
			'terms'    => $term_id,
		];
		$query->set( 'tax_query', $existing );
	}
}
