<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\ACF;

use ColumnKit\Columns\BaseColumn;
use ColumnKit\Columns\FilterableColumn;
use ColumnKit\Columns\SortableColumn;
use WP_Query;
use WP_Term;

/**
 * Generic ACF field column — pick a field via dropdown, render type-aware.
 *
 * Read-only display + sort + filter. Editing of ACF fields is intentionally NOT supported here
 * (defer to ACF's own admin UIs which already handle type-specific inputs correctly — see
 * project memory feedback_scope_custom_fields).
 *
 * Renderer dispatches by ACF field 'type' returned from get_field_object():
 *   image          → thumbnail (40x40)
 *   relationship   → comma-separated post titles (capped at 5)
 *   post_object    → same as relationship
 *   select / radio → label
 *   checkbox       → comma-separated values
 *   true_false     → Yes / No
 *   date_picker    → formatted date (ACF returns Y-m-d or formatted by its setting)
 *   number / range → numeric
 *   repeater       → "N rows"
 *   flexible_content → "N layouts"
 *   taxonomy       → term names
 *   user           → display names
 *   url            → linked
 *   email          → mailto link
 *   wysiwyg / textarea → first 100 chars plain text
 *   text and unknown → string
 */
final class ACFFieldColumn extends BaseColumn implements SortableColumn, FilterableColumn {
	public function get_type(): string {
		return 'acf_field';
	}

