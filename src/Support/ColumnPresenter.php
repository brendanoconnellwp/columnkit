<?php
declare( strict_types=1 );

namespace ColumnKit\Support;

/**
 * Applies per-column display formatting to already-rendered cell HTML.
 *
 * Two layers of presentation, applied in different places:
 *   - Content level (this class): prefix / suffix text and an optional coloured "badge" pill
 *     wrapped around the value. Applied wherever a cell value is emitted (post cells, user/term
 *     cells, and after an inline-edit re-render so the formatting survives the save).
 *   - Column level (ListScreenManager::print_column_styles): width + text alignment, injected as
 *     CSS targeting `.column-ck_{id}` so the header and every cell line up. Not handled here.
 *
 * The `format` array (stored per column entry, sanitised by Sanitizer::sanitize_format):
 *   [ 'align' => '', 'prefix' => '', 'suffix' => '', 'style' => '', 'color' => '', 'bg' => '' ]
 */
final class ColumnPresenter {
	/**
	 * @param string               $value_html Already-escaped HTML for the cell value.
	 * @param array<string, mixed> $format     Sanitised format block (may be empty).
	 */
	public static function format( string $value_html, array $format ): string {
		$prefix = isset( $format['prefix'] ) && is_string( $format['prefix'] ) ? $format['prefix'] : '';
		$suffix = isset( $format['suffix'] ) && is_string( $format['suffix'] ) ? $format['suffix'] : '';
		$style  = isset( $format['style'] ) && is_string( $format['style'] ) ? $format['style'] : '';
		$color  = isset( $format['color'] ) && is_string( $format['color'] ) ? $format['color'] : '';
		$bg     = isset( $format['bg'] ) && is_string( $format['bg'] ) ? $format['bg'] : '';

		// Empty values stay empty — don't render a lone badge / prefix / suffix on a blank cell.
		if ( $value_html === '' ) {
			return '';
		}

		$inner = $value_html;

		$is_badge = $style === 'badge';
		if ( $is_badge || $color !== '' || $bg !== '' ) {
			$css = [];
			if ( $color !== '' ) {
				$css[] = 'color:' . $color;
			}
			if ( $bg !== '' ) {
				$css[] = 'background-color:' . $bg;
			}
			$class      = $is_badge ? 'ck-badge' : 'ck-colored';
			$style_attr = $css ? ' style="' . esc_attr( implode( ';', $css ) ) . '"' : '';
			$inner      = '<span class="' . esc_attr( $class ) . '"' . $style_attr . '>' . $value_html . '</span>';
		}

		if ( $prefix !== '' ) {
			$inner = '<span class="ck-affix ck-prefix">' . esc_html( $prefix ) . '</span>' . $inner;
		}
		if ( $suffix !== '' ) {
			$inner .= '<span class="ck-affix ck-suffix">' . esc_html( $suffix ) . '</span>';
		}

		return $inner;
	}

	/**
	 * Plain-text prefix/suffix for export values (no markup, no badge/colour).
	 *
	 * @param array<string, mixed> $format
	 */
	public static function format_export( string $value, array $format ): string {
		if ( $value === '' ) {
			return $value;
		}
		$prefix = isset( $format['prefix'] ) && is_string( $format['prefix'] ) ? $format['prefix'] : '';
		$suffix = isset( $format['suffix'] ) && is_string( $format['suffix'] ) ? $format['suffix'] : '';
		return $prefix . $value . $suffix;
	}
}
