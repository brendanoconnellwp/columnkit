<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\MetaBox;

use ColumnKit\ColumnRegistry;

/**
 * Loads Meta Box integration columns when Meta Box (metabox.io) is active.
 *
 * Detection uses the public `rwmb_get_value` function which is the documented entry point
 * for reading field values — present in Meta Box free + all extensions.
 */
final class Loader {
	public static function is_active(): bool {
		return function_exists( 'rwmb_get_value' );
	}

	public function register( ColumnRegistry $registry ): void {
		if ( ! self::is_active() ) {
			return;
		}
		$registry->register( new MetaBoxFieldColumn() );
	}
}
