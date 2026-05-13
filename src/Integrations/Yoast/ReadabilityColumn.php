<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\Yoast;

final class ReadabilityColumn extends YoastScoreColumn {
	public function get_type(): string {
		return 'yoast_readability';
	}

	public function get_label(): string {
		return __( 'Yoast Readability', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Yoast readability score (0–100, bucketed Good/OK/Needs work).', 'columnkit' );
	}

	protected function meta_key(): string {
		return '_yoast_wpseo_content_score';
	}
}
