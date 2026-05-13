<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\WooCommerce;

use ColumnKit\Columns\BaseColumn;
use ColumnKit\Columns\FilterableColumn;
use WP_Query;

/**
 * Product stock column — shows stock status + quantity (when managed).
 */
final class ProductStockColumn extends BaseColumn implements FilterableColumn {
	public function get_type(): string {
		return 'wc_product_stock';
	}

	public function get_label(): string {
		return __( 'Product Stock', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Stock status (in / out / backorder) and quantity for WooCommerce products.', 'columnkit' );
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
		$status     = (string) $product->get_stock_status();
		$qty        = $product->get_stock_quantity();
		$status_map = [
			'instock'     => __( 'In stock', 'columnkit' ),
			'outofstock'  => __( 'Out of stock', 'columnkit' ),
			'onbackorder' => __( 'On backorder', 'columnkit' ),
		];
		$label = $status_map[ $status ] ?? $status;

		$qty_html = '';
		if ( $product->managing_stock() && $qty !== null ) {
			/* translators: %d: quantity */
			$qty_html = ' <span class="ck-stock-qty">(' . esc_html( (string) $qty ) . ')</span>';
		}
		return '<span class="ck-stock ck-stock-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>' . $qty_html;
	}

	public function get_export_value( int $object_id, array $settings ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product = wc_get_product( $object_id );
		if ( ! $product ) {
			return '';
		}
		$out = (string) $product->get_stock_status();
		if ( $product->managing_stock() ) {
			$qty = $product->get_stock_quantity();
			if ( $qty !== null ) {
				$out .= ' (' . $qty . ')';
			}
		}
		return $out;
	}

	public function filter_value_keys(): array {
		return [ '' ];
	}

	public function render_filter( string $name_prefix, array $settings, array $current ): void {
		$v = (string) ( $current[''] ?? '' );
		echo '<select name="' . esc_attr( $name_prefix ) . '">';
		printf( '<option value="">%s</option>', esc_html__( 'Stock: any', 'columnkit' ) );
		foreach ( [
			'instock'     => __( 'In stock', 'columnkit' ),
			'outofstock'  => __( 'Out of stock', 'columnkit' ),
			'onbackorder' => __( 'On backorder', 'columnkit' ),
		] as $val => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $v, $val, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$v       = (string) ( $values[''] ?? '' );
		$allowed = [ 'instock', 'outofstock', 'onbackorder' ];
		if ( ! in_array( $v, $allowed, true ) ) {
			return;
		}
		$raw_mq   = $query->get( 'meta_query' );
		$existing = is_array( $raw_mq ) ? $raw_mq : [];
		$existing[] = [ 'key' => '_stock_status', 'value' => $v, 'compare' => '=' ];
		$query->set( 'meta_query', $existing );
	}
}
