<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\MetaBox;

use ColumnKit\Columns\BaseColumn;
use ColumnKit\Columns\FilterableColumn;
use ColumnKit\Columns\SortableColumn;
use WP_Query;

/**
 * Meta Box field column — pick a field via dropdown, render via rwmb_get_value which handles
 * field-type formatting (images return arrays with URL, post-pickers return WP_Post arrays, etc.).
 *
 * Read-only display + sort + filter. Editing is intentionally NOT supported — defer to Meta Box's
 * own admin UI which handles every field type correctly.
 */
final class MetaBoxFieldColumn extends BaseColumn implements SortableColumn, FilterableColumn {
	public function get_type(): string {
		return 'metabox_field';
	}

	public function get_label(): string {
		return __( 'Meta Box Field', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Display a value from a Meta Box field. Auto-discovers fields from registered meta boxes.', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		return str_starts_with( $screen_key, 'post_type:' ) || $screen_key === 'media';
	}

	public function settings_fields(): array {
		return [
			[
				'key'      => 'field_id',
				'label'    => __( 'Meta Box Field', 'columnkit' ),
				'type'     => 'select',
				'options'  => $this->discover_field_options(),
				'required' => true,
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( isset( $out['field_id'] ) ) {
			$out['field_id'] = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $out['field_id'] );
		}
		return $out;
	}

	/** @return array<string, string> field_id => "Name (type)" */
	private function discover_field_options(): array {
		$out = [];
		// Meta Box exposes registered meta boxes via this filter. Calling apply_filters with []
		// returns the full list registered by themes / extensions / MB Builder.
		$meta_boxes = apply_filters( 'rwmb_meta_boxes', [] );
		if ( ! is_array( $meta_boxes ) ) {
			return [];
		}
		foreach ( $meta_boxes as $mb ) {
			$fields = $mb['fields'] ?? [];
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				$id   = is_array( $field ) ? (string) ( $field['id'] ?? '' ) : '';
				$name = is_array( $field ) ? (string) ( $field['name'] ?? $id ) : '';
				$type = is_array( $field ) ? (string) ( $field['type'] ?? 'text' ) : 'text';
				if ( $id === '' ) {
					continue;
				}
				$out[ $id ] = sprintf( '%s (%s)', $name !== '' ? $name : $id, $type );
			}
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$field_id = (string) ( $settings['field_id'] ?? '' );
		if ( $field_id === '' ) {
			return '';
		}

		// Try rwmb_get_value first (returns formatted/structured data), fall back to raw meta.
		$value = null;
		if ( function_exists( 'rwmb_get_value' ) ) {
			$value = rwmb_get_value( $field_id, [], $object_id );
		}
		if ( $value === null || $value === '' || $value === false ) {
			$raw = get_post_meta( $object_id, $field_id, true );
			if ( $raw === '' || $raw === false || $raw === null ) {
				return '';
			}
			return is_scalar( $raw ) ? esc_html( (string) $raw ) : esc_html( (string) wp_json_encode( $raw ) );
		}

		return $this->render_value( $value );
	}

	/** @param mixed $value */
	private function render_value( $value ): string {
		// Scalar — common case for text/number/textarea/select.
		if ( is_scalar( $value ) ) {
			return esc_html( (string) $value );
		}

		if ( ! is_array( $value ) ) {
			return esc_html( (string) wp_json_encode( $value ) );
		}

		// Image field (single) — Meta Box returns ['ID' => N, 'url' => '...', ...].
		if ( isset( $value['url'] ) && ( isset( $value['ID'] ) || isset( $value['id'] ) ) ) {
			$id  = (int) ( $value['ID'] ?? $value['id'] ?? 0 );
			$alt = (string) ( $value['alt'] ?? '' );
			if ( $id > 0 ) {
				$html = wp_get_attachment_image( $id, [ 40, 40 ], false, [ 'loading' => 'lazy', 'style' => 'max-height:40px;width:auto;' ] );
				return is_string( $html ) && $html !== '' ? $html : sprintf( '<img src="%s" alt="%s" style="max-height:40px;" />', esc_url( $value['url'] ), esc_attr( $alt ) );
			}
			return sprintf( '<img src="%s" alt="%s" style="max-height:40px;" />', esc_url( $value['url'] ), esc_attr( $alt ) );
		}

		// Post / user picker (single) — Meta Box returns a WP_Post / WP_User-like array.
		if ( isset( $value['ID'], $value['post_title'] ) ) {
			return esc_html( (string) $value['post_title'] );
		}
		if ( isset( $value['ID'], $value['display_name'] ) ) {
			return esc_html( (string) $value['display_name'] );
		}

		// Multi-value: array of scalars or arrays. Flatten to "a, b, c" up to 5.
		$flat = [];
		foreach ( array_slice( $value, 0, 5 ) as $item ) {
			if ( is_scalar( $item ) ) {
				$flat[] = (string) $item;
			} elseif ( is_array( $item ) ) {
				if ( isset( $item['post_title'] ) ) {
					$flat[] = (string) $item['post_title'];
				} elseif ( isset( $item['name'] ) ) {
					$flat[] = (string) $item['name'];
				} elseif ( isset( $item['url'] ) ) {
					$flat[] = (string) $item['url'];
				} else {
					$flat[] = (string) wp_json_encode( $item );
				}
			} elseif ( is_object( $item ) && isset( $item->name ) ) {
				$flat[] = (string) $item->name;
			}
		}
		$out = esc_html( implode( ', ', $flat ) );
		if ( count( $value ) > 5 ) {
			$out .= ' &hellip;';
		}
		return $out;
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$field_id = (string) ( $settings['field_id'] ?? '' );
		if ( $field_id === '' ) {
			return '';
		}
		// For export prefer raw meta (machine-readable) over rwmb_get_value (formatted).
		$raw = get_post_meta( $object_id, $field_id, true );
		if ( $raw === '' || $raw === false || $raw === null ) {
			return '';
		}
		if ( is_array( $raw ) || is_object( $raw ) ) {
			return (string) wp_json_encode( $raw );
		}
		return (string) $raw;
	}

	// ------------------------------------------------------------------
	// SortableColumn — meta_value LEFT JOIN
	// ------------------------------------------------------------------

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		$key = (string) ( $settings['field_id'] ?? '' );
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
				$alias = 'ck_mb_sort';
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
		$field_id   = (string) ( $settings['field_id'] ?? '' );
		$placeholder = $field_id !== '' ? $field_id : __( 'Meta Box field', 'columnkit' );
		printf(
			'<input type="search" name="%s" value="%s" placeholder="%s" />',
			esc_attr( $name_prefix ),
			esc_attr( (string) ( $current[''] ?? '' ) ),
			esc_attr( $placeholder )
		);
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$key = (string) ( $settings['field_id'] ?? '' );
		$v   = (string) ( $values[''] ?? '' );
		if ( $key === '' || $v === '' ) {
			return;
		}
		$raw_mq   = $query->get( 'meta_query' );
		$existing = is_array( $raw_mq ) ? $raw_mq : [];
		$existing[] = [ 'key' => $key, 'value' => $v, 'compare' => 'LIKE' ];
		$query->set( 'meta_query', $existing );
	}
}
