<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

/**
 * Post status (Published / Draft / Pending / Private / Scheduled). Sortable and inline-editable;
 * publishing or making private requires the post type's publish_posts cap, same as core.
 */
final class PostStatusColumn extends AbstractPostFieldColumn implements SortableColumn, EditableColumn {
	/** Statuses offered in the editor. Scheduled ('future') is set by giving a future date. */
	private const EDITABLE = [ 'publish', 'draft', 'pending', 'private' ];

	public function get_type(): string {
		return 'post_status';
	}

	public function get_label(): string {
		return __( 'Status', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Published, draft, pending, private or scheduled — change it inline.', 'columnkit' );
	}

	public function render( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		if ( ! $post ) {
			return '';
		}
		$obj = get_post_status_object( $post->post_status );
		return sprintf(
			'<span class="ck-post-status ck-post-status-%s">%s</span>',
			esc_attr( sanitize_html_class( $post->post_status ) ),
			esc_html( $obj ? (string) $obj->label : $post->post_status )
		);
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return $post ? $post->post_status : '';
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		global $wpdb;
		self::order_by_sql( $query, "{$wpdb->posts}.post_status", $order );
	}

	// --- EditableColumn ------------------------------------------------

	public function get_raw_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return $post ? $post->post_status : '';
	}

	public function get_edit_input_type( array $settings ): string {
		return 'select';
	}

	public function get_edit_options( array $settings ): ?array {
		$out = [];
		foreach ( self::EDITABLE as $status ) {
			$obj            = get_post_status_object( $status );
			$out[ $status ] = $obj ? (string) $obj->label : $status;
		}
		return $out;
	}

	public function render_bulk_edit_field( string $input_name, array $settings ): void {
		echo '<select name="' . esc_attr( $input_name ) . '" class="ck-edit-input">';
		printf( '<option value="">%s</option>', esc_html__( '— (unchanged)', 'columnkit' ) );
		foreach ( (array) $this->get_edit_options( $settings ) as $value => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( (string) $value ), esc_html( (string) $label ) );
		}
		echo '</select>';
	}

	public function save_value( int $post_id, string $raw_value, array $settings ): void {
		$post = self::post( $post_id );
		if ( ! $post || ! in_array( $raw_value, self::EDITABLE, true ) || $raw_value === $post->post_status ) {
			return;
		}
		if ( in_array( $raw_value, [ 'publish', 'private' ], true ) ) {
			$pt_obj = get_post_type_object( $post->post_type );
			if ( ! current_user_can( $pt_obj->cap->publish_posts ?? 'publish_posts' ) ) {
				return; // Contributors can't publish — same rule as core.
			}
		}
		$fields = [ 'post_status' => $raw_value ];
		// A post scheduled in the future that's "published" now goes live now, as core does.
		if ( $raw_value === 'publish' && $post->post_status === 'future' ) {
			$fields['post_date']     = current_time( 'mysql' );
			$fields['post_date_gmt'] = current_time( 'mysql', true );
		}
		self::update_post( $post_id, $fields );
	}
}
