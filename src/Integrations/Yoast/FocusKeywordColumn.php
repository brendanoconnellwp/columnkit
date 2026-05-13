<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\Yoast;

use ColumnKit\Columns\BaseColumn;
use ColumnKit\Columns\FilterableColumn;
use ColumnKit\Columns\SortableColumn;
use WP_Query;

final class FocusKeywordColumn extends BaseColumn implements SortableColumn, FilterableColumn {
	private const META_KEY = '_yoast_wpseo_focuskw';

	public function get_type(): string {
		return 'yoast_focus_keyword';
	}

	public function get_label(): string {
		return __( 'Yoast Focus Keyword', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'The focus keyword (or keyphrase) configured in Yoast SEO.', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		return str_starts_with( $screen_key, 'post_type:' );
	}

	public function render( int $object_id, array $settings ): string {
		return esc_html( (string) get_post_meta( $object_id, self::META_KEY, true ) );
	}

	public function get_export_value( int $object_id, array $settings ): string {
		return (string) get_post_meta( $object_id, self::META_KEY, true );
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		add_filter(
			'posts_clauses',
			static function ( array $clauses, $q ) use ( $order, $query ) {
				if ( $q !== $query ) {
					return $clauses;
				}
				global $wpdb;
				$alias = 'ck_yoast_kw';
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->postmeta} AS {$alias} ON {$wpdb->posts}.ID = {$alias}.post_id AND {$alias}.meta_key = %s",
					self::META_KEY
				);
				$clauses['orderby'] = "{$alias}.meta_value {$order}, {$wpdb->posts}.ID DESC";
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
		printf(
			'<input type="search" name="%s" value="%s" placeholder="%s" />',
			esc_attr( $name_prefix ),
			esc_attr( (string) ( $current[''] ?? '' ) ),
			esc_attr__( 'Focus keyword contains…', 'columnkit' )
		);
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$v = (string) ( $values[''] ?? '' );
		if ( $v === '' ) {
			return;
		}
		$raw_mq   = $query->get( 'meta_query' );
		$existing = is_array( $raw_mq ) ? $raw_mq : [];
		$existing[] = [ 'key' => self::META_KEY, 'value' => $v, 'compare' => 'LIKE' ];
		$query->set( 'meta_query', $existing );
	}
}
