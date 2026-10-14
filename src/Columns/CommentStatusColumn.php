<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

/** Whether comments are open. Sortable, inline + bulk editable, filterable. */
final class CommentStatusColumn extends AbstractPostFieldColumn implements SortableColumn, FilterableColumn, EditableColumn {
	protected function supports_post_type( string $post_type ): bool {
		return post_type_supports( $post_type, 'comments' );
	}

	public function get_type(): string {
		return 'comment_status';
	}

	public function get_label(): string {
		return __( 'Comments open', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Whether new comments are allowed — toggle it inline.', 'columnkit' );
	}

	public function render( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		if ( ! $post ) {
			return '';
		}
		return $post->comment_status === 'open'
			? esc_html__( 'Open', 'columnkit' )
			: '<span class="ck-muted">' . esc_html__( 'Closed', 'columnkit' ) . '</span>';
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return $post ? $post->comment_status : '';
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		global $wpdb;
		self::order_by_sql( $query, "{$wpdb->posts}.comment_status", $order );
	}

	public function get_raw_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return ( $post && $post->comment_status === 'open' ) ? '1' : '0';
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
		printf( '<option value="1">%s</option>', esc_html__( 'Open', 'columnkit' ) );
		printf( '<option value="0">%s</option>', esc_html__( 'Closed', 'columnkit' ) );
		echo '</select>';
	}

	public function save_value( int $post_id, string $raw_value, array $settings ): void {
		if ( $raw_value === '' ) {
			return;
		}
		$open = in_array( strtolower( $raw_value ), [ '1', 'true', 'yes', 'on', 'open' ], true );
		self::update_post( $post_id, [ 'comment_status' => $open ? 'open' : 'closed' ] );
	}

	public function filter_value_keys(): array {
		return [ '' ];
	}

	public function render_filter( string $name_prefix, array $settings, array $current ): void {
		$v = (string) ( $current[''] ?? '' );
		echo '<select name="' . esc_attr( $name_prefix ) . '">';
		printf( '<option value="">%s</option>', esc_html__( 'Comments: any', 'columnkit' ) );
		printf( '<option value="open" %s>%s</option>', selected( $v, 'open', false ), esc_html__( 'Open', 'columnkit' ) );
		printf( '<option value="closed" %s>%s</option>', selected( $v, 'closed', false ), esc_html__( 'Closed', 'columnkit' ) );
		echo '</select>';
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$v = (string) ( $values[''] ?? '' );
		if ( $v !== 'open' && $v !== 'closed' ) {
			return;
		}
		add_filter(
			'posts_where',
			static function ( string $where, $q ) use ( $query, $v ) {
				if ( $q !== $query ) {
					return $where;
				}
				global $wpdb;
				return $where . $wpdb->prepare( " AND {$wpdb->posts}.comment_status = %s", $v );
			},
			10,
			2
		);
	}
}
