<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

interface EditableColumn {
	/**
	 * Return the raw stored value as a string. Used to pre-fill the inline editor popover.
	 * Implementations must NOT escape — escaping happens at the data-attribute boundary in
	 * ListScreenManager::render_cell.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function get_raw_value( int $object_id, array $settings ): string;

	/**
	 * What kind of HTML input should the JS popover render? One of:
	 *   - 'text'     plain text input
	 *   - 'number'   numeric input
	 *   - 'date'     date input
	 *   - 'boolean'  three-option select: unchanged / yes / no
	 *   - 'select'   options-driven select (get_edit_options must return non-null)
	 *
	 * @param array<string, mixed> $settings
	 */
	public function get_edit_input_type( array $settings ): string;

	/**
	 * For input type 'select': value => label map. Null otherwise.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, string>|null
	 */
	public function get_edit_options( array $settings ): ?array;

	/**
	 * Render the input control shown inside WP's native Bulk Edit panel. The EditManager
	 * provides the surrounding fieldset and the "apply this column" checkbox; columns only
	 * render the value input.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function render_bulk_edit_field( string $input_name, array $settings ): void;

	/**
	 * Persist a submitted value for one post. $raw_value has already been wp_unslash'd and
	 * sanitize_text_field'd. Implementations may reject by returning without writing.
	 *
	 * Recursion note: implementations that call wp_update_post() must first unhook
	 * EditManager::on_save_post to avoid re-entry. Implementations that only touch post meta
	 * (update_post_meta / delete_post_meta) are inherently safe.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function save_value( int $post_id, string $raw_value, array $settings ): void;
}
