<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\WooCommerce;

use ColumnKit\ColumnRegistry;

/**
 * Loads WooCommerce integration columns when WC is active.
 *
 * v1 scope: Products (post_type=product) only. HPOS Orders deferred.
 */
final class Loader {
	public static function is_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	public function register( ColumnRegistry $registry ): void {
		if ( ! self::is_active() ) {
			return;
		}
		$registry->register( new ProductPriceColumn() );
		$registry->register( new ProductStockColumn() );
		$registry->register( new ProductSKUColumn() );
	}
}
