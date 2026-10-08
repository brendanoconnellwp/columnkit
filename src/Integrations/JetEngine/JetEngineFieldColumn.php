<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\JetEngine;

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
 * JetEngine meta-field column. JetEngine stores meta values as standard post meta keyed by the
 * field name, so render reads via get_post_meta. Field discovery walks
 * jet_engine()->meta_boxes->get_registered_boxes().
 *
 * Display + sort + filter, plus inline/bulk edit for the plain-string scalar types: text,
 * number, non-timestamp date, and single select/radio. JetEngine stores these as ordinary
 * post meta, so a plain update_post_meta() is the correct write. Boolean-ish types (switcher,
 * checkbox) and timestamp dates are intentionally NOT editable here — JetEngine's stored
 * representation for those varies by version/config and a wrong write would corrupt data;
 * editing them belongs in JetEngine's own UI. supports_inline_edit() is the gate.
 *
 * Also works on taxonomy and user screens: meta boxes whose object_type is `taxonomy` / `user`
 * (plus meta fields defined on JetEngine-built taxonomies) are offered there, and values are
 * plain term / user meta keyed by the field name.
 */
final class JetEngineFieldColumn extends BaseColumn implements SortableColumn, FilterableColumn, ConditionallyEditableColumn, ContextualColumn, MetaSortable {
	/** JetEngine field type => popover input. select covers select/radio (single value). */
	private const EDITABLE_TYPES = [
		'text'   => 'text',
		'number' => 'number',
		'date'   => 'date',
		'select' => 'select',
		'radio'  => 'select',
	];

	/** Per-request cache: field_name => field definition array|null. */
	private array $field_cache = [];

	public function get_type(): string {
		return 'jetengine_field';
	}

