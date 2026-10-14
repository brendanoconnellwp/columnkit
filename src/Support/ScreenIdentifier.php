<?php
declare( strict_types=1 );

namespace ColumnKit\Support;

use WP_Screen;

/**
 * Maps WP_Screen objects to stable internal screen keys.
 *
 * Recognised screen kinds:
 *   - post_type:{slug}  → posts/pages/CPTs
 *   - media             → /wp-admin/upload.php (Media library)
 *   - users             → /wp-admin/users.php
 *   - taxonomy:{slug}   → /wp-admin/edit-tags.php for a taxonomy
 */
final class ScreenIdentifier {
	public static function from_screen( WP_Screen $screen ): ?string {
		// Media library — WP_Media_List_Table uses `manage_media_*` hooks, so we identify it
		// by base, not by post_type=attachment.
		if ( $screen->base === 'upload' ) {
			return 'media';
		}
		if ( $screen->base === 'edit' && is_string( $screen->post_type ) && $screen->post_type !== '' ) {
			return 'post_type:' . $screen->post_type;
		}
		if ( $screen->base === 'users' ) {
			return 'users';
		}
		if ( $screen->base === 'edit-tags' && is_string( $screen->taxonomy ) && $screen->taxonomy !== '' ) {
			return 'taxonomy:' . $screen->taxonomy;
		}
		return null;
	}

	public static function post_type( string $screen_key ): ?string {
		if ( str_starts_with( $screen_key, 'post_type:' ) ) {
			return substr( $screen_key, strlen( 'post_type:' ) );
		}
		return null;
	}

	public static function taxonomy( string $screen_key ): ?string {
		if ( str_starts_with( $screen_key, 'taxonomy:' ) ) {
			return substr( $screen_key, strlen( 'taxonomy:' ) );
		}
		return null;
	}

	public static function is_users( string $screen_key ): bool {
		return $screen_key === 'users';
	}

	public static function is_media( string $screen_key ): bool {
		return $screen_key === 'media';
	}

	/** Admin URL of the list table a screen key refers to. */
	public static function list_url( string $screen_key ): string {
		if ( self::is_users( $screen_key ) ) {
			return admin_url( 'users.php' );
		}
		if ( self::is_media( $screen_key ) ) {
			return admin_url( 'upload.php?mode=list' );
		}
		$taxonomy = self::taxonomy( $screen_key );
		if ( $taxonomy !== null ) {
			$args    = [ 'taxonomy' => $taxonomy ];
			$tax_obj = get_taxonomy( $taxonomy );
			$pt      = $tax_obj && ! empty( $tax_obj->object_type ) ? (string) reset( $tax_obj->object_type ) : '';
			if ( $pt !== '' && $pt !== 'post' ) {
				$args['post_type'] = $pt;
			}
			return add_query_arg( $args, admin_url( 'edit-tags.php' ) );
		}
		$post_type = (string) self::post_type( $screen_key );
		return $post_type === 'post' ? admin_url( 'edit.php' ) : add_query_arg( 'post_type', $post_type, admin_url( 'edit.php' ) );
	}

	/**
	 * @return array<string, string> screen_key => human label, for the settings UI dropdown.
	 */
	public static function available_screens(): array {
		$out = [];

		foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $pt ) {
			if ( $pt->name === 'attachment' ) {
				continue;
			}
			$out[ 'post_type:' . $pt->name ] = sprintf(
				/* translators: %s: post type label */
				__( 'Posts — %s', 'columnkit' ),
				$pt->labels->name
			);
		}

		$out['media'] = __( 'Media library', 'columnkit' );
		$out['users'] = __( 'Users', 'columnkit' );

		$taxonomies = get_taxonomies( [ 'show_ui' => true ], 'objects' );
		$label_uses = array_count_values( array_map( static fn( $t ) => (string) $t->labels->name, $taxonomies ) );
		foreach ( $taxonomies as $tax ) {
			$label = (string) $tax->labels->name;
			// Two taxonomies both called "Categories" (core + a CPT's) need telling apart.
			if ( ( $label_uses[ $label ] ?? 0 ) > 1 && ! empty( $tax->object_type ) ) {
				$pt_obj = get_post_type_object( (string) reset( $tax->object_type ) );
				if ( $pt_obj && $pt_obj->name !== 'post' ) {
					$label .= ' (' . $pt_obj->labels->name . ')';
				}
			}
			$out[ 'taxonomy:' . $tax->name ] = sprintf(
				/* translators: %s: taxonomy plural label */
				__( 'Taxonomy — %s', 'columnkit' ),
				$label
			);
		}

		return $out;
	}

	/**
	 * Screens that exist (show_ui) but that nobody manages columns on: block-editor and
	 * plugin-internal post types (patterns, navigation menus, ACF's own field-group screens)
	 * and taxonomies that aren't in the admin menu. Hidden from the editor's sidebar only — they
	 * stay valid screen keys so nothing already configured breaks.
	 */
	public static function is_internal( string $screen_key ): bool {
		$post_type = self::post_type( $screen_key );
		if ( $post_type !== null ) {
			if ( in_array( $post_type, [ 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_font_family', 'wp_font_face' ], true )
				|| str_starts_with( $post_type, 'acf-' ) ) {
				return true;
			}
			$obj = get_post_type_object( $post_type );
			return $obj !== null && $obj->show_in_menu === false;
		}
		$taxonomy = self::taxonomy( $screen_key );
		if ( $taxonomy !== null ) {
			if ( in_array( $taxonomy, [ 'wp_pattern_category', 'nav_menu', 'post_format' ], true ) ) {
				return true;
			}
			if ( $taxonomy === 'link_category' ) {
				return ! get_option( 'link_manager_enabled' );
			}
			$obj = get_taxonomy( $taxonomy );
			return $obj !== false && $obj->show_in_menu === false;
		}
		return false;
	}
}
