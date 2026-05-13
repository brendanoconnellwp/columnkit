<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\ACF;

use ColumnKit\ColumnRegistry;

/**
 * Loads ACF integration column types when Advanced Custom Fields is active.
 */
final class Loader {
	public static function is_active(): bool {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'get_field_object' );
	}

	public function register( ColumnRegistry $registry ): void {
		if ( ! self::is_active() ) {
			return;
		}
		$registry->register( new ACFFieldColumn() );
	}
}
