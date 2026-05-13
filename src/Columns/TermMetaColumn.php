<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

/**
 * Term meta column — reads via get_term_meta. Only valid on taxonomy term lists.
 */
final class TermMetaColumn extends BaseColumn {
	public function get_type(): string {
		return 'term_meta';
	}

	public function get_label(): string {
		return __( 'Term Meta', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Show the value of a term meta key.', 'columnkit' );
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
}
