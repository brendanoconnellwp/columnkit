<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

/** The post's URL slug (post_name). Sortable and inline-editable; WP keeps it unique. */
final class PostSlugColumn extends AbstractPostFieldColumn implements SortableColumn, InlineOnlyEditable {
	public function get_type(): string {
		return 'post_slug';
	}

	public function get_label(): string {
		return __( 'Slug', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'The URL slug — fix it inline.', 'columnkit' );
	}

	public function render( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		if ( ! $post ) {
			return '';
		}
		$slug = urldecode( $post->post_name );
		return $slug !== '' ? '<code class="ck-slug">' . esc_html( $slug ) . '</code>' : self::empty_cell();
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return $post ? urldecode( $post->post_name ) : '';
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		$query->set( 'orderby', 'name' );
		$query->set( 'order', $order );
	}

	public function get_raw_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return $post ? urldecode( $post->post_name ) : '';
	}

	public function get_edit_input_type( array $settings ): string {
		return 'text';
	}

	public function get_edit_options( array $settings ): ?array {
		return null;
	}

	public function render_bulk_edit_field( string $input_name, array $settings ): void {
		// Inline-only: one slug shared by many posts makes no sense.
	}

	public function save_value( int $post_id, string $raw_value, array $settings ): void {
		$slug = sanitize_title( $raw_value );
		if ( $slug === '' ) {
			return;
		}
		self::update_post( $post_id, [ 'post_name' => $slug ] ); // wp_insert_post makes it unique.
	}
}
