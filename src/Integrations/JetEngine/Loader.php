<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\JetEngine;

use ColumnKit\ColumnRegistry;

/**
 * Loads JetEngine integration columns when JetEngine (Crocoblock) is active.
 */
final class Loader {
	public static function is_active(): bool {
		return function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' );
	}

	public function register( ColumnRegistry $registry ): void {
		if ( ! self::is_active() ) {
			return;
		}
		$registry->register( new JetEngineFieldColumn() );
	}
}
