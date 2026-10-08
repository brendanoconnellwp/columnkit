<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

/**
 * Who last edited the post (core's `_edit_last` meta — the same source as "Last edited by …" in
 * the editor). Sortable by name and filterable by user.
 */
final class ModifiedByColumn extends AbstractPostFieldColumn implements SortableColumn, FilterableColumn {
	public function get_type(): string {
		return 'modified_by';
	}

	public function get_label(): string {
		return __( 'Modified by', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'The user who last edited the post.', 'columnkit' );
	}

	public function render( int $object_id, array $settings ): string {
		$user_id = (int) get_post_meta( $object_id, '_edit_last', true );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user ? esc_html( $user->display_name ) : self::empty_cell();
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$user_id = (int) get_post_meta( $object_id, '_edit_last', true );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user ? $user->display_name : '';
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		global $wpdb;
		$join = "LEFT JOIN {$wpdb->postmeta} AS ck_el ON {$wpdb->posts}.ID = ck_el.post_id AND ck_el.meta_key = '_edit_last'"
			. " LEFT JOIN {$wpdb->users} AS ck_elu ON ck_elu.ID = CAST(ck_el.meta_value AS UNSIGNED)";
		self::order_by_sql( $query, 'ck_elu.display_name', $order, $join );
	}

	public function filter_value_keys(): array {
		return [ '' ];
	}

	public function render_filter( string $name_prefix, array $settings, array $current ): void {
		wp_dropdown_users(
			[
				'name'            => $name_prefix,
				'show_option_all' => __( 'Modified by: anyone', 'columnkit' ),
				'selected'        => (int) ( $current[''] ?? 0 ),
				'capability'      => [ 'edit_posts' ],
			]
		);
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$user_id = (int) ( $values[''] ?? 0 );
		if ( $user_id <= 0 ) {
			return;
		}
		$raw_mq     = $query->get( 'meta_query' );
		$existing   = is_array( $raw_mq ) ? $raw_mq : [];
		$existing[] = [ 'key' => '_edit_last', 'value' => (string) $user_id ];
		$query->set( 'meta_query', $existing );
	}
}
