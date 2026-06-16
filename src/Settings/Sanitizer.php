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
	 * @param mixed       $raw        The raw POSTed payload (expected to be an array of column entries).
	 * @param string|null $screen_key When given, columns whose type doesn't apply to this screen
	 *                                are dropped. A user-meta column persisted onto a posts screen
	 *                                would otherwise render get_user_meta(<post_id>) — leaking
	 *                                meta of whichever user shares that ID.
	 * @return array<int, array<string, mixed>> sanitised columns
	 */
	public function sanitize_columns( $raw, ?string $screen_key = null ): array {
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
			if ( $screen_key !== null && ! $col->applies_to_screen( $screen_key ) ) {
				continue; // Type not valid for this screen — drop.
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

			$format_in = isset( $entry['format'] ) && is_array( $entry['format'] ) ? $entry['format'] : [];

			$out[] = [
				'id'       => $id,
				'type'     => $type,
				'label'    => $label,
				'settings' => $settings_out,
				'width'    => $width,
				'format'   => self::sanitize_format( $format_in ),
			];
		}
		return $out;
	}

	/**
	 * Validate a per-column display-format block.
	 *
	 * @param array<string, mixed> $in
	 * @return array{align:string, prefix:string, suffix:string, style:string, color:string, bg:string}
	 */
	public static function sanitize_format( array $in ): array {
		$align = isset( $in['align'] ) && is_string( $in['align'] ) ? strtolower( $in['align'] ) : '';
		if ( ! in_array( $align, [ '', 'left', 'center', 'right' ], true ) ) {
			$align = '';
		}

		$style = isset( $in['style'] ) && is_string( $in['style'] ) ? strtolower( $in['style'] ) : '';
		if ( ! in_array( $style, [ '', 'badge' ], true ) ) {
			$style = '';
		}

		$prefix = isset( $in['prefix'] ) && is_scalar( $in['prefix'] ) ? sanitize_text_field( (string) $in['prefix'] ) : '';
		$suffix = isset( $in['suffix'] ) && is_scalar( $in['suffix'] ) ? sanitize_text_field( (string) $in['suffix'] ) : '';

		// sanitize_hex_color() returns '' for empty and null for invalid — coerce both to ''.
		$color = isset( $in['color'] ) && is_scalar( $in['color'] ) ? (string) sanitize_hex_color( (string) $in['color'] ) : '';
		$bg    = isset( $in['bg'] ) && is_scalar( $in['bg'] ) ? (string) sanitize_hex_color( (string) $in['bg'] ) : '';

		return [
			'align'  => $align,
			'prefix' => $prefix,
			'suffix' => $suffix,
			'style'  => $style,
			'color'  => $color,
			'bg'     => $bg,
		];
	}
}
