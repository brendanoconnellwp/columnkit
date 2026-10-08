<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

/**
 * An EditableColumn whose popover needs extra context on the cell — e.g. the term picker needs
 * to know WHICH taxonomy. Each key/value becomes a data-ck-{key} attribute on the cell wrapper.
 */
interface EditAttributes {
	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, string> key ([a-z0-9-]) => value
	 */
	public function edit_attributes( array $settings ): array;
}
