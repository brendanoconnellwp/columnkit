<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

/**
 * Page template. Sortable and inline-editable from the templates the active theme offers for
 * the post type; wp_insert_post() itself rejects a template the theme doesn't have.
 */
final class PageTemplateColumn extends AbstractPostFieldColumn implements SortableColumn, InlineOnlyEditable {
	protected function supports_post_type( string $post_type ): bool {
		return self::templates( $post_type ) !== [];
	}

	public function get_type(): string {
		return 'page_template';
	}

	public function get_label(): string {
		return __( 'Template', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Which page template the item uses — switch it inline.', 'columnkit' );
	}

	/** @return array<string, string> file => name, from the active theme. */
	private static function templates( string $post_type ): array {
		static $cache = [];
		if ( ! isset( $cache[ $post_type ] ) ) {
			$templates            = wp_get_theme()->get_page_templates( null, $post_type );
			$cache[ $post_type ] = is_array( $templates ) ? array_map( 'strval', $templates ) : [];
		}
		return $cache[ $post_type ];
	}

	public function render( int $object_id, array $settings ): string {
		$file = (string) get_post_meta( $object_id, '_wp_page_template', true );
		if ( $file === '' || $file === 'default' ) {
			return '<span class="ck-muted">' . esc_html__( 'Default', 'columnkit' ) . '</span>';
		}
		$templates = self::templates( (string) get_post_type( $object_id ) );
		return esc_html( $templates[ $file ] ?? $file );
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$file = (string) get_post_meta( $object_id, '_wp_page_template', true );
		return $file === '' ? 'default' : $file;
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		global $wpdb;
		$join = "LEFT JOIN {$wpdb->postmeta} AS ck_tpl ON {$wpdb->posts}.ID = ck_tpl.post_id AND ck_tpl.meta_key = '_wp_page_template'";
		self::order_by_sql( $query, 'ck_tpl.meta_value', $order, $join );
	}

	public function get_raw_value( int $object_id, array $settings ): string {
		$file = (string) get_post_meta( $object_id, '_wp_page_template', true );
		return $file === '' ? 'default' : $file;
	}

	public function get_edit_input_type( array $settings ): string {
		return 'select';
	}

	public function get_edit_options( array $settings ): ?array {
		global $typenow;
		$post_type = is_string( $typenow ) && $typenow !== '' ? $typenow : 'page';
		return [ 'default' => __( 'Default template', 'columnkit' ) ] + self::templates( $post_type );
	}

	public function render_bulk_edit_field( string $input_name, array $settings ): void {
		// Inline-only: core's Bulk Edit already has a Template dropdown for pages.
	}

	public function save_value( int $post_id, string $raw_value, array $settings ): void {
		$post_type = (string) get_post_type( $post_id );
		if ( $raw_value !== 'default' && ! isset( self::templates( $post_type )[ $raw_value ] ) ) {
			return;
		}
		self::update_post( $post_id, [ 'page_template' => $raw_value ] );
	}
}
