<?php
declare( strict_types=1 );

namespace ColumnKit\Settings;

use ColumnKit\ColumnRegistry;

/**
 * Sanitises the raw column-list payload submitted from the admin form.
 * Whitelist-driven: any column whose type is not registered is dropped.
 */
final class Sanitizer {
	public function __construct( private ColumnRegistry $registry ) {}

	/**
	 * @param mixed $raw The raw POSTed payload (expected to be an array of column entries).
	 * @return array<int, array<string, mixed>> sanitised columns
	 */
	public function sanitize_columns( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out  = [];
		$used = [];
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$type = isset( $entry['type'] ) && is_string( $entry['type'] ) ? $entry['type'] : '';
			$col  = $this->registry->get( $type );
			if ( ! $col ) {
				continue; // Unknown type — drop silently.
			}

			$id = isset( $entry['id'] ) && is_string( $entry['id'] ) ? $entry['id'] : '';
			$id = preg_replace( '/[^a-z0-9_]/i', '', $id );
			if ( $id === '' || isset( $used[ $id ] ) ) {
				$id = 'col_' . substr( md5( $type . microtime( true ) . count( $out ) ), 0, 8 );
			}
			$used[ $id ] = true;

			$label = isset( $entry['label'] ) && is_string( $entry['label'] ) ? $entry['label'] : '';
			$label = sanitize_text_field( $label );
			if ( $label === '' ) {
				$label = $col->get_label();
			}

			$settings_in  = isset( $entry['settings'] ) && is_array( $entry['settings'] ) ? $entry['settings'] : [];
			$settings_out = $col->sanitize_settings( $settings_in );

			$width = isset( $entry['width'] ) ? (string) $entry['width'] : '';
			$width = preg_replace( '/[^0-9a-z%px]/i', '', $width );

			$out[] = [
				'id'       => $id,
				'type'     => $type,
				'label'    => $label,
				'settings' => $settings_out,
				'width'    => $width,
			];
		}
		return $out;
	}
}
