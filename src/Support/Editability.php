<?php
declare( strict_types=1 );

namespace ColumnKit\Support;

use ColumnKit\Columns\ConditionallyEditableColumn;
use ColumnKit\Columns\EditableColumn;

/**
 * Single decision point for "can this column, configured this way, be inline/bulk edited?".
 *
 * Used by ListScreenManager (whether to emit the .ck-editable wrapper), EditManager
 * (whether to accept an AJAX save / render a bulk-edit field / apply a bulk save). Keeping
 * the rule here means the cell UI and the save endpoint can never disagree — a mismatch
 * would either show an editor that 403s on save, or silently drop a configured edit.
 */
final class Editability {
	/**
	 * @param object               $col      A column instance (any ColumnInterface).
	 * @param array<string, mixed>  $settings The column's per-instance settings.
	 */
	public static function is_editable( object $col, array $settings ): bool {
		if ( ! $col instanceof EditableColumn ) {
			return false;
		}
		if ( $col instanceof ConditionallyEditableColumn ) {
			return $col->supports_inline_edit( $settings );
		}
		return true;
	}
}
