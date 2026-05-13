<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\Yoast;

use ColumnKit\Columns\BaseColumn;
use ColumnKit\Columns\FilterableColumn;
use ColumnKit\Columns\SortableColumn;
use WP_Query;

/**
 * Shared base for Yoast score columns (SEO score, readability score). Both read a 0..100 score
 * from a different meta key and render the same coloured badge.
 */
abstract class YoastScoreColumn extends BaseColumn implements SortableColumn, FilterableColumn {
	abstract protected function meta_key(): string;

	public function applies_to_screen( string $screen_key ): bool {
		return str_starts_with( $screen_key, 'post_type:' );
	}

	public function render( int $object_id, array $settings ): string {
		$score = get_post_meta( $object_id, $this->meta_key(), true );
		if ( $score === '' || $score === false || $score === null ) {
			return '<span class="ck-yoast-badge ck-yoast-none">—</span>';
		}
		$score = (int) $score;
		$class = $this->bucket_class( $score );
		return '<span class="ck-yoast-badge ' . esc_attr( $class ) . '">' . esc_html( (string) $score ) . '</span>';
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$score = get_post_meta( $object_id, $this->meta_key(), true );
		return ( $score === '' || $score === false || $score === null ) ? '' : (string) (int) $score;
	}

	private function bucket_class( int $score ): string {
		if ( $score >= 70 ) {
			return 'ck-yoast-good';
		}
		if ( $score >= 40 ) {
			return 'ck-yoast-ok';
		}
		return 'ck-yoast-bad';
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		$key = $this->meta_key();
		add_filter(
			'posts_clauses',
			static function ( array $clauses, $q ) use ( $key, $order, $query ) {
				if ( $q !== $query ) {
					return $clauses;
				}
				global $wpdb;
				$alias = 'ck_yoast_sort';
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->postmeta} AS {$alias} ON {$wpdb->posts}.ID = {$alias}.post_id AND {$alias}.meta_key = %s",
					$key
				);
				$clauses['orderby'] = "CAST({$alias}.meta_value AS UNSIGNED) {$order}, {$wpdb->posts}.ID DESC";
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
		$v = (string) ( $current[''] ?? '' );
		echo '<select name="' . esc_attr( $name_prefix ) . '">';
		printf( '<option value="">%s</option>', esc_html__( 'Score: any', 'columnkit' ) );
		foreach ( [
			'good' => __( 'Good (70+)', 'columnkit' ),
			'ok'   => __( 'OK (40-69)', 'columnkit' ),
			'bad'  => __( 'Needs work (< 40)', 'columnkit' ),
		] as $val => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $v, $val, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$v = (string) ( $values[''] ?? '' );
		$ranges = [
			'good' => [ 70, 100 ],
			'ok'   => [ 40, 69  ],
			'bad'  => [ 0,  39  ],
		];
		if ( ! isset( $ranges[ $v ] ) ) {
			return;
		}
		[ $min, $max ] = $ranges[ $v ];
		$raw_mq   = $query->get( 'meta_query' );
		$existing = is_array( $raw_mq ) ? $raw_mq : [];
		$existing[] = [ 'key' => $this->meta_key(), 'value' => [ $min, $max ], 'type' => 'NUMERIC', 'compare' => 'BETWEEN' ];
		$query->set( 'meta_query', $existing );
	}
}
