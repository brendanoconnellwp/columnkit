<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\Yoast;

use ColumnKit\ColumnRegistry;

/**
 * Loads Yoast SEO integration columns when Yoast is active.
 *
 * Detection is intentionally loose — works for both Yoast SEO (free) and Yoast SEO Premium,
 * since both store the same meta keys (_yoast_wpseo_*).
 */
final class Loader {
	public static function is_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
	}

	public function register( ColumnRegistry $registry ): void {
		if ( ! self::is_active() ) {
			return;
		}
		$registry->register( new SEOScoreColumn() );
		$registry->register( new ReadabilityColumn() );
		$registry->register( new FocusKeywordColumn() );
	}
}