	public function get_label(): string {
		return __( 'JetEngine Field', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Display a value from a JetEngine meta field. Auto-discovers fields from registered meta boxes.', 'columnkit' );
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
				'key'      => 'field_name',
				'label'    => __( 'JetEngine Field', 'columnkit' ),
				'type'     => 'select',
				'options'  => $options,
				'required' => true,
				'empty'    => __( 'No JetEngine meta boxes target this screen yet. Create one in JetEngine → Meta Boxes for this taxonomy or for users.', 'columnkit' ),
			],
		];
	}

	/**
	 * Every place JetEngine defines meta fields, normalised to
	 * [ object => post|term|user, subjects => string[] (empty = all of that object), fields => array[] ].
	 *
	 * @return array<int, array{object: string, subjects: array<int, string>, fields: array<int, mixed>}>
	 */
	private function field_sources(): array {
		if ( ! function_exists( 'jet_engine' ) ) {
			return [];
		}
		$engine  = jet_engine();
		$sources = [];

		if ( isset( $engine->meta_boxes ) && method_exists( $engine->meta_boxes, 'get_registered_boxes' ) ) {
			try {
				$boxes = $engine->meta_boxes->get_registered_boxes();
			} catch ( \Throwable $e ) {
				$boxes = [];
			}
			foreach ( is_array( $boxes ) ? $boxes : [] as $box ) {
				if ( ! is_array( $box ) ) {
					continue;
				}
				$args   = is_array( $box['args'] ?? null ) ? $box['args'] : $box;
				$fields = $args['meta_fields'] ?? ( $box['meta_fields'] ?? [] );
				$kind   = (string) ( $args['object_type'] ?? 'post' );
				$object = match ( $kind ) {
					'taxonomy', 'term' => ObjectContext::TERM,
					'user'             => ObjectContext::USER,
					default            => ObjectContext::POST,
				};
				$subjects = match ( $object ) {
					ObjectContext::TERM => (array) ( $args['allowed_tax'] ?? [] ),
					ObjectContext::POST => (array) ( $args['allowed_post_type'] ?? [] ),
					default             => [],
				};
				$sources[] = [
					'object'   => $object,
					'subjects' => array_values( array_map( 'strval', $subjects ) ),
					'fields'   => is_array( $fields ) ? $fields : [],
				];
			}
		}

		// Meta fields defined directly on JetEngine-built taxonomies.
		$tax_module = $engine->taxonomies ?? null;
		$store      = null;
		if ( is_object( $tax_module ) && isset( $tax_module->data ) && is_object( $tax_module->data ) && method_exists( $tax_module->data, 'get_items' ) ) {
			$store = $tax_module->data;
		} elseif ( is_object( $tax_module ) && method_exists( $tax_module, 'get_items' ) ) {
			$store = $tax_module;
		}
		if ( $store !== null ) {
			try {
				$items = $store->get_items();
			} catch ( \Throwable $e ) {
				$items = [];
			}
			foreach ( is_array( $items ) ? $items : [] as $item ) {
				if ( ! is_array( $item ) || empty( $item['slug'] ) || ! is_array( $item['meta_fields'] ?? null ) ) {
					continue;
				}
				$sources[] = [
					'object'   => ObjectContext::TERM,
					'subjects' => [ (string) $item['slug'] ],
					'fields'   => $item['meta_fields'],
				];
			}
		}

		return $sources;
	}

	/** @param array{object: string, subjects: array<int, string>, fields: array<int, mixed>} $source */
	private static function source_targets_screen( array $source, string $screen_key ): bool {
		$object = ObjectContext::from_screen( $screen_key );
		if ( $source['object'] !== $object ) {
			return false;
		}
		if ( $source['subjects'] === [] ) {
			return true;
		}
		$subject = match ( $object ) {
			ObjectContext::TERM => (string) ScreenIdentifier::taxonomy( $screen_key ),
			ObjectContext::USER => '',
			default             => ScreenIdentifier::is_media( $screen_key ) ? 'attachment' : (string) ScreenIdentifier::post_type( $screen_key ),
		};
		return in_array( $subject, $source['subjects'], true );
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( isset( $out['field_name'] ) ) {
			$out['field_name'] = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $out['field_name'] );
		}
		return $out;
	}

	/**
	 * Field options for the picker. With a screen key, only sources targeting that screen; post
	 * screens fall back to every source when none match (keeps unusual configs working).
	 *
	 * @return array<string, string> field_name => "Title (type)"
	 */
	private function discover_field_options( ?string $screen_key ): array {
		$sources = $this->field_sources();
		if ( $screen_key !== null ) {
			$matched = array_values( array_filter( $sources, static fn( $src ) => self::source_targets_screen( $src, $screen_key ) ) );
			if ( $matched !== [] || ObjectContext::from_screen( $screen_key ) !== ObjectContext::POST ) {
				$sources = $matched;
			}
		}
		$out = [];
		foreach ( $sources as $source ) {
			foreach ( $source['fields'] as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name  = (string) ( $field['name'] ?? '' );
				$title = (string) ( $field['title'] ?? $name );
				$type  = (string) ( $field['type'] ?? 'text' );
				if ( $name === '' ) {
					continue;
				}
				$out[ $name ] = sprintf( '%s (%s)', $title !== '' ? $title : $name, $type );
			}
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$field_name = (string) ( $settings['field_name'] ?? '' );
		if ( $field_name === '' ) {
			return '';
		}
		$value = ObjectContext::get_meta( ObjectContext::from_settings( $settings ), $object_id, $field_name );
		if ( $value === '' || $value === false || $value === null ) {
			return '';
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return esc_html( (string) wp_json_encode( $value ) );
		}
		// JetEngine sometimes stores serialized "media" fields as the attachment ID — render as
		// thumbnail if the value looks like a positive integer attachment ID that exists.
		if ( ctype_digit( (string) $value ) ) {
			$id   = (int) $value;
			$post = $id > 0 ? get_post( $id ) : null;
			if ( $post && $post->post_type === 'attachment' && wp_attachment_is_image( $id ) ) {
				$html = wp_get_attachment_image( $id, [ 40, 40 ], false, [ 'loading' => 'lazy', 'style' => 'max-height:40px;width:auto;' ] );
				if ( is_string( $html ) && $html !== '' ) {
					return $html;
				}
			}
		}
		return esc_html( (string) $value );
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$field_name = (string) ( $settings['field_name'] ?? '' );
		if ( $field_name === '' ) {
			return '';
		}
		$raw = ObjectContext::get_meta( ObjectContext::from_settings( $settings ), $object_id, $field_name );
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
		$field = $this->resolve_field( (string) ( $settings['field_name'] ?? '' ) );
		if ( $field === null ) {
			return false;
		}
		$type = (string) ( $field['type'] ?? '' );
		if ( ! isset( self::EDITABLE_TYPES[ $type ] ) ) {
			return false;
		}
		if ( $type === 'date' && $this->flag( $field, 'is_timestamp' ) ) {
			return false; // Timestamp storage — a <input type=date> can't round-trip it safely.
		}
		// JetEngine multi-value select stores an array; single popover can't represent it.
		if ( $type === 'select' && ( $this->flag( $field, 'is_multiple' ) || $this->flag( $field, 'multiple' ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Robustly read a JetEngine boolean field flag. The meta-box builder persists these as
	 * strings ('true'/'false'), so a naive ! empty() reads 'false' as true. filter_var with
	 * FILTER_VALIDATE_BOOLEAN handles 'true'/'false'/'1'/'0'/''/bool/int uniformly.
	 *
	 * @param array<string, mixed> $field
	 */
	private function flag( array $field, string $key ): bool {
		return filter_var( $field[ $key ] ?? false, FILTER_VALIDATE_BOOLEAN );
	}

	public function get_edit_input_type( array $settings ): string {
		$field = $this->resolve_field( (string) ( $settings['field_name'] ?? '' ) );
		$type  = $field !== null ? (string) ( $field['type'] ?? '' ) : '';
		$input = self::EDITABLE_TYPES[ $type ] ?? 'text';
		// select/radio with no resolvable options → free-text edit of the stored value.
		if ( $input === 'select' && $this->get_edit_options( $settings ) === null ) {
			return 'text';
		}
		return $input;
	}

	public function get_edit_options( array $settings ): ?array {
		$field = $this->resolve_field( (string) ( $settings['field_name'] ?? '' ) );
		if ( $field === null ) {
			return null;
		}
		if ( ! in_array( (string) ( $field['type'] ?? '' ), [ 'select', 'radio' ], true ) ) {
			return null;
		}
		$opts = $this->normalize_options( $field['options'] ?? null );
		return $opts !== [] ? $opts : null;
	}

	public function get_raw_value( int $object_id, array $settings ): string {
		$name = (string) ( $settings['field_name'] ?? '' );
		if ( $name === '' ) {
			return '';
		}
		$val = ObjectContext::get_meta( ObjectContext::from_settings( $settings ), $object_id, $name );
		if ( $val === '' || $val === false || $val === null || is_array( $val ) || is_object( $val ) ) {
			return '';
		}
		return (string) $val;
	}

	public function render_bulk_edit_field( string $input_name, array $settings ): void {
		$input   = $this->get_edit_input_type( $settings );
		$options = $this->get_edit_options( $settings );

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
		$name = (string) ( $settings['field_name'] ?? '' );
		if ( $name === '' || ! $this->supports_inline_edit( $settings ) ) {
			return;
		}
		$field = $this->resolve_field( $name );
		if ( $field === null ) {
			return;
		}

		// Capability parity with PostMetaColumn for protected meta keys.
		$object = ObjectContext::from_settings( $settings );
		if ( ! ObjectContext::can_write_key( $object, $post_id, $name ) ) {
			return;
		}

		$type = (string) ( $field['type'] ?? '' );

		switch ( self::EDITABLE_TYPES[ $type ] ) {
			case 'number':
				if ( $raw_value === '' ) {
					ObjectContext::delete_meta( $object, $post_id, $name );
					return;
				}
				if ( ! is_numeric( $raw_value ) ) {
					return;
				}
				ObjectContext::update_meta( $object, $post_id, $name, $raw_value + 0 );
				return;

			case 'date':
				if ( $raw_value === '' ) {
					ObjectContext::delete_meta( $object, $post_id, $name );
					return;
				}
				$ts = strtotime( $raw_value );
				if ( $ts === false ) {
					return;
				}
				ObjectContext::update_meta( $object, $post_id, $name, gmdate( 'Y-m-d', $ts ) );
				return;

			case 'select':
				if ( $raw_value === '' ) {
					ObjectContext::delete_meta( $object, $post_id, $name );
					return;
				}
				$options = $this->get_edit_options( $settings );
				if ( is_array( $options ) && ! array_key_exists( $raw_value, $options ) ) {
					return; // Reject values outside known choices (when choices are known).
				}
				ObjectContext::update_meta( $object, $post_id, $name, $raw_value );
				return;

			case 'text':
			default:
				if ( $raw_value === '' ) {
					ObjectContext::delete_meta( $object, $post_id, $name );
					return;
				}
				ObjectContext::update_meta( $object, $post_id, $name, $raw_value );
				return;
		}
	}

	/**
	 * Normalise JetEngine's `options` (shape varies by version: assoc map, or a list of
	 * { key/value } or { value/label } rows) into a flat value => label map.
	 *
	 * @param mixed $options
	 * @return array<string, string>
	 */
	private function normalize_options( $options ): array {
		if ( ! is_array( $options ) || $options === [] ) {
			return [];
		}
		$out = [];
		foreach ( $options as $key => $opt ) {
			if ( is_array( $opt ) ) {
				// Builder rows: key=stored value, value=label (older) OR value/label keys.
				$val   = $opt['key']   ?? $opt['value'] ?? null;
				$label = $opt['value'] ?? $opt['label'] ?? $val;
				if ( isset( $opt['value'], $opt['label'] ) ) {
					$val   = $opt['value'];
					$label = $opt['label'];
				}
				if ( $val === null ) {
					continue;
				}
				$out[ (string) $val ] = (string) $label;
				continue;
			}
			// Plain assoc map: key => label.
			$out[ (string) $key ] = (string) $opt;
		}
		return $out;
	}

	/**
	 * Resolve a JetEngine field definition by name. Memoised per request.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolve_field( string $name ): ?array {
		if ( $name === '' ) {
			return null;
		}
		if ( array_key_exists( $name, $this->field_cache ) ) {
			return $this->field_cache[ $name ];
		}

		$found = null;
		foreach ( $this->field_sources() as $source ) {
			foreach ( $source['fields'] as $field ) {
				if ( is_array( $field ) && ( $field['name'] ?? '' ) === $name ) {
					$found = $field;
					break 2;
				}
			}
		}

		$this->field_cache[ $name ] = $found;
		return $found;
	}

	// ------------------------------------------------------------------
	// MetaSortable — term + user screens
	// ------------------------------------------------------------------

	public function sort_meta_key( array $settings ): string {
		return (string) ( $settings['field_name'] ?? '' );
	}

	public function sort_meta_type( array $settings ): string {
		$field = $this->resolve_field( (string) ( $settings['field_name'] ?? '' ) );
		return ( $field !== null && ( $field['type'] ?? '' ) === 'number' ) ? 'numeric' : 'string';
	}

	// ------------------------------------------------------------------
	// SortableColumn — meta_value LEFT JOIN
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
				$alias = 'ck_je_sort';
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
		$field_name  = (string) ( $settings['field_name'] ?? '' );
		$placeholder = $field_name !== '' ? $field_name : __( 'JetEngine field', 'columnkit' );
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
		$raw_mq   = $query->get( 'meta_query' );
		$existing = is_array( $raw_mq ) ? $raw_mq : [];
		$existing[] = [ 'key' => $key, 'value' => $v, 'compare' => 'LIKE' ];
		$query->set( 'meta_query', $existing );
	}
}
