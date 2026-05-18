<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\JetEngine;

use ColumnKit\Columns\BaseColumn;
use ColumnKit\Columns\ConditionallyEditableColumn;
use ColumnKit\Columns\FilterableColumn;
use ColumnKit\Columns\SortableColumn;
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
 */
final class JetEngineFieldColumn extends BaseColumn implements SortableColumn, FilterableColumn, ConditionallyEditableColumn {
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
		return str_starts_with( $screen_key, 'post_type:' ) || $screen_key === 'media';
	}

	public function settings_fields(): array {
		return [
			[
				'key'      => 'field_name',
				'label'    => __( 'JetEngine Field', 'columnkit' ),
				'type'     => 'select',
				'options'  => $this->discover_field_options(),
				'required' => true,
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( isset( $out['field_name'] ) ) {
			$out['field_name'] = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $out['field_name'] );
		}
		return $out;
	}

	/** @return array<string, string> field_name => "Title (type)" */
	private function discover_field_options(): array {
		if ( ! function_exists( 'jet_engine' ) ) {
			return [];
		}
		$engine = jet_engine();
		if ( ! isset( $engine->meta_boxes ) || ! method_exists( $engine->meta_boxes, 'get_registered_boxes' ) ) {
			return [];
		}
		$out = [];
		try {
			$boxes = $engine->meta_boxes->get_registered_boxes();
		} catch ( \Throwable $e ) {
			return [];
		}
		if ( ! is_array( $boxes ) ) {
			return [];
		}
		foreach ( $boxes as $box ) {
			$fields = $box['args']['meta_fields'] ?? ( $box['meta_fields'] ?? [] );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
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
		$value = get_post_meta( $object_id, $field_name, true );
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
		$raw = get_post_meta( $object_id, $field_name, true );
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
		$val = get_post_meta( $object_id, $name, true );
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
		if ( is_protected_meta( $name, 'post' ) ) {
			$post_type   = get_post_type( $post_id );
			$pt_obj      = $post_type ? get_post_type_object( $post_type ) : null;
			$trusted_cap = $pt_obj->cap->edit_others_posts ?? 'edit_others_posts';
			if ( ! current_user_can( $trusted_cap ) ) {
				return;
			}
		}

		$type = (string) ( $field['type'] ?? '' );

		switch ( self::EDITABLE_TYPES[ $type ] ) {
			case 'number':
				if ( $raw_value === '' ) {
					delete_post_meta( $post_id, $name );
					return;
				}
				if ( ! is_numeric( $raw_value ) ) {
					return;
				}
				update_post_meta( $post_id, $name, $raw_value + 0 );
				return;

			case 'date':
				if ( $raw_value === '' ) {
					delete_post_meta( $post_id, $name );
					return;
				}
				$ts = strtotime( $raw_value );
				if ( $ts === false ) {
					return;
				}
				update_post_meta( $post_id, $name, gmdate( 'Y-m-d', $ts ) );
				return;

			case 'select':
				if ( $raw_value === '' ) {
					delete_post_meta( $post_id, $name );
					return;
				}
				$options = $this->get_edit_options( $settings );
				if ( is_array( $options ) && ! array_key_exists( $raw_value, $options ) ) {
					return; // Reject values outside known choices (when choices are known).
				}
				update_post_meta( $post_id, $name, $raw_value );
				return;

			case 'text':
			default:
				if ( $raw_value === '' ) {
					delete_post_meta( $post_id, $name );
					return;
				}
				update_post_meta( $post_id, $name, $raw_value );
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
		if ( function_exists( 'jet_engine' ) ) {
			$engine = jet_engine();
			if ( isset( $engine->meta_boxes ) && method_exists( $engine->meta_boxes, 'get_registered_boxes' ) ) {
				try {
					$boxes = $engine->meta_boxes->get_registered_boxes();
				} catch ( \Throwable $e ) {
					$boxes = [];
				}
				if ( is_array( $boxes ) ) {
					foreach ( $boxes as $box ) {
						$fields = $box['args']['meta_fields'] ?? ( $box['meta_fields'] ?? [] );
						if ( ! is_array( $fields ) ) {
							continue;
						}
						foreach ( $fields as $field ) {
							if ( is_array( $field ) && ( $field['name'] ?? '' ) === $name ) {
								$found = $field;
								break 2;
							}
						}
					}
				}
			}
		}

		$this->field_cache[ $name ] = $found;
		return $found;
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
