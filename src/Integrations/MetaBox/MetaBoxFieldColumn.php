<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\MetaBox;

use ColumnKit\Columns\BaseColumn;
use ColumnKit\Columns\ConditionallyEditableColumn;
use ColumnKit\Columns\FilterableColumn;
use ColumnKit\Columns\SortableColumn;
use WP_Query;

/**
 * Meta Box field column — pick a field via dropdown, render via rwmb_get_value which handles
 * field-type formatting (images return arrays with URL, post-pickers return WP_Post arrays, etc.).
 *
 * Display + sort + filter, plus inline/bulk edit for single, non-clone scalar fields: text,
 * email, url, number, range, checkbox/switch (boolean), single select/radio, and plain Y-m-d
 * dates. These store as ordinary post meta keyed by the field id, so a plain update_post_meta()
 * is the correct write. Anything cloned, multi-value, timestamp-stored, custom-date-format, or
 * structurally complex (image, file, group, post, taxonomy …) stays read-only — editing those
 * belongs in Meta Box's own UI. supports_inline_edit() is the gate.
 */
final class MetaBoxFieldColumn extends BaseColumn implements SortableColumn, FilterableColumn, ConditionallyEditableColumn {
	/** Meta Box field type => popover input. select covers select/select_advanced/radio. */
	private const EDITABLE_TYPES = [
		'text'            => 'text',
		'email'           => 'text',
		'url'             => 'text',
		'number'          => 'number',
		'range'           => 'number',
		'date'            => 'date',
		'checkbox'        => 'boolean',
		'switch'          => 'boolean',
		'select'          => 'select',
		'select_advanced' => 'select',
		'radio'           => 'select',
	];

	/** Per-request cache: field_id => field definition array|null. */
	private array $field_cache = [];

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
	// ConditionallyEditableColumn + EditableColumn
	// ------------------------------------------------------------------

	public function supports_inline_edit( array $settings ): bool {
		$field = $this->resolve_field( (string) ( $settings['field_id'] ?? '' ) );
		if ( $field === null ) {
			return false;
		}
		$type = (string) ( $field['type'] ?? '' );
		if ( ! isset( self::EDITABLE_TYPES[ $type ] ) ) {
			return false;
		}
		// Cloned or multi-value fields store arrays — a single popover input can't represent them.
		if ( ! empty( $field['clone'] ) || ! empty( $field['multiple'] ) ) {
			return false;
		}
		if ( $type === 'date' ) {
			// Timestamp storage or a non-Y-m-d save_format can't round-trip via <input type=date>.
			if ( ! empty( $field['timestamp'] ) ) {
				return false;
			}
			$save_format = (string) ( $field['save_format'] ?? '' );
			if ( $save_format !== '' && $save_format !== 'Y-m-d' ) {
				return false;
			}
		}
		return true;
	}

	public function get_edit_input_type( array $settings ): string {
		$field = $this->resolve_field( (string) ( $settings['field_id'] ?? '' ) );
		$type  = $field !== null ? (string) ( $field['type'] ?? '' ) : '';
		$input = self::EDITABLE_TYPES[ $type ] ?? 'text';
		if ( $input === 'select' && $this->get_edit_options( $settings ) === null ) {
			return 'text'; // No resolvable options → free-text edit of the stored value.
		}
		return $input;
	}

	public function get_edit_options( array $settings ): ?array {
		$field = $this->resolve_field( (string) ( $settings['field_id'] ?? '' ) );
		if ( $field === null ) {
			return null;
		}
		if ( ! in_array( (string) ( $field['type'] ?? '' ), [ 'select', 'select_advanced', 'radio' ], true ) ) {
			return null;
		}
		$options = $field['options'] ?? null;
		if ( ! is_array( $options ) || $options === [] ) {
			return null;
		}
		$out = [];
		foreach ( $options as $value => $label ) {
			$out[ (string) $value ] = is_scalar( $label ) ? (string) $label : (string) $value;
		}
		return $out !== [] ? $out : null;
	}

	public function get_raw_value( int $object_id, array $settings ): string {
		$field_id = (string) ( $settings['field_id'] ?? '' );
		if ( $field_id === '' ) {
			return '';
		}
		$val = get_post_meta( $object_id, $field_id, true );
		if ( $val === '' || $val === false || $val === null || is_array( $val ) || is_object( $val ) ) {
			return '';
		}
		return (string) $val;
	}

