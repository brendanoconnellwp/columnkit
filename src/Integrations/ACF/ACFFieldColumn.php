<?php
declare( strict_types=1 );

namespace ColumnKit\Integrations\ACF;

use ColumnKit\Columns\BaseColumn;
use ColumnKit\Columns\ConditionallyEditableColumn;
use ColumnKit\Columns\FilterableColumn;
use ColumnKit\Columns\SortableColumn;
use WP_Query;
use WP_Term;

/**
 * Generic ACF field column — pick a field via dropdown, render type-aware.
 *
 * Display + sort + filter, plus inline/bulk edit for field types that round-trip cleanly through
 * a single-input popover: true_false, text, email, url, password, number, range, date_picker, and
 * single-value select/radio/button_group. Complex types (image, gallery, relationship,
 * post_object, repeater, flexible_content, taxonomy, user, file, clone, multi-selects …) stay
 * read-only — editing them belongs in ACF's own field UI. supports_inline_edit() is the gate.
 *
 * Writes go through ACF's update_field() (by field key when known) so ACF's `_fieldname`
 * field-key reference is maintained — a plain update_post_meta() would leave ACF unable to
 * resolve the field for posts that never had it saved.
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
final class ACFFieldColumn extends BaseColumn implements SortableColumn, FilterableColumn, ConditionallyEditableColumn {
	/**
	 * ACF field type => our popover input type. Anything not listed here is read-only.
	 * 'select' covers select/radio/button_group (single-value only — see supports_inline_edit).
	 */
	private const EDITABLE_TYPES = [
		'true_false'   => 'boolean',
		'text'         => 'text',
		'email'        => 'text',
		'url'          => 'text',
		'password'     => 'text',
		'number'       => 'number',
		'range'        => 'number',
		'date_picker'  => 'date',
		'select'       => 'select',
		'radio'        => 'select',
		'button_group' => 'select',
	];

	/** Per-request cache: field_name => field definition array|null (avoids re-scanning groups per row). */
	private array $field_cache = [];

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
		// true_false must be resolved BEFORE the generic emptiness guard: ACF formats it to a
		// bool, so a stored "No" is `false` and would otherwise blank out instead of showing "No".
		if ( $type === 'true_false' ) {
			return esc_html( $value ? __( 'Yes', 'columnkit' ) : __( 'No', 'columnkit' ) );
		}

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
		// Multi-value selects/radios return arrays — a single popover input can't represent them.
		if ( ( $type === 'select' || $type === 'radio' ) && ! empty( $field['multiple'] ) ) {
			return false;
		}
		return true;
	}

	public function get_edit_input_type( array $settings ): string {
		$field = $this->resolve_field( (string) ( $settings['field_name'] ?? '' ) );
		$type  = $field !== null ? (string) ( $field['type'] ?? '' ) : '';
		return self::EDITABLE_TYPES[ $type ] ?? 'text';
	}

	public function get_edit_options( array $settings ): ?array {
		$field = $this->resolve_field( (string) ( $settings['field_name'] ?? '' ) );
		if ( $field === null ) {
			return null;
		}
		$type = (string) ( $field['type'] ?? '' );
		if ( ! in_array( $type, [ 'select', 'radio', 'button_group' ], true ) ) {
			return null;
		}
		$choices = is_array( $field['choices'] ?? null ) ? $field['choices'] : [];
		$out     = [];
		// allow_null fields can be cleared; offer an explicit empty choice.
		if ( ! empty( $field['allow_null'] ) ) {
			$out[''] = __( '— (none)', 'columnkit' );
		}
		foreach ( $choices as $value => $label ) {
			$out[ (string) $value ] = (string) $label;
		}
		return $out !== [] ? $out : null;
	}

	public function get_raw_value( int $object_id, array $settings ): string {
		$name = (string) ( $settings['field_name'] ?? '' );
		if ( $name === '' || ! function_exists( 'get_field' ) ) {
			return '';
		}
		// Unformatted value (3rd arg false): true_false → '1'/'0'/'', date_picker → stored Ymd.
		$val = get_field( $name, $object_id, false );
		if ( $val === null || $val === false || is_array( $val ) || is_object( $val ) ) {
			return '';
		}
		$val   = (string) $val;
		$field = $this->resolve_field( $name );
		if ( $val !== '' && $field !== null && ( $field['type'] ?? '' ) === 'date_picker' ) {
			$val = $this->acf_date_to_iso( $val );
		}
		return $val;
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
		$name = (string) ( $settings['field_name'] ?? '' );
		if ( $name === '' || ! $this->supports_inline_edit( $settings ) ) {
			return;
		}
		$field = $this->resolve_field( $name );
		if ( $field === null ) {
			return;
		}

		// Capability parity with PostMetaColumn: ACF's `_fieldname` reference (and rare
		// underscore-prefixed field names) is protected meta. Only users trusted with other
		// people's content may edit protected keys, even if an admin configured the column.
		if ( is_protected_meta( $name, 'post' ) ) {
			$post_type   = get_post_type( $post_id );
			$pt_obj      = $post_type ? get_post_type_object( $post_type ) : null;
			$trusted_cap = $pt_obj->cap->edit_others_posts ?? 'edit_others_posts';
			if ( ! current_user_can( $trusted_cap ) ) {
				return;
			}
		}

		$type = (string) ( $field['type'] ?? '' );

		/** @var int|float|string $value_to_store */
		$value_to_store = $raw_value;

		switch ( self::EDITABLE_TYPES[ $type ] ) {
			case 'boolean':
				if ( $raw_value === '' ) {
					return; // '' = unchanged.
				}
				$value_to_store = in_array( strtolower( $raw_value ), [ '1', 'true', 'yes', 'on' ], true ) ? 1 : 0;
				break;

			case 'number':
				if ( $raw_value === '' ) {
					$value_to_store = '';
					break; // Clearing is allowed.
				}
				if ( ! is_numeric( $raw_value ) ) {
					return; // Reject non-numeric.
				}
				$value_to_store = $raw_value + 0;
				break;

			case 'date':
				if ( $raw_value === '' ) {
					$value_to_store = '';
					break;
				}
				$ts = strtotime( $raw_value );
				if ( $ts === false ) {
					return;
				}
				// ACF date_picker stores Ymd regardless of display/return format.
				$value_to_store = gmdate( 'Ymd', $ts );
				break;

			case 'select':
				if ( $raw_value === '' ) {
					if ( empty( $field['allow_null'] ) ) {
						return; // Not nullable — ignore empty rather than blank a required field.
					}
					$value_to_store = '';
					break;
				}
				$choices = is_array( $field['choices'] ?? null )
					? array_map( 'strval', array_keys( $field['choices'] ) )
					: [];
				if ( $choices !== [] && ! in_array( $raw_value, $choices, true ) ) {
					return; // Reject values outside the field's defined choices.
				}
				$value_to_store = $raw_value;
				break;

			case 'text':
			default:
				$value_to_store = $raw_value;
				break;
		}

		if ( function_exists( 'update_field' ) ) {
			$key = (string) ( $field['key'] ?? '' );
			update_field( $key !== '' ? $key : $name, $value_to_store, $post_id );
			return;
		}
		update_post_meta( $post_id, $name, $value_to_store );
	}

	/**
	 * Resolve an ACF field definition by name WITHOUT a post context (settings-time calls have
	 * none). acf_get_field() is the canonical resolver and is itself cached by ACF; the
	 * field-group scan is a fallback for older ACF. Result memoised per request.
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
		if ( function_exists( 'acf_get_field' ) ) {
			$f = acf_get_field( $name );
			if ( is_array( $f ) ) {
				$found = $f;
			}
		}
		if ( $found === null && function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( acf_get_field_groups() as $group ) {
				$fields = acf_get_fields( $group );
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

		$this->field_cache[ $name ] = $found;
		return $found;
	}

	/** Stored ACF date (Ymd, or already Y-m-d / strtotime-able) → Y-m-d for <input type=date>. */
	private function acf_date_to_iso( string $stored ): string {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $stored ) === 1 ) {
			return $stored;
		}
		if ( preg_match( '/^\d{8}$/', $stored ) === 1 ) {
			$d = \DateTimeImmutable::createFromFormat( 'Ymd', $stored );
			if ( $d instanceof \DateTimeImmutable ) {
				return $d->format( 'Y-m-d' );
			}
		}
		$ts = strtotime( $stored );
		return $ts !== false ? gmdate( 'Y-m-d', $ts ) : $stored;
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
