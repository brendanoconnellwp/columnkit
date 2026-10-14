<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

/**
 * A column type that works on more than one kind of list screen (posts, terms, users) and needs
 * to know which one it's configured for.
 *
 * - The settings UI asks for screen-specific settings fields, so a field picker on a taxonomy
 *   screen offers that taxonomy's fields rather than every field on the site.
 * - Sanitizer stamps the screen's object context (`object`, `taxonomy`) into the instance
 *   settings; read it back with Support\ObjectContext::from_settings().
 */
interface ContextualColumn {
	/**
	 * Settings fields for the given screen. Same shape as ColumnInterface::settings_fields().
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function settings_fields_for_screen( string $screen_key ): array;
}
