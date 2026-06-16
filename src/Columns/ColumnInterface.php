<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

interface ColumnInterface {
	/** Unique slug for this column type, e.g. "post_meta". */
	public function get_type(): string;

	/** Human-readable name shown in the settings UI. */
	public function get_label(): string;

	/** Short help text shown under the label. */
	public function get_description(): string;

	/**
	 * Per-column settings schema. Each entry: [ 'key' => string, 'label' => string, 'type' => 'text'|'select', 'options' => array|null, 'required' => bool, 'help' => string ].
	 * 'help' is optional inline guidance shown under the field in the settings UI.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function settings_fields(): array;

	/**
	 * Sanitize per-column user settings before storage.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( array $input ): array;

	/**
	 * Whether this column can render on a given screen key (e.g. post type slug).
	 */
	public function applies_to_screen( string $screen_key ): bool;

	/**
	 * Final HTML for one cell. MUST be already-escaped/safe HTML.
	 *
	 * @param array<string, mixed> $settings User-configured settings for this column instance.
	 */
	public function render( int $object_id, array $settings ): string;

	/**
	 * Plain-text representation for export (CSV / JSON). Default implementation in BaseColumn
	 * is to strip tags and decode entities from render(). Override when you need something
	 * different (e.g. FeaturedImageColumn returns the image URL instead of empty text).
	 *
	 * @param array<string, mixed> $settings
	 */
	public function get_export_value( int $object_id, array $settings ): string;
}
