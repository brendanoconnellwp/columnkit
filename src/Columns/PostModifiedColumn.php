<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

/** When the post was last changed — as a date or "3 days ago". Sortable. */
final class PostModifiedColumn extends AbstractPostFieldColumn implements SortableColumn {
	public function get_type(): string {
		return 'post_modified';
	}

	public function get_label(): string {
		return __( 'Last modified', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'When the post was last updated. Sort to find stale content.', 'columnkit' );
	}

	public function settings_fields(): array {
		return [
			[
				'key'     => 'format',
				'label'   => __( 'Show as', 'columnkit' ),
				'type'    => 'select',
				'options' => [
					'relative' => __( 'Relative ("3 days ago")', 'columnkit' ),
					'date'     => __( 'Date', 'columnkit' ),
					'datetime' => __( 'Date and time', 'columnkit' ),
				],
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( ! in_array( $out['format'] ?? '', [ 'relative', 'date', 'datetime' ], true ) ) {
			$out['format'] = 'relative';
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		// get_post_timestamp() copes with drafts, whose *_gmt dates can be zeroed.
		$ts = $post ? get_post_timestamp( $post, 'modified' ) : false;
		if ( ! $ts ) {
			return self::empty_cell();
		}
		$full     = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
		$format   = (string) ( $settings['format'] ?? 'relative' );
		$text     = match ( $format ) {
			'date'     => wp_date( (string) get_option( 'date_format' ), $ts ),
			'datetime' => $full,
			/* translators: %s: human-readable time difference, e.g. "3 days" */
			default    => sprintf( __( '%s ago', 'columnkit' ), human_time_diff( $ts ) ),
		};
		return '<abbr title="' . esc_attr( (string) $full ) . '">' . esc_html( (string) $text ) . '</abbr>';
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return $post ? $post->post_modified : '';
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		$query->set( 'orderby', 'modified' );
		$query->set( 'order', $order );
	}
}
