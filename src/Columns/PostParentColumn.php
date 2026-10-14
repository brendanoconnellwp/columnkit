<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

/**
 * Parent page (hierarchical post types only). Sortable and inline-editable from a dropdown of the
 * post type's items; a save that would make a post its own ancestor is refused.
 */
final class PostParentColumn extends AbstractPostFieldColumn implements SortableColumn, InlineOnlyEditable {
	private const MAX_OPTIONS = 300;

	protected function supports_post_type( string $post_type ): bool {
		return is_post_type_hierarchical( $post_type );
	}

	public function get_type(): string {
		return 'post_parent';
	}

	public function get_label(): string {
		return __( 'Parent', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'The parent item — move pages around the hierarchy inline.', 'columnkit' );
	}

	public function render( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		if ( ! $post || (int) $post->post_parent === 0 ) {
			return self::empty_cell();
		}
		$title = get_the_title( (int) $post->post_parent );
		$link  = get_edit_post_link( (int) $post->post_parent );
		return $link
			? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>'
			: esc_html( $title );
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return ( $post && $post->post_parent ) ? get_the_title( (int) $post->post_parent ) : '';
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		$query->set( 'orderby', 'parent' );
		$query->set( 'order', $order );
	}

	public function get_raw_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return $post ? (string) (int) $post->post_parent : '0';
	}

	public function get_edit_input_type( array $settings ): string {
		return 'select';
	}

	/** Items of the current list's post type, indented by depth. */
	public function get_edit_options( array $settings ): ?array {
		static $cache = [];
		$post_type = self::current_post_type();
		if ( $post_type === '' ) {
			return null;
		}
		if ( isset( $cache[ $post_type ] ) ) {
			return $cache[ $post_type ];
		}
		$pages = get_pages( [ 'post_type' => $post_type, 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'number' => self::MAX_OPTIONS, 'sort_column' => 'menu_order,post_title' ] );
		$out   = [ '0' => __( '(no parent)', 'columnkit' ) ];
		$depth = static function ( $p ) {
			return count( get_post_ancestors( $p ) );
		};
		foreach ( is_array( $pages ) ? $pages : [] as $p ) {
			$out[ (string) $p->ID ] = str_repeat( '— ', $depth( $p ) ) . ( $p->post_title !== '' ? $p->post_title : __( '(no title)', 'columnkit' ) );
		}
		return $cache[ $post_type ] = $out;
	}

	public function render_bulk_edit_field( string $input_name, array $settings ): void {
		// Inline-only: core's own Bulk Edit already has a Parent dropdown.
	}

	public function save_value( int $post_id, string $raw_value, array $settings ): void {
		$post   = self::post( $post_id );
		$parent = (int) $raw_value;
		if ( ! $post || $parent === (int) $post->post_parent ) {
			return;
		}
		if ( $parent > 0 ) {
			$candidate = self::post( $parent );
			// Same post type, not itself, and not one of its own descendants (no cycles).
			if ( ! $candidate || $candidate->post_type !== $post->post_type || $parent === $post_id
				|| in_array( $post_id, get_post_ancestors( $candidate ), true ) ) {
				return;
			}
		}
		self::update_post( $post_id, [ 'post_parent' => max( 0, $parent ) ] );
	}

	private static function current_post_type(): string {
		global $typenow;
		if ( is_string( $typenow ) && $typenow !== '' ) {
			return $typenow;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return ( $screen && is_string( $screen->post_type ) ) ? $screen->post_type : '';
	}
}
