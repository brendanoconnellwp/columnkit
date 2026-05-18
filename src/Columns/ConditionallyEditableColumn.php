<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

/**
 * An EditableColumn whose editability depends on its per-instance settings, not just its class.
 *
 * Custom-field integrations (ACF, Meta Box, JetEngine) expose ONE column type that can point at
 * any field. Whether inline edit is safe depends on the *configured field's type*: a true/false
 * or text field round-trips cleanly through our single-input popover, but an image, repeater,
 * gallery or relationship field would be corrupted by it. Such columns implement this interface
 * so the edit pipeline (cell wrapper, AJAX save, bulk edit) can ask per configuration.
 *
 * A plain EditableColumn (e.g. PostMetaColumn) is always editable; only columns that need to
 * refuse *some* configurations implement this.
 */
interface ConditionallyEditableColumn extends EditableColumn {
	/**
	 * Whether inline/bulk editing is supported for THIS column configuration. When false the
	 * cell renders read-only (no popover, AJAX save rejected, no bulk-edit field).
	 *
	 * @param array<string, mixed> $settings
	 */
	public function supports_inline_edit( array $settings ): bool;
}
