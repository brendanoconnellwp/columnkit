<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

abstract class BaseColumn implements ColumnInterface {
	public function get_description(): string {
		return '';
	}

	public function settings_fields(): array {
		return [];
	}

	public function sanitize_settings( array $input ): array {
		$out = [];
		foreach ( $this->settings_fields() as $field ) {
			$key = $field['key'];
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
			$out[ $key ] = sanitize_text_field( $value );
		}
		return $out;
	}

	public function applies_to_screen( string $screen_key ): bool {
		return true;
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$html = $this->render( $object_id, $settings );
		// Strip tags, decode HTML entities so the exported text is clean.
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
