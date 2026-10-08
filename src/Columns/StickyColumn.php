<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

/**
 * Whether a post is sticky (posts only). Inline + bulk editable and filterable. Same permission
 * rule as core Quick Edit: edit_others_posts AND publish_posts.
 */
final class StickyColumn extends AbstractPostFieldColumn implements FilterableColumn, EditableColumn {
	protected function supports_post_type( string $post_type ): bool {
		return $post_type === 'post';
	}

	public function get_type(): string {
		return 'sticky';
	}

	public function get_label(): string {
		return __( 'Sticky', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Whether the post is pinned to the top of the blog — toggle it inline.', 'columnkit' );
	}

	public function render( int $object_id, array $settings ): string {
		return is_sticky( $object_id )
			? '<span class="dashicons dashicons-sticky" aria-hidden="true"></span> ' . esc_html__( 'Yes', 'columnkit' )
			: '<span class="ck-muted">' . esc_html__( 'No', 'columnkit' ) . '</span>';
	}

	public function get_export_value( int $object_id, array $settings ): string {
		return is_sticky( $object_id ) ? '1' : '0';
	}

	public function get_raw_value( int $object_id, array $settings ): string {
		return is_sticky( $object_id ) ? '1' : '0';
	}

	public function get_edit_input_type( array $settings ): string {
		return 'boolean';
	}

	public function get_edit_options( array $settings ): ?array {
		return null;
	}

	public function render_bulk_edit_field( string $input_name, array $settings ): void {
		echo '<select name="' . esc_attr( $input_name ) . '" class="ck-edit-input">';
		printf( '<option value="">%s</option>', esc_html__( '— (unchanged)', 'columnkit' ) );
		printf( '<option value="1">%s</option>', esc_html__( 'Yes', 'columnkit' ) );
		printf( '<option value="0">%s</option>', esc_html__( 'No', 'columnkit' ) );
		echo '</select>';
	}

	public function save_value( int $post_id, string $raw_value, array $settings ): void {
		if ( $raw_value === '' || get_post_type( $post_id ) !== 'post' ) {
			return;
		}
		$pt = get_post_type_object( 'post' );
		if ( ! current_user_can( $pt->cap->edit_others_posts ?? 'edit_others_posts' ) || ! current_user_can( $pt->cap->publish_posts ?? 'publish_posts' ) ) {
			return;
		}
		if ( in_array( strtolower( $raw_value ), [ '1', 'true', 'yes', 'on' ], true ) ) {
			stick_post( $post_id );
		} else {
			unstick_post( $post_id );
		}
	}

	public function filter_value_keys(): array {
		return [ '' ];
	}

	public function render_filter( string $name_prefix, array $settings, array $current ): void {
		$v = (string) ( $current[''] ?? '' );
		echo '<select name="' . esc_attr( $name_prefix ) . '">';
		printf( '<option value="">%s</option>', esc_html__( 'Sticky: any', 'columnkit' ) );
		printf( '<option value="1" %s>%s</option>', selected( $v, '1', false ), esc_html__( 'Sticky', 'columnkit' ) );
		printf( '<option value="0" %s>%s</option>', selected( $v, '0', false ), esc_html__( 'Not sticky', 'columnkit' ) );
		echo '</select>';
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$v      = (string) ( $values[''] ?? '' );
		$sticky = array_map( 'intval', (array) get_option( 'sticky_posts', [] ) );
		if ( $v === '1' ) {
			$query->set( 'post__in', $sticky !== [] ? $sticky : [ 0 ] );
		} elseif ( $v === '0' && $sticky !== [] ) {
			$query->set( 'post__not_in', $sticky );
		}
	}
}
