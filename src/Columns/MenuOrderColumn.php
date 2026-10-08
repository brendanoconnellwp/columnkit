<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

/** The "Order" page attribute (menu_order). Sortable, inline- and bulk-editable. */
final class MenuOrderColumn extends AbstractPostFieldColumn implements SortableColumn, EditableColumn {
	protected function supports_post_type( string $post_type ): bool {
		return post_type_supports( $post_type, 'page-attributes' );
	}

	public function get_type(): string {
		return 'menu_order';
	}

	public function get_label(): string {
		return __( 'Order', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'The page-attributes "Order" number used for manual sorting.', 'columnkit' );
	}

	public function render( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return $post ? esc_html( (string) (int) $post->menu_order ) : '';
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		$query->set( 'orderby', 'menu_order' );
		$query->set( 'order', $order );
	}

	public function get_raw_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return $post ? (string) (int) $post->menu_order : '0';
	}

	public function get_edit_input_type( array $settings ): string {
		return 'number';
	}

	public function get_edit_options( array $settings ): ?array {
		return null;
	}

	public function render_bulk_edit_field( string $input_name, array $settings ): void {
		printf( '<input type="number" step="1" name="%s" value="" class="ck-edit-input" />', esc_attr( $input_name ) );
	}

	public function save_value( int $post_id, string $raw_value, array $settings ): void {
		if ( $raw_value === '' || ! is_numeric( $raw_value ) ) {
			return;
		}
		self::update_post( $post_id, [ 'menu_order' => (int) $raw_value ] );
	}
}
