<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\MetaBox;

use ColumnKit\Columns\BaseColumn;
use ColumnKit\Columns\ConditionallyEditableColumn;
use ColumnKit\Columns\ContextualColumn;
use ColumnKit\Columns\MetaSortable;
use ColumnKit\Columns\FilterableColumn;
use ColumnKit\Columns\SortableColumn;
use ColumnKit\Support\ObjectContext;
use ColumnKit\Support\ScreenIdentifier;
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
 *
 * Also works on taxonomy and user screens (MB Term Meta / MB User Meta): the picker offers only
 * meta boxes registered for that taxonomy (`taxonomies`) or for users (`type => user`), values
 * read through rwmb_get_value() with the matching object_type, and writes go to term/user meta.
 */
final class MetaBoxFieldColumn extends BaseColumn implements SortableColumn, FilterableColumn, ConditionallyEditableColumn, ContextualColumn, MetaSortable {
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
		return str_starts_with( $screen_key, 'post_type:' )
			|| $screen_key === 'media'
			|| str_starts_with( $screen_key, 'taxonomy:' )
			|| $screen_key === 'users';
	}

	public function settings_fields(): array {
		return $this->fields_with_options( $this->discover_field_options( null ) );
	}

	public function settings_fields_for_screen( string $screen_key ): array {
		return $this->fields_with_options( $this->discover_field_options( $screen_key ) );
	}

	/**
	 * @param array<string, string> $options
	 * @return array<int, array<string, mixed>>
	 */
	private function fields_with_options( array $options ): array {
		return [
			[
				'key'      => 'field_id',
				'label'    => __( 'Meta Box Field', 'columnkit' ),
				'type'     => 'select',
				'options'  => $options,
				'required' => true,
				'empty'    => __( 'No Meta Box field groups are registered for this screen. Term and user fields need the MB Term Meta / MB User Meta extensions.', 'columnkit' ),
			],
		];
	}

	/**
	 * Does a registered meta box target this screen?
	 *
	 * @param array<string, mixed> $mb
	 */
	public static function box_targets_screen( array $mb, string $screen_key ): bool {
		$object = ObjectContext::from_screen( $screen_key );
		$type   = (string) ( $mb['type'] ?? '' );

		if ( $object === ObjectContext::USER ) {
			return $type === 'user';
		}
		$taxonomies = array_map( 'strval', (array) ( $mb['taxonomies'] ?? [] ) );
		if ( $object === ObjectContext::TERM ) {
			return in_array( (string) ScreenIdentifier::taxonomy( $screen_key ), $taxonomies, true );
		}
		// Posts / media: anything that isn't a term, user, settings-page, comment or block box.
		if ( $taxonomies !== [] || in_array( $type, [ 'user', 'comment', 'block' ], true ) || ! empty( $mb['settings_pages'] ) ) {
			return false;
		}
		$post_types = (array) ( $mb['post_types'] ?? ( $mb['pages'] ?? [ 'post' ] ) );
		$post_type  = ScreenIdentifier::is_media( $screen_key ) ? 'attachment' : (string) ScreenIdentifier::post_type( $screen_key );
		return in_array( $post_type, array_map( 'strval', $post_types ), true );
	}

	/** rwmb_get_value()/rwmb_meta() args for the column's object type. @return array<string, string> */
	private static function rwmb_args( array $settings ): array {
		$object = ObjectContext::from_settings( $settings );
		return $object === ObjectContext::POST ? [] : [ 'object_type' => $object ];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( isset( $out['field_id'] ) ) {
			$out['field_id'] = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $out['field_id'] );
		}
		return $out;
	}

	/**
	 * Field options for the picker. With a screen key, only boxes targeting that screen; post
	 * screens fall back to every box when none match (keeps unusual configs working).
	 *
	 * @return array<string, string> field_id => "Name (type)"
	 */
	private function discover_field_options( ?string $screen_key ): array {
		$out = [];
		// Meta Box exposes registered meta boxes via this filter. Calling apply_filters with []
		// returns the full list registered by themes / extensions / MB Builder.
		$meta_boxes = apply_filters( 'rwmb_meta_boxes', [] );
		if ( ! is_array( $meta_boxes ) ) {
			return [];
		}
		if ( $screen_key !== null ) {
			$matched = array_values( array_filter( $meta_boxes, static fn( $mb ) => is_array( $mb ) && self::box_targets_screen( $mb, $screen_key ) ) );
			if ( $matched !== [] || ObjectContext::from_screen( $screen_key ) !== ObjectContext::POST ) {
				$meta_boxes = $matched;
			}
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
			$value = rwmb_get_value( $field_id, self::rwmb_args( $settings ), $object_id );
		}
		if ( $value === null || $value === '' || $value === false ) {
			$raw = ObjectContext::get_meta( ObjectContext::from_settings( $settings ), $object_id, $field_id );
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
		$raw = ObjectContext::get_meta( ObjectContext::from_settings( $settings ), $object_id, $field_id );
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
		$val = ObjectContext::get_meta( ObjectContext::from_settings( $settings ), $object_id, $field_id );
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
		$object = ObjectContext::from_settings( $settings );
		if ( ! ObjectContext::can_write_key( $object, $post_id, $field_id ) ) {
			return;
		}

		$type = (string) ( $field['type'] ?? '' );

		switch ( self::EDITABLE_TYPES[ $type ] ) {
			case 'boolean':
				if ( $raw_value === '' ) {
					return; // '' = unchanged.
				}
				$on = in_array( strtolower( $raw_value ), [ '1', 'true', 'yes', 'on' ], true );
				ObjectContext::update_meta( $object, $post_id, $field_id, $on ? 1 : 0 );
				return;

			case 'number':
				if ( $raw_value === '' ) {
					ObjectContext::delete_meta( $object, $post_id, $field_id );
					return;
				}
				if ( ! is_numeric( $raw_value ) ) {
					return;
				}
				ObjectContext::update_meta( $object, $post_id, $field_id, $raw_value + 0 );
				return;

			case 'date':
				if ( $raw_value === '' ) {
					ObjectContext::delete_meta( $object, $post_id, $field_id );
					return;
				}
				$ts = strtotime( $raw_value );
				if ( $ts === false ) {
					return;
				}
				ObjectContext::update_meta( $object, $post_id, $field_id, gmdate( 'Y-m-d', $ts ) );
				return;

			case 'select':
				if ( $raw_value === '' ) {
					ObjectContext::delete_meta( $object, $post_id, $field_id );
					return;
				}
				$options = $this->get_edit_options( $settings );
				if ( is_array( $options ) && ! array_key_exists( $raw_value, $options ) ) {
					return; // Reject values outside the field's defined options.
				}
				ObjectContext::update_meta( $object, $post_id, $field_id, $raw_value );
				return;

			case 'text':
			default:
				if ( $raw_value === '' ) {
					ObjectContext::delete_meta( $object, $post_id, $field_id );
					return;
				}
				ObjectContext::update_meta( $object, $post_id, $field_id, $raw_value );
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
	// MetaSortable — term + user screens
	// ------------------------------------------------------------------

	public function sort_meta_key( array $settings ): string {
		return (string) ( $settings['field_id'] ?? '' );
	}

	public function sort_meta_type( array $settings ): string {
		$field = $this->resolve_field( (string) ( $settings['field_id'] ?? '' ) );
		$type  = $field !== null ? (string) ( $field['type'] ?? '' ) : '';
		return in_array( $type, [ 'number', 'range', 'slider' ], true ) ? 'numeric' : 'string';
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