	public function get_label(): string {
		return __( 'ACF Field', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Display a value from an Advanced Custom Fields field.', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		// ACF supports users and terms too, but our integration is post-based for v1.
		return str_starts_with( $screen_key, 'post_type:' ) || $screen_key === 'media';
	}

	public function settings_fields(): array {
		return [
			[
				'key'      => 'field_name',
				'label'    => __( 'ACF Field', 'columnkit' ),
				'type'     => 'select',
				'options'  => $this->discover_field_options(),
				'required' => true,
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( isset( $out['field_name'] ) ) {
			// Field names in ACF are sanitised slugs.
			$out['field_name'] = preg_replace( '/[^A-Za-z0-9_]/', '', $out['field_name'] );
		}
		return $out;
	}

	/** @return array<string, string> field_name => "Label (type)" */
	private function discover_field_options(): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return [];
		}
		$out = [];
		foreach ( acf_get_field_groups() as $group ) {
			$fields = acf_get_fields( $group );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) || empty( $field['name'] ) ) {
					continue;
				}
				$label = (string) ( $field['label'] ?? $field['name'] );
				$type  = (string) ( $field['type']  ?? 'text' );
				$out[ (string) $field['name'] ] = sprintf( '%s (%s)', $label, $type );
			}
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$field_name = (string) ( $settings['field_name'] ?? '' );
		if ( $field_name === '' || ! function_exists( 'get_field_object' ) ) {
			return '';
		}
		$field = get_field_object( $field_name, $object_id );
		if ( ! is_array( $field ) ) {
			// Fall back to raw meta if field doesn't exist on this post's groups.
			$val = get_post_meta( $object_id, $field_name, true );
			if ( $val === '' || $val === false || $val === null ) {
				return '';
			}
			return esc_html( is_scalar( $val ) ? (string) $val : (string) wp_json_encode( $val ) );
		}
		return $this->render_value( $field['value'] ?? null, (string) ( $field['type'] ?? 'text' ), $field );
	}

	/** @param mixed $value */
	private function render_value( $value, string $type, array $field ): string {
		if ( $value === null || $value === '' || $value === false ) {
			return '';
		}

		switch ( $type ) {
			case 'image':
				$id = is_array( $value ) ? (int) ( $value['ID'] ?? 0 ) : (int) $value;
				if ( $id <= 0 ) {
					return '';
				}
				$html = wp_get_attachment_image( $id, [ 40, 40 ], false, [ 'loading' => 'lazy', 'style' => 'max-height:40px;width:auto;' ] );
				return is_string( $html ) ? $html : '';

			case 'relationship':
			case 'post_object':
				return $this->render_post_titles( (array) $value, 5 );

			case 'select':
			case 'radio':
				if ( is_array( $value ) ) {
					return esc_html( implode( ', ', array_map( 'strval', $value ) ) );
				}
				return esc_html( (string) $value );

			case 'checkbox':
				return esc_html( implode( ', ', array_map( 'strval', (array) $value ) ) );

			case 'true_false':
				return esc_html( $value ? __( 'Yes', 'columnkit' ) : __( 'No', 'columnkit' ) );

			case 'date_picker':
			case 'date_time_picker':
			case 'time_picker':
				return esc_html( (string) $value );

			case 'number':
			case 'range':
				return esc_html( (string) $value );

			case 'repeater':
				$count = is_array( $value ) ? count( $value ) : 0;
				return esc_html( sprintf( /* translators: %d: rows */ _n( '%d row', '%d rows', $count, 'columnkit' ), $count ) );

			case 'flexible_content':
				$count = is_array( $value ) ? count( $value ) : 0;
				return esc_html( sprintf( /* translators: %d: layouts */ _n( '%d layout', '%d layouts', $count, 'columnkit' ), $count ) );

			case 'taxonomy':
				$names = [];
				foreach ( (array) $value as $term ) {
					if ( $term instanceof WP_Term ) {
						$names[] = $term->name;
					} elseif ( is_numeric( $term ) ) {
						$t = get_term( (int) $term );
						if ( $t instanceof WP_Term ) {
							$names[] = $t->name;
						}
					}
				}
				return esc_html( implode( ', ', $names ) );

			case 'user':
				$names = [];
				foreach ( (array) $value as $u ) {
					$id = is_array( $u ) ? (int) ( $u['ID'] ?? 0 ) : (int) $u;
					$user = $id > 0 ? get_userdata( $id ) : false;
					if ( $user ) {
						$names[] = $user->display_name;
					}
				}
				return esc_html( implode( ', ', $names ) );

			case 'url':
				$url = (string) $value;
				return sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $url ), esc_html( $url ) );

			case 'email':
				$email = (string) $value;
				return sprintf( '<a href="mailto:%s">%s</a>', esc_attr( $email ), esc_html( $email ) );

			case 'wysiwyg':
			case 'textarea':
				$text = wp_strip_all_tags( (string) $value );
				$cap  = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 100 ) : substr( $text, 0, 100 );
				$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
				return esc_html( $cap ) . ( $len > 100 ? '…' : '' );

			case 'text':
			default:
				if ( is_array( $value ) || is_object( $value ) ) {
					return esc_html( (string) wp_json_encode( $value ) );
				}
				return esc_html( (string) $value );
		}
	}

	/** @param array<int, mixed> $items */
	private function render_post_titles( array $items, int $cap ): string {
		$titles = [];
		foreach ( array_slice( $items, 0, $cap ) as $item ) {
			if ( is_object( $item ) && isset( $item->post_title ) ) {
				$titles[] = (string) $item->post_title;
			} elseif ( is_numeric( $item ) ) {
				$title = get_the_title( (int) $item );
				if ( $title ) {
					$titles[] = $title;
				}
			}
		}
		$out = implode( ', ', array_map( 'esc_html', $titles ) );
		if ( count( $items ) > $cap ) {
			$out .= ' &hellip;';
		}
		return $out;
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$field_name = (string) ( $settings['field_name'] ?? '' );
		if ( $field_name === '' || ! function_exists( 'get_field' ) ) {
			return '';
		}
		$value = get_field( $field_name, $object_id );
		if ( $value === null || $value === '' || $value === false ) {
			return '';
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			// For complex types just JSON-encode for the export.
			return (string) wp_json_encode( $value );
		}
		return (string) $value;
	}

	// ------------------------------------------------------------------
	// SortableColumn — meta_value LEFT JOIN, same pattern as PostMetaColumn
	// ------------------------------------------------------------------

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		$key = (string) ( $settings['field_name'] ?? '' );
		if ( $key === '' ) {
			return;
		}
		add_filter(
			'posts_clauses',
			static function ( array $clauses, $q ) use ( $key, $order, $query ) {
				if ( $q !== $query ) {
					return $clauses;
				}
				global $wpdb;
				$alias = 'ck_acf_sort';
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->postmeta} AS {$alias} ON {$wpdb->posts}.ID = {$alias}.post_id AND {$alias}.meta_key = %s",
					$key
				);
				$clauses['orderby'] = "{$alias}.meta_value {$order}, {$wpdb->posts}.ID DESC";
				return $clauses;
			},
			10,
			2
		);
	}

	// ------------------------------------------------------------------
	// FilterableColumn — text contains
	// ------------------------------------------------------------------

	public function filter_value_keys(): array {
		return [ '' ];
	}

	public function render_filter( string $name_prefix, array $settings, array $current ): void {
		$field_name = (string) ( $settings['field_name'] ?? '' );
		$placeholder = $field_name !== '' ? $field_name : __( 'ACF field', 'columnkit' );
		printf(
			'<input type="search" name="%s" value="%s" placeholder="%s" />',
			esc_attr( $name_prefix ),
			esc_attr( (string) ( $current[''] ?? '' ) ),
			esc_attr( $placeholder )
		);
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$key = (string) ( $settings['field_name'] ?? '' );
		$v   = (string) ( $values[''] ?? '' );
		if ( $key === '' || $v === '' ) {
			return;
		}
		$raw_mq = $query->get( 'meta_query' );
		$existing = is_array( $raw_mq ) ? $raw_mq : [];
		$existing[] = [ 'key' => $key, 'value' => $v, 'compare' => 'LIKE' ];
		$query->set( 'meta_query', $existing );
	}
}
