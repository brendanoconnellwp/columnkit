<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\WooCommerce;

use ColumnKit\Columns\BaseColumn;
use ColumnKit\Columns\FilterableColumn;
use ColumnKit\Columns\SortableColumn;
use WP_Query;

/**
 * Product price column — uses _price meta key (regular or sale, normalised by WC).
 */
final class ProductPriceColumn extends BaseColumn implements SortableColumn, FilterableColumn {
	public function get_type(): string {
		return 'wc_product_price';
	}

	public function get_label(): string {
		return __( 'Product Price', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'WooCommerce product price (current — sale price if active, otherwise regular).', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		return $screen_key === 'post_type:product';
	}

	public function render( int $object_id, array $settings ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product = wc_get_product( $object_id );
		if ( ! $product ) {
			return '';
		}
		// $product->get_price_html() returns escaped HTML including <ins>/<del> for sale prices.
		return (string) $product->get_price_html();
	}

	public function get_export_value( int $object_id, array $settings ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product = wc_get_product( $object_id );
		if ( ! $product ) {
			return '';
		}
		return (string) $product->get_price();
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		add_filter(
			'posts_clauses',
			static function ( array $clauses, $q ) use ( $order, $query ) {
				if ( $q !== $query ) {
					return $clauses;
				}
				global $wpdb;
				$alias = 'ck_wc_price';
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->postmeta} AS {$alias} ON {$wpdb->posts}.ID = {$alias}.post_id AND {$alias}.meta_key = %s",
					'_price'
				);
				$clauses['orderby'] = "CAST({$alias}.meta_value AS DECIMAL(20,6)) {$order}, {$wpdb->posts}.ID DESC";
				return $clauses;
			},
			10,
			2
		);
	}

	public function filter_value_keys(): array {
		return [ 'min', 'max' ];
	}

	public function render_filter( string $name_prefix, array $settings, array $current ): void {
		printf(
			'<input type="number" step="any" name="%s__min" value="%s" placeholder="%s" style="width:7em" /> ',
			esc_attr( $name_prefix ),
			esc_attr( (string) ( $current['min'] ?? '' ) ),
			esc_attr__( 'Price ≥', 'columnkit' )
		);
		printf(
			'<input type="number" step="any" name="%s__max" value="%s" placeholder="%s" style="width:7em" />',
			esc_attr( $name_prefix ),
			esc_attr( (string) ( $current['max'] ?? '' ) ),
			esc_attr__( 'Price ≤', 'columnkit' )
		);
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$min = (string) ( $values['min'] ?? '' );
		$max = (string) ( $values['max'] ?? '' );
		if ( $min === '' && $max === '' ) {
			return;
		}
		$raw_mq = $query->get( 'meta_query' );
		$existing = is_array( $raw_mq ) ? $raw_mq : [];
		if ( $min !== '' && $max !== '' ) {
			$existing[] = [ 'key' => '_price', 'value' => [ $min, $max ], 'type' => 'NUMERIC', 'compare' => 'BETWEEN' ];
		} elseif ( $min !== '' ) {
			$existing[] = [ 'key' => '_price', 'value' => $min, 'type' => 'NUMERIC', 'compare' => '>=' ];
		} else {
			$existing[] = [ 'key' => '_price', 'value' => $max, 'type' => 'NUMERIC', 'compare' => '<=' ];
		}
		$query->set( 'meta_query', $existing );
	}
}