	public function render_bulk_edit_field( string $input_name, array $settings ): void {
		$input   = $this->get_edit_input_type( $settings );
		$options = $this->get_edit_options( $settings );

		if ( $input === 'boolean' ) {
			echo '<select name="' . esc_attr( $input_name ) . '" class="ck-edit-input">';
			printf( '<option value="">%s</option>', esc_html__( '— (unchanged)', 'columnkit' ) );
			printf( '<option value="1">%s</option>', esc_html__( 'Yes', 'columnkit' ) );
			printf( '<option value="0">%s</option>', esc_html__( 'No', 'columnkit' ) );
			echo '</select>';
			return;
		}

		if ( $input === 'select' && is_array( $options ) ) {
			echo '<select name="' . esc_attr( $input_name ) . '" class="ck-edit-input">';
			printf( '<option value="">%s</option>', esc_html__( '— (unchanged)', 'columnkit' ) );
			foreach ( $options as $value => $label ) {
				printf( '<option value="%s">%s</option>', esc_attr( (string) $value ), esc_html( (string) $label ) );
			}
			echo '</select>';
			return;
		}

		$html_input = match ( $input ) {
			'number' => 'number',
			'date'   => 'date',
			default  => 'text',
		};
		$extra = $html_input === 'number' ? ' step="any"' : '';
		printf(
			'<input type="%1$s"%2$s name="%3$s" value="" class="ck-edit-input" />',
			esc_attr( $html_input ),
			$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal.
			esc_attr( $input_name )
		);
	}

	public function save_value( int $post_id, string $raw_value, array $settings ): void {
		$field_id = (string) ( $settings['field_id'] ?? '' );
		if ( $field_id === '' || ! $this->supports_inline_edit( $settings ) ) {
			return;
		}
		$field = $this->resolve_field( $field_id );
		if ( $field === null ) {
			return;
		}

		// Capability parity with PostMetaColumn for protected meta keys.
		if ( is_protected_meta( $field_id, 'post' ) ) {
			$post_type   = get_post_type( $post_id );
			$pt_obj      = $post_type ? get_post_type_object( $post_type ) : null;
			$trusted_cap = $pt_obj->cap->edit_others_posts ?? 'edit_others_posts';
			if ( ! current_user_can( $trusted_cap ) ) {
				return;
			}
		}

		$type = (string) ( $field['type'] ?? '' );

		switch ( self::EDITABLE_TYPES[ $type ] ) {
			case 'boolean':
				if ( $raw_value === '' ) {
					return; // '' = unchanged.
				}
				$on = in_array( strtolower( $raw_value ), [ '1', 'true', 'yes', 'on' ], true );
				update_post_meta( $post_id, $field_id, $on ? 1 : 0 );
				return;

			case 'number':
				if ( $raw_value === '' ) {
					delete_post_meta( $post_id, $field_id );
					return;
				}
				if ( ! is_numeric( $raw_value ) ) {
					return;
				}
				update_post_meta( $post_id, $field_id, $raw_value + 0 );
				return;

			case 'date':
				if ( $raw_value === '' ) {
					delete_post_meta( $post_id, $field_id );
					return;
				}
				$ts = strtotime( $raw_value );
				if ( $ts === false ) {
					return;
				}
				update_post_meta( $post_id, $field_id, gmdate( 'Y-m-d', $ts ) );
				return;

			case 'select':
				if ( $raw_value === '' ) {
					delete_post_meta( $post_id, $field_id );
					return;
				}
				$options = $this->get_edit_options( $settings );
				if ( is_array( $options ) && ! array_key_exists( $raw_value, $options ) ) {
					return; // Reject values outside the field's defined options.
				}
				update_post_meta( $post_id, $field_id, $raw_value );
				return;

			case 'text':
			default:
				if ( $raw_value === '' ) {
					delete_post_meta( $post_id, $field_id );
					return;
				}
				update_post_meta( $post_id, $field_id, $raw_value );
				return;
		}
	}

	/**
	 * Resolve a Meta Box field definition by id from the rwmb_meta_boxes filter. Memoised
	 * per request.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolve_field( string $field_id ): ?array {
		if ( $field_id === '' ) {
			return null;
		}
		if ( array_key_exists( $field_id, $this->field_cache ) ) {
			return $this->field_cache[ $field_id ];
		}

		$found      = null;
		$meta_boxes = apply_filters( 'rwmb_meta_boxes', [] );
		if ( is_array( $meta_boxes ) ) {
			foreach ( $meta_boxes as $mb ) {
				$fields = $mb['fields'] ?? [];
				if ( ! is_array( $fields ) ) {
					continue;
				}
				foreach ( $fields as $field ) {
					if ( is_array( $field ) && (string) ( $field['id'] ?? '' ) === $field_id ) {
						$found = $field;
						break 2;
					}
				}
			}
		}

		$this->field_cache[ $field_id ] = $found;
		return $found;
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
