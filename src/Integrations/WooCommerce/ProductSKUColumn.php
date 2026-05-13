<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\WooCommerce;

use ColumnKit\Columns\BaseColumn;
use ColumnKit\Columns\FilterableColumn;
use ColumnKit\Columns\SortableColumn;
use WP_Query;

/**
 * Product SKU column — reads _sku meta directly.
 */
final class ProductSKUColumn extends BaseColumn implements SortableColumn, FilterableColumn {
	public function get_type(): string {
		return 'wc_product_sku';
	}

	public function get_label(): string {
		return __( 'Product SKU', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		return $screen_key === 'post_type:product';
	}

	public function render( int $object_id, array $settings ): string {
		return esc_html( (string) get_post_meta( $object_id, '_sku', true ) );
	}

	public function get_export_value( int $object_id, array $settings ): string {
		return (string) get_post_meta( $object_id, '_sku', true );
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		add_filter(
			'posts_clauses',
			static function ( array $clauses, $q ) use ( $order, $query ) {
				if ( $q !== $query ) {
					return $clauses;
				}
				global $wpdb;
				$alias = 'ck_wc_sku';
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->postmeta} AS {$alias} ON {$wpdb->posts}.ID = {$alias}.post_id AND {$alias}.meta_key = %s",
					'_sku'
				);
				$clauses['orderby'] = "{$alias}.meta_value {$order}, {$wpdb->posts}.ID DESC";
				return $clauses;
			},
			10,
			2
		);
	}

	public function filter_value_keys(): array {
		return [ '' ];
	}

	public function render_filter( string $name_prefix, array $settings, array $current ): void {
		printf(
			'<input type="search" name="%s" value="%s" placeholder="%s" />',
			esc_attr( $name_prefix ),
			esc_attr( (string) ( $current[''] ?? '' ) ),
			esc_attr__( 'SKU contains…', 'columnkit' )
		);
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$v = (string) ( $values[''] ?? '' );
		if ( $v === '' ) {
			return;
		}
		$raw_mq   = $query->get( 'meta_query' );
		$existing = is_array( $raw_mq ) ? $raw_mq : [];
		$existing[] = [ 'key' => '_sku', 'value' => $v, 'compare' => 'LIKE' ];
		$query->set( 'meta_query', $existing );
	}
}
