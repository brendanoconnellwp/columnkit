<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\Yoast;

final class SEOScoreColumn extends YoastScoreColumn {
	public function get_type(): string {
		return 'yoast_seo_score';
	}

	public function get_label(): string {
		return __( 'Yoast SEO Score', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Yoast SEO Score (0–100, bucketed Good/OK/Needs work).', 'columnkit' );
	}

	protected function meta_key(): string {
		return '_yoast_wpseo_linkdex';
	}
}
