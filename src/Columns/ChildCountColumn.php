<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

/**
 * Count of a post's children — either attached media, or child items of the same type (sub-pages).
 * One column type, two modes, both sortable via a correlated COUNT subquery.
 */
final class ChildCountColumn extends AbstractPostFieldColumn implements SortableColumn {
	public function get_type(): string {
		return 'child_count';
	}

	public function get_label(): string {
		return __( 'Attachments / children', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'How many media files are attached, or how many child pages it has.', 'columnkit' );
	}

	public function settings_fields(): array {
		return [
			[
				'key'     => 'count',
				'label'   => __( 'Count', 'columnkit' ),
				'type'    => 'select',
				'options' => [
					'attachments' => __( 'Attached media', 'columnkit' ),
					'children'    => __( 'Child items (sub-pages)', 'columnkit' ),
				],
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( ! in_array( $out['count'] ?? '', [ 'attachments', 'children' ], true ) ) {
			$out['count'] = 'attachments';
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		if ( ! $post ) {
			return '';
		}
		$n = self::count( $post->ID, self::child_type( $post->post_type, $settings ) );
		if ( $n === 0 ) {
			return '<span class="ck-muted">0</span>';
		}
		return esc_html( number_format_i18n( $n ) );
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return $post ? (string) self::count( $post->ID, self::child_type( $post->post_type, $settings ) ) : '';
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		global $wpdb;
		$parent_type = $query->get( 'post_type' );
		$child_type  = self::child_type( is_string( $parent_type ) ? $parent_type : 'post', $settings );
		$expr        = $wpdb->prepare(
			"(SELECT COUNT(*) FROM {$wpdb->posts} AS ck_c WHERE ck_c.post_parent = {$wpdb->posts}.ID AND ck_c.post_type = %s AND ck_c.post_status NOT IN ('trash','auto-draft'))",
			$child_type
		);
		self::order_by_sql( $query, $expr, $order );
	}

	/** @param array<string, mixed> $settings */
	private static function child_type( string $parent_type, array $settings ): string {
		return ( $settings['count'] ?? 'attachments' ) === 'children' ? $parent_type : 'attachment';
	}

	private static function count( int $post_id, string $child_type ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ('trash','auto-draft')",
				$post_id,
				$child_type
			)
		);
	}
}
