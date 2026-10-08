<?php
declare( strict_types=1 );

namespace ColumnKit\Support;

use WP_Error;
use WP_Post;
use WP_Term;

/**
 * Shared plumbing for editing a post's terms inline — used by both WordPress's own taxonomy
 * columns (Categories, Tags, `taxonomy-{tax}`) and ColumnKit's Taxonomy column.
 *
 * Wire format of the edited value (one sanitize_text_field-safe string):
 *   "12,15"            → assign term IDs 12 and 15 (replacing the current set)
 *   "12,15|Foo, Bar"   → …plus new terms named Foo and Bar (non-hierarchical taxonomies only,
 *                        exactly what core's Quick Edit tags box allows)
 *   ""                 → remove all terms
 */
final class TermAssigner {
	/** Picker lists are capped; beyond this the taxonomy is edited in core's own UI. */
	public const MAX_TERMS = 500;

	/** Taxonomies on a post type whose terms the current user may (re)assign. @return array<int, \WP_Taxonomy> */
	public static function editable_taxonomies( string $post_type ): array {
		$out = [];
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $tax ) {
			if ( ! $tax->show_ui || ! current_user_can( $tax->cap->assign_terms ) ) {
				continue;
			}
			$out[] = $tax;
		}
		return $out;
	}

	/**
	 * Picker config for JS: label, hierarchical flag, whether new names may be typed, and the
	 * term list (hierarchy flattened with depth). Null when the taxonomy is too large to list.
	 *
	 * @return array{label: string, hierarchical: bool, allowNew: bool, terms: array<int, array{id: int, name: string, depth: int}>}|null
	 */
	public static function picker_config( string $taxonomy ): ?array {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			return null;
		}
		$count = (int) wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
		if ( $count > self::MAX_TERMS ) {
			return null;
		}
		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'number' => self::MAX_TERMS ] );
		$terms = is_array( $terms ) ? $terms : [];

		$list = [];
		if ( $tax->hierarchical ) {
			$children = [];
			foreach ( $terms as $t ) {
				$children[ (int) $t->parent ][] = $t;
			}
			$walk = static function ( int $parent, int $depth ) use ( &$walk, &$list, $children ): void {
				foreach ( $children[ $parent ] ?? [] as $t ) {
					$list[] = [ 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'depth' => $depth ];
					$walk( (int) $t->term_id, $depth + 1 );
				}
			};
			$walk( 0, 0 );
		} else {
			foreach ( $terms as $t ) {
				$list[] = [ 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'depth' => 0 ];
			}
		}

		return [
			'label'        => (string) $tax->labels->name,
			'hierarchical' => (bool) $tax->hierarchical,
			'allowNew'     => ! $tax->hierarchical,
			'terms'        => $list,
		];
	}

	public static function raw_value( int $post_id, string $taxonomy ): string {
		$ids = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
		return is_array( $ids ) ? implode( ',', array_map( 'intval', $ids ) ) : '';
	}

	/**
	 * Replace a post's terms in one taxonomy. Caller has already checked edit_post.
	 *
	 * @return true|WP_Error
	 */
	public static function assign( WP_Post $post, string $taxonomy, string $raw ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return new WP_Error( 'ck_bad_taxonomy', __( 'This taxonomy is not used by this post type.', 'columnkit' ) );
		}
		if ( ! current_user_can( $tax->cap->assign_terms ) ) {
			return new WP_Error( 'ck_forbidden', __( 'You cannot assign these terms.', 'columnkit' ) );
		}

		[ $id_part, $new_part ] = array_pad( explode( '|', $raw, 2 ), 2, '' );

		$ids = [];
		foreach ( explode( ',', $id_part ) as $piece ) {
			$id = (int) trim( $piece );
			if ( $id <= 0 ) {
				continue;
			}
			$term = get_term( $id, $taxonomy );
			if ( $term instanceof WP_Term ) {
				$ids[] = (int) $term->term_id; // Only terms that really belong to this taxonomy.
			}
		}

		$terms = $ids;
		if ( ! $tax->hierarchical && trim( $new_part ) !== '' ) {
			foreach ( explode( ',', $new_part ) as $name ) {
				$name = trim( $name );
				if ( $name !== '' ) {
					$terms[] = $name; // wp_set_object_terms() creates missing names, like Quick Edit.
				}
			}
		}

		$result = wp_set_object_terms( $post->ID, $terms, $taxonomy, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		clean_post_cache( $post->ID );
		return true;
	}

	/** Cell HTML matching core's taxonomy column: names linking to the filtered list. */
	public static function render_links( WP_Post $post, string $taxonomy ): string {
		$terms = get_the_terms( $post->ID, $taxonomy );
		if ( ! is_array( $terms ) || $terms === [] ) {
			return '<span aria-hidden="true">&#8212;</span>';
		}
		$tax  = get_taxonomy( $taxonomy );
		$out  = [];
		foreach ( $terms as $t ) {
			$args = [ 'post_type' => $post->post_type ];
			if ( $taxonomy === 'category' ) {
				$args['category_name'] = $t->slug;
			} elseif ( $taxonomy === 'post_tag' ) {
				$args['tag'] = $t->slug;
			} elseif ( $tax && $tax->query_var ) {
				$args[ $tax->query_var ] = $t->slug;
			} else {
				$args['taxonomy'] = $taxonomy;
				$args['term']     = $t->slug;
			}
			$out[] = sprintf( '<a href="%s">%s</a>', esc_url( add_query_arg( $args, admin_url( 'edit.php' ) ) ), esc_html( $t->name ) );
		}
		return implode( ', ', $out );
	}

	/** WP's list-table column key for a taxonomy: categories / tags / taxonomy-{slug}. */
	public static function core_column_key( string $taxonomy ): string {
		if ( $taxonomy === 'category' ) {
			return 'categories';
		}
		if ( $taxonomy === 'post_tag' ) {
			return 'tags';
		}
		return 'taxonomy-' . $taxonomy;
	}

	public static function taxonomy_from_core_key( string $key ): string {
		if ( $key === 'categories' ) {
			return 'category';
		}
		if ( $key === 'tags' ) {
			return 'post_tag';
		}
		return str_starts_with( $key, 'taxonomy-' ) ? substr( $key, strlen( 'taxonomy-' ) ) : '';
	}
}
