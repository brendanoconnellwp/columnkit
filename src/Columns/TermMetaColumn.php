<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

/**
 * Term meta column — reads via get_term_meta. Valid only on taxonomy term lists.
 *
 * Sortable (by meta key, via WP_Term_Query) and inline-editable (writes term meta). The
 * TermListManager wires the sort and the edit AJAX; this class only does the read/write.
 */
final class TermMetaColumn extends BaseColumn implements EditableColumn, MetaSortable {
	public function get_type(): string {
		return 'term_meta';
	}

	public function get_label(): string {
		return __( 'Term Meta', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Show, sort, and inline-edit the value of a term meta key.', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		return str_starts_with( $screen_key, 'taxonomy:' );
	}

	public function settings_fields(): array {
		return [
			[ 'key' => 'meta_key', 'label' => __( 'Term meta key', 'columnkit' ), 'type' => 'text', 'required' => true ],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( isset( $out['meta_key'] ) ) {
			$out['meta_key'] = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $out['meta_key'] );
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$key = (string) ( $settings['meta_key'] ?? '' );
		if ( $key === '' ) {
			return '';
		}
		$val = get_term_meta( $object_id, $key, true );
		if ( is_array( $val ) || is_object( $val ) ) {
			$val = wp_json_encode( $val );
		}
		return esc_html( (string) $val );
	}

	// --- MetaSortable ---------------------------------------------------

	public function sort_meta_key( array $settings ): string {
		return (string) ( $settings['meta_key'] ?? '' );
	}

	// --- EditableColumn -------------------------------------------------

	public function get_raw_value( int $object_id, array $settings ): string {
		$key = (string) ( $settings['meta_key'] ?? '' );
		if ( $key === '' ) {
			return '';
		}
		$val = get_term_meta( $object_id, $key, true );
		if ( is_array( $val ) || is_object( $val ) ) {
			return '';
		}
		return (string) $val;
	}

	public function get_edit_input_type( array $settings ): string {
		return 'text';
	}

	public function get_edit_options( array $settings ): ?array {
		return null;
	}

	public function render_bulk_edit_field( string $input_name, array $settings ): void {
		// Terms have no native bulk-edit panel; included to satisfy the interface.
		printf( '<input type="text" name="%s" value="">', esc_attr( $input_name ) );
	}

	public function save_value( int $object_id, string $raw_value, array $settings ): void {
		$key = (string) ( $settings['meta_key'] ?? '' );
		if ( $key === '' ) {
			return;
		}
		if ( $raw_value === '' ) {
			delete_term_meta( $object_id, $key );
			return;
		}
		update_term_meta( $object_id, $key, $raw_value );
	}
}
